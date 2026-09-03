<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejects obvious payment-card primary account numbers before storage or AI use.
 */
final class ORAS_AI_Sensitive_Input_Guard {

	public function validate( $text ) {
		if ( ! is_string( $text ) ) {
			return $this->invalid_request();
		}

		if ( $this->contains_payment_card_number( $text ) ) {
			return new WP_Error(
				'oras_ai_sensitive_input',
				__( 'Payment card information cannot be submitted to ORAS AI.', 'oras-ai-assistant' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	private function contains_payment_card_number( $text ) {
		$matches = array();
		preg_match_all( '/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/', $text, $matches );

		foreach ( $matches[0] as $candidate ) {
			$digits = preg_replace( '/\D/', '', $candidate );
			$length = strlen( $digits );

			if (
				$length >= 13
				&& $length <= 19
				&& preg_match( '/^[2-6]/', $digits )
				&& $this->passes_luhn( $digits )
			) {
				return true;
			}
		}

		return false;
	}

	private function passes_luhn( $digits ) {
		$sum       = 0;
		$alternate = false;

		for ( $index = strlen( $digits ) - 1; $index >= 0; $index-- ) {
			$digit = (int) $digits[ $index ];
			if ( $alternate ) {
				$digit *= 2;
				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}
			$sum      += $digit;
			$alternate = ! $alternate;
		}

		return 0 === $sum % 10;
	}

	private function invalid_request() {
		return new WP_Error(
			'oras_ai_invalid_request',
			__( 'Invalid request.', 'oras-ai-assistant' ),
			array( 'status' => 400 )
		);
	}
}
