<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps Fluent Support objects and calls inside the adapter boundary. */
final class ORAS_AI_Fluent_Support_Core_Gateway {
	public function versions() {
		$core = defined( 'FLUENT_SUPPORT_VERSION' ) ? FLUENT_SUPPORT_VERSION : null;
		$pro  = defined( 'FLUENTSUPPORTPRO' ) && defined( 'FLUENTSUPPORTPRO_PLUGIN_VERSION' ) ? FLUENTSUPPORTPRO_PLUGIN_VERSION : null;
		return array(
			'core'    => $core,
			'pro'     => $pro,
			'capable' => function_exists( 'FluentSupportApi' )
				&& class_exists( 'FluentSupport\App\Models\Customer' )
				&& class_exists( 'FluentSupport\App\Models\Ticket' )
				&& class_exists( 'FluentSupport\App\Models\MailBox' )
				&& class_exists( 'FluentSupport\App\Models\Tag' )
				&& class_exists( 'FluentSupport\App\Services\Helper' ),
		);
	}

	private function customers_api() {
		return FluentSupportApi( 'customers' );
	}

	public function customers_by_user_id( $user_id ) {
		$rows = $this->customers_api()->getInstance()->where( 'user_id', $user_id )->get();
		return $this->rows( $rows );
	}

	public function customers_by_email( $email ) {
		$rows = $this->customers_api()->getInstance()->where( 'email', $email )->get();
		return $this->rows( $rows );
	}

	private function rows( $collection ) {
		$rows = array();
		foreach ( $collection as $row ) {
			$rows[] = $row;
		}
		return $rows;
	}

	public function create_customer( array $data ) {
		unset( $data['create_wp_user'] );
		return $this->customers_api()->createCustomerWithOrWithoutWpUser( $data, false );
	}

	public function mailbox( $id ) {
		return (bool) \FluentSupport\App\Models\MailBox::find( $id );
	}

	public function tag( $id ) {
		return (bool) \FluentSupport\App\Models\Tag::find( $id );
	}

	public function current_user_is_agent() {
		return (bool) \FluentSupport\App\Services\Helper::getAgentByUserId( get_current_user_id() );
	}

	public function create_ticket( array $data ) {
		return FluentSupportApi( 'tickets' )->createTicket( $data );
	}

	public function apply_tags( $ticket, array $tag_ids ) {
		if ( ! $ticket instanceof \FluentSupport\App\Models\Ticket ) {
			return false;
		}
		$ticket->applyTags( $tag_ids );
		foreach ( $tag_ids as $tag_id ) {
			if ( ! $ticket->hasTag( $tag_id ) ) {
				return false;
			}
		}
		return true;
	}
}
