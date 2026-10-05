<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Native daily metadata maintenance; no provider dependencies. */
final class ORAS_AI_Usage_Maintenance {
	const CLEANUP_HOOK = 'oras_ai_prune_usage';
	const STATUS_OPTION = 'oras_ai_usage_maintenance';
	private $ledger;

	public function __construct( ?ORAS_AI_Usage_Ledger $ledger = null ) {
		$this->ledger = $ledger ?: new ORAS_AI_Usage_Ledger();
		add_action( 'init', array( __CLASS__, 'schedule_cleanup' ) );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
	}

	public static function schedule_cleanup() {
		if ( false === wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	public function cleanup() {
		$result = $this->ledger->prune_batch();
		$previous = self::status();
		$status = array( 'last_attempt' => time(), 'last_success' => $previous['last_success'], 'outcome' => 'success', 'examined' => 0, 'removed' => 0, 'redacted' => 0 );
		if ( is_wp_error( $result ) ) {
			$status['outcome'] = 'oras_ai_usage_ledger_busy' === $result->get_error_code() ? 'busy' : 'failed';
		} else {
			$status['last_success'] = $status['last_attempt'];
			foreach ( array( 'examined', 'removed', 'redacted' ) as $key ) {
				$status[ $key ] = max( 0, (int) $result[ $key ] );
			}
		}
		update_option( self::STATUS_OPTION, $status, false );
		return $status;
	}

	public static function status() {
		$stored = get_option( self::STATUS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$status = array( 'next_scheduled' => wp_next_scheduled( self::CLEANUP_HOOK ), 'outcome' => 'never' );
		if ( in_array( $stored['outcome'] ?? '', array( 'success', 'busy', 'failed' ), true ) ) {
			$status['outcome'] = $stored['outcome'];
		}
		foreach ( array( 'last_attempt', 'last_success', 'examined', 'removed', 'redacted' ) as $key ) {
			$status[ $key ] = max( 0, (int) ( $stored[ $key ] ?? 0 ) );
		}
		return $status;
	}
}
