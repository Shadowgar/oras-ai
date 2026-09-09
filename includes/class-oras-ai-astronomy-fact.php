<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One normalized astronomy fact. Provider payloads must be mapped explicitly.
 */
final class ORAS_AI_Astronomy_Fact implements ORAS_AI_Current_Data_Value_Interface {
	private $provider_id;
	private $fact_type;
	private $value;
	private $unit;
	private $calculated_at;
	private $valid_at;
	private $target_identity;
	private $fact_key;
	private $provider_version;
	private $site_identity;

	public function __construct(
		$provider_id,
		$fact_type,
		$value,
		$unit,
		DateTimeImmutable $calculated_at,
		DateTimeImmutable $valid_at,
		$target_identity = '',
		$fact_key = '',
		$provider_version = '',
		$site_identity = 'oras_observatory'
	) {
		$provider_id = ORAS_AI_Current_Data_Result::normalize_provider_id( $provider_id );
		$astronomy_types = array(
			ORAS_AI_Current_Data_Request::SUNSET,
			ORAS_AI_Current_Data_Request::ASTRONOMICAL_DARKNESS,
			ORAS_AI_Current_Data_Request::MOON_STATE,
			ORAS_AI_Current_Data_Request::PLANET_POSITION,
			ORAS_AI_Current_Data_Request::TARGET_POSITION,
		);
		$target_identity = ORAS_AI_Current_Data_Request::normalize_target_identity( $target_identity );
		$fact_key = ORAS_AI_Current_Data_Request::normalize_target_identity( $fact_key );
		$provider_version = sanitize_text_field( $provider_version );
		$site_identity = ORAS_AI_Current_Data_Request::normalize_target_identity( $site_identity );
		if (
			'' === $provider_id
			|| ! in_array( $fact_type, $astronomy_types, true )
			|| ! is_scalar( $value )
			|| ( is_float( $value ) && ! is_finite( $value ) )
			|| ! is_string( $unit )
			|| strlen( $unit ) > 40
			|| strlen( $provider_version ) > 80
			|| '' === $site_identity
			|| ( in_array( $fact_type, array( ORAS_AI_Current_Data_Request::PLANET_POSITION, ORAS_AI_Current_Data_Request::TARGET_POSITION ), true ) && '' === $target_identity )
		) {
			throw new InvalidArgumentException( 'Invalid astronomy fact.' );
		}

		$utc                 = new DateTimeZone( 'UTC' );
		$this->provider_id   = $provider_id;
		$this->fact_type     = $fact_type;
		$this->value         = $value;
		$this->unit          = sanitize_text_field( $unit );
		$this->calculated_at = $calculated_at->setTimezone( $utc );
		$this->valid_at      = $valid_at->setTimezone( $utc );
		$this->target_identity = $target_identity;
		$this->fact_key        = $fact_key;
		$this->provider_version = $provider_version;
		$this->site_identity    = $site_identity;
	}

	public function provider_id() {
		return $this->provider_id;
	}

	public function authority_class() {
		return ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER;
	}

	public function to_array() {
		return array(
			'provider'        => $this->provider_id,
			'fact_type'       => $this->fact_type,
			'value'           => $this->value,
			'unit'            => $this->unit,
			'calculated_at'   => $this->calculated_at->format( DATE_ATOM ),
			'valid_at'        => $this->valid_at->format( DATE_ATOM ),
			'target_identity' => $this->target_identity,
			'fact_key'        => $this->fact_key,
			'provider_version'=> $this->provider_version,
			'site_identity'   => $this->site_identity,
			'authority_class' => $this->authority_class(),
		);
	}
}
