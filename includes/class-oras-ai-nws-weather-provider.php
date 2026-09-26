<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Strict api.weather.gov adapter for the authoritative ORAS site. */
final class ORAS_AI_NWS_Weather_Provider implements ORAS_AI_Weather_Provider_Interface {
	const PROVIDER_ID = 'nws';
	const CURRENT_OBSERVATION_MAX_AGE_SECONDS = 5400;
	const MAX_RESPONSE_BYTES = 1048576;

	private const HOST = 'https://api.weather.gov';
	private const POINT_TTL = 86400;
	private const STATION_TTL = 86400;
	private const OBSERVATION_TTL = 300;
	private const FORECAST_TTL = 600;

	private $user_agent;
	private $http;
	private $clock;
	private $cache;

	public function __construct( $user_agent, $http = null, ?ORAS_AI_Clock_Interface $clock = null, ?ORAS_AI_NWS_Cache $cache = null ) {
		$this->user_agent = trim( (string) $user_agent );
		$this->http       = is_callable( $http ) ? $http : 'wp_remote_get';
		$this->clock      = $clock ?: new ORAS_AI_System_Clock();
		$this->cache      = $cache ?: new ORAS_AI_NWS_Cache( $this->clock );
	}

	public function provider_id() {
		return self::PROVIDER_ID;
	}

	public function fetch( ORAS_AI_Current_Data_Request $request ) {
		if ( ! in_array( ORAS_AI_Current_Data_Request::WEATHER_CONDITIONS, $request->fact_types(), true ) ) {
			return ORAS_AI_Current_Data_Result::unknown( self::PROVIDER_ID, 'unsupported_fact_type' );
		}
		if ( '' === $this->user_agent || strlen( $this->user_agent ) > 200 || preg_match( '/[\r\n]/', $this->user_agent ) ) {
			return ORAS_AI_Current_Data_Result::unavailable( self::PROVIDER_ID, 'provider_not_configured' );
		}

		try {
			return $request->window_end() > $request->requested_at() || $request->requested_at() > $this->clock->now()
				? $this->forecast( $request )
				: $this->observation( $request );
		} catch ( ORAS_AI_NWS_Exception $exception ) {
			return in_array( $exception->reason(), array( 'provider_unavailable', 'provider_rate_limited', 'provider_request_failed' ), true )
				? ORAS_AI_Current_Data_Result::unavailable( self::PROVIDER_ID, $exception->reason() )
				: ORAS_AI_Current_Data_Result::unknown( self::PROVIDER_ID, $exception->reason() );
		} catch ( Throwable $throwable ) {
			return ORAS_AI_Current_Data_Result::unknown( self::PROVIDER_ID, 'malformed_provider_response' );
		}
	}

	private function observation( ORAS_AI_Current_Data_Request $request ) {
		$cached = $this->cache->get( 'latest_observation' );
		if ( is_array( $cached ) ) {
			return $this->observation_result( $cached );
		}

		$mapping = $this->point_mapping( $request );
		try {
			$station = $this->station_mapping( $request, $mapping );
		} catch ( ORAS_AI_NWS_Exception $exception ) {
			if ( 'point_mapping_invalid' !== $exception->reason() ) {
				throw $exception;
			}
			$this->cache->delete( 'point_mapping' );
			$this->cache->delete( 'station_mapping' );
			$mapping = $this->point_mapping( $request, true );
			$station = $this->station_mapping( $request, $mapping, true );
		}
		$url = self::HOST . '/stations/' . rawurlencode( $station['station_id'] ) . '/observations/latest?require_qc=true';
		$response = $this->request_json( $url );
		if ( in_array( $response['status'], array( 404, 410 ), true ) ) {
			$this->cache->delete( 'station_mapping' );
			$station = $this->station_mapping( $request, $mapping, true );
			$url = self::HOST . '/stations/' . rawurlencode( $station['station_id'] ) . '/observations/latest?require_qc=true';
			$response = $this->request_json( $url );
		}
		$this->require_success( $response );
		$normalized = $this->normalize_observation( $response['body'] );
		$this->cache->put( 'latest_observation', $normalized, min( self::OBSERVATION_TTL, $response['ttl'] ) );
		return $this->observation_result( $normalized );
	}

	private function observation_result( array $data ) {
		$issued = $this->date( $data['issued_at'] ?? '' );
		$now = $this->clock->now()->setTimezone( new DateTimeZone( 'UTC' ) );
		$age = $now->getTimestamp() - $issued->getTimestamp();
		if ( $age < 0 || $age > self::CURRENT_OBSERVATION_MAX_AGE_SECONDS ) {
			return ORAS_AI_Current_Data_Result::unknown( self::PROVIDER_ID, 'stale_observation' );
		}
		return ORAS_AI_Current_Data_Result::success( self::PROVIDER_ID, array( $this->snapshot_from_data( $data ) ) );
	}

	private function forecast( ORAS_AI_Current_Data_Request $request ) {
		$cached = $this->cache->get( 'forecast_grid' );
		if ( is_array( $cached ) && $this->forecast_covers( $cached, $request ) ) {
			return $this->forecast_result( $cached );
		}
		$mapping = $this->point_mapping( $request );
		$url = self::HOST . '/gridpoints/' . $mapping['grid_id'] . '/' . $mapping['grid_x'] . ',' . $mapping['grid_y'];
		$response = $this->request_json( $url );
		if ( in_array( $response['status'], array( 404, 410 ), true ) ) {
			$this->cache->delete( 'point_mapping' );
			$mapping = $this->point_mapping( $request, true );
			$url = self::HOST . '/gridpoints/' . $mapping['grid_id'] . '/' . $mapping['grid_x'] . ',' . $mapping['grid_y'];
			$response = $this->request_json( $url );
		}
		$this->require_success( $response );
		$normalized = $this->normalize_forecast( $response['body'], $request );
		$this->cache->put( 'forecast_grid', $normalized, min( self::FORECAST_TTL, $response['ttl'] ) );
		return $this->forecast_result( $normalized );
	}

	private function point_mapping( ORAS_AI_Current_Data_Request $request, $force = false ) {
		$cached = $force ? null : $this->cache->get( 'point_mapping' );
		if ( is_array( $cached ) && $this->valid_mapping( $cached ) ) {
			return $cached;
		}
		$site = $request->site();
		$url = sprintf( '%s/points/%.4f,%.4f', self::HOST, $site->latitude(), $site->longitude() );
		$response = $this->request_json( $url );
		$this->require_success( $response );
		$properties = isset( $response['body']['properties'] ) && is_array( $response['body']['properties'] ) ? $response['body']['properties'] : array();
		$mapping = array(
			'grid_id' => strtoupper( (string) ( $properties['gridId'] ?? '' ) ),
			'grid_x'  => $properties['gridX'] ?? null,
			'grid_y'  => $properties['gridY'] ?? null,
		);
		if ( ! $this->valid_mapping( $mapping ) ) {
			throw new ORAS_AI_NWS_Exception( 'point_mapping_invalid' );
		}
		$mapping['grid_x'] = (int) $mapping['grid_x'];
		$mapping['grid_y'] = (int) $mapping['grid_y'];
		$this->cache->put( 'point_mapping', $mapping, min( self::POINT_TTL, $response['ttl'] ) );
		return $mapping;
	}

	private function station_mapping( ORAS_AI_Current_Data_Request $request, array $mapping, $force = false ) {
		$cached = $force ? null : $this->cache->get( 'station_mapping' );
		if ( is_array( $cached ) && $this->valid_station( $cached['station_id'] ?? '' ) ) {
			return $cached;
		}
		$url = self::HOST . '/gridpoints/' . $mapping['grid_id'] . '/' . $mapping['grid_x'] . ',' . $mapping['grid_y'] . '/stations?limit=8';
		$response = $this->request_json( $url );
		if ( in_array( $response['status'], array( 404, 410 ), true ) ) {
			throw new ORAS_AI_NWS_Exception( 'point_mapping_invalid' );
		}
		$this->require_success( $response );
		$features = isset( $response['body']['features'] ) && is_array( $response['body']['features'] ) ? array_slice( $response['body']['features'], 0, 8 ) : array();
		$nearest = null;
		$distance = INF;
		foreach ( $features as $feature ) {
			$id = $feature['properties']['stationIdentifier'] ?? '';
			$coordinates = $feature['geometry']['coordinates'] ?? array();
			if ( ! $this->valid_station( $id ) || ! is_array( $coordinates ) || count( $coordinates ) < 2 || ! is_numeric( $coordinates[0] ) || ! is_numeric( $coordinates[1] ) ) {
				continue;
			}
			$current = pow( (float) $coordinates[0] - $request->site()->longitude(), 2 ) + pow( (float) $coordinates[1] - $request->site()->latitude(), 2 );
			if ( $current < $distance ) {
				$distance = $current;
				$nearest = strtoupper( (string) $id );
			}
		}
		if ( null === $nearest ) {
			throw new ORAS_AI_NWS_Exception( 'station_mapping_invalid' );
		}
		$value = array( 'station_id' => $nearest );
		$this->cache->put( 'station_mapping', $value, min( self::STATION_TTL, $response['ttl'] ) );
		return $value;
	}

	private function normalize_observation( array $body ) {
		$p = isset( $body['properties'] ) && is_array( $body['properties'] ) ? $body['properties'] : array();
		$issued = $this->date( $p['timestamp'] ?? '' );
		$data = array(
			'issued_at' => $issued->format( DATE_ATOM ),
			'fresh_at' => $this->clock->now()->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM ),
			'valid_from' => $issued->format( DATE_ATOM ),
			'valid_until' => $issued->format( DATE_ATOM ),
			'designation' => ORAS_AI_Weather_Snapshot::CURRENT,
			'cloud_cover_percent' => null,
			'precipitation_probability_percent' => null,
			'precipitation_type' => $this->precipitation( array_key_exists( 'presentWeather', $p ) ? $p['presentWeather'] : null ),
			'temperature_celsius' => $this->measure( $p['temperature'] ?? null, array( 'wmoUnit:degC' ), 1.0 ),
			'wind_speed_mps' => $this->measure( $p['windSpeed'] ?? null, array( 'wmoUnit:m_s-1', 'wmoUnit:km_h-1' ), null ),
			'wind_gust_mps' => $this->measure( $p['windGust'] ?? null, array( 'wmoUnit:m_s-1', 'wmoUnit:km_h-1' ), null ),
			'humidity_percent' => $this->measure( $p['relativeHumidity'] ?? null, array( 'wmoUnit:percent' ), 1.0 ),
			'visibility_meters' => $this->measure( $p['visibility'] ?? null, array( 'wmoUnit:m' ), 1.0 ),
		);
		$this->require_useful_fields( $data );
		$data['uncertainty'] = $this->uncertainty( $data );
		return $data;
	}

	private function normalize_forecast( array $body, ORAS_AI_Current_Data_Request $request ) {
		$p = isset( $body['properties'] ) && is_array( $body['properties'] ) ? $body['properties'] : array();
		$issued = $this->date( $p['updateTime'] ?? '' );
		$bases = array();
		foreach ( array( 'skyCover', 'probabilityOfPrecipitation', 'weather', 'temperature', 'windSpeed', 'windGust', 'relativeHumidity', 'visibility' ) as $field ) {
			$bases = $this->grid_values( $p[ $field ] ?? null, $request );
			if ( ! empty( $bases ) ) {
				break;
			}
		}
		if ( empty( $bases ) ) {
			throw new ORAS_AI_NWS_Exception( 'forecast_interval_unavailable' );
		}
		$snapshots = array();
		foreach ( $bases as $base ) {
			$midpoint = ( new DateTimeImmutable( '@' . (int) floor( ( $base['from']->getTimestamp() + $base['until']->getTimestamp() ) / 2 ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
			$data = array(
				'issued_at' => $issued->format( DATE_ATOM ),
				'fresh_at' => $this->clock->now()->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM ),
				'valid_from' => $base['from']->format( DATE_ATOM ),
				'valid_until' => $base['until']->format( DATE_ATOM ),
				'designation' => ORAS_AI_Weather_Snapshot::FORECAST,
				'cloud_cover_percent' => $this->grid_measure_at( $p['skyCover'] ?? null, $midpoint, array( 'wmoUnit:percent' ), 1.0 ),
				'precipitation_probability_percent' => $this->grid_measure_at( $p['probabilityOfPrecipitation'] ?? null, $midpoint, array( 'wmoUnit:percent' ), 1.0 ),
				'precipitation_type' => $this->grid_weather_at( $p['weather'] ?? null, $midpoint ),
				'temperature_celsius' => $this->grid_measure_at( $p['temperature'] ?? null, $midpoint, array( 'wmoUnit:degC' ), 1.0 ),
				'wind_speed_mps' => $this->grid_measure_at( $p['windSpeed'] ?? null, $midpoint, array( 'wmoUnit:m_s-1', 'wmoUnit:km_h-1' ), null ),
				'wind_gust_mps' => $this->grid_measure_at( $p['windGust'] ?? null, $midpoint, array( 'wmoUnit:m_s-1', 'wmoUnit:km_h-1' ), null ),
				'humidity_percent' => $this->grid_measure_at( $p['relativeHumidity'] ?? null, $midpoint, array( 'wmoUnit:percent' ), 1.0 ),
				'visibility_meters' => $this->grid_measure_at( $p['visibility'] ?? null, $midpoint, array( 'wmoUnit:m' ), 1.0 ),
			);
			$this->require_useful_fields( $data );
			$data['uncertainty'] = $this->uncertainty( $data );
			$snapshots[] = $data;
		}
		return array(
			'request_from' => $request->requested_at()->format( DATE_ATOM ),
			'request_until' => $request->window_end()->format( DATE_ATOM ),
			'snapshots' => $snapshots,
		);
	}

	private function grid_value( $property, ORAS_AI_Current_Data_Request $request ) {
		$values = $this->grid_values( $property, $request );
		return empty( $values ) ? null : $values[0];
	}

	private function grid_values( $property, ORAS_AI_Current_Data_Request $request ) {
		$values = is_array( $property ) && isset( $property['values'] ) && is_array( $property['values'] ) ? $property['values'] : array();
		$matched = array();
		foreach ( array_slice( $values, 0, 200 ) as $item ) {
			$interval = $this->interval( $item['validTime'] ?? '' );
			if ( null !== $interval && $interval[1] >= $request->requested_at() && $interval[0] <= $request->window_end() ) {
				$matched[] = array( 'value' => $item['value'] ?? null, 'from' => $interval[0], 'until' => $interval[1] );
			}
		}
		return $matched;
	}

	private function grid_value_at( $property, DateTimeImmutable $instant ) {
		$values = is_array( $property ) && isset( $property['values'] ) && is_array( $property['values'] ) ? $property['values'] : array();
		foreach ( array_slice( $values, 0, 200 ) as $item ) {
			$interval = $this->interval( $item['validTime'] ?? '' );
			if ( null !== $interval && $interval[0] <= $instant && $interval[1] >= $instant ) {
				return array( 'value' => $item['value'] ?? null, 'from' => $interval[0], 'until' => $interval[1] );
			}
		}
		return null;
	}

	private function grid_measure_at( $property, DateTimeImmutable $instant, array $units, $factor ) {
		$item = $this->grid_value_at( $property, $instant );
		if ( null === $item ) {
			return null;
		}
		return $this->measure( array( 'value' => $item['value'], 'unitCode' => $property['uom'] ?? '' ), $units, $factor );
	}

	private function grid_weather_at( $property, DateTimeImmutable $instant ) {
		$item = $this->grid_value_at( $property, $instant );
		return null === $item ? '' : $this->precipitation( $item['value'] );
	}

	private function measure( $field, array $allowed_units, $factor ) {
		if ( ! is_array( $field ) || ! array_key_exists( 'value', $field ) || null === $field['value'] ) {
			return null;
		}
		$unit = (string) ( $field['unitCode'] ?? '' );
		if ( ! in_array( $unit, $allowed_units, true ) || ! is_numeric( $field['value'] ) || ! is_finite( (float) $field['value'] ) ) {
			throw new ORAS_AI_NWS_Exception( 'malformed_provider_units' );
		}
		$value = (float) $field['value'];
		if ( 'wmoUnit:km_h-1' === $unit ) {
			return $value / 3.6;
		}
		return null === $factor ? $value : $value * $factor;
	}

	private function precipitation( $items ) {
		if ( null === $items ) {
			return '';
		}
		if ( ! is_array( $items ) || empty( $items ) ) {
			return 'none';
		}
		$found = array();
		$allowed = array( 'rain', 'snow', 'drizzle', 'freezing_rain', 'ice_pellets', 'hail', 'thunderstorms' );
		foreach ( array_slice( $items, 0, 20 ) as $item ) {
			$code = sanitize_key( is_array( $item ) ? ( $item['weather'] ?? '' ) : '' );
			$aliases = array( 'rain_showers' => 'rain', 'snow_showers' => 'snow', 'thunderstorm' => 'thunderstorms' );
			$code = $aliases[ $code ] ?? $code;
			if ( in_array( $code, $allowed, true ) ) {
				$found[ $code ] = $code;
			}
		}
		return count( $found ) > 1 ? 'mixed' : ( empty( $found ) ? 'none' : reset( $found ) );
	}

	private function interval( $value ) {
		if ( ! is_string( $value ) || false === strpos( $value, '/' ) ) {
			return null;
		}
		list( $start, $duration ) = explode( '/', $value, 2 );
		try {
			$from = new DateTimeImmutable( $start );
			$until = $from->add( new DateInterval( $duration ) );
			return array( $from->setTimezone( new DateTimeZone( 'UTC' ) ), $until->setTimezone( new DateTimeZone( 'UTC' ) ) );
		} catch ( Throwable $throwable ) {
			return null;
		}
	}

	private function snapshot_from_data( array $data ) {
		return new ORAS_AI_Weather_Snapshot(
			self::PROVIDER_ID, $this->date( $data['issued_at'] ?? '' ), $this->date( $data['fresh_at'] ?? '' ),
			$this->date( $data['valid_from'] ?? '' ), $this->date( $data['valid_until'] ?? '' ), $data['designation'] ?? '',
			$data['cloud_cover_percent'] ?? null, $data['precipitation_probability_percent'] ?? null, $data['precipitation_type'] ?? '',
			$data['temperature_celsius'] ?? null, $data['wind_speed_mps'] ?? null, $data['wind_gust_mps'] ?? null,
			$data['humidity_percent'] ?? null, $data['visibility_meters'] ?? null, $data['uncertainty'] ?? ''
		);
	}

	private function forecast_covers( array $data, ORAS_AI_Current_Data_Request $request ) {
		try {
			return ! empty( $data['snapshots'] )
				&& $this->date( $data['request_from'] ?? '' ) <= $request->requested_at()
				&& $this->date( $data['request_until'] ?? '' ) >= $request->window_end()
				&& $this->date( $data['request_until'] ?? '' ) >= $this->clock->now();
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	private function forecast_result( array $data ) {
		$values = array();
		foreach ( array_slice( is_array( $data['snapshots'] ?? null ) ? $data['snapshots'] : array(), 0, 200 ) as $snapshot ) {
			if ( is_array( $snapshot ) ) {
				$values[] = $this->snapshot_from_data( $snapshot );
			}
		}
		if ( empty( $values ) ) {
			throw new ORAS_AI_NWS_Exception( 'forecast_interval_unavailable' );
		}
		return ORAS_AI_Current_Data_Result::success( self::PROVIDER_ID, $values );
	}

	private function require_useful_fields( array $data ) {
		foreach ( array( 'cloud_cover_percent', 'precipitation_probability_percent', 'temperature_celsius', 'wind_speed_mps', 'wind_gust_mps', 'humidity_percent', 'visibility_meters' ) as $field ) {
			if ( null !== $data[ $field ] ) {
				return;
			}
		}
		if ( ! empty( $data['precipitation_type'] ) && 'none' !== $data['precipitation_type'] ) {
			return;
		}
		throw new ORAS_AI_NWS_Exception( 'weather_fields_unavailable' );
	}

	private function uncertainty( array $data ) {
		$missing = 0;
		foreach ( array( 'cloud_cover_percent', 'precipitation_probability_percent', 'wind_gust_mps' ) as $field ) {
			if ( null === $data[ $field ] ) {
				$missing++;
			}
		}
		return $missing > 0 ? 'partial_fields_unavailable' : '';
	}

	private function request_json( $url ) {
		if ( 0 !== strpos( $url, self::HOST . '/' ) ) {
			throw new ORAS_AI_NWS_Exception( 'unsafe_provider_url' );
		}
		$response = call_user_func( $this->http, $url, array(
			'timeout' => 10,
			'redirection' => 0,
			'headers' => array( 'User-Agent' => $this->user_agent, 'Accept' => 'application/geo+json' ),
		) );
		if ( is_wp_error( $response ) ) {
			throw new ORAS_AI_NWS_Exception( 'provider_request_failed' );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		if ( strlen( $raw_body ) > self::MAX_RESPONSE_BYTES ) {
			throw new ORAS_AI_NWS_Exception( 'malformed_provider_response' );
		}
		$body = json_decode( $raw_body, true );
		$headers = $response['headers'] ?? array();
		if ( $headers instanceof ArrayAccess ) {
			$headers = array(
				'cache-control' => $headers['cache-control'] ?? $headers['Cache-Control'] ?? '',
				'age'           => $headers['age'] ?? $headers['Age'] ?? 0,
				'expires'       => $headers['expires'] ?? $headers['Expires'] ?? '',
			);
		}
		return array(
			'status' => $status,
			'body' => is_array( $body ) ? $body : array(),
			'ttl' => $this->response_ttl( is_array( $headers ) ? $headers : array() ),
		);
	}

	private function require_success( array $response ) {
		if ( 200 === $response['status'] && ! empty( $response['body'] ) ) {
			return;
		}
		if ( 429 === $response['status'] ) {
			throw new ORAS_AI_NWS_Exception( 'provider_rate_limited' );
		}
		if ( $response['status'] >= 500 ) {
			throw new ORAS_AI_NWS_Exception( 'provider_unavailable' );
		}
		throw new ORAS_AI_NWS_Exception( 'malformed_provider_response' );
	}

	private function response_ttl( array $headers ) {
		$ttl = PHP_INT_MAX;
		$cache_control = (string) ( $headers['cache-control'] ?? $headers['Cache-Control'] ?? '' );
		if ( preg_match( '/(?:^|,)\s*(?:no-store|no-cache)\b/i', $cache_control ) ) {
			return 0;
		}
		if ( preg_match( '/max-age\s*=\s*(\d+)/i', $cache_control, $match ) ) {
			$ttl = (int) $match[1];
		}
		$age = max( 0, (int) ( $headers['age'] ?? $headers['Age'] ?? 0 ) );
		if ( PHP_INT_MAX !== $ttl ) {
			return max( 0, $ttl - $age );
		}
		$expires = $headers['expires'] ?? $headers['Expires'] ?? '';
		if ( is_string( $expires ) && '' !== trim( $expires ) ) {
			$expires_at = strtotime( $expires );
			if ( false !== $expires_at ) {
				return max( 0, $expires_at - $this->clock->now()->getTimestamp() );
			}
		}
		return PHP_INT_MAX;
	}

	private function date( $value ) {
		try {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				throw new Exception();
			}
			return ( new DateTimeImmutable( $value ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		} catch ( Throwable $throwable ) {
			throw new ORAS_AI_NWS_Exception( 'malformed_provider_response' );
		}
	}

	private function valid_mapping( array $mapping ) {
		return (bool) preg_match( '/^[A-Z]{3}$/', (string) ( $mapping['grid_id'] ?? '' ) )
			&& filter_var( $mapping['grid_x'] ?? null, FILTER_VALIDATE_INT ) !== false
			&& filter_var( $mapping['grid_y'] ?? null, FILTER_VALIDATE_INT ) !== false
			&& (int) $mapping['grid_x'] >= 0 && (int) $mapping['grid_x'] <= 10000
			&& (int) $mapping['grid_y'] >= 0 && (int) $mapping['grid_y'] <= 10000;
	}

	private function valid_station( $station ) {
		return is_string( $station ) && (bool) preg_match( '/^[A-Z0-9]{3,8}$/', $station );
	}
}

final class ORAS_AI_NWS_Exception extends RuntimeException {
	private $safe_reason;
	public function __construct( $safe_reason ) {
		$this->safe_reason = sanitize_key( $safe_reason );
		parent::__construct( 'NWS provider request failed.' );
	}
	public function reason() { return $this->safe_reason; }
}
