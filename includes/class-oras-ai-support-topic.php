<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Small, server-owned M7 routing vocabulary. */
final class ORAS_AI_Support_Topic {
	const GENERAL = 'general_support';

	public static function labels() {
		return array(
			'membership'        => 'Membership',
			'observer_pass'     => 'Observer Pass',
			'observatory'       => 'Observatory',
			'equipment'         => 'Equipment',
			'events'            => 'Events and AstroBlast',
			'facilities'        => 'Facilities',
			'website'           => 'Website and Technical',
			'payment'           => 'Payments',
			'board_organization' => 'Board and Organization',
			'feedback'          => 'Feedback',
			self::GENERAL       => 'General ORAS Support',
		);
	}

	public static function allowed( $topic ) {
		return is_string( $topic ) && isset( self::labels()[ $topic ] );
	}

	public static function normalize( $topic ) {
		return self::allowed( $topic ) ? $topic : self::GENERAL;
	}

	public static function infer( $question ) {
		$text = strtolower( wp_strip_all_tags( (string) $question, true ) );
		$patterns = array(
			'website'            => '/\b(calendar|website|web site|login|log in|technical)\b/i',
			'payment'            => '/\b(payment|pay|paid|charge|refund|dues|treasurer|checkout|billing)\b/i',
			'observer_pass'      => '/\bobserver pass(?:es)?\b/i',
			'membership'         => '/\b(member|membership|renewal|renew)\b/i',
			'observatory'        => '/\b(observatory|guest access|observatory access)\b/i',
			'facilities'         => '/\b(facility|facilities|parking|lighting|building)\b/i',
			'equipment'          => '/\b(equipment|telescope problem|mount problem|eyepiece problem)\b/i',
			'events'             => '/\b(event|astroblast|public night|registration)\b/i',
			'board_organization' => '/\b(board|organization|committee|volunteer)\b/i',
			'feedback'           => '/\b(feedback|suggestion|idea|complaint|bug report|report a bug)\b/i',
		);
		foreach ( $patterns as $topic => $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return $topic;
			}
		}
		return self::GENERAL;
	}

	public static function is_explicit_request( $question ) {
		$text = strtolower( wp_strip_all_tags( (string) $question, true ) );
		if ( preg_match( '/\b(human help|talk to (?:a )?(?:person|human)|contact support|support ticket|need (?:a )?human|bug report|report a bug|file a bug|suggestion|i have an idea|complain|complaint)\b/i', $text ) ) {
			return true;
		}
		return (bool) preg_match( '/\b(calendar|website|web site|oras|observatory|facility|facilities|parking)\b.{0,60}\b(broken|bug|problem)\b|\b(broken|bug|problem)\b.{0,60}\b(calendar|website|web site|oras|observatory|facility|facilities|parking)\b/i', $text );
	}

	public static function is_general_oras_support( $question ) {
		$text = strtolower( wp_strip_all_tags( (string) $question, true ) );
		return (bool) preg_match( '/\b(oras|oil region astronomical society)\b/i', $text )
			&& ! preg_match( '/\b(mars|jupiter|saturn|venus|mercury|uranus|neptune|moon|sun|star|galaxy|nebula|planet|ephemeris|forecast|weather|visibility|right ascension|declination|constellation)\b/i', $text );
	}
}
