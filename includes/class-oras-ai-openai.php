<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_OpenAI {

	const OPTION_API_KEY = ORAS_AI_Config::OPTION_OPENAI_API_KEY;
	const OPTION_MODEL   = ORAS_AI_Config::OPTION_OPENAI_MODEL;

	public static function get_model() {
		return ORAS_AI_Config::get_openai_model();
	}

	public static function get_api_key() {
		return ORAS_AI_Config::get_openai_api_key();
	}

	public static function has_api_key() {
		return ORAS_AI_Config::has_openai_api_key();
	}

	public static function classify_source( $title, $url, $post_type, $content ) {
		$api_key = self::get_api_key();

		if ( '' === $api_key ) {
			return new WP_Error( 'oras_ai_no_key', __( 'OpenAI API key is not configured.', 'oras-ai-assistant' ) );
		}

		$categories = ORAS_AI_Knowledge_Base::default_categories();
		$content_for_ai = mb_substr( $content, 0, 30000 );

		$system = 'You classify content from the Oil Region Astronomical Society (ORAS) WordPress website for an AI knowledge system. '
			. 'Do not invent facts. Classify only what the supplied source contains. '
			. 'static_knowledge means stable information useful for answering future ORAS questions. '
			. 'live_data means information whose answer should be retrieved live instead of memorized, such as current/upcoming event dates, calendars, ticket or observer-pass inventory, schedules, weather, current observing conditions, account/member status, checkout data, or other frequently changing records. '
			. 'mixed means a source with both durable knowledge and changing facts; return durable facts only in stable_fragments and place current facts in excluded_dynamic_claims with their dynamic_fact_types. '
			. 'ignore means navigation, empty content, third-party legal, privacy, or cookie boilerplate, search/account/checkout utility pages, or content with no useful ORAS knowledge. '
			. 'review means content that cannot be safely classified or separated, has policy/effective-date ambiguity, would lose critical qualifications, has low confidence, or fails stable/dynamic validation. '
			. 'Public ORAS privacy and website-security policy pages are eligible searchable knowledge under Policies & Rules; do not ignore legitimate ORAS policy merely because it concerns privacy, security, legal terms, data handling, or vulnerability reporting. '
			. 'Historical ORAS event pages with archival value use static_knowledge in Events when they contain durable past-event context, but must not preserve current dates, prices, deadlines, schedules, or availability as durable facts. '
			. 'Set historical_event true only for Historical Event Knowledge and false for every other source. Historical Event Knowledge always requires human review during this milestone. '
			. 'For non-mixed outcomes return empty stable_fragments, excluded_dynamic_claims, and dynamic_fact_types arrays. '
			. 'Set validation.stable_dynamic_separation true only when durable and changing information are safely separated, and validation.critical_qualifications_preserved true only when the durable content retains necessary qualifications. '
			. 'Choose exactly one of the supplied ORAS categories. '
			. 'Visibility should reflect the apparent intended audience of the content; when uncertain for a normal published website page, choose public.';

		$user = "SOURCE TITLE:\n{$title}\n\nSOURCE URL:\n{$url}\n\nWORDPRESS TYPE:\n{$post_type}\n\nCONTENT:\n{$content_for_ai}";

		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'source_kind' => array(
					'type' => 'string',
					'enum' => array( 'static_knowledge', 'live_data', 'mixed', 'ignore', 'review' ),
				),
				'category' => array(
					'type' => 'string',
					'enum' => array_values( $categories ),
				),
				'visibility' => array(
					'type' => 'string',
					'enum' => array( 'public', 'members', 'admin' ),
				),
				'confidence' => array(
					'type' => 'string',
					'enum' => array( 'high', 'medium', 'low' ),
				),
				'knowledge_title' => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'reason' => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'historical_event' => array(
					'type' => 'boolean',
				),
				'stable_fragments' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'stable_title' => array(
								'type'      => 'string',
								'minLength' => 1,
							),
							'stable_content' => array(
								'type'      => 'string',
								'minLength' => 1,
							),
						),
						'required'             => array( 'stable_title', 'stable_content' ),
						'additionalProperties' => false,
					),
				),
				'excluded_dynamic_claims' => array(
					'type'  => 'array',
					'items' => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				),
				'dynamic_fact_types' => array(
					'type'  => 'array',
					'items' => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				),
				'validation' => array(
					'type'                 => 'object',
					'properties'           => array(
						'stable_dynamic_separation' => array(
							'type' => 'boolean',
						),
						'critical_qualifications_preserved' => array(
							'type' => 'boolean',
						),
					),
					'required'             => array( 'stable_dynamic_separation', 'critical_qualifications_preserved' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array(
				'source_kind',
				'category',
				'visibility',
				'confidence',
				'knowledge_title',
				'reason',
				'historical_event',
				'stable_fragments',
				'excluded_dynamic_claims',
				'dynamic_fact_types',
				'validation',
			),
			'additionalProperties' => false,
		);

		$payload = array(
			'model'     => self::get_model(),
			'reasoning' => array( 'effort' => 'low' ),
			'input'     => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			'text'      => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'oras_knowledge_classification',
					'strict' => true,
					'schema' => $schema,
				),
			),
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'OpenAI API request failed.', 'oras-ai-assistant' );
			return new WP_Error( 'oras_ai_openai_http', sanitize_text_field( $message ), array( 'status' => $code ) );
		}

		$output_text = self::extract_output_text( $body );

		if ( '' === $output_text ) {
			return new WP_Error( 'oras_ai_empty_response', __( 'OpenAI returned no classification text.', 'oras-ai-assistant' ) );
		}

		$classification = json_decode( $output_text, true );

		if ( ! is_array( $classification ) ) {
			return new WP_Error( 'oras_ai_invalid_json', __( 'OpenAI returned an invalid classification payload.', 'oras-ai-assistant' ) );
		}

		return $classification;
	}

	private static function extract_output_text( $body ) {
		if ( isset( $body['output_text'] ) && is_string( $body['output_text'] ) ) {
			return trim( $body['output_text'] );
		}

		if ( empty( $body['output'] ) || ! is_array( $body['output'] ) ) {
			return '';
		}

		foreach ( $body['output'] as $item ) {
			if ( empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
				continue;
			}

			foreach ( $item['content'] as $content ) {
				if (
					isset( $content['type'], $content['text'] ) &&
					'output_text' === $content['type'] &&
					is_string( $content['text'] )
				) {
					return trim( $content['text'] );
				}
			}
		}

		return '';
	}
}
