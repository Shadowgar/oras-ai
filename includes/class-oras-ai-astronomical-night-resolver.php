<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves an ORAS observing night through the Task 2 provider contract. */
final class ORAS_AI_Astronomical_Night_Resolver {
	private $provider;
	private $clock;

	public function __construct( ORAS_AI_Astronomy_Provider_Interface $provider, ORAS_AI_Clock_Interface $clock ) {
		$this->provider = $provider;
		$this->clock    = $clock;
	}

	public function resolve( ORAS_AI_Authorized_Request $authorized_request, DateTimeImmutable $local_date, $include_active_night = true ) {
		$site = ORAS_AI_Observing_Site::oras_observatory();
		$timezone = $site->timezone();
		$date = $local_date->setTimezone( $timezone )->format( 'Y-m-d' );
		// Local noon avoids date rollover ambiguity inside day-based astronomy
		// libraries while preserving the authoritative site timezone.
		$next = ( new DateTimeImmutable( $date . ' 12:00:00', $timezone ) )->modify( '+1 day' );
		$now  = $this->clock->now();
		$is_today = $now->setTimezone( $timezone )->format( 'Y-m-d' ) === $date;
		$first_instant = $is_today
			? null // The request factory samples its own trusted now, avoiding clock drift.
			: new DateTimeImmutable( $date . ' 12:00:00', $timezone );

		try {
			$first = $this->provider->fetch(
				ORAS_AI_Current_Data_Request::from_authorized_request(
					$authorized_request,
					$this->clock,
					array( ORAS_AI_Current_Data_Request::ASTRONOMICAL_DARKNESS ),
					$first_instant,
					$first_instant
				)
			);
			// Before today's dawn, "tonight" is still the active preceding night.
			// Today's calculation supplies that dawn without requesting yesterday.
			$current_dawn = $this->fact_time( $first, 'astronomy:sun:astronomical-dawn' );
			if ( $is_today && $include_active_night && $current_dawn instanceof DateTimeImmutable && $now < $current_dawn ) {
				return array( $now, $current_dawn );
			}
			$second = $this->provider->fetch(
				ORAS_AI_Current_Data_Request::from_authorized_request(
					$authorized_request,
					$this->clock,
					array( ORAS_AI_Current_Data_Request::ASTRONOMICAL_DARKNESS ),
					$next,
					$next
				)
			);
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'night_window_unavailable', 'Astronomical night is unavailable.' );
		}

		$dusk = $this->fact_time( $first, 'astronomy:sun:astronomical-dusk' );
		$dawn = $this->fact_time( $second, 'astronomy:sun:astronomical-dawn' );
		if ( ! $dusk instanceof DateTimeImmutable || ! $dawn instanceof DateTimeImmutable || $dawn <= $dusk ) {
			return new WP_Error( 'night_window_unavailable', 'Astronomical night is unavailable.' );
		}
		return array( $is_today && $now > $dusk ? $now : $dusk, $dawn );
	}

	private function fact_time( $result, $fact_key ) {
		if ( ! $result instanceof ORAS_AI_Current_Data_Result || ORAS_AI_Current_Data_Result::SUCCESS !== $result->status() ) {
			return null;
		}
		foreach ( $result->values() as $fact ) {
			if ( ! $fact instanceof ORAS_AI_Astronomy_Fact ) {
				continue;
			}
			$data = $fact->to_array();
			if ( $fact_key === $data['fact_key'] && is_string( $data['value'] ) ) {
				try {
					return new DateTimeImmutable( $data['value'] );
				} catch ( Throwable $throwable ) {
					return null;
				}
			}
		}
		return null;
	}
}
