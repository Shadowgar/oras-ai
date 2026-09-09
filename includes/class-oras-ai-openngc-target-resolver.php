<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Exact, catalog-backed resolver. No fuzzy matching or runtime network access. */
final class ORAS_AI_OpenNGC_Target_Resolver {
	const CATALOG_COMMIT = 'da90466031b0372c896588b85be6016c617e205b';

	private $loader;
	private $shards = array();

	public function __construct( ?callable $loader = null ) {
		$this->loader = $loader;
	}

	public function resolve( $candidate ) {
		$key = self::normalize_alias( $candidate );
		if ( '' === $key || strlen( $key ) > 100 ) {
			return ORAS_AI_Target_Resolution::unknown();
		}
		$canonical_record = $this->load( 'object', $key );
		if ( ! empty( $canonical_record ) && is_array( $canonical_record ) ) {
			return $this->resolved_record( $key, $canonical_record );
		}
		$identities = $this->load( 'alias', $key );
		$identities = array_values( array_unique( array_filter( $identities, 'is_string' ) ) );
		if ( empty( $identities ) ) {
			return ORAS_AI_Target_Resolution::unknown();
		}
		if ( count( $identities ) > 1 ) {
			return ORAS_AI_Target_Resolution::ambiguous();
		}
		$record = $this->load( 'object', $identities[0] );
		if ( empty( $record ) || ! is_array( $record ) ) {
			return ORAS_AI_Target_Resolution::unknown();
		}
		return $this->resolved_record( $identities[0], $record );
	}

	public function resolve_from_question( $question ) {
		$question = substr( sanitize_text_field( $question ), 0, 500 );
		preg_match_all( '/\b(?:m|ngc|ic)\s*\d+[a-z]?\b/i', $question, $matches );
		$resolved = array();
		foreach ( $matches[0] ?? array() as $identifier ) {
			$result = $this->resolve( $identifier );
			if ( self::add_resolution( $resolved, $result ) ) {
				return ORAS_AI_Target_Resolution::ambiguous();
			}
		}
		if ( 1 === count( $resolved ) ) {
			return reset( $resolved );
		}

		$words = preg_split( '/[^a-z0-9]+/', self::ascii_lower( $question ), -1, PREG_SPLIT_NO_EMPTY );
		$word_count = count( $words );
		for ( $length = min( 5, $word_count ); $length >= 1; $length-- ) {
			for ( $start = 0; $start + $length <= $word_count; $start++ ) {
				$result = $this->resolve( implode( ' ', array_slice( $words, $start, $length ) ) );
				if ( self::add_resolution( $resolved, $result ) ) {
					return ORAS_AI_Target_Resolution::ambiguous();
				}
			}
			if ( 1 === count( $resolved ) ) {
				return reset( $resolved );
			}
		}
		return ORAS_AI_Target_Resolution::unknown();
	}

	public static function normalize_alias( $alias ) {
		$alias = self::ascii_lower( trim( (string) $alias ) );
		return preg_replace( '/[^a-z0-9]+/', '', $alias );
	}

	private static function ascii_lower( $value ) {
		$value = (string) $value;
		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		}
		return strtolower( $value );
	}

	private static function add_resolution( array &$resolved, ORAS_AI_Target_Resolution $result ) {
		if ( ORAS_AI_Target_Resolution::RESOLVED !== $result->status() ) {
			return false;
		}
		$resolved[ $result->target()->identity() ] = $result;
		return count( $resolved ) > 1;
	}

	private function resolved_record( $identity, array $record ) {
		try {
			return ORAS_AI_Target_Resolution::resolved(
				new ORAS_AI_Resolved_Target(
					$identity,
					$record['display_name'] ?? '',
					$record['ra_degrees'] ?? null,
					$record['dec_degrees'] ?? null,
					self::CATALOG_COMMIT
				)
			);
		} catch ( InvalidArgumentException $exception ) {
			return ORAS_AI_Target_Resolution::unknown();
		}
	}

	private function load( $kind, $key ) {
		try {
			if ( $this->loader ) {
				$loaded = call_user_func( $this->loader, $kind, $key );
				return is_array( $loaded ) ? $loaded : array();
			}
			$prefix = 'alias' === $kind ? 'aliases' : 'objects';
			$shard  = substr( hash( 'sha256', $key ), 0, 1 );
			$cache_key = $prefix . '-' . $shard;
			if ( ! isset( $this->shards[ $cache_key ] ) ) {
				$path   = ORAS_AI_PLUGIN_DIR . 'data/openngc/' . $cache_key . '.php';
				$loaded = is_file( $path ) ? require $path : array();
				$this->shards[ $cache_key ] = is_array( $loaded ) ? $loaded : array();
			}
			$record = $this->shards[ $cache_key ][ $key ] ?? array();
			return is_array( $record ) ? $record : array();
		} catch ( Throwable $throwable ) {
			return array();
		}
	}
}
