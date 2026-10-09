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
	const CURRENT_WEATHER_MESSAGE = "I couldn't establish current observing weather from the qualified provider.";

	private $controls;
	private $ledger;
	private $domain_guard;
	private $retriever;
	private $context_assembler;
	private $answer_provider;
	private $live_service;
	private $astronomy_service;
	private $weather_service;
	private $observing_planner;

	public function __construct(
		ORAS_AI_Execution_Controls $controls,
		ORAS_AI_Usage_Ledger $ledger,
		ORAS_AI_Domain_Guard $domain_guard,
		ORAS_AI_Retriever_Interface $retriever,
		ORAS_AI_Grounded_Context_Assembler $context_assembler,
		ORAS_AI_Answer_Provider_Interface $answer_provider,
		$live_service = null,
		$astronomy_service = null,
		$weather_service = null,
		$observing_planner = null
	) {
		$this->controls          = $controls;
		$this->ledger            = $ledger;
		$this->domain_guard      = $domain_guard;
		$this->retriever         = $retriever;
		$this->context_assembler = $context_assembler;
		$this->answer_provider   = $answer_provider;
		$this->live_service      = $live_service instanceof ORAS_AI_Live_Service ? $live_service : null;
		$this->astronomy_service = $astronomy_service instanceof ORAS_AI_Current_Astronomy_Service ? $astronomy_service : null;
		$this->weather_service   = $weather_service instanceof ORAS_AI_Current_Weather_Service ? $weather_service : null;
		$this->observing_planner = $observing_planner instanceof ORAS_AI_Observing_Planner ? $observing_planner : null;
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

		$intent = $this->intent_for( $request->question() );
		$plan = null !== $this->observing_planner ? $this->observing_planner->query( $request ) : null;
		$planning = $plan instanceof ORAS_AI_Observing_Plan_Result && $plan->matched();
		if ( $planning && 'unavailable' === $plan->state() && ( ORAS_AI_Domain_Result::ASTRONOMY === $domain->outcome() || ! $this->requires_live_oras( $request->question() ) ) ) {
			$this->ledger->release( $reservation_id );
			return ORAS_AI_Answer_Result::no_evidence( self::CURRENT_DATA_MESSAGE, 'current_data_unavailable' );
		}
		$current_astronomy = null;
		$current_astronomy_packet = $planning ? $plan->evidence_packet() : new ORAS_AI_Evidence_Packet();
		$requires_current_weather = $this->requires_current_weather( $request->question() )
			&& in_array( $domain->outcome(), array( ORAS_AI_Domain_Result::ASTRONOMY, ORAS_AI_Domain_Result::CROSSOVER ), true );
		$requires_current_astronomy = $this->requires_current_astronomy( $request->question() )
			&& $this->requires_astronomy_facts( $request->question() )
			&& in_array( $domain->outcome(), array( ORAS_AI_Domain_Result::ASTRONOMY, ORAS_AI_Domain_Result::CROSSOVER ), true );
		if ( $requires_current_astronomy && ! $planning ) {
			if ( null === $this->astronomy_service ) {
				$this->ledger->release( $reservation_id );
				return ORAS_AI_Answer_Result::no_evidence( self::CURRENT_DATA_MESSAGE, 'current_data_unavailable' );
			}
			$current_astronomy = $this->astronomy_service->query( $request );
			if ( ! $current_astronomy instanceof ORAS_AI_Current_Astronomy_Query_Result || ! $current_astronomy->matched() || ( ! $current_astronomy->has_facts() && ! $requires_current_weather ) ) {
				$this->ledger->release( $reservation_id );
				return ORAS_AI_Answer_Result::no_evidence( self::CURRENT_DATA_MESSAGE, 'current_data_unavailable' );
			}
			$current_astronomy_packet = $current_astronomy->evidence_packet();
		}
		$current_weather = null;
		$current_weather_packet = new ORAS_AI_Evidence_Packet();
		if ( $requires_current_weather && ! $planning ) {
			if ( null === $this->weather_service ) {
				if ( ( $current_astronomy instanceof ORAS_AI_Current_Astronomy_Query_Result && $current_astronomy->has_facts() ) || ORAS_AI_Domain_Result::CROSSOVER === $domain->outcome() ) {
					$current_weather_packet = $this->weather_failure_packet( 'provider_not_configured' );
				} else {
					$this->ledger->release( $reservation_id );
					return ORAS_AI_Answer_Result::no_evidence( self::CURRENT_WEATHER_MESSAGE, 'current_weather_unavailable' );
				}
			} else {
				$current_weather = $this->weather_service->query( $request );
				if ( ! $current_weather instanceof ORAS_AI_Current_Weather_Query_Result || ! $current_weather->matched() ) {
					$this->ledger->release( $reservation_id );
					return ORAS_AI_Answer_Result::no_evidence( self::CURRENT_WEATHER_MESSAGE, 'current_weather_unavailable' );
				}
				$current_weather_packet = $current_weather->evidence_packet();
				if ( ! $current_weather->has_facts() && ( ! $current_astronomy instanceof ORAS_AI_Current_Astronomy_Query_Result || ! $current_astronomy->has_facts() ) && ORAS_AI_Domain_Result::ASTRONOMY === $domain->outcome() ) {
					$this->ledger->release( $reservation_id );
					return ORAS_AI_Answer_Result::no_evidence( self::CURRENT_WEATHER_MESSAGE, 'current_weather_unavailable' );
				}
			}
		}
		$live_result = null;
		$live_packet = new ORAS_AI_Evidence_Packet();
		if (
			null !== $this->live_service
			&& in_array( $domain->outcome(), array( ORAS_AI_Domain_Result::ORAS, ORAS_AI_Domain_Result::CROSSOVER ), true )
		) {
			$live_result = $this->live_service->query( ORAS_AI_Live_Request::from_authorized_request( $request, $intent ) );
			if ( $live_result instanceof ORAS_AI_Live_Result ) {
				if ( ! $live_result->successful() && ( ! $planning || 'unavailable' === $plan->state() ) ) {
					$this->ledger->release( $reservation_id );
					if ( 'ambiguous_event_subject' === $live_result->reason() ) {
						return ORAS_AI_Answer_Result::no_evidence( 'Please name the event so I can verify current registration availability.', 'event_identity_required' );
					}
					// Disclose each requested member/action fact even when all providers fail.
					// Denial must retain the existing authorization boundary.
					if ( ORAS_AI_Live_Result::DENIED !== $live_result->status() ) {
						$unavailable_context = $this->context_assembler->assemble( $guarded, new ORAS_AI_Evidence_Packet(), $intent, ORAS_AI_Grounded_Context::ORAS_GROUNDED );
						if ( ! is_wp_error( $unavailable_context ) ) {
							$unavailable_answer = $this->bounded_member_aware_answer( $request->question(), $unavailable_context );
							if ( null !== $unavailable_answer ) {
								return ORAS_AI_Answer_Result::no_evidence( $unavailable_answer, 'live_data_unavailable' );
							}
						}
					}
					return ORAS_AI_Answer_Result::no_evidence( self::NO_EVIDENCE_MESSAGE, 'live_data_unavailable' );
				}
				if ( $live_result->successful() ) {
					$live_packet = $this->live_service->evidence_packet( $live_result );
				}
			}
		}
		if ( $planning && 'unavailable' === $plan->state() && $live_packet->is_empty() ) {
			$this->ledger->release( $reservation_id );
			return ORAS_AI_Answer_Result::no_evidence( self::CURRENT_DATA_MESSAGE, 'current_data_unavailable' );
		}

		$packet = new ORAS_AI_Evidence_Packet( array_merge( $current_astronomy_packet->items(), $current_weather_packet->items() ) );
		if ( in_array( $domain->outcome(), array( ORAS_AI_Domain_Result::ORAS, ORAS_AI_Domain_Result::CROSSOVER ), true ) ) {
			$packet = $this->retriever->retrieve(
				ORAS_AI_Retrieval_Request::from_trusted_context(
					array(
						'query'                => $request->question(),
						'allowed_visibilities' => $request->allowed_visibilities(),
						'intent'               => $intent,
						'fact_keys'            => $live_result instanceof ORAS_AI_Live_Result
							? array_merge( $live_result->fact_keys(), $live_result->failed_fact_keys() )
							: array(),
						'top_k'                => ORAS_AI_WordPress_Retriever::MAX_TOP_K,
						'text_budget'          => ORAS_AI_Grounded_Context_Assembler::MAX_EVIDENCE_CHARACTERS,
					)
				)
			);
			if ( ! $packet instanceof ORAS_AI_Evidence_Packet ) {
				$this->ledger->release( $reservation_id );
				return ORAS_AI_Answer_Result::failure( 'retrieval_failed' );
			}

			$packet = new ORAS_AI_Evidence_Packet( array_merge( $packet->items(), $live_packet->items(), $current_astronomy_packet->items(), $current_weather_packet->items() ) );
		}

		$scope = $this->scope_for( $domain->outcome(), ! $packet->is_empty(), $planning || $requires_current_astronomy || $requires_current_weather );
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
			if ( $this->requires_live_oras( $request->question() ) && ! $this->has_live_oras_evidence( $context ) ) {
				$context = $this->context_assembler->assemble(
					$guarded,
					$planning ? $current_astronomy_packet : new ORAS_AI_Evidence_Packet(),
					$intent,
					$planning ? ORAS_AI_Grounded_Context::CROSSOVER_CURRENT : ORAS_AI_Grounded_Context::CROSSOVER_ASTRONOMY_ONLY
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
			$admission->timeout_seconds(),
			$reservation_id
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
		$action_answer = $this->bounded_member_aware_answer( $request->question(), $context );
		if ( null !== $action_answer ) {
			$answer = $action_answer;
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

	private function scope_for( $domain, $has_evidence, $current_astronomy = false ) {
		if ( ORAS_AI_Domain_Result::ASTRONOMY === $domain ) {
			return $current_astronomy ? ORAS_AI_Grounded_Context::CURRENT_ASTRONOMY : ORAS_AI_Grounded_Context::GENERAL_ASTRONOMY;
		}
		if ( ORAS_AI_Domain_Result::CROSSOVER === $domain ) {
			if ( $current_astronomy ) {
				return ORAS_AI_Grounded_Context::CROSSOVER_CURRENT;
			}
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
		if ( preg_match( '/\b(current|currently|now|today|tonight|tomorrow|upcoming|latest|next|price|availability|available|schedule|registration)\b/', $question ) ) {
			return ORAS_AI_Retrieval_Request::INTENT_CURRENT;
		}
		if ( ORAS_AI_WooCommerce_Connector::matches_offering_question( $question ) ) {
			return ORAS_AI_Retrieval_Request::INTENT_CURRENT;
		}
		if (
			preg_match( '/\b(astro\s*blast|public\s+night)\b/', $question )
			&& preg_match( '/\b(when|where|start|starts|end|ends|time|venue|location)\b/', $question )
		) {
			return ORAS_AI_Retrieval_Request::INTENT_CURRENT;
		}
		if (
			preg_match( '/\b(member|membership)\b/', $question )
			&& preg_match( '/\b(my|mine|me|am i|do i|have i|i have)\b/', $question )
		) {
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

	private function requires_astronomy_facts( $question ) {
		$question = strtolower( (string) $question );
		return (bool) preg_match(
			'/\b(astronomy|astronomical|sun|sunset|sunrise|moon|lunar|planet|planets|mercury|venus|mars|jupiter|saturn|uranus|neptune|where is|rise|rises|set|sets|visible|ngc\s*\d+|ic\s*\d+|m\s*\d+)\b/',
			$question
		);
	}

	private function requires_current_weather( $question ) {
		$question = strtolower( (string) $question );
		return (bool) preg_match( '/\b(weather|forecast|clouds?|cloudy|rain|snow|precipitation|temperature|wind|humidity|conditions?|seeing|transparency)\b/', $question );
	}

	private function weather_failure_packet( $reason ) {
		return new ORAS_AI_Evidence_Packet(
			array(
				ORAS_AI_Evidence::from_array(
					array(
						'source_type'           => 'current_weather_status',
						'source_title'          => 'National Weather Service',
						'relevant_text'         => 'Required current weather information could not be established (' . sanitize_key( $reason ) . ').',
						'visibility'            => 'members',
						'lifecycle'             => 'approved',
						'source_classification' => 'current_data',
						'authority_class'       => ORAS_AI_Source_Precedence::CURRENT_ASTRONOMY_WEATHER,
						'fact_keys'             => array( 'weather:forecast:interval' ),
					)
				),
			)
		);
	}

	private function requires_live_oras( $question ) {
		$question = strtolower( (string) $question );
		return (bool) ( ORAS_AI_WooCommerce_Connector::matches_offering_question( $question ) || preg_match(
			'/\b(price|cost|availability|available|inventory|register|registration|ticket|upcoming event|event date|event time|current schedule|next astroblast|next public night|my membership|member status|membership status|membership level|membership tier|active member|order status|support ticket status)\b/',
			$question
		) );
	}

	private function has_live_oras_evidence( ORAS_AI_Grounded_Context $context ) {
		foreach ( $context->evidence_packet()->items() as $item ) {
			if ( ORAS_AI_Source_Precedence::LIVE_ORAS_STATE === $item->field( 'authority_class' ) ) {
				return true;
			}
		}

		return false;
	}

	/** Compose independent admitted domains once; no model prose can replace a fragment. */
	private function bounded_member_aware_answer( $question, ORAS_AI_Grounded_Context $context ) {
		$fragments = array();
		foreach ( array(
			$this->bounded_membership_answer( $question, $context ),
			$this->bounded_pass_answer( $question, $context ),
		) as $fragment ) {
			if ( null !== $fragment ) {
				$fragments[] = $fragment;
			}
		}
		$event = $this->bounded_event_answer( $question, $context, ! empty( $fragments ) );
		if ( null !== $event ) {
			$fragments[] = $event;
		}
		return $fragments ? implode( ' ', $fragments ) : null;
	}

	/** Keep pass purchase/price claims tied to selected Woo facts. */
	private function bounded_pass_answer( $question, ORAS_AI_Grounded_Context $context ) {
		$question = strtolower( (string) $question );
		if ( ORAS_AI_WooCommerce_Connector::matches_offering_question( $question ) ) {
			$products = array();
			foreach ( $context->evidence_packet()->items() as $item ) {
				if ( ORAS_AI_Source_Precedence::LIVE_ORAS_STATE !== $item->field( 'authority_class' )
					|| ! in_array( $item->field( 'source_type' ), array( 'product', 'product_variation' ), true ) ) {
					continue;
				}
				foreach ( (array) $item->field( 'fact_keys' ) as $key ) {
					if ( preg_match( '/^product:observer-pass-(annual|daily):(price|availability|purchasable)$/', (string) $key, $match ) ) {
						$products[ $match[1] ][ $match[2] ] = $item;
					}
				}
			}
			$price_requested = (bool) preg_match( '/\b(?:price|cost|how much)\b/', $question );
			$availability_requested = (bool) preg_match( '/\b(?:available|availability|stock|buy|get|purchase|purchasable|where)\b/', $question );
			if ( empty( $products ) && ! $price_requested && ! preg_match( '/\b(?:annual|daily)\b/', $question ) ) {
				return 'I could not verify current Observer Pass availability or purchasability.';
			}
			$sentences = array();
			$is_annual = (bool) preg_match( '/\bannual\b/', $question );
			$is_daily = (bool) preg_match( '/\bdaily\b/', $question );
			$options = $is_annual && ! $is_daily ? array( 'annual' )
				: ( $is_daily && ! $is_annual ? array( 'daily' ) : array( 'annual', 'daily' ) );
			foreach ( $options as $option ) {
				$facts = $products[ $option ] ?? array();
				$title = ucfirst( $option ) . ' Observer Pass';
				if ( isset( $facts['price'] ) ) {
					$sentences[] = $facts['price']->field( 'relevant_text' );
				} elseif ( $price_requested ) {
					$sentences[] = 'I could not verify the ' . $title . ' price from current WooCommerce information.';
				}
				if ( isset( $facts['availability'], $facts['purchasable'] ) ) {
					$in_stock = in_array( $facts['availability']->field( 'comparison_value' ), array( 'instock|yes', 'onbackorder|yes' ), true );
					$purchasable = 'yes' === $facts['purchasable']->field( 'comparison_value' );
					$url = (string) $facts['purchasable']->field( 'canonical_url' );
					$sentences[] = $in_stock && $purchasable && '' !== $url
						? $title . ' is currently purchasable. Use the linked ORAS product page to continue through WooCommerce checkout.'
						: $title . ' is not currently purchasable.';
				} elseif ( $availability_requested ) {
					$sentences[] = $facts
						? 'I could not verify whether the ' . $title . ' is currently purchasable.'
						: 'I could not verify current ' . $title . ' availability or purchasability.';
				}
			}
			return implode( ' ', $sentences );
		}
		return null;
	}

	/** Event state is independent of whether its canonical page can be linked. */
	private function bounded_event_answer( $question, ORAS_AI_Grounded_Context $context, $include_schedule = false ) {
		$question = strtolower( (string) $question );
		$registration_requested = (bool) preg_match( '/\b(?:register|registration|tickets?|spots?|book|sold out)\b/', $question )
			|| ( ! preg_match( '/\bobserver\s+pass(?:es)?\b/', $question )
				&& preg_match( '/\b(?:available|availability)\b/', $question ) )
			|| (bool) preg_match( '/\b(?:next|upcoming)\s+events?\b/', $question );
		if ( ( preg_match( '/\b(?:astro\s*blast|public\s+night)\b/', $question )
				&& ( $registration_requested || $include_schedule ) )
			|| preg_match( '/\b(?:next|upcoming)\s+events?\b/', $question ) ) {
			$subject = preg_match( '/\bastro\s*blast\b/', $question ) ? 'astroblast'
				: ( preg_match( '/\bpublic\s+night\b/', $question ) ? 'public-night' : 'upcoming' );
			$schedule = array();
			$registration = null;
			foreach ( $context->evidence_packet()->items() as $item ) {
				if ( ORAS_AI_Source_Precedence::LIVE_ORAS_STATE !== $item->field( 'authority_class' ) ) {
					continue;
				}
				foreach ( (array) $item->field( 'fact_keys' ) as $key ) {
					if ( preg_match( '/^event:' . preg_quote( $subject, '/' ) . ':(start|end|venue)$/', (string) $key ) ) {
						$schedule[] = $item->field( 'relevant_text' );
					} elseif ( 'event_offering' === $item->field( 'source_type' )
						&& 'event:' . $subject . ':registration' === (string) $key ) {
						$registration = $item;
					}
				}
			}
			if ( ! $registration_requested ) {
				return $schedule ? implode( ' ', $schedule ) : 'I could not verify the current event schedule.';
			}
			$state = null === $registration ? '' : (string) $registration->field( 'comparison_value' );
			$url = null === $registration ? '' : (string) $registration->field( 'canonical_url' );
			$availability = array(
				'open' => 'Registration is currently open.',
				'full' => 'Registration is currently full.',
				'closed' => 'Registration is currently closed.',
			);
			if ( 'open' === $state && '' !== $url ) {
				$availability_text = 'Registration is currently open. Use the linked ORAS event page to register.';
			} else {
				$availability_text = $availability[ $state ] ?? 'I could not verify registration availability.';
			}
			return implode( ' ', $schedule ) . ( $schedule ? ' ' : '' ) . $availability_text;
		}
		return null;
	}

	/** Return only selected current PMPro facts for self-membership answers. */
	private function bounded_membership_answer( $question, ORAS_AI_Grounded_Context $context ) {
		$question = strtolower( (string) $question );
		if ( ! preg_match( '/\b(?:member|membership)\b/', $question )
			|| ! preg_match( '/\b(?:my|mine|me|am i|do i|have i|i have)\b/', $question )
			|| ( ! preg_match( '/\b(?:status|level|tier|active|inactive)\b/', $question )
				&& ! preg_match( '/^what(?:\'s| is) my membership\??$/', $question ) ) ) {
			return null;
		}
		$facts = array();
		foreach ( $context->evidence_packet()->items() as $item ) {
			if ( ORAS_AI_Source_Precedence::LIVE_ORAS_STATE !== $item->field( 'authority_class' ) ) {
				continue;
			}
			foreach ( (array) $item->field( 'fact_keys' ) as $key ) {
				if ( 'pmpro_membership' === $item->field( 'source_type' )
					&& in_array( $key, array( 'member:self:membership-status', 'member:self:membership-level' ), true ) ) {
					$facts[ $key ] = (string) $item->field( 'relevant_text' );
				}
			}
		}
		return $facts ? implode( ' ', array_values( $facts ) ) : 'I could not verify current membership status or level.';
	}
}
