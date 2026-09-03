<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes one authorized backend answer request without conversation storage.
 */
final class ORAS_AI_Answer_Orchestrator {

	const NO_EVIDENCE_MESSAGE = "I couldn't establish that from the current ORAS information.";
	const CURRENT_DATA_MESSAGE = "I couldn't establish current astronomy data because a qualified live data provider is not available yet.";

	private $controls;
	private $ledger;
	private $domain_guard;
	private $retriever;
	private $context_assembler;
	private $answer_provider;

	public function __construct(
		ORAS_AI_Execution_Controls $controls,
		ORAS_AI_Usage_Ledger $ledger,
		ORAS_AI_Domain_Guard $domain_guard,
		ORAS_AI_Retriever_Interface $retriever,
		ORAS_AI_Grounded_Context_Assembler $context_assembler,
		ORAS_AI_Answer_Provider_Interface $answer_provider
	) {
		$this->controls          = $controls;
		$this->ledger            = $ledger;
		$this->domain_guard      = $domain_guard;
		$this->retriever         = $retriever;
		$this->context_assembler = $context_assembler;
		$this->answer_provider   = $answer_provider;
	}

	public function answer( ORAS_AI_Authorized_Request $request ) {
		$model     = $this->answer_provider->model();
		$admission = $this->controls->admit(
			$request,
			$model,
			ORAS_AI_Grounded_Context_Assembler::MAX_PROVIDER_INPUT_CHARACTERS
		);
		if ( ! $admission->allowed() ) {
			return ORAS_AI_Answer_Result::failure( $admission->reason() );
		}

		$reservation_id = $admission->reservation_id();
		$domain         = $this->domain_guard->classify( $request->question() );
		$guarded        = new ORAS_AI_Guarded_Request( $request, $domain );
		if ( ! $guarded->is_allowed() ) {
			$this->ledger->release( $reservation_id );
			return ORAS_AI_Answer_Result::refusal( $domain->refusal_message(), $domain->refusal_code() );
		}

		if ( ORAS_AI_Domain_Result::ASTRONOMY === $domain->outcome() && $this->requires_current_astronomy( $request->question() ) ) {
			$this->ledger->release( $reservation_id );
			return ORAS_AI_Answer_Result::no_evidence( self::CURRENT_DATA_MESSAGE, 'current_data_unavailable' );
		}

		$intent = $this->intent_for( $request->question() );
		$packet = new ORAS_AI_Evidence_Packet();
		if ( in_array( $domain->outcome(), array( ORAS_AI_Domain_Result::ORAS, ORAS_AI_Domain_Result::CROSSOVER ), true ) ) {
			$packet = $this->retriever->retrieve(
				ORAS_AI_Retrieval_Request::from_trusted_context(
					array(
						'query'                => $request->question(),
						'allowed_visibilities' => $request->allowed_visibilities(),
						'intent'               => $intent,
						'fact_key'             => hash( 'sha256', strtolower( trim( $request->question() ) ) ),
						'top_k'                => ORAS_AI_WordPress_Retriever::MAX_TOP_K,
						'text_budget'          => ORAS_AI_Grounded_Context_Assembler::MAX_EVIDENCE_CHARACTERS,
					)
				)
			);
			if ( ! $packet instanceof ORAS_AI_Evidence_Packet ) {
				$this->ledger->release( $reservation_id );
				return ORAS_AI_Answer_Result::failure( 'retrieval_failed' );
			}
		}

		$scope = $this->scope_for( $domain->outcome(), ! $packet->is_empty() );
		$context = $this->context_assembler->assemble( $guarded, $packet, $intent, $scope );
		if ( is_wp_error( $context ) ) {
			$this->ledger->release( $reservation_id );
			return ORAS_AI_Answer_Result::failure( 'context_unavailable' );
		}

		$has_evidence = ! $context->evidence_packet()->is_empty();
		if ( ORAS_AI_Domain_Result::ORAS === $domain->outcome() ) {
			if ( ! $has_evidence || ( $this->requires_live_oras( $request->question() ) && ! $this->has_live_oras_evidence( $context ) ) ) {
				$this->ledger->release( $reservation_id );
				return ORAS_AI_Answer_Result::no_evidence( self::NO_EVIDENCE_MESSAGE );
			}
		}

		if ( ORAS_AI_Domain_Result::CROSSOVER === $domain->outcome() ) {
			if ( $this->requires_current_astronomy( $request->question() ) ) {
				$this->ledger->release( $reservation_id );
				return ORAS_AI_Answer_Result::no_evidence( self::CURRENT_DATA_MESSAGE, 'current_data_unavailable' );
			}
			if ( $this->requires_live_oras( $request->question() ) && ! $this->has_live_oras_evidence( $context ) ) {
				$context = $this->context_assembler->assemble(
					$guarded,
					new ORAS_AI_Evidence_Packet(),
					$intent,
					ORAS_AI_Grounded_Context::CROSSOVER_ASTRONOMY_ONLY
				);
			}
		}

		if ( is_wp_error( $context ) ) {
			$this->ledger->release( $reservation_id );
			return ORAS_AI_Answer_Result::failure( 'context_unavailable' );
		}

		$provider_answer = $this->answer_provider->answer(
			$context,
			$admission->max_output_tokens(),
			$admission->timeout_seconds()
		);
		if ( ! $provider_answer instanceof ORAS_AI_Provider_Answer ) {
			$this->ledger->settle_reserved_maximum( $reservation_id );
			return ORAS_AI_Answer_Result::failure( 'provider_response_invalid', $reservation_id );
		}
		if ( ! $provider_answer->successful() ) {
			if ( $provider_answer->usage_may_have_occurred() ) {
				$this->ledger->settle_reserved_maximum( $reservation_id );
			} else {
				$this->ledger->release( $reservation_id );
			}
			return ORAS_AI_Answer_Result::failure( $provider_answer->error_code(), $reservation_id );
		}

		$reconciliation = $this->ledger->reconcile(
			$reservation_id,
			$provider_answer->model(),
			$provider_answer->input_tokens(),
			$provider_answer->output_tokens()
		);
		if ( is_wp_error( $reconciliation ) ) {
			$this->ledger->settle_reserved_maximum( $reservation_id );
			return ORAS_AI_Answer_Result::failure( 'usage_reconciliation_failed', $reservation_id );
		}

		$answer = $provider_answer->answer();
		if ( ORAS_AI_Grounded_Context::CROSSOVER_ASTRONOMY_ONLY === $context->scope() ) {
			$answer = self::NO_EVIDENCE_MESSAGE . ' ' . $answer;
		}

		return ORAS_AI_Answer_Result::success(
			$answer,
			$context->source_references(),
			$provider_answer->model(),
			array(
				'input_tokens'  => $provider_answer->input_tokens(),
				'output_tokens' => $provider_answer->output_tokens(),
			),
			$reservation_id
		);
	}

	private function scope_for( $domain, $has_evidence ) {
		if ( ORAS_AI_Domain_Result::ASTRONOMY === $domain ) {
			return ORAS_AI_Grounded_Context::GENERAL_ASTRONOMY;
		}
		if ( ORAS_AI_Domain_Result::CROSSOVER === $domain ) {
			return $has_evidence
				? ORAS_AI_Grounded_Context::CROSSOVER_GROUNDED
				: ORAS_AI_Grounded_Context::CROSSOVER_ASTRONOMY_ONLY;
		}

		return ORAS_AI_Grounded_Context::ORAS_GROUNDED;
	}

	private function intent_for( $question ) {
		$question = strtolower( (string) $question );
		if ( preg_match( '/\b(privacy|security|policy|policies|rules|terms|legal|data handling|vulnerability)\b/', $question ) ) {
			return ORAS_AI_Retrieval_Request::INTENT_POLICY;
		}
		if ( preg_match( '/\b(past|previous|prior|historical|history|formerly|last year|20[0-9]{2})\b/', $question ) ) {
			return ORAS_AI_Retrieval_Request::INTENT_HISTORICAL;
		}
		if ( preg_match( '/\b(current|currently|now|today|tonight|tomorrow|upcoming|latest|price|availability|available|schedule|registration)\b/', $question ) ) {
			return ORAS_AI_Retrieval_Request::INTENT_CURRENT;
		}

		return ORAS_AI_Retrieval_Request::INTENT_GENERAL;
	}

	private function requires_current_astronomy( $question ) {
		$question = strtolower( (string) $question );
		return (bool) preg_match(
			'/\b(now|today|tonight|tomorrow|this evening|this weekend|current(?:ly)?|forecast|weather|clouds?|where is|rise|rises|set|sets|visible)\b/',
			$question
		);
	}

	private function requires_live_oras( $question ) {
		$question = strtolower( (string) $question );
		return (bool) preg_match(
			'/\b(price|cost|availability|available|inventory|register|registration|ticket|upcoming event|event date|event time|current schedule|member status|order status|support ticket status)\b/',
			$question
		);
	}

	private function has_live_oras_evidence( ORAS_AI_Grounded_Context $context ) {
		foreach ( $context->evidence_packet()->items() as $item ) {
			if ( ORAS_AI_Source_Precedence::LIVE_ORAS_STATE === $item->field( 'authority_class' ) ) {
				return true;
			}
		}

		return false;
	}
}
