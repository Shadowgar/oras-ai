<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_PMPro_Membership_Authorizer implements ORAS_AI_Membership_Authorizer_Interface {

	private $membership_checker;

	/**
	 * @param callable|null $membership_checker Optional PMPro-compatible test boundary.
	 */
	public function __construct( $membership_checker = null ) {
		$this->membership_checker = is_callable( $membership_checker ) ? $membership_checker : null;
	}

	public function has_active_membership( $user_id ) {
		$user_id = absint( $user_id );
		if ( 0 === $user_id ) {
			return false;
		}

		$checker = $this->membership_checker;
		if ( null === $checker && function_exists( 'pmpro_hasMembershipLevel' ) ) {
			$checker = 'pmpro_hasMembershipLevel';
		}

		if ( ! is_callable( $checker ) ) {
			return false;
		}

		return (bool) call_user_func( $checker, null, $user_id );
	}
}
