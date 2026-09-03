<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded metadata-only accounting for paid member execution.
 */
final class ORAS_AI_Usage_Ledger {

	const OPTION      = 'oras_ai_usage_ledger';
	const LOCK_OPTION = 'oras_ai_usage_ledger_lock';
	const RETENTION   = '12 months';
	const LOCK_TTL    = 30;

	private $clock;

	public function __construct( $clock = null ) {
		$this->clock = is_callable( $clock ) ? $clock : 'time';
	}

	public function reserve( $user_id, $model, $estimated_input_tokens, array $configuration ) {
		$user_id = absint( $user_id );
		$model   = sanitize_text_field( (string) $model );
		$input   = max( 1, (int) $estimated_input_tokens );

		if ( $user_id <= 0 ) {
			return new ORAS_AI_Execution_Admission( false, 'invalid_identity' );
		}

		$result = $this->with_lock(
			function () use ( $user_id, $model, $input, $configuration ) {
				$now   = $this->now();
				$state = $this->pruned_state( $this->state(), $now );
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

				$pricing = isset( $configuration['pricing'][ $model ] ) && is_array( $configuration['pricing'][ $model ] )
					? $configuration['pricing'][ $model ]
					: null;
				if ( ! $pricing || 'per_million_tokens' !== ( $pricing['unit'] ?? '' ) ) {
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
					'actual_input_tokens'         => 0,
					'actual_output_tokens'        => 0,
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
				if ( 'open' !== $record['status'] ) {
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
				$record['resolved_at']               = $this->now();
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
				$state['reservations'][ $reservation_id ]['resolved_at'] = $this->now();
				$this->store( $state );
				return true;
			}
		);

		return ! is_wp_error( $result ) && (bool) $result;
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
			if ( 'released' === ( $reservation['status'] ?? '' ) || gmdate( 'Y-m', (int) ( $reservation['created_at'] ?? 0 ) ) !== $month ) {
				continue;
			}
			$site_usage['allowed']++;
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
			'member_day_allowed'              => $counts['day'],
			'member_month_allowed'            => $counts['month'],
			'site_month_actual_microdollars'   => $site['actual'],
			'site_month_reserved_microdollars' => $site['reserved'],
			'site_month_allowed'               => $site_usage['allowed'],
			'site_month_input_tokens'          => $site_usage['input_tokens'],
			'site_month_output_tokens'         => $site_usage['output_tokens'],
			'site_month_models'                => $site_usage['models'],
			'rejections'                       => $rejections,
		);
	}

	public function budget_state( array $configuration ) {
		$summary = $this->summary();
		$actual  = $summary['site_month_actual_microdollars'];
		$exposure = $actual + $summary['site_month_reserved_microdollars'];

		return array(
			'warning'   => $actual >= (int) $configuration['warning_microdollars'],
			'hard_stop' => $exposure >= (int) $configuration['hard_stop_microdollars'],
		);
	}

	public function prune() {
		$result = $this->with_lock(
			function () {
				return $this->store( $this->pruned_state( $this->state(), $this->now() ) );
			}
		);

		return ! is_wp_error( $result ) && (bool) $result;
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
		return update_option( self::OPTION, $state, false );
	}

	private function pruned_state( array $state, $now ) {
		$cutoff       = strtotime( '-' . self::RETENTION, (int) $now );
		$cutoff_month = gmdate( 'Y-m', $cutoff );

		foreach ( $state['reservations'] as $id => $reservation ) {
			if ( (int) ( $reservation['created_at'] ?? 0 ) < $cutoff ) {
				unset( $state['reservations'][ $id ] );
			}
		}

		foreach ( $state['rejections'] as $user_id => $periods ) {
			foreach ( $periods as $month => $counts ) {
				if ( (string) $month < $cutoff_month ) {
					unset( $state['rejections'][ $user_id ][ $month ] );
				}
			}
			if ( empty( $state['rejections'][ $user_id ] ) ) {
				unset( $state['rejections'][ $user_id ] );
			}
		}

		foreach ( $state['burst'] as $user_id => $timestamps ) {
			$state['burst'][ $user_id ] = array_values(
				array_filter(
					is_array( $timestamps ) ? $timestamps : array(),
					static function ( $timestamp ) use ( $now ) {
						return (int) $timestamp > (int) $now - 60;
					}
				)
			);
			if ( empty( $state['burst'][ $user_id ] ) ) {
				unset( $state['burst'][ $user_id ] );
			}
		}

		return $state;
	}

	private function reservation_counts( array $state, $user_id, $now ) {
		$day   = gmdate( 'Y-m-d', (int) $now );
		$month = gmdate( 'Y-m', (int) $now );
		$counts = array( 'day' => 0, 'month' => 0 );

		foreach ( $state['reservations'] as $reservation ) {
			if ( (int) ( $reservation['user_id'] ?? 0 ) !== (int) $user_id || 'released' === ( $reservation['status'] ?? '' ) ) {
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
			if ( 'open' === $status && gmdate( 'Y-m', (int) $reservation['created_at'] ) === $month ) {
				$totals['reserved'] += max( 0, (int) $reservation['reserved_cost_microdollars'] );
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

		if ( ! add_option( self::LOCK_OPTION, $lock, '', false ) ) {
			$current = get_option( self::LOCK_OPTION, array() );
			if ( ! is_array( $current ) || (int) ( $current['acquired_at'] ?? 0 ) > $now - self::LOCK_TTL ) {
				return new WP_Error( 'oras_ai_usage_ledger_busy', __( 'Usage accounting is temporarily unavailable.', 'oras-ai-assistant' ) );
			}
			delete_option( self::LOCK_OPTION );
			if ( ! add_option( self::LOCK_OPTION, $lock, '', false ) ) {
				return new WP_Error( 'oras_ai_usage_ledger_busy', __( 'Usage accounting is temporarily unavailable.', 'oras-ai-assistant' ) );
			}
		}

		try {
			return call_user_func( $callback );
		} finally {
			$current = get_option( self::LOCK_OPTION, array() );
			if ( is_array( $current ) && $token === ( $current['token'] ?? '' ) ) {
				delete_option( self::LOCK_OPTION );
			}
		}
	}

	private function invalid_reservation() {
		return new WP_Error(
			'oras_ai_invalid_cost_reservation',
			__( 'Invalid cost reservation.', 'oras-ai-assistant' )
		);
	}
}
