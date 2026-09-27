<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** An ephemeral, owner-bound preview. Task 3 owns durable confirmation state. */
final class ORAS_AI_Escalation_Proposal {
	private $owner_user_id;
	private $conversation_id;
	private $topic;
	private $subject;
	private $summary;
	private $original_question;
	private $destination;
	private $provider_route;

	public function __construct( $owner_user_id, $conversation_id, $topic, $subject, $summary, $original_question, $destination, ORAS_AI_Fluent_Support_Route $provider_route ) {
		$this->owner_user_id    = (int) $owner_user_id;
		$this->conversation_id  = (int) $conversation_id;
		$this->topic            = ORAS_AI_Support_Topic::normalize( $topic );
		$this->subject          = (string) $subject;
		$this->summary          = (string) $summary;
		$this->original_question = (string) $original_question;
		$this->destination      = (string) $destination;
		$this->provider_route   = $provider_route;
	}

	public function topic() { return $this->topic; }
	public function owner_user_id() { return $this->owner_user_id; }
	public function conversation_id() { return $this->conversation_id; }
	public function provider_route() { return $this->provider_route; }

	public function to_member_array() {
		return array(
			'topic'                      => $this->topic,
			'category'                   => ORAS_AI_Support_Topic::labels()[ $this->topic ],
			'subject'                    => $this->subject,
			'summary'                    => $this->summary,
			'original_question'          => $this->original_question,
			'original_question_included' => true,
			'destination'                => $this->destination,
		);
	}
}
