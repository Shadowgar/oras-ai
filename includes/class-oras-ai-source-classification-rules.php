<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Source_Classification_Rules {

	const VERSION           = 1;
	const META_RULE_VERSION = '_oras_ai_rule_version';

	public function version() {
		return self::VERSION;
	}

	public function effective_version( $stored_version ) {
		return '' === (string) $stored_version ? self::VERSION : (int) $stored_version;
	}

	public function classify( $post_type, $title, $url ) {
		/*
		 * WordPress already tells us what these records are. Do not spend AI
		 * tokens deciding whether a WooCommerce product or Calendar event is
		 * changing data.
		 */
		if ( 'tribe_events' === $post_type ) {
			return array(
				'source_kind'     => 'live_data',
				'category'        => $this->category_for( $title, $url, 'Events' ),
				'visibility'      => 'public',
				'confidence'      => 'high',
				'knowledge_title' => $title,
				'reason'          => 'The Events Calendar event records are time-sensitive and must be queried live.',
				'classified_by'   => 'rule',
			);
		}

		if ( 'product' === $post_type ) {
			return array(
				'source_kind'     => 'live_data',
				'category'        => $this->category_for( $title, $url, 'Events' ),
				'visibility'      => 'public',
				'confidence'      => 'high',
				'knowledge_title' => $title,
				'reason'          => 'WooCommerce product price, availability, ticket inventory, and purchase state can change.',
				'classified_by'   => 'rule',
			);
		}

		if ( in_array( $post_type, array( 'elementor_library', 'mailpoet_page', 'gm_menu_block' ), true ) ) {
			return array(
				'source_kind'     => 'ignore',
				'category'        => 'Website / Technical Help',
				'visibility'      => 'admin',
				'confidence'      => 'high',
				'knowledge_title' => $title,
				'reason'          => 'This is a WordPress/plugin template or utility record, not an authoritative end-user knowledge source.',
				'classified_by'   => 'rule',
			);
		}

		if ( 'oras_speaker' === $post_type ) {
			return array(
				'source_kind'     => 'static_knowledge',
				'category'        => 'Events',
				'visibility'      => 'public',
				'confidence'      => 'high',
				'knowledge_title' => $title,
				'reason'          => 'ORAS speaker biography records are useful reference information and remain synchronized to WordPress changes.',
				'classified_by'   => 'rule',
			);
		}

		if ( 'page' === $post_type && $this->is_utility_page( $url ) ) {
			return array(
				'source_kind'     => 'ignore',
				'category'        => $this->category_for( $title, $url, 'Website / Technical Help' ),
				'visibility'      => 'admin',
				'confidence'      => 'high',
				'knowledge_title' => $title,
				'reason'          => 'This is an account, checkout, form, confirmation, test, or other utility page rather than durable ORAS knowledge.',
				'classified_by'   => 'rule',
			);
		}

		/*
		 * tribe_event_series can contain durable explanatory information, and
		 * normal pages can be informational or dynamic. Let the model judge.
		 */
		return null;
	}

	public function category_for( $title, $url, $fallback = 'General FAQ' ) {
		$haystack = strtolower( wp_strip_all_tags( $title . ' ' . $url ) );

		if ( false !== strpos( $haystack, 'astroblast' ) ) {
			return 'AstroBlast';
		}

		if ( false !== strpos( $haystack, 'public-night' ) || false !== strpos( $haystack, 'public night' ) ) {
			return 'Public Nights';
		}

		if ( false !== strpos( $haystack, 'observer-pass' ) || false !== strpos( $haystack, 'observer pass' ) ) {
			return 'Observer Passes';
		}

		if ( false !== strpos( $haystack, 'member' ) ) {
			return 'Membership';
		}

		if ( false !== strpos( $haystack, 'equipment' ) || false !== strpos( $haystack, 'telescope' ) ) {
			return 'Telescopes & Equipment';
		}

		if ( false !== strpos( $haystack, 'donat' ) || false !== strpos( $haystack, 'billing' ) || false !== strpos( $haystack, 'payment' ) ) {
			return 'Payments / Treasurer';
		}

		if ( false !== strpos( $haystack, 'observatory' ) ) {
			return 'Observatory Access';
		}

		return $fallback;
	}

	private function is_utility_page( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? strtolower( trim( $path, '/' ) ) : '';

		$exact = array(
			'cart',
			'login',
			'register-2',
			'my-account',
			'membership-account',
			'membership-account/membership-billing',
			'membership-account/membership-cancel',
			'membership-account/membership-orders',
			'membership-account/your-profile',
			'membership-checkout',
			'membership-checkout/membership-confirmation',
			'manage-group',
			'verify-waiting-email',
			'opt-out-preferences',
			'donation-list',
			'paidmemberships',
			'woo-hub',
			'wpsd-thank-you',
			'support',
			'support-portal',
			'website-feedback',
			'members-hub/equipment-exchange/listing',
			'members-hub/equipment-exchange/list-equipment',
			'members-hub/equipment-exchange/my-listings'
		);

		if ( in_array( $path, $exact, true ) ) {
			return true;
		}

		$contains = array(
			'/checkout',
			'/confirmation',
			'/thank-you',
		);

		$slash_path = '/' . $path;

		foreach ( $contains as $needle ) {
			if ( false !== strpos( $slash_path, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
