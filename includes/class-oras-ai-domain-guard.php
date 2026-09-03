<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Domain_Guard {

	private $classifier;

	public function __construct( $classifier = null ) {
		$this->classifier = $classifier instanceof ORAS_AI_Domain_Classifier_Interface
			? $classifier
			: new ORAS_AI_OpenAI_Domain_Classifier();
	}

	public function classify( $question ) {
		$classifier_question = trim( wp_strip_all_tags( (string) $question, true ) );
		$question            = $this->normalize( $classifier_question );

		if ( $this->contains_any( $question, $this->off_topic_phrases() ) || $this->contains_unsafe_directive( $question ) ) {
			return $this->recorded( ORAS_AI_Domain_Result::from_outcome( ORAS_AI_Domain_Result::OFF_TOPIC ) );
		}

		$has_oras      = $this->contains_any( $question, $this->oras_phrases() );
		$has_astronomy = $this->contains_any( $question, $this->astronomy_phrases() );
		$has_weather   = $this->contains_any( $question, array( 'weather', 'forecast', 'cloudy', 'clouds', 'seeing', 'transparency' ) );

		if ( $has_weather && ( $has_oras || $has_astronomy ) ) {
			$has_astronomy = true;
		}

		if ( $has_oras && $has_astronomy ) {
			return $this->recorded( ORAS_AI_Domain_Result::from_outcome( ORAS_AI_Domain_Result::CROSSOVER ) );
		}

		if ( $has_oras ) {
			return $this->recorded( ORAS_AI_Domain_Result::from_outcome( ORAS_AI_Domain_Result::ORAS ) );
		}

		if ( $has_astronomy ) {
			return $this->recorded( ORAS_AI_Domain_Result::from_outcome( ORAS_AI_Domain_Result::ASTRONOMY ) );
		}

		$result = $this->classifier->classify( $classifier_question );
		if ( ! $result instanceof ORAS_AI_Domain_Result ) {
			$result = ORAS_AI_Domain_Result::ambiguous();
		}

		return $this->recorded( $result );
	}

	private function recorded( ORAS_AI_Domain_Result $result ) {
		ORAS_AI_Domain_Observability::record( $result->outcome() );
		return $result;
	}

	private function normalize( $question ) {
		$question = strtolower( trim( wp_strip_all_tags( (string) $question, true ) ) );
		return preg_replace( '/\s+/', ' ', $question ) ?: '';
	}

	private function contains_any( $question, array $phrases ) {
		foreach ( $phrases as $phrase ) {
			$pattern = '/(?<![a-z0-9])' . preg_quote( $phrase, '/' ) . '(?![a-z0-9])/i';
			if ( preg_match( $pattern, $question ) ) {
				return true;
			}
		}

		return false;
	}

	private function contains_unsafe_directive( $question ) {
		$patterns = array(
			'/reveal (?:the )?(?:openai |api )?key/i',
			'/change (?:the )?(?:member|user).{0,20}(?:admin|administrator)/i',
			'/use any tool/i',
			'/call (?:this |the )?url/i',
			'/fetch https?:\/\//i',
			'/disable (?:the )?(?:quota|security|authorization)/i',
			'/select another user/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $question ) ) {
				return true;
			}
		}

		return false;
	}

	private function oras_phrases() {
		return array(
			'oras',
			'oras.org',
			'oil region astronomical society',
			'astroblast',
			'observer pass',
			'observer passes',
			'public night',
			'public nights',
			'observatory',
			'membership',
			'member services',
			'volunteer',
			'volunteering',
			'facilities',
			'directions',
			'parking',
			'website help',
			'membership dues',
			'treasurer',
		);
	}

	private function astronomy_phrases() {
		return array(
			'astronomy',
			'astronomical',
			'astrophysics',
			'cosmology',
			'space science',
			'telescope',
			'telescopes',
			'eyepiece',
			'eyepieces',
			'binoculars',
			'astrophotography',
			'observe',
			'observing',
			'night sky',
			'celestial',
			'planet',
			'planets',
			'mercury',
			'venus',
			'mars',
			'jupiter',
			'saturn',
			'uranus',
			'neptune',
			'pluto',
			'moon',
			'lunar',
			'sun',
			'solar',
			'star',
			'stars',
			'galaxy',
			'galaxies',
			'nebula',
			'comet',
			'meteor',
			'asteroid',
			'constellation',
			'eclipse',
			'aurora',
			'milky way',
			'black hole',
			'deep sky',
			'right ascension',
			'declination',
			'light year',
			'seeing',
			'transparency',
		);
	}

	private function off_topic_phrases() {
		return array(
			'coding assistant',
			'write code',
			'programming help',
			'history paper',
			'divorce agreement',
			'legal agreement',
			'steelers',
			'sports score',
			'football score',
			'baseball score',
			'basketball score',
			'homework',
			'shopping list',
			'stock price',
			'investment advice',
			'write an essay',
			'write a poem',
			'write my resume',
			'cover letter',
			'recipe',
		);
	}
}
