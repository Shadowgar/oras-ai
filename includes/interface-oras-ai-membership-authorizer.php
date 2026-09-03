<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ORAS_AI_Membership_Authorizer_Interface {

	/**
	 * Determine whether a WordPress user has any active ORAS membership.
	 *
	 * @param int $user_id Server-derived WordPress user ID.
	 * @return bool
	 */
	public function has_active_membership( $user_id );
}
