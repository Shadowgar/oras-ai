<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Admin-initiated, review-only gap candidate for one resolved support ticket. */
final class ORAS_AI_Support_Knowledge_Candidates {
	const NONCE_ACTION = 'oras_ai_create_support_knowledge_candidate';
	private $adapter;

	public function __construct( ORAS_AI_Fluent_Support_Adapter $adapter = null ) {
		$this->adapter = $adapter ?: new ORAS_AI_Fluent_Support_Adapter();
		add_action( 'admin_post_oras_ai_create_support_knowledge_candidate', array( $this, 'handle_action' ) );
	}

	public function create( $ticket_id ) {
		if ( ! current_user_can( 'manage_options' ) || ! get_current_user_id() ) {
			return array( 'status' => 'forbidden' );
		}
		if ( ! is_string( $ticket_id ) && ! is_int( $ticket_id ) ) {
			return array( 'status' => 'invalid_id' );
		}
		$ticket_id = (string) $ticket_id;
		if ( ! preg_match( '/^[1-9][0-9]*$/', $ticket_id ) || (string) (int) $ticket_id !== $ticket_id ) {
			return array( 'status' => 'invalid_id' );
		}
		$ticket_id = (int) $ticket_id;
		$key = 'oras_ai_support_candidate_fluent_support_' . $ticket_id;
		$claimed = get_option( $key, false );
		if ( false !== $claimed ) {
			$existing_id = absint( $claimed );
			if ( $existing_id && ORAS_AI_Knowledge_Base::POST_TYPE === get_post_type( $existing_id ) ) {
				ORAS_AI_Audit_Log::log_support_knowledge_candidate( 'support_knowledge_candidate_existing', $ticket_id, $existing_id );
				return array( 'status' => 'existing', 'id' => $existing_id );
			}
			return array( 'status' => 'pending' );
		}
		$reference = $this->adapter->resolved_ticket_reference( $ticket_id );
		if ( 'resolved' !== $reference['status'] ) {
			ORAS_AI_Audit_Log::log_support_knowledge_candidate( 'support_knowledge_candidate_rejected', $ticket_id );
			return $reference;
		}
		if ( ! add_option( $key, 'creating', '', false ) ) {
			$existing_id = absint( get_option( $key ) );
			if ( $existing_id && ORAS_AI_Knowledge_Base::POST_TYPE === get_post_type( $existing_id ) ) {
				ORAS_AI_Audit_Log::log_support_knowledge_candidate( 'support_knowledge_candidate_existing', $ticket_id, $existing_id );
				return array( 'status' => 'existing', 'id' => $existing_id );
			}
			return array( 'status' => 'pending' );
		}
		$title = sprintf( __( 'Support ticket #%d — knowledge gap', 'oras-ai-assistant' ), $ticket_id );
		$id = wp_insert_post( array(
			'post_type'   => ORAS_AI_Knowledge_Base::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
		), true );
		if ( is_wp_error( $id ) || ! $id ) {
			update_option( $key, 'failed', false );
			ORAS_AI_Audit_Log::log_support_knowledge_candidate( 'support_knowledge_candidate_rejected', $ticket_id );
			return array( 'status' => 'failed' );
		}
		$id = (int) $id;
		update_post_meta( $id, '_oras_ai_status', 'review' );
		update_post_meta( $id, '_oras_ai_visibility', 'admin' );
		update_post_meta( $id, '_oras_ai_official_answer', '' );
		update_post_meta( $id, '_oras_ai_source', 'Fluent Support' );
		update_post_meta( $id, '_oras_ai_support_source_type', 'support_ticket' );
		update_post_meta( $id, '_oras_ai_support_provider', 'fluent_support' );
		update_post_meta( $id, '_oras_ai_support_ticket_id', $ticket_id );
		update_post_meta( $id, '_oras_ai_support_candidate_created_at', current_time( 'mysql' ) );
		update_option( $key, $id, false );
		ORAS_AI_Audit_Log::log_support_knowledge_candidate( 'support_knowledge_candidate_created', $ticket_id, $id );
		return array( 'status' => 'created', 'id' => $id );
	}

	public function handle_action() {
		if ( ! current_user_can( 'manage_options' ) || ! get_current_user_id() ) {
			wp_die( esc_html__( 'You do not have permission to create a knowledge candidate.', 'oras-ai-assistant' ) );
		}
		check_admin_referer( self::NONCE_ACTION, 'oras_ai_support_candidate_nonce' );
		$result = $this->create( isset( $_POST['ticket_id'] ) ? wp_unslash( $_POST['ticket_id'] ) : '' );
		$status = in_array( $result['status'], array( 'created', 'existing', 'not_resolved', 'unavailable', 'provider_unavailable', 'invalid_id', 'pending', 'failed' ), true ) ? $result['status'] : 'failed';
		wp_safe_redirect( admin_url( 'admin.php?page=oras-ai-review&support-candidate=' . $status ) );
		exit;
	}

	public function render_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$messages = array(
			'created' => __( 'Review-only candidate created.', 'oras-ai-assistant' ),
			'existing' => __( 'A candidate already exists for this ticket.', 'oras-ai-assistant' ),
			'not_resolved' => __( 'The ticket is not resolved.', 'oras-ai-assistant' ),
			'unavailable' => __( 'The ticket is unavailable.', 'oras-ai-assistant' ),
			'provider_unavailable' => __( 'Fluent Support is unavailable.', 'oras-ai-assistant' ),
			'invalid_id' => __( 'Enter a valid ticket ID.', 'oras-ai-assistant' ),
			'pending' => __( 'Candidate creation is already in progress or needs administrator review.', 'oras-ai-assistant' ),
			'failed' => __( 'Candidate creation could not be confirmed.', 'oras-ai-assistant' ),
		);
		$status = isset( $_GET['support-candidate'] ) && is_string( $_GET['support-candidate'] )
			? sanitize_key( wp_unslash( $_GET['support-candidate'] ) ) : '';
		?>
		<h2><?php esc_html_e( 'Support knowledge candidate', 'oras-ai-assistant' ); ?></h2>
		<p><?php esc_html_e( 'Enter a resolved Fluent Support ticket ID. This creates an empty Needs Review item linked by ticket reference; review the ticket and write an approved answer separately.', 'oras-ai-assistant' ); ?></p>
		<?php if ( isset( $messages[ $status ] ) ) : ?><div class="notice notice-info" role="status"><p><?php echo esc_html( $messages[ $status ] ); ?></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="oras_ai_create_support_knowledge_candidate">
			<?php wp_nonce_field( self::NONCE_ACTION, 'oras_ai_support_candidate_nonce' ); ?>
			<label for="oras_ai_support_ticket_id"><?php esc_html_e( 'Resolved ticket ID', 'oras-ai-assistant' ); ?></label>
			<input id="oras_ai_support_ticket_id" type="number" min="1" name="ticket_id" required>
			<?php submit_button( __( 'Create Needs Review candidate', 'oras-ai-assistant' ) ); ?>
		</form>
		<?php
	}
}
