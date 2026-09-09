<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_M6_Provider_Admin {
	const NONCE_ACTION = 'oras_ai_save_m6_provider_settings';
	private $observability;

	public function __construct( ?ORAS_AI_Astronomy_Observability $observability = null ) {
		$this->observability = $observability ?: new ORAS_AI_Astronomy_Observability();
		add_action( 'admin_post_oras_ai_save_m6_provider_settings', array( $this, 'save_settings' ) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$site                = ORAS_AI_Observing_Site::oras_observatory();
		$has_credentials     = ORAS_AI_Config::has_astronomyapi_credentials();
		$constant_credentials = ORAS_AI_Config::astronomyapi_credentials_from_constants();
		$application_id      = $constant_credentials ? '' : ORAS_AI_Config::get_astronomyapi_application_id();
		$nws_contact         = ORAS_AI_Config::get_nws_contact();
		?>
		<div class="wrap oras-ai-wrap">
			<h1><?php esc_html_e( 'Astronomy & Weather Providers', 'oras-ai-assistant' ); ?></h1>
			<div class="oras-ai-panel">
				<h2><?php esc_html_e( 'Authoritative observing site', 'oras-ai-assistant' ); ?></h2>
				<p><?php echo esc_html( sprintf( 'Latitude %.6f · Longitude %.6f · Elevation %.3f m · %s', $site->latitude(), $site->longitude(), $site->elevation_meters(), $site->timezone_name() ) ); ?></p>
				<p class="description"><?php esc_html_e( 'Server-controlled M6 configuration; member and browser input cannot replace this site.', 'oras-ai-assistant' ); ?></p>
			</div>
			<div class="oras-ai-panel">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="oras_ai_save_m6_provider_settings">
					<?php wp_nonce_field( self::NONCE_ACTION, 'oras_ai_m6_provider_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="astronomyapi_application_id"><?php esc_html_e( 'AstronomyAPI Application ID', 'oras-ai-assistant' ); ?></label></th>
							<td><input class="regular-text" type="text" name="astronomyapi_application_id" id="astronomyapi_application_id" value="<?php echo esc_attr( $application_id ); ?>" <?php disabled( $constant_credentials ); ?>></td>
						</tr>
						<tr>
							<th scope="row"><label for="astronomyapi_application_secret"><?php esc_html_e( 'AstronomyAPI Application Secret', 'oras-ai-assistant' ); ?></label></th>
							<td>
								<input class="regular-text" type="password" name="astronomyapi_application_secret" id="astronomyapi_application_secret" value="" autocomplete="new-password" <?php disabled( $constant_credentials ); ?>>
								<p class="description"><?php echo esc_html( $has_credentials ? __( 'Configured. Enter a new secret only to replace it.', 'oras-ai-assistant' ) : __( 'Not configured.', 'oras-ai-assistant' ) ); ?></p>
							</td>
						</tr>
						<?php if ( $has_credentials && ! $constant_credentials ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Remove credentials', 'oras-ai-assistant' ); ?></th><td><label><input type="checkbox" name="remove_astronomyapi_credentials" value="1"> <?php esc_html_e( 'Remove credentials stored by this plugin', 'oras-ai-assistant' ); ?></label></td></tr>
						<?php endif; ?>
						<tr>
							<th scope="row"><label for="nws_contact"><?php esc_html_e( 'NWS identifying contact', 'oras-ai-assistant' ); ?></label></th>
							<td><input class="regular-text" type="text" name="nws_contact" id="nws_contact" value="<?php echo esc_attr( $nws_contact ); ?>"><p class="description"><?php esc_html_e( 'Email address or HTTPS contact URL used only to construct the required server-side User-Agent.', 'oras-ai-assistant' ); ?></p></td>
						</tr>
					</table>
					<?php submit_button( __( 'Save Provider Settings', 'oras-ai-assistant' ) ); ?>
				</form>
			</div>
			<div class="oras-ai-panel">
				<h2><?php esc_html_e( 'Astronomy provider health', 'oras-ai-assistant' ); ?></h2>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Provider', 'oras-ai-assistant' ); ?></th><th><?php esc_html_e( 'State', 'oras-ai-assistant' ); ?></th><th><?php esc_html_e( 'Failures', 'oras-ai-assistant' ); ?></th><th><?php esc_html_e( 'Last bounded reason', 'oras-ai-assistant' ); ?></th></tr></thead><tbody>
				<?php foreach ( $this->observability->snapshot() as $provider_id => $health ) : ?>
					<tr><td><?php echo esc_html( $provider_id ); ?></td><td><?php echo esc_html( $health['operational_state'] ); ?></td><td><?php echo esc_html( number_format_i18n( $health['failure_count'] ) ); ?></td><td><?php echo esc_html( $health['last_failure_reason'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</div>
		</div>
		<?php
	}

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change astronomy or weather provider settings.', 'oras-ai-assistant' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'oras_ai_m6_provider_nonce' );
		$remove = isset( $_POST['remove_astronomyapi_credentials'] ) && '1' === sanitize_key( wp_unslash( $_POST['remove_astronomyapi_credentials'] ) );
		$old_id     = ORAS_AI_Config::get_astronomyapi_application_id();
		$old_secret = ORAS_AI_Config::get_astronomyapi_application_secret();
		$old_contact = ORAS_AI_Config::get_nws_contact();

		$application_id = isset( $_POST['astronomyapi_application_id'] )
			? trim( (string) wp_unslash( $_POST['astronomyapi_application_id'] ) )
			: '';
		$submitted_secret = isset( $_POST['astronomyapi_application_secret'] )
			? trim( (string) wp_unslash( $_POST['astronomyapi_application_secret'] ) )
			: '';
		$application_secret = '' !== $submitted_secret ? $submitted_secret : $old_secret;
		$nws_contact = isset( $_POST['nws_contact'] ) ? trim( (string) wp_unslash( $_POST['nws_contact'] ) ) : '';

		if ( $remove ) {
			$application_id = '';
			$application_secret = '';
		}

		$validated_credentials = ORAS_AI_Config::validate_astronomyapi_credentials( $application_id, $application_secret, true );
		$validated_contact     = ORAS_AI_Config::normalize_nws_contact( $nws_contact );
		if ( is_wp_error( $validated_credentials ) || null === $validated_contact ) {
			wp_die( esc_html__( 'Invalid astronomy or weather provider settings.', 'oras-ai-assistant' ) );
		}

		if ( ! ORAS_AI_Config::astronomyapi_credentials_from_constants() ) {
			if ( '' === $validated_credentials[0] ) {
				ORAS_AI_Config::delete_stored_astronomyapi_credentials();
			} else {
				ORAS_AI_Config::update_stored_astronomyapi_credentials( $validated_credentials[0], $validated_credentials[1] );
			}
		}
		ORAS_AI_Config::update_nws_contact( $validated_contact );

		$new_id      = ORAS_AI_Config::get_astronomyapi_application_id();
		$new_secret  = ORAS_AI_Config::get_astronomyapi_application_secret();
		$new_contact = ORAS_AI_Config::get_nws_contact();
		if ( $old_id !== $new_id || $old_secret !== $new_secret ) {
			$action = '' === $new_id ? 'removed' : ( '' === $old_id ? 'set' : 'replaced' );
			ORAS_AI_Audit_Log::log_m6_provider_config_changed( 'astronomyapi_credentials', $action );
		}
		if ( $old_contact !== $new_contact ) {
			ORAS_AI_Audit_Log::log_m6_provider_config_changed( 'nws_contact', '' === $new_contact ? 'removed' : ( '' === $old_contact ? 'set' : 'replaced' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=oras-ai-m6-providers&settings-updated=1' ) );
		exit;
	}
}
