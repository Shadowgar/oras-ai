<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the shared chat component and its plugin-owned frontend/admin entry points.
 */
final class ORAS_AI_Chat_UI {
	const ADMIN_PAGE = 'oras-ai-test-console';

	private $request_gateway;
	private static $instance = 0;

	public function __construct( ORAS_AI_Request_Gateway $request_gateway ) {
		$this->request_gateway = $request_gateway;

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_footer', array( $this, 'output_sitewide' ) );
		add_shortcode( 'oras_ai_chat', array( $this, 'render_shortcode' ) );
	}

	public function enqueue_assets() {
		if ( is_admin() || ! $this->request_gateway->member_ui_allowed() ) {
			return;
		}

		$this->enqueue_chat_assets();
	}

	public function enqueue_admin_assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) || self::ADMIN_PAGE !== $page ) {
			return;
		}

		$this->enqueue_chat_assets();
	}

	private function enqueue_chat_assets() {
		wp_enqueue_style(
			'oras-ai-chat',
			ORAS_AI_PLUGIN_URL . 'assets/chat.css',
			array(),
			ORAS_AI_VERSION
		);
		wp_enqueue_script(
			'oras-ai-chat',
			ORAS_AI_PLUGIN_URL . 'assets/chat.js',
			array(),
			ORAS_AI_VERSION,
			true
		);
		wp_localize_script(
			'oras-ai-chat',
			'ORAS_AI_CHAT',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => ORAS_AI_Conversation_Transport::AJAX_ACTION,
				'nonce'   => wp_create_nonce( ORAS_AI_Request_Gateway::NONCE_ACTION ),
				'strings' => array(
					'loading'             => __( 'Loading your current conversation…', 'oras-ai-assistant' ),
					'thinking'            => __( 'Thinking…', 'oras-ai-assistant' ),
					'generic_error'       => __( 'ORAS AI could not complete that request. Please try again.', 'oras-ai-assistant' ),
					'unavailable'          => __( 'ORAS AI is temporarily unavailable.', 'oras-ai-assistant' ),
					'sensitive_input'      => __( 'Please do not send payment-card information through ORAS AI.', 'oras-ai-assistant' ),
					'no_evidence'          => __( 'ORAS information could not establish an answer to that question.', 'oras-ai-assistant' ),
					'refusal'              => __( 'ORAS AI supports ORAS and astronomy questions.', 'oras-ai-assistant' ),
					'limit'                => __( 'That request cannot be processed right now. Please try again later.', 'oras-ai-assistant' ),
					'empty'                => __( 'Ask ORAS AI about ORAS or astronomy.', 'oras-ai-assistant' ),
					'loaded'               => __( 'Conversation loaded.', 'oras-ai-assistant' ),
					'submitting'           => __( 'Thinking…', 'oras-ai-assistant' ),
					'new_chat'             => __( 'New conversation started.', 'oras-ai-assistant' ),
					'chat_label'           => __( 'ORAS AI Assistant chat', 'oras-ai-assistant' ),
					'input_label'          => __( 'Your ORAS or astronomy question', 'oras-ai-assistant' ),
					'close'                => __( 'Close ORAS AI chat', 'oras-ai-assistant' ),
					'new_chat_label'       => __( 'Start a New Chat', 'oras-ai-assistant' ),
					'send'                => __( 'Send question', 'oras-ai-assistant' ),
				),
			)
		);
	}

	public function render_admin_console() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap oras-ai-wrap">
			<h1><?php esc_html_e( 'ORAS AI — Test Console', 'oras-ai-assistant' ); ?></h1>
			<p><?php esc_html_e( 'This console tests the normal assistant path using your administrator account and the currently configured model.', 'oras-ai-assistant' ); ?></p>
			<?php echo $this->render_component( 'page' ); ?>
		</div>
		<?php
	}

	public function render_sitewide() {
		if ( is_admin() || ! $this->request_gateway->member_ui_allowed() ) {
			return '';
		}

		$panel_id = 'oras-ai-chat-panel-' . ++self::$instance;
		return '<div class="oras-ai-chat-launcher-wrap">'
			. '<button type="button" class="oras-ai-chat-launcher" data-oras-ai-chat-launcher aria-controls="' . esc_attr( $panel_id ) . '" aria-expanded="false">Support</button>'
			. $this->render_component( 'panel', $panel_id )
			. '</div>';
	}

	/** Output the site-wide component when WordPress fires wp_footer. */
	public function output_sitewide() {
		echo $this->render_sitewide();
	}

	public function render_shortcode() {
		if ( is_admin() || ! $this->request_gateway->member_ui_allowed() ) {
			return '<p class="oras-ai-chat-unavailable">' . esc_html__( 'ORAS AI chat is available to eligible members.', 'oras-ai-assistant' ) . '</p>';
		}

		return $this->render_component( 'page' );
	}

	public function render_component( $mode, $panel_id = '' ) {
		$mode = 'panel' === $mode ? 'panel' : 'page';
		$id   = '' !== $panel_id ? sanitize_key( $panel_id ) : 'oras-ai-chat-' . ++self::$instance;
		$title_id = $id . '-title';
		$input_id = $id . '-input';
		$is_panel = 'panel' === $mode;

		ob_start();
		?>
		<section
			id="<?php echo esc_attr( $id ); ?>"
			class="oras-ai-chat oras-ai-chat--<?php echo esc_attr( $mode ); ?>"
			data-oras-ai-chat
			data-oras-ai-chat-mode="<?php echo esc_attr( $mode ); ?>"
			<?php if ( $is_panel ) : ?>
				role="dialog"
				aria-modal="true"
				aria-labelledby="<?php echo esc_attr( $title_id ); ?>"
				hidden
			<?php endif; ?>
		>
			<header class="oras-ai-chat__header">
				<div>
					<h2 id="<?php echo esc_attr( $title_id ); ?>"><?php esc_html_e( 'ORAS AI Assistant', 'oras-ai-assistant' ); ?></h2>
					<p class="oras-ai-chat__identity"><?php esc_html_e( 'This is an AI-powered ORAS/astronomy assistant.', 'oras-ai-assistant' ); ?></p>
				</div>
				<div class="oras-ai-chat__actions">
					<button type="button" class="oras-ai-chat__new" data-oras-ai-chat-new><?php esc_html_e( 'New Chat', 'oras-ai-assistant' ); ?></button>
					<?php if ( $is_panel ) : ?>
						<button type="button" class="oras-ai-chat__close" data-oras-ai-chat-close><?php esc_html_e( 'Close', 'oras-ai-assistant' ); ?></button>
					<?php endif; ?>
				</div>
			</header>
			<p class="oras-ai-chat__scope"><?php esc_html_e( 'Ask about ORAS/support or astronomy questions.', 'oras-ai-assistant' ); ?></p>
			<p class="oras-ai-chat__privacy"><?php esc_html_e( 'Responses use external AI processing. Conversation text is retained for 30 days.', 'oras-ai-assistant' ); ?></p>
			<div class="oras-ai-chat__messages" data-oras-ai-chat-messages role="log" aria-live="polite" aria-relevant="additions" tabindex="0"></div>
			<div class="oras-ai-chat__status" data-oras-ai-chat-status role="status" aria-live="polite"></div>
			<form class="oras-ai-chat__form" data-oras-ai-chat-form>
				<label for="<?php echo esc_attr( $input_id ); ?>"><?php esc_html_e( 'Your ORAS or astronomy question', 'oras-ai-assistant' ); ?></label>
				<textarea id="<?php echo esc_attr( $input_id ); ?>" data-oras-ai-chat-input rows="3" required></textarea>
				<button type="submit" data-oras-ai-chat-send><?php esc_html_e( 'Send', 'oras-ai-assistant' ); ?></button>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
