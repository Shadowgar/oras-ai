<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Local fixed-catalog position provider for resolved deep-sky targets. */
final class ORAS_AI_OpenNGC_Astronomy_Provider implements ORAS_AI_Astronomy_Provider_Interface {
	const PROVIDER_ID = 'openngc_local';

	private $resolver;
	private $calculator;
	private $clock;

	public function __construct(
		ORAS_AI_OpenNGC_Target_Resolver $resolver,
		ORAS_AI_Horizontal_Position_Calculator $calculator,
		?ORAS_AI_Clock_Interface $clock = null
	) {
		$this->resolver   = $resolver;
		$this->calculator = $calculator;
		$this->clock      = $clock ?: new ORAS_AI_System_Clock();
	}

	public function provider_id() { return self::PROVIDER_ID; }

	public function fetch( ORAS_AI_Current_Data_Request $request ) {
		if ( ! in_array( ORAS_AI_Current_Data_Request::TARGET_POSITION, $request->fact_types(), true ) ) {
			return ORAS_AI_Current_Data_Result::unknown( self::PROVIDER_ID, 'unsupported_fact_type' );
		}
		$resolution = $this->resolver->resolve( $request->target_identity() );
		if ( ORAS_AI_Target_Resolution::AMBIGUOUS === $resolution->status() ) {
			return ORAS_AI_Current_Data_Result::unknown( self::PROVIDER_ID, 'target_ambiguous' );
		}
		if ( ORAS_AI_Target_Resolution::RESOLVED !== $resolution->status() ) {
			return ORAS_AI_Current_Data_Result::unknown( self::PROVIDER_ID, 'target_not_resolved' );
		}

		$target = $resolution->target();
		try {
			$position = $this->calculator->calculate(
				$target->right_ascension_degrees(),
				$target->declination_degrees(),
				$request->requested_at(),
				$request->site()
			);
		} catch ( Throwable $throwable ) {
			return ORAS_AI_Current_Data_Result::unavailable( self::PROVIDER_ID, 'local_calculation_failed' );
		}

		$identity   = $target->identity();
		$fact_root  = 'astronomy:target:' . $identity;
		$calculated = $this->clock->now();
		$valid      = $request->requested_at();
		$version    = $target->catalog_version();
		$facts = array(
			new ORAS_AI_Astronomy_Fact( self::PROVIDER_ID, ORAS_AI_Current_Data_Request::TARGET_POSITION, $position['altitude'], 'degrees', $calculated, $valid, $identity, $fact_root . ':altitude', $version ),
			new ORAS_AI_Astronomy_Fact( self::PROVIDER_ID, ORAS_AI_Current_Data_Request::TARGET_POSITION, $position['azimuth'], 'degrees', $calculated, $valid, $identity, $fact_root . ':azimuth', $version ),
			new ORAS_AI_Astronomy_Fact( self::PROVIDER_ID, ORAS_AI_Current_Data_Request::TARGET_POSITION, $position['geometric_horizon'], 'geometric_horizon', $calculated, $valid, $identity, $fact_root . ':geometric_horizon', $version ),
		);
		return ORAS_AI_Current_Data_Result::success( self::PROVIDER_ID, $facts );
	}
}
