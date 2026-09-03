<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_WordPress_Retriever implements ORAS_AI_Retriever_Interface {

	const MAX_TOP_K                = 5;
	const MAX_ITEM_TEXT_CHARACTERS = 2000;
	const MAX_TOTAL_CHARACTERS     = 6000;
	const MAX_CANDIDATES           = 500;

	public function retrieve( ORAS_AI_Retrieval_Request $request ) {
		$query = $this->normalize_search_text( $request->query() );
		if ( '' === $query || empty( $request->allowed_visibilities() ) ) {
			return new ORAS_AI_Evidence_Packet();
		}

		$artifact_ids = get_posts(
			array(
				'post_type'      => ORAS_AI_Knowledge_Base::POST_TYPE,
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page' => self::MAX_CANDIDATES,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$candidates = array();
		foreach ( $artifact_ids as $artifact_id ) {
			$candidate = $this->candidate_for_artifact( (int) $artifact_id, $request, $query );
			if ( null !== $candidate ) {
				$candidates[] = $candidate;
			}
		}

		usort(
			$candidates,
			static function ( array $left, array $right ) {
				$score = $right['score'] <=> $left['score'];
				if ( 0 !== $score ) {
					return $score;
				}

				$authority = ORAS_AI_Source_Precedence::priority( $right['evidence']->field( 'authority_class' ) )
					<=> ORAS_AI_Source_Precedence::priority( $left['evidence']->field( 'authority_class' ) );
				if ( 0 !== $authority ) {
					return $authority;
				}

				return (int) $left['evidence']->field( 'artifact_id' )
					<=> (int) $right['evidence']->field( 'artifact_id' );
			}
		);

		$top_k        = max( 1, min( self::MAX_TOP_K, $request->top_k() ) );
		$total_budget = max( 1, min( self::MAX_TOTAL_CHARACTERS, $request->text_budget() ) );
		$item_budget  = max( 1, min( self::MAX_ITEM_TEXT_CHARACTERS, (int) floor( $total_budget / $top_k ) ) );
		$items        = array();

		foreach ( array_slice( $candidates, 0, $top_k ) as $candidate ) {
			$fields                  = $candidate['evidence']->to_array();
			$fields['relevant_text'] = substr( $fields['relevant_text'], 0, $item_budget );
			$items[]                 = ORAS_AI_Evidence::from_array( $fields );
		}

		return new ORAS_AI_Evidence_Packet( $items );
	}

	private function candidate_for_artifact( $artifact_id, ORAS_AI_Retrieval_Request $request, $query ) {
		if ( ! ORAS_AI_Knowledge_Base::is_active_artifact( $artifact_id ) ) {
			return null;
		}

		$visibility = sanitize_key( get_post_meta( $artifact_id, '_oras_ai_visibility', true ) ?: 'members' );
		if ( ! in_array( $visibility, $request->allowed_visibilities(), true ) ) {
			return null;
		}

		$source_id = absint( get_post_meta( $artifact_id, '_oras_ai_source_record_id', true ) );
		if ( $source_id > 0 && ! $this->source_is_available( $source_id ) ) {
			return null;
		}

		$category = $this->artifact_category( $artifact_id );
		if ( '' !== $request->category() && 0 !== strcasecmp( $request->category(), $category ) ) {
			return null;
		}

		$is_policy = 0 === strcasecmp( 'Policies & Rules', $category );
		if ( $is_policy && ORAS_AI_Retrieval_Request::INTENT_POLICY !== $request->intent() ) {
			return null;
		}

		$is_historical = '1' === get_post_meta( $artifact_id, '_oras_ai_historical_event', true );
		if ( $is_historical && ORAS_AI_Retrieval_Request::INTENT_HISTORICAL !== $request->intent() ) {
			return null;
		}

		$post = get_post( $artifact_id );
		if ( ! $post ) {
			return null;
		}

		$answer = (string) get_post_meta( $artifact_id, '_oras_ai_official_answer', true );
		if ( '' === trim( $answer ) ) {
			$answer = (string) $post->post_content;
		}
		$answer = trim( wp_strip_all_tags( strip_shortcodes( $answer ), true ) );

		$source_post  = $source_id > 0 ? get_post( $source_id ) : null;
		$source_title = $source_post
			? (string) $source_post->post_title
			: (string) get_post_meta( $artifact_id, '_oras_ai_source', true );
		$source_url   = (string) get_post_meta( $artifact_id, '_oras_ai_source_url', true );
		if ( '' === $source_url && $source_id > 0 ) {
			$source_url = (string) get_post_meta( $source_id, '_oras_ai_source_url', true );
		}

		$score = $this->relevance_score(
			$query,
			array(
				'title'    => (string) $post->post_title,
				'answer'   => $answer,
				'category' => $category,
				'source'   => $source_title,
			)
		);
		if ( $score <= 0 ) {
			return null;
		}

		$source_wp_object_id = absint( get_post_meta( $artifact_id, '_oras_ai_source_wp_post_id', true ) );
		$source_type         = sanitize_key( get_post_meta( $artifact_id, '_oras_ai_source_wp_post_type', true ) );
		if ( $source_id > 0 ) {
			if ( 0 === $source_wp_object_id ) {
				$source_wp_object_id = absint( get_post_meta( $source_id, '_oras_ai_wp_post_id', true ) );
			}
			if ( '' === $source_type ) {
				$source_type = sanitize_key( get_post_meta( $source_id, '_oras_ai_wp_post_type', true ) );
			}
		}

		$evidence = ORAS_AI_Evidence::from_array(
			array(
				'artifact_id'           => $artifact_id,
				'source_record_id'      => $source_id,
				'source_wp_object_id'   => $source_wp_object_id,
				'source_type'           => $source_type,
				'artifact_title'        => (string) $post->post_title,
				'source_title'          => $source_title,
				'canonical_url'         => $source_url,
				'relevant_text'         => $answer,
				'category'              => $category,
				'visibility'            => $visibility,
				'lifecycle'             => ORAS_AI_Knowledge_Base::lifecycle_status( $artifact_id ),
				'source_classification' => sanitize_key( get_post_meta( $artifact_id, '_oras_ai_source_classification', true ) ),
				'authority_class'       => $is_policy
					? ORAS_AI_Source_Precedence::APPROVED_ORAS_POLICY
					: ORAS_AI_Source_Precedence::SYNCHRONIZED_ORAS_KNOWLEDGE,
				'source_hash'           => (string) get_post_meta( $artifact_id, '_oras_ai_source_hash', true ),
				'source_modified_gmt'   => (string) get_post_meta( $artifact_id, '_oras_ai_source_modified_gmt', true ),
				'synced_at'             => (string) get_post_meta( $artifact_id, '_oras_ai_synced_at', true ),
				'historical_event'      => $is_historical,
				'fact_key'              => $request->fact_key(),
				'content_role'          => 'untrusted_evidence',
			)
		);

		return array(
			'score'    => $score,
			'evidence' => $evidence,
		);
	}

	private function source_is_available( $source_id ) {
		$source = get_post( $source_id );
		if (
			! $source
			|| ORAS_AI_Sources::POST_TYPE !== $source->post_type
			|| 'publish' !== $source->post_status
			|| ORAS_AI_Sources::is_source_excluded( $source_id )
		) {
			return false;
		}

		return ! in_array(
			sanitize_key( get_post_meta( $source_id, '_oras_ai_scan_status', true ) ),
			array( 'missing', 'excluded', 'error' ),
			true
		);
	}

	private function artifact_category( $artifact_id ) {
		$terms = get_the_terms( $artifact_id, ORAS_AI_Knowledge_Base::TAXONOMY );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		$name = (string) $terms[0]->name;
		foreach ( ORAS_AI_Knowledge_Base::default_categories() as $category ) {
			if ( 0 === strcasecmp( $name, $category ) ) {
				return $category;
			}
		}

		return $name;
	}

	private function relevance_score( $query, array $fields ) {
		$tokens = preg_split( '/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY );
		$score  = 0;
		$weights = array(
			'title'    => 40,
			'answer'   => 30,
			'category' => 20,
			'source'   => 10,
		);

		foreach ( $weights as $field => $weight ) {
			$value = $this->normalize_search_text( $fields[ $field ] );
			foreach ( $tokens as $token ) {
				if ( false !== strpos( $value, $token ) ) {
					$score += $weight;
				}
			}
		}

		return $score;
	}

	private function normalize_search_text( $value ) {
		$value = strtolower( trim( wp_strip_all_tags( (string) $value, true ) ) );
		return preg_replace( '/\s+/', ' ', $value ) ?: '';
	}
}
