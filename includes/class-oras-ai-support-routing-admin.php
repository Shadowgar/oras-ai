<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Protected M7 routing slice of ADM-005. */
final class ORAS_AI_Support_Routing_Admin {
	const NONCE_ACTION = 'oras_ai_save_support_routing';
	private $routing;

	public function __construct( ORAS_AI_Support_Routing $routing ) {
		$this->routing = $routing;
		add_action( 'admin_post_oras_ai_save_support_routing', array( $this, 'save_settings' ) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$config = $this->routing->configuration();
		$primary = $config['primary_mailbox_id'] ?? '';
		$name = $config['destination_name'] ?? 'ORAS Support';
		$general = implode( ',', (array) ( $config['general_tag_ids'] ?? array() ) );
		?>
		<div class="wrap oras-ai-wrap">
			<h1><?php esc_html_e( 'Support Routing', 'oras-ai-assistant' ); ?></h1>
			<p><?php esc_html_e( 'Configure provider IDs for the single ORAS Support mailbox and optional topic tags. No ticket is created here.', 'oras-ai-assistant' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="oras_ai_save_support_routing">
				<?php wp_nonce_field( self::NONCE_ACTION, 'oras_ai_support_routing_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="oras_support_mailbox"><?php esc_html_e( 'Primary ORAS Support mailbox ID', 'oras-ai-assistant' ); ?></label></th><td><input id="oras_support_mailbox" type="number" min="1" name="primary_mailbox_id" value="<?php echo esc_attr( $primary ); ?>" required></td></tr>
				<tr><th scope="row"><label for="oras_support_destination"><?php esc_html_e( 'Friendly destination name', 'oras-ai-assistant' ); ?></label></th><td><input id="oras_support_destination" type="text" maxlength="80" name="destination_name" value="<?php echo esc_attr( $name ); ?>" required></td></tr>
				<tr><th scope="row"><label for="oras_support_general_tags"><?php esc_html_e( 'General fallback tag IDs', 'oras-ai-assistant' ); ?></label></th><td><input id="oras_support_general_tags" type="text" name="general_tag_ids" value="<?php echo esc_attr( $general ); ?>"><p class="description"><?php esc_html_e( 'Optional comma-separated tag IDs. The primary mailbox is always required.', 'oras-ai-assistant' ); ?></p></td></tr>
				<?php foreach ( ORAS_AI_Support_Topic::labels() as $topic => $label ) : ?>
					<?php if ( ORAS_AI_Support_Topic::GENERAL === $topic ) { continue; } ?>
					<?php $route = $config['topic_routes'][ $topic ] ?? array(); ?>
					<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td>
						<label><?php esc_html_e( 'Mailbox ID', 'oras-ai-assistant' ); ?> <input type="number" min="1" name="topic_routes[<?php echo esc_attr( $topic ); ?>][mailbox_id]" value="<?php echo esc_attr( $route['mailbox_id'] ?? '' ); ?>"></label>
						<label><?php esc_html_e( 'Tag IDs', 'oras-ai-assistant' ); ?> <input type="text" name="topic_routes[<?php echo esc_attr( $topic ); ?>][tag_ids]" value="<?php echo esc_attr( implode( ',', (array) ( $route['tag_ids'] ?? array() ) ) ); ?>"></label>
					</td></tr>
				<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Save Support Routing', 'oras-ai-assistant' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change support routing.', 'oras-ai-assistant' ) );
		}
		check_admin_referer( self::NONCE_ACTION, 'oras_ai_support_routing_nonce' );
		$input = array(
			'primary_mailbox_id' => isset( $_POST['primary_mailbox_id'] ) ? wp_unslash( $_POST['primary_mailbox_id'] ) : '',
			'destination_name'  => isset( $_POST['destination_name'] ) ? wp_unslash( $_POST['destination_name'] ) : '',
			'general_tag_ids'   => isset( $_POST['general_tag_ids'] ) ? wp_unslash( $_POST['general_tag_ids'] ) : '',
			'topic_routes'      => isset( $_POST['topic_routes'] ) ? wp_unslash( $_POST['topic_routes'] ) : array(),
		);
		if ( ! is_array( $input['topic_routes'] ) || is_wp_error( $this->routing->save( $input ) ) ) {
			wp_die( esc_html__( 'Invalid support routing settings.', 'oras-ai-assistant' ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=oras-ai-support-routing&settings-updated=1' ) );
		exit;
	}
}
