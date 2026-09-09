<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Resolved_Target {
	private $identity;
	private $display_name;
	private $right_ascension_degrees;
	private $declination_degrees;
	private $catalog_version;

	public function __construct( $identity, $display_name, $right_ascension_degrees, $declination_degrees, $catalog_version ) {
		$identity = ORAS_AI_Current_Data_Request::normalize_target_identity( $identity );
		$display_name = sanitize_text_field( $display_name );
		if (
			'' === $identity
			|| '' === $display_name
			|| ! is_numeric( $right_ascension_degrees )
			|| ! is_numeric( $declination_degrees )
			|| ! is_finite( (float) $right_ascension_degrees )
			|| ! is_finite( (float) $declination_degrees )
			|| (float) $right_ascension_degrees < 0.0
			|| (float) $right_ascension_degrees >= 360.0
			|| (float) $declination_degrees < -90.0
			|| (float) $declination_degrees > 90.0
			|| ! preg_match( '/^[a-f0-9]{40}$/', (string) $catalog_version )
		) {
			throw new InvalidArgumentException( 'Invalid resolved catalog target.' );
		}
		$this->identity                = $identity;
		$this->display_name            = $display_name;
		$this->right_ascension_degrees = (float) $right_ascension_degrees;
		$this->declination_degrees     = (float) $declination_degrees;
		$this->catalog_version         = $catalog_version;
	}

	public function identity() { return $this->identity; }
	public function display_name() { return $this->display_name; }
	public function right_ascension_degrees() { return $this->right_ascension_degrees; }
	public function declination_degrees() { return $this->declination_degrees; }
	public function catalog_version() { return $this->catalog_version; }
}
