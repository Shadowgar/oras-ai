<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output contract for the single authoritative ORAS scoring service.
 */
final class ORAS_AI_Observing_Score_Result implements ORAS_AI_Current_Data_Value_Interface {
	private $data;

	public function __construct(
		$score,
		$category,
		$model_version,
		$confidence,
		$limiter,
		$reason,
		DateTimeImmutable $calculated_at,
		DateTimeImmutable $data_at
	) {
		$category      = sanitize_key( $category );
		$model_version = trim( (string) $model_version );
		$confidence    = sanitize_key( $confidence );
		$limiter       = sanitize_key( $limiter );
		$reason        = sanitize_key( $reason );
		if (
			! is_int( $score )
			|| $score < 0
			|| $score > 100
			|| ! in_array( $category, array( 'clear', 'marginal', 'poor' ), true )
			|| ! preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $model_version )
			|| ! in_array( $confidence, array( '', 'low', 'medium', 'high' ), true )
			|| strlen( $limiter ) > 64
			|| strlen( $reason ) > 64
		) {
			throw new InvalidArgumentException( 'Invalid observing score result.' );
		}

		$utc = new DateTimeZone( 'UTC' );
		$this->data = array(
			'score'         => $score,
			'category'      => $category,
			'model_version' => $model_version,
			'confidence'    => $confidence,
			'limiter'       => $limiter,
			'reason'        => $reason,
			'calculated_at' => $calculated_at->setTimezone( $utc ),
			'data_at'       => $data_at->setTimezone( $utc ),
		);
	}

	public function provider_id() {
		return 'oras_observing_score';
	}

	public function authority_class() {
		return ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER;
	}

	public function to_array() {
		return array(
			'score'           => $this->data['score'],
			'category'        => $this->data['category'],
			'model_version'   => $this->data['model_version'],
			'confidence'      => $this->data['confidence'],
			'limiter'         => $this->data['limiter'],
			'reason'          => $this->data['reason'],
			'calculated_at'   => $this->data['calculated_at']->format( DATE_ATOM ),
			'data_at'         => $this->data['data_at']->format( DATE_ATOM ),
			'authority_class' => $this->authority_class(),
		);
	}
}
