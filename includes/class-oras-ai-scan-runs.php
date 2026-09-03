<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores a small, bounded history of scanner run outcomes.
 */
final class ORAS_AI_Scan_Runs {

	const OPTION      = 'oras_ai_scan_runs';
	const MAX_RECORDS = 20;

	public static function start( $mode, $rule_version, $extraction_version, $model ) {
		$records = self::recent();
		$last_id = 0;

		foreach ( $records as $record ) {
			$last_id = max( $last_id, isset( $record['id'] ) ? absint( $record['id'] ) : 0 );
		}

		$record = array(
			'id'                 => $last_id + 1,
			'mode'               => 'rebuild' === $mode ? 'rebuild' : 'changed',
			'started_at'         => current_time( 'mysql' ),
			'completed_at'       => '',
			'discovered'         => 0,
			'processed'          => 0,
			'unchanged'          => 0,
			'static'             => 0,
			'mixed'              => 0,
			'review'             => 0,
			'live'               => 0,
			'ignored'            => 0,
			'excluded'           => 0,
			'missing'            => 0,
			'retired'            => 0,
			'failures'           => 0,
			'rule_version'       => absint( $rule_version ),
			'extraction_version' => absint( $extraction_version ),
			'model'              => sanitize_text_field( $model ),
		);

		$records[] = $record;
		self::store( $records );

		return $record['id'];
	}

	public static function record_discovery( $run_id, $counts ) {
		$allowed = array( 'discovered', 'unchanged', 'excluded', 'missing', 'retired' );
		$changes = array();

		foreach ( $allowed as $field ) {
			if ( isset( $counts[ $field ] ) ) {
				$changes[ $field ] = absint( $counts[ $field ] );
			}
		}

		return self::change( $run_id, $changes );
	}

	public static function record_outcome( $run_id, $outcome, $needs_review = false ) {
		$outcome    = sanitize_key( $outcome );
		$increments = array( 'processed' => 1 );

		$fields = array(
			'static'   => 'static',
			'mixed'    => 'mixed',
			'live'     => 'live',
			'ignored'  => 'ignored',
			'excluded' => 'excluded',
			'error'    => 'failures',
		);

		if ( isset( $fields[ $outcome ] ) ) {
			$increments[ $fields[ $outcome ] ] = 1;
		}

		if ( $needs_review || 'review' === $outcome ) {
			$increments['review'] = 1;
		}

		return self::increment( $run_id, $increments );
	}

	public static function complete( $run_id ) {
		return self::change( $run_id, array( 'completed_at' => current_time( 'mysql' ) ) );
	}

	public static function find( $run_id ) {
		$run_id = absint( $run_id );

		foreach ( self::recent() as $record ) {
			if ( $run_id === absint( isset( $record['id'] ) ? $record['id'] : 0 ) ) {
				return $record;
			}
		}

		return null;
	}

	public static function recent() {
		$records = get_option( self::OPTION, array() );
		return is_array( $records ) ? array_values( $records ) : array();
	}

	private static function increment( $run_id, $increments ) {
		$records = self::recent();
		$updated = false;

		foreach ( $records as &$record ) {
			if ( absint( $run_id ) !== absint( isset( $record['id'] ) ? $record['id'] : 0 ) ) {
				continue;
			}

			foreach ( $increments as $field => $amount ) {
				if ( array_key_exists( $field, $record ) ) {
					$record[ $field ] = absint( $record[ $field ] ) + absint( $amount );
				}
			}
			$updated = true;
			break;
		}
		unset( $record );

		if ( $updated ) {
			self::store( $records );
		}

		return $updated;
	}

	private static function change( $run_id, $changes ) {
		$records = self::recent();
		$updated = false;

		foreach ( $records as &$record ) {
			if ( absint( $run_id ) !== absint( isset( $record['id'] ) ? $record['id'] : 0 ) ) {
				continue;
			}

			foreach ( $changes as $field => $value ) {
				if ( array_key_exists( $field, $record ) ) {
					$record[ $field ] = $value;
				}
			}
			$updated = true;
			break;
		}
		unset( $record );

		if ( $updated ) {
			self::store( $records );
		}

		return $updated;
	}

	private static function store( $records ) {
		$records = array_values( $records );
		if ( count( $records ) > self::MAX_RECORDS ) {
			$records = array_slice( $records, -self::MAX_RECORDS );
		}

		return update_option( self::OPTION, $records, false );
	}
}
