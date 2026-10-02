<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Metered AI summary for a support proposal; no support-provider side effects. */
final class ORAS_AI_Support_Summary_Service {
	private $provider;
	private $controls;
	private $ledger;

	public function __construct( $provider = null, ORAS_AI_Execution_Controls $controls = null, ORAS_AI_Usage_Ledger $ledger = null ) {
		$this->provider = $provider ?: new ORAS_AI_OpenAI_Answer_Provider();
		$this->ledger = $ledger ?: new ORAS_AI_Usage_Ledger();
		$this->controls = $controls ?: new ORAS_AI_Execution_Controls( $this->ledger );
	}

	public function generate( ORAS_AI_Authorized_Request $request, $question ) {
		if ( $request->user_id() <= 0 || $request->user_id() !== get_current_user_id()
			|| ! is_string( $question ) || '' === trim( $question ) || strlen( $question ) > ORAS_AI_Escalation_Proposal_Service::MAX_QUESTION_BYTES ) {
			return array( 'status' => 'unavailable' );
		}
		$admission = $this->controls->admit_support_summary( $request, $this->provider->model(), strlen( $question ) + 400 );
		if ( ! $admission->allowed() ) {
			return array( 'status' => 'unavailable' );
		}
		$reservation_id = $admission->reservation_id();
		try {
			$result = $this->provider->summarize_support_question(
				$question,
				min( 160, $admission->max_output_tokens() ),
				min( 10, $admission->timeout_seconds() ),
				$reservation_id
			);
		} catch ( Throwable $error ) {
			$this->ledger->settle_reserved_maximum( $reservation_id );
			return array( 'status' => 'unavailable' );
		}
		if ( ! $result instanceof ORAS_AI_Provider_Answer ) {
			$this->ledger->settle_reserved_maximum( $reservation_id );
			return array( 'status' => 'unavailable' );
		}
		if ( ! $result->successful() ) {
			if ( $result->usage_may_have_occurred() ) {
				$this->ledger->settle_reserved_maximum( $reservation_id );
			} else {
				$this->ledger->release( $reservation_id );
			}
			return array( 'status' => 'unavailable' );
		}
		$reconciled = $this->ledger->reconcile( $reservation_id, $result->model(), $result->input_tokens(), $result->output_tokens() );
		if ( is_wp_error( $reconciled ) ) {
			$this->ledger->settle_reserved_maximum( $reservation_id );
			return array( 'status' => 'unavailable' );
		}
		$summary = trim( preg_replace( '/\s+/u', ' ', $result->answer() ) ?: '' );
		$decoded = $summary;
		for ( $pass = 0; $pass < 4; $pass++ ) {
			$next = html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $next === $decoded ) {
				break;
			}
			$decoded = $next;
		}
		preg_match_all( '/\d+(?:[.,]\d+)?/u', $question, $question_numbers );
		preg_match_all( '/\d+(?:[.,]\d+)?/u', $decoded, $summary_numbers );
		if ( '' === $summary || strlen( $summary ) > ORAS_AI_Escalation_Proposal_Service::MAX_SUMMARY_BYTES
			|| str_contains( $decoded, '<' ) || str_contains( $decoded, '>' )
			|| array_diff( $summary_numbers[0], $question_numbers[0] )
			|| preg_match( '/\b(?:mailbox|customer|agent|tag)[_ -]?id\b/i', $summary )
			|| ! self::distinct( $question, $summary ) ) {
			return array( 'status' => 'unavailable' );
		}
		return array( 'status' => 'generated', 'summary' => $summary );
	}

	private static function distinct( $question, $summary ) {
		$normalize = static function ( $text ) {
			return trim( preg_replace( '/[^\pL\pN]+/u', ' ', mb_strtolower( (string) $text, 'UTF-8' ) ) ?: '' );
		};
		$question = $normalize( $question );
		$summary = $normalize( $summary );
		return '' !== $summary && '' !== $question && ! str_contains( $summary, $question );
	}
}
