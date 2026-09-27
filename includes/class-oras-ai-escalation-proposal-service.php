<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds previews from one authorized turn; never writes to Fluent Support. */
final class ORAS_AI_Escalation_Proposal_Service {
	const MAX_SUBJECT_BYTES = 192;
	const MAX_SUMMARY_BYTES = 600;
	const MAX_QUESTION_BYTES = 1000;

	private $routing;
	private $conversations;
	private $summary_service;

	public function __construct( ORAS_AI_Support_Routing $routing, ORAS_AI_Conversations $conversations, $summary_service = null ) {
		$this->routing       = $routing;
		$this->conversations = $conversations;
		$this->summary_service = $summary_service ?: new ORAS_AI_Support_Summary_Service();
	}

	public function propose( ORAS_AI_Authorized_Request $request, $conversation_id, ORAS_AI_Answer_Result $answer, array $model_candidate = array() ) {
		if ( $request->user_id() <= 0 || $request->user_id() !== get_current_user_id() || ! is_int( $conversation_id ) || $conversation_id <= 0 ) {
			return ORAS_AI_Escalation_Result::none();
		}
		$conversation = $this->conversations->get_conversation( $conversation_id );
		if ( is_wp_error( $conversation ) || (int) ( $conversation['user_id'] ?? 0 ) !== $request->user_id() ) {
			return ORAS_AI_Escalation_Result::none();
		}
		if ( ! in_array( $answer->status(), array( ORAS_AI_Answer_Result::SUCCESS, ORAS_AI_Answer_Result::NO_EVIDENCE ), true ) ) {
			return ORAS_AI_Escalation_Result::none();
		}
		$question = $this->plain( $request->question(), self::MAX_QUESTION_BYTES );
		if ( '' === $question ) {
			return ORAS_AI_Escalation_Result::none();
		}
		$inferred = ORAS_AI_Support_Topic::infer( $question );
		$explicit = ORAS_AI_Support_Topic::is_explicit_request( $question );
		if ( ! $explicit && ( ORAS_AI_Answer_Result::NO_EVIDENCE !== $answer->status() || ( ORAS_AI_Support_Topic::GENERAL === $inferred && ! ORAS_AI_Support_Topic::is_general_oras_support( $question ) ) ) ) {
			return ORAS_AI_Escalation_Result::none();
		}
		$topic = $inferred;
		if ( isset( $model_candidate['topic'] ) ) {
			$candidate_topic = $model_candidate['topic'];
			if ( ! ORAS_AI_Support_Topic::allowed( $candidate_topic ) ) {
				$topic = ORAS_AI_Support_Topic::GENERAL;
			} elseif ( ORAS_AI_Support_Topic::GENERAL === $inferred || $candidate_topic === $inferred ) {
				$topic = $candidate_topic;
			}
		}
		$route = $this->routing->route_for( $topic );
		if ( 'route_valid' !== $route['status'] ) {
			ORAS_AI_Audit_Log::log_support_escalation( 'route_unavailable', $topic );
			return ORAS_AI_Escalation_Result::routing_unavailable();
		}
		$topic = $route['topic'];
		$subject = $this->plain( $model_candidate['subject'] ?? '', self::MAX_SUBJECT_BYTES );
		if ( '' === $subject ) {
			$subject = $this->truncate( 'ORAS support: ' . $question, self::MAX_SUBJECT_BYTES );
		}
		$generated = $this->summary_service->generate( $request, $question );
		if ( ! is_array( $generated ) || 'generated' !== ( $generated['status'] ?? '' ) || ! isset( $generated['summary'] ) ) {
			return ORAS_AI_Escalation_Result::unavailable();
		}
		$summary = $generated['summary'];
		$proposal = new ORAS_AI_Escalation_Proposal(
			$request->user_id(), $conversation_id, $topic, $subject, $summary, $question,
			$route['destination'], $route['route']
		);
		ORAS_AI_Audit_Log::log_support_escalation( 'proposed', $topic );
		return ORAS_AI_Escalation_Result::proposed( $proposal );
	}

	private function plain( $value, $max_bytes ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		for ( $pass = 0; $pass < 4; $pass++ ) {
			$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $decoded === $value ) {
				break;
			}
			$value = $decoded;
		}
		$value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value, true ) ) ?: '' );
		$value = str_replace( array( '<', '>' ), '', $value );
		return $this->truncate( $value, $max_bytes );
	}

	private function truncate( $value, $max_bytes ) {
		if ( strlen( $value ) <= $max_bytes ) {
			return $value;
		}
		return function_exists( 'mb_strcut' )
			? mb_strcut( $value, 0, $max_bytes, 'UTF-8' )
			: iconv( 'UTF-8', 'UTF-8//IGNORE', substr( $value, 0, $max_bytes ) );
	}
}
