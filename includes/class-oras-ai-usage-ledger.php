<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metadata-only accounting for site-wide paid execution and member question quotas.
 */
final class ORAS_AI_Usage_Ledger {

	const OPTION      = 'oras_ai_usage_ledger';
	const LOCK_OPTION = 'oras_ai_usage_ledger_lock';
	const FAULT_OPTION = 'oras_ai_usage_ledger_fault';
	const RETENTION   = '12 months';
	const RETENTION_BATCH = 100;
	const LOCK_TTL    = 30;

	private $clock;

	public function __construct( $clock = null ) {
		$this->clock = is_callable( $clock ) ? $clock : 'time';
	}

	public function reserve( $user_id, $model, $estimated_input_tokens, array $configuration, $source = 'answer', $member_question = true ) {
		$user_id = absint( $user_id );
		$model   = sanitize_text_field( (string) $model );
		$input   = max( 1, (int) $estimated_input_tokens );

		if ( ( $member_question && $user_id <= 0 ) || ! in_array( $source, array( 'answer', 'support_summary', 'domain_classifier', 'scanner_classification' ), true ) ) {
			return new ORAS_AI_Execution_Admission( false, 'invalid_identity' );
		}

		$result = $this->with_lock(
			function () use ( $user_id, $model, $input, $configuration, $source, $member_question ) {
				if ( get_option( self::FAULT_OPTION, false ) ) {
					return new ORAS_AI_Execution_Admission( false, 'ledger_unavailable' );
				}
				$now   = $this->now();
				$state = $this->pruned_state( $this->state(), $now );
				if ( $member_question ) {
					$burst = isset( $state['burst'][ $user_id ] ) && is_array( $state['burst'][ $user_id ] )
						? $state['burst'][ $user_id ]
						: array();
					$burst = array_values(
						array_filter(
							$burst,
							static function ( $timestamp ) use ( $now ) {
								return (int) $timestamp > $now - 60;
							}
						)
					);
					$burst_count = count( $burst );
					$burst[]     = $now;
					$state['burst'][ $user_id ] = $burst;

					if ( $burst_count >= (int) $configuration['burst_per_minute'] ) {
						$this->increment_rejection( $state, $user_id, 'burst_limit', $now );
						$this->store( $state );
						return new ORAS_AI_Execution_Admission( false, 'burst_limit' );
					}

					$counts = $this->reservation_counts( $state, $user_id, $now );
					if ( $counts['day'] >= (int) $configuration['daily_quota'] ) {
						$this->increment_rejection( $state, $user_id, 'daily_quota', $now );
						$this->store( $state );
						return new ORAS_AI_Execution_Admission( false, 'daily_quota' );
					}

					if ( $counts['month'] >= (int) $configuration['monthly_quota'] ) {
						$this->increment_rejection( $state, $user_id, 'monthly_quota', $now );
						$this->store( $state );
						return new ORAS_AI_Execution_Admission( false, 'monthly_quota' );
					}
				}

				$pricing = isset( $configuration['pricing'][ $model ] ) && is_array( $configuration['pricing'][ $model ] )
					? $configuration['pricing'][ $model ]
					: null;
				if ( ! in_array( $model, ORAS_AI_Config::allowed_openai_models(), true ) || ! $pricing || 'per_million_tokens' !== ( $pricing['unit'] ?? '' )
					|| ! is_int( $pricing['input_microdollars_per_million_tokens'] ?? null ) || $pricing['input_microdollars_per_million_tokens'] <= 0
					|| ! is_int( $pricing['output_microdollars_per_million_tokens'] ?? null ) || $pricing['output_microdollars_per_million_tokens'] <= 0 ) {
					$this->increment_rejection( $state, $user_id, 'missing_model_price', $now );
					$this->store( $state );
					return new ORAS_AI_Execution_Admission( false, 'missing_model_price' );
				}

				$output_tokens = max( 1, (int) $configuration['max_output_tokens'] );
				$reserved_cost = $this->calculate_cost(
					$input,
					$output_tokens,
					$pricing
				);
				$site = $this->site_month_totals( $state, $now );
				if ( $site['actual'] + $site['reserved'] + $reserved_cost >= (int) $configuration['hard_stop_microdollars'] ) {
					$this->increment_rejection( $state, $user_id, 'site_hard_stop', $now );
					$this->store( $state );
					return new ORAS_AI_Execution_Admission( false, 'site_hard_stop' );
				}

				$reservation_id = 'oras-ai-' . str_pad( (string) $state['next_id'], 10, '0', STR_PAD_LEFT );
				$state['next_id']++;
				$state['reservations'][ $reservation_id ] = array(
					'id'                         => $reservation_id,
					'user_id'                    => $user_id,
					'source'                     => $source,
					'member_question'            => (bool) $member_question,
					'created_at'                 => $now,
					'model'                      => $model,
					'status'                     => 'open',
					'estimated_input_tokens'     => $input,
					'maximum_output_tokens'      => $output_tokens,
					'reserved_cost_microdollars' => $reserved_cost,
					'pricing'                     => array(
						'input_microdollars_per_million_tokens'  => (int) $pricing['input_microdollars_per_million_tokens'],
						'output_microdollars_per_million_tokens' => (int) $pricing['output_microdollars_per_million_tokens'],
						'unit'                                    => 'per_million_tokens',
					),
					'actual_input_tokens'         => null,
					'actual_output_tokens'        => null,
					'actual_cost_microdollars'    => 0,
					'resolved_at'                 => 0,
				);
				$this->store( $state );

				return new ORAS_AI_Execution_Admission(
					true,
					'',
					$reservation_id,
					$reserved_cost,
					$output_tokens,
					(int) $configuration['execution_timeout_seconds']
				);
			}
		);

		return is_wp_error( $result )
			? new ORAS_AI_Execution_Admission( false, 'ledger_unavailable' )
			: $result;
	}

	public function record_rejection( $user_id, $reason ) {
		$user_id = absint( $user_id );
		$reason  = sanitize_key( $reason );
		if ( $user_id <= 0 || '' === $reason ) {
			return false;
		}

		$result = $this->with_lock(
			function () use ( $user_id, $reason ) {
				$now   = $this->now();
				$state = $this->pruned_state( $this->state(), $now );
				$this->increment_rejection( $state, $user_id, $reason, $now );
				return $this->store( $state );
			}
		);

		return ! is_wp_error( $result ) && (bool) $result;
	}

	public function reconcile( $reservation_id, $model, $input_tokens, $output_tokens ) {
		$reservation_id = sanitize_text_field( (string) $reservation_id );
		$model          = sanitize_text_field( (string) $model );
		if ( '' === $reservation_id || ! is_int( $input_tokens ) || ! is_int( $output_tokens ) || $input_tokens < 0 || $output_tokens < 0 ) {
			return $this->invalid_reservation();
		}

		return $this->with_lock(
			function () use ( $reservation_id, $model, $input_tokens, $output_tokens ) {
				$state = $this->state();
				if ( ! isset( $state['reservations'][ $reservation_id ] ) ) {
					return $this->invalid_reservation();
				}

				$record = $state['reservations'][ $reservation_id ];
				if ( $model !== $record['model'] ) {
					return $this->invalid_reservation();
				}
				if ( 'reconciled' === $record['status'] ) {
					return $record;
				}
				if ( ! in_array( $record['status'], array( 'open', 'dispatched', 'usage_unknown' ), true ) ) {
					return $this->invalid_reservation();
				}

				$record['status']                   = 'reconciled';
				$record['actual_input_tokens']      = max( 0, (int) $input_tokens );
				$record['actual_output_tokens']     = max( 0, (int) $output_tokens );
				$record['actual_cost_microdollars'] = $this->calculate_cost(
					$record['actual_input_tokens'],
					$record['actual_output_tokens'],
					$record['pricing']
				);
				$record['resolved_at']               = $this->resolution_time( $record );
				$state['reservations'][ $reservation_id ] = $record;
				$this->store( $state );

				return $record;
			}
		);
	}

	public function release( $reservation_id ) {
		$reservation_id = sanitize_text_field( (string) $reservation_id );
		if ( '' === $reservation_id ) {
			return false;
		}

		$result = $this->with_lock(
			function () use ( $reservation_id ) {
				$state = $this->state();
				if ( ! isset( $state['reservations'][ $reservation_id ] ) ) {
					return false;
				}

				$status = $state['reservations'][ $reservation_id ]['status'];
				if ( 'released' === $status || 'reconciled' === $status ) {
					return true;
				}
				if ( 'open' !== $status ) {
					return false;
				}

				$state['reservations'][ $reservation_id ]['status']      = 'released';
				$state['reservations'][ $reservation_id ]['resolved_at'] = $this->resolution_time( $state['reservations'][ $reservation_id ] );
				$this->store( $state );
				return true;
			}
		);

		return ! is_wp_error( $result ) && (bool) $result;
	}

	/**
	 * Conservatively account the full reservation when dispatch occurred but
	 * normalized provider usage is unavailable.
	 *
	 * @param string $reservation_id Reservation identifier.
	 * @return array|WP_Error
	 */
	public function settle_reserved_maximum( $reservation_id ) {
		return $this->with_lock(
			function () use ( $reservation_id ) {
				$state = $this->state();
				$record = $state['reservations'][ $reservation_id ] ?? null;
				if ( ! is_array( $record ) ) {
					return $this->invalid_reservation();
				}
				if ( in_array( $record['status'], array( 'reconciled', 'usage_unknown' ), true ) ) {
					return $record;
				}
				if ( ! in_array( $record['status'], array( 'open', 'dispatched' ), true ) ) {
					return $this->invalid_reservation();
				}
				$record['status'] = 'usage_unknown';
				$record['actual_input_tokens'] = null;
				$record['actual_output_tokens'] = null;
				$record['conservative_cost_microdollars'] = $record['reserved_cost_microdollars'];
				$record['resolved_at'] = $this->resolution_time( $record );
				$state['reservations'][ $reservation_id ] = $record;
				$this->store( $state );
				return $record;
			}
		);
	}

	/** Claim once, covering the complete serialized request before HTTP. */
	public function claim_dispatch( $reservation_id, $model, $source, $input, $output, array $configuration ) {
		return $this->with_lock(
			function () use ( $reservation_id, $model, $source, $input, $output, $configuration ) {
				if ( get_option( self::FAULT_OPTION, false ) ) {
					return $this->invalid_reservation();
				}
				$state = $this->state();
				$record = $state['reservations'][ $reservation_id ] ?? null;
				if ( ! is_array( $record ) || 'open' !== $record['status'] || $model !== $record['model']
					|| $source !== ( $record['source'] ?? 'answer' ) || $output > $record['maximum_output_tokens'] ) {
					return $this->invalid_reservation();
				}
				$site = $this->site_month_totals( $state, $this->now() );
				$record['estimated_input_tokens'] = max( $input, $record['estimated_input_tokens'] );
				$cost = $this->calculate_cost( $record['estimated_input_tokens'], $record['maximum_output_tokens'], $record['pricing'] );
				if ( $site['actual'] + $site['reserved'] - $record['reserved_cost_microdollars'] + $cost >= $configuration['hard_stop_microdollars'] ) {
					return new WP_Error( 'oras_ai_site_hard_stop', __( 'OpenAI budget is unavailable.', 'oras-ai-assistant' ) );
				}
				$record['reserved_cost_microdollars'] = $cost;
				$record['status'] = 'dispatched';
				if ( empty( $record['retention_redacted'] ) ) {
					$record['dispatched_at'] = $this->now();
				}
				$state['reservations'][ $reservation_id ] = $record;
				$this->store( $state );
				return $record;
			}
		);
	}

	/** Durable incident fence; this is not another spend ledger. First failure wins. */
	public function flag_settlement_failure( $reservation_id, $input_tokens = null, $output_tokens = null ) {
		$this->insert_option_once( self::FAULT_OPTION, array(
			'reservation_id' => sanitize_text_field( (string) $reservation_id ),
			'input_tokens' => is_int( $input_tokens ) && $input_tokens >= 0 ? $input_tokens : null,
			'output_tokens' => is_int( $output_tokens ) && $output_tokens >= 0 ? $output_tokens : null,
		) );
	}

	public function reservation( $reservation_id ) {
		$state = $this->state();
		return isset( $state['reservations'][ $reservation_id ] )
			? $state['reservations'][ $reservation_id ]
			: null;
	}

	public function summary( $user_id = 0 ) {
		$now     = $this->now();
		$state   = $this->pruned_state( $this->state(), $now );
		$user_id = absint( $user_id );
		$counts  = $user_id > 0 ? $this->reservation_counts( $state, $user_id, $now ) : array( 'day' => 0, 'month' => 0 );
		$site    = $this->site_month_totals( $state, $now );
		$month   = gmdate( 'Y-m', $now );
		$rejections = array();
		$site_usage = array(
			'allowed'       => 0,
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'models'        => array(),
			'sources'       => array(),
			'unknown'       => 0,
		);

		foreach ( $state['rejections'] as $record_user_id => $periods ) {
			if ( $user_id > 0 && (int) $record_user_id !== $user_id ) {
				continue;
			}
			$period = isset( $periods[ $month ] ) && is_array( $periods[ $month ] ) ? $periods[ $month ] : array();
			foreach ( $period as $reason => $count ) {
				$rejections[ $reason ] = ( $rejections[ $reason ] ?? 0 ) + max( 0, (int) $count );
			}
		}

		foreach ( $state['reservations'] as $reservation ) {
			$status = $reservation['status'] ?? '';
			$settled = in_array( $status, array( 'reconciled', 'usage_unknown' ), true );
			if ( 'released' === $status || ( $settled && gmdate( 'Y-m', (int) ( $reservation['resolved_at'] ?? 0 ) ) !== $month ) ) {
				continue;
			}
			$site_usage['allowed']++;
			$source = $reservation['source'] ?? 'answer';
			if ( ! isset( $site_usage['sources'][ $source ] ) ) {
				$site_usage['sources'][ $source ] = array( 'calls' => 0, 'accounted_microdollars' => 0 );
			}
			$site_usage['sources'][ $source ]['calls']++;
			$cost = 'usage_unknown' === $reservation['status'] ? ( $reservation['conservative_cost_microdollars'] ?? 0 ) : ( $reservation['actual_cost_microdollars'] ?? 0 );
			$site_usage['sources'][ $source ]['accounted_microdollars'] += $cost;
			if ( 'usage_unknown' === $reservation['status'] ) {
				$site_usage['unknown'] += $cost;
			}
			$model = (string) ( $reservation['model'] ?? '' );
			if ( '' !== $model ) {
				$site_usage['models'][ $model ] = ( $site_usage['models'][ $model ] ?? 0 ) + 1;
			}
			if ( 'reconciled' === ( $reservation['status'] ?? '' ) ) {
				$site_usage['input_tokens']  += max( 0, (int) ( $reservation['actual_input_tokens'] ?? 0 ) );
				$site_usage['output_tokens'] += max( 0, (int) ( $reservation['actual_output_tokens'] ?? 0 ) );
			}
		}

		return array(
			'accounting_available'            => ! (bool) get_option( self::FAULT_OPTION, false ),
			'member_day_allowed'              => $counts['day'],
			'member_month_allowed'            => $counts['month'],
			'site_month_actual_microdollars'   => $site['actual'],
			'site_month_reserved_microdollars' => $site['reserved'],
			'site_month_allowed'               => $site_usage['allowed'],
			'site_month_input_tokens'          => $site_usage['input_tokens'],
			'site_month_output_tokens'         => $site_usage['output_tokens'],
			'site_month_models'                => $site_usage['models'],
			'site_month_sources'               => $site_usage['sources'],
			'site_month_unknown_microdollars'  => $site_usage['unknown'],
			'rejections'                       => $rejections,
		);
	}

	public function budget_state( array $configuration ) {
		$summary = $this->summary();
		$actual  = $summary['site_month_actual_microdollars'];
		$exposure = $actual + $summary['site_month_reserved_microdollars'];

		return array(
			'warning'   => $actual >= (int) $configuration['warning_microdollars'],
			'hard_stop' => ! $summary['accounting_available'] || $exposure >= (int) $configuration['hard_stop_microdollars'],
		);
	}

	public function prune() {
		return ! is_wp_error( $this->prune_batch() );
	}

	/** One bounded batch, using the same atomic mutation boundary as paid calls. */
	public function prune_batch() {
		return $this->with_lock(
			function () {
				$counts = array( 'examined' => 0, 'removed' => 0, 'redacted' => 0 );
				$state = $this->pruned_state( $this->state(), $this->now(), $counts );
				$this->store( $state );
				return $counts;
			}
		);
	}

	private function state() {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'next_id'      => max( 1, (int) ( $state['next_id'] ?? 1 ) ),
			'reservations' => isset( $state['reservations'] ) && is_array( $state['reservations'] ) ? $state['reservations'] : array(),
			'burst'        => isset( $state['burst'] ) && is_array( $state['burst'] ) ? $state['burst'] : array(),
			'rejections'   => isset( $state['rejections'] ) && is_array( $state['rejections'] ) ? $state['rejections'] : array(),
		);
	}

	private function store( array $state ) {
		if ( ! update_option( self::OPTION, $state, false ) && get_option( self::OPTION ) !== $state ) {
			throw new RuntimeException( 'Usage ledger persistence failed.' );
		}
		return true;
	}

	private function pruned_state( array $state, $now, &$counts = null ) {
		$counts = array( 'examined' => 0, 'removed' => 0, 'redacted' => 0 );
		$cutoff = strtotime( '-' . self::RETENTION, (int) $now );
		$cutoff_month = gmdate( 'Y-m', $cutoff );
		$month = gmdate( 'Y-m', $now );
		$fault = get_option( self::FAULT_OPTION, array() );

		// Rotate retained entries so a bounded pass never starves later old records.
		// The option still requires a whole-value load/store; this bounds record work.
		foreach ( array_slice( $state['reservations'], 0, self::RETENTION_BATCH, true ) as $id => $record ) {
			$counts['examined']++;
			unset( $state['reservations'][ $id ] );
			if ( (int) ( $record['created_at'] ?? 0 ) < $cutoff ) {
				$preserve = in_array( $record['status'] ?? '', array( 'open', 'dispatched', 'usage_unknown' ), true )
					|| $month === gmdate( 'Y-m', (int) ( $record['resolved_at'] ?? 0 ) )
					|| $id === ( $fault['reservation_id'] ?? '' );
				if ( ! $preserve ) {
					$counts['removed']++;
					continue;
				}
				if ( empty( $record['retention_redacted'] ) ) {
					$counts['redacted']++;
				}
				// Recovery data carries no member identity, content or exact activity time.
				$record = array_intersect_key( $record, array_flip( array(
					'id', 'source', 'model', 'status', 'estimated_input_tokens', 'maximum_output_tokens',
					'reserved_cost_microdollars', 'pricing', 'actual_input_tokens', 'actual_output_tokens',
					'actual_cost_microdollars', 'conservative_cost_microdollars', 'resolved_at',
				) ) );
				$record['retention_redacted'] = true;
				$record['resolved_at'] = empty( $record['resolved_at'] ) ? 0 : strtotime( gmdate( 'Y-m-01', (int) $record['resolved_at'] ) . ' UTC' );
			}
			$state['reservations'][ $id ] = $record;
		}

		foreach ( array( 'rejections', 'burst' ) as $bucket ) {
			$remaining = self::RETENTION_BATCH;
			foreach ( array_slice( $state[ $bucket ], 0, self::RETENTION_BATCH, true ) as $user_id => $entries ) {
				unset( $state[ $bucket ][ $user_id ] );
				$entries = is_array( $entries ) ? $entries : array();
				foreach ( array_slice( $entries, 0, $remaining, true ) as $key => $value ) {
					$remaining--;
					$counts['examined']++;
					unset( $entries[ $key ] );
					$expired = 'rejections' === $bucket ? (string) $key < $cutoff_month : (int) $value <= (int) $now - 60;
					if ( $expired ) {
						$counts['removed']++;
					} else {
						$entries[ $key ] = $value;
					}
				}
				if ( $entries ) {
					$state[ $bucket ][ $user_id ] = $entries;
				}
				if ( $remaining <= 0 ) {
					break;
				}
			}
		}
		return $state;
	}

	private function resolution_time( array $record ) {
		$now = $this->now();
		return empty( $record['retention_redacted'] ) ? $now : strtotime( gmdate( 'Y-m-01', $now ) . ' UTC' );
	}

	private function reservation_counts( array $state, $user_id, $now ) {
		$day   = gmdate( 'Y-m-d', (int) $now );
		$month = gmdate( 'Y-m', (int) $now );
		$counts = array( 'day' => 0, 'month' => 0 );

		foreach ( $state['reservations'] as $reservation ) {
			if ( false === ( $reservation['member_question'] ?? true ) || (int) ( $reservation['user_id'] ?? 0 ) !== (int) $user_id || 'released' === ( $reservation['status'] ?? '' ) ) {
				continue;
			}
			$created = (int) ( $reservation['created_at'] ?? 0 );
			if ( gmdate( 'Y-m', $created ) === $month ) {
				$counts['month']++;
			}
			if ( gmdate( 'Y-m-d', $created ) === $day ) {
				$counts['day']++;
			}
		}

		return $counts;
	}

	private function site_month_totals( array $state, $now ) {
		$month = gmdate( 'Y-m', (int) $now );
		$totals = array( 'actual' => 0, 'reserved' => 0 );

		foreach ( $state['reservations'] as $reservation ) {
			$status = $reservation['status'] ?? '';
			if ( in_array( $status, array( 'open', 'dispatched' ), true ) ) {
				$totals['reserved'] += max( 0, (int) $reservation['reserved_cost_microdollars'] );
			} elseif ( 'usage_unknown' === $status && gmdate( 'Y-m', (int) $reservation['resolved_at'] ) === $month ) {
				$totals['actual'] += max( 0, (int) $reservation['conservative_cost_microdollars'] );
			} elseif ( 'reconciled' === $status && gmdate( 'Y-m', (int) $reservation['resolved_at'] ) === $month ) {
				$totals['actual'] += max( 0, (int) $reservation['actual_cost_microdollars'] );
			}
		}

		return $totals;
	}

	private function increment_rejection( array &$state, $user_id, $reason, $now ) {
		$month = gmdate( 'Y-m', (int) $now );
		if ( ! isset( $state['rejections'][ $user_id ][ $month ][ $reason ] ) ) {
			$state['rejections'][ $user_id ][ $month ][ $reason ] = 0;
		}
		$state['rejections'][ $user_id ][ $month ][ $reason ]++;
	}

	private function calculate_cost( $input_tokens, $output_tokens, array $pricing ) {
		$input_cost = (int) ceil(
			( max( 0, (int) $input_tokens ) * (int) $pricing['input_microdollars_per_million_tokens'] ) / 1000000
		);
		$output_cost = (int) ceil(
			( max( 0, (int) $output_tokens ) * (int) $pricing['output_microdollars_per_million_tokens'] ) / 1000000
		);

		return $input_cost + $output_cost;
	}

	private function now() {
		return (int) call_user_func( $this->clock );
	}

	private function with_lock( $callback ) {
		$now   = $this->now();
		$token = uniqid( 'oras-ai-', true );
		$lock  = array( 'token' => $token, 'acquired_at' => $now );

		$this->refresh_options();
		if ( ! $this->insert_option_once( self::LOCK_OPTION, $lock ) ) {
			// Never delete an unowned lock, even if old: a delayed writer may still run.
			return new WP_Error( 'oras_ai_usage_ledger_busy', __( 'Usage accounting is temporarily unavailable.', 'oras-ai-assistant' ) );
		}
		$release = true;
		try {
			$this->refresh_options();
			return call_user_func( $callback );
		} catch ( Throwable $error ) {
			// Failed storage may hide known spend. Deny further dispatch until repaired.
			$release = false;
			return new WP_Error( 'oras_ai_usage_ledger_unavailable', __( 'Usage accounting is temporarily unavailable.', 'oras-ai-assistant' ) );
		} finally {
			$this->refresh_options();
			$current = get_option( self::LOCK_OPTION, array() );
			if ( $release && is_array( $current ) && $token === ( $current['token'] ?? '' ) ) {
				delete_option( self::LOCK_OPTION );
			}
		}
	}

	/** WordPress add_option can upsert a concurrent insert, so cannot acquire a lock. */
	private function insert_option_once( $name, array $value ) {
		global $wpdb;
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
			$name,
			serialize( $value )
		) );
		$this->refresh_options();
		return 1 === $inserted;
	}

	private function refresh_options() {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( self::FAULT_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private function invalid_reservation() {
		return new WP_Error(
			'oras_ai_invalid_cost_reservation',
			__( 'Invalid cost reservation.', 'oras-ai-assistant' )
		);
	}
}
