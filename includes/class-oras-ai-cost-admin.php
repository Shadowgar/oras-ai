<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Cost_Admin {

	const NONCE_ACTION = 'oras_ai_save_cost_controls';

	private $ledger;

	public function __construct( ?ORAS_AI_Usage_Ledger $ledger = null ) {
		$this->ledger = $ledger ?: new ORAS_AI_Usage_Ledger();
		add_action( 'admin_post_oras_ai_save_cost_controls', array( $this, 'save_settings' ) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->ledger->prune();
		$config  = ORAS_AI_Cost_Config::get();
		$summary = $this->ledger->summary();
		$budget  = $this->ledger->budget_state( $config );
		$status  = $budget['hard_stop'] ? __( 'Hard stop', 'oras-ai-assistant' ) : ( $budget['warning'] ? __( 'Warning', 'oras-ai-assistant' ) : __( 'Normal', 'oras-ai-assistant' ) );
		?>
		<div class="wrap oras-ai-wrap">
			<h1><?php esc_html_e( 'Usage & Cost Controls', 'oras-ai-assistant' ); ?></h1>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Usage and cost controls saved.', 'oras-ai-assistant' ); ?></p></div>
			<?php endif; ?>

			<div class="oras-ai-panel">
				<h2><?php esc_html_e( 'Current month', 'oras-ai-assistant' ); ?></h2>
				<p><strong><?php esc_html_e( 'Current month accounted spend:', 'oras-ai-assistant' ); ?></strong> $<?php echo esc_html( ORAS_AI_Cost_Config::format_usd( $summary['site_month_actual_microdollars'] ) ); ?></p>
				<p><strong><?php esc_html_e( 'Outstanding maximum reservations:', 'oras-ai-assistant' ); ?></strong> $<?php echo esc_html( ORAS_AI_Cost_Config::format_usd( $summary['site_month_reserved_microdollars'] ) ); ?></p>
				<p><strong><?php esc_html_e( 'Allowed executions:', 'oras-ai-assistant' ); ?></strong> <?php echo esc_html( number_format_i18n( $summary['site_month_allowed'] ) ); ?></p>
				<p><strong><?php esc_html_e( 'Provider input tokens:', 'oras-ai-assistant' ); ?></strong> <?php echo esc_html( number_format_i18n( $summary['site_month_input_tokens'] ) ); ?></p>
				<p><strong><?php esc_html_e( 'Provider output tokens:', 'oras-ai-assistant' ); ?></strong> <?php echo esc_html( number_format_i18n( $summary['site_month_output_tokens'] ) ); ?></p>
				<p><strong><?php esc_html_e( 'Budget state:', 'oras-ai-assistant' ); ?></strong> <?php echo esc_html( $status ); ?></p>
				<p><strong><?php esc_html_e( 'Rejected executions:', 'oras-ai-assistant' ); ?></strong> <?php echo esc_html( number_format_i18n( array_sum( $summary['rejections'] ) ) ); ?></p>
			</div>

			<div class="oras-ai-panel">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="oras_ai_save_cost_controls">
					<?php wp_nonce_field( self::NONCE_ACTION, 'oras_ai_cost_nonce' ); ?>

					<h2><?php esc_html_e( 'Limits', 'oras-ai-assistant' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php $this->number_row( 'daily_quota', __( 'Daily successful request quota', 'oras-ai-assistant' ), $config['daily_quota'], 1, ORAS_AI_Cost_Config::MAX_DAILY_QUOTA ); ?>
						<?php $this->number_row( 'monthly_quota', __( 'Monthly successful request quota', 'oras-ai-assistant' ), $config['monthly_quota'], 1, ORAS_AI_Cost_Config::MAX_MONTHLY_QUOTA ); ?>
						<?php $this->number_row( 'burst_per_minute', __( 'Requests per rolling minute', 'oras-ai-assistant' ), $config['burst_per_minute'], 1, ORAS_AI_Cost_Config::MAX_BURST_PER_MINUTE ); ?>
						<?php $this->number_row( 'max_input_characters', __( 'Maximum input characters', 'oras-ai-assistant' ), $config['max_input_characters'], 100, ORAS_AI_Cost_Config::MAX_INPUT_CHARACTERS ); ?>
						<?php $this->number_row( 'max_output_tokens', __( 'Maximum output tokens', 'oras-ai-assistant' ), $config['max_output_tokens'], 1, ORAS_AI_Cost_Config::MAX_OUTPUT_TOKENS ); ?>
						<?php $this->number_row( 'execution_timeout_seconds', __( 'Execution timeout seconds', 'oras-ai-assistant' ), $config['execution_timeout_seconds'], 1, ORAS_AI_Cost_Config::MAX_EXECUTION_TIMEOUT_SECONDS ); ?>
						<tr>
							<th scope="row"><label for="warning_usd"><?php esc_html_e( 'Monthly warning (USD)', 'oras-ai-assistant' ); ?></label></th>
							<td><input type="number" min="0.000001" step="0.000001" name="warning_usd" id="warning_usd" value="<?php echo esc_attr( ORAS_AI_Cost_Config::format_usd( $config['warning_microdollars'] ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="hard_stop_usd"><?php esc_html_e( 'Monthly hard stop (USD)', 'oras-ai-assistant' ); ?></label></th>
							<td><input type="number" min="0.000001" step="0.000001" name="hard_stop_usd" id="hard_stop_usd" value="<?php echo esc_attr( ORAS_AI_Cost_Config::format_usd( $config['hard_stop_microdollars'] ) ); ?>"></td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Model pricing', 'oras-ai-assistant' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Local USD rates per one million tokens. Both input and output rates are required before paid member execution can use a model.', 'oras-ai-assistant' ); ?></p>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Model', 'oras-ai-assistant' ); ?></th><th><?php esc_html_e( 'Input USD / 1M tokens', 'oras-ai-assistant' ); ?></th><th><?php esc_html_e( 'Output USD / 1M tokens', 'oras-ai-assistant' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( ORAS_AI_Config::allowed_openai_models() as $model ) :
							$rates = isset( $config['pricing'][ $model ] ) ? $config['pricing'][ $model ] : null;
						?>
							<tr>
								<th scope="row"><?php echo esc_html( $model ); ?></th>
								<td><input type="number" min="0.000001" step="0.000001" name="pricing[<?php echo esc_attr( $model ); ?>][input_usd_per_million_tokens]" value="<?php echo esc_attr( $rates ? ORAS_AI_Cost_Config::format_usd( $rates['input_microdollars_per_million_tokens'] ) : '' ); ?>"></td>
								<td><input type="number" min="0.000001" step="0.000001" name="pricing[<?php echo esc_attr( $model ); ?>][output_usd_per_million_tokens]" value="<?php echo esc_attr( $rates ? ORAS_AI_Cost_Config::format_usd( $rates['output_microdollars_per_million_tokens'] ) : '' ); ?>"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<?php submit_button( __( 'Save Usage & Cost Controls', 'oras-ai-assistant' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change ORAS AI usage and cost controls.', 'oras-ai-assistant' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'oras_ai_cost_nonce' );
		$new_config = ORAS_AI_Cost_Config::from_admin_request( $_POST );
		if ( is_wp_error( $new_config ) ) {
			wp_die( esc_html__( 'Invalid usage and cost settings.', 'oras-ai-assistant' ) );
		}

		$old_config = ORAS_AI_Cost_Config::get();
		if ( $old_config !== $new_config ) {
			$result = ORAS_AI_Cost_Config::update( $new_config );
			if ( is_wp_error( $result ) || ! $result ) {
				wp_die( esc_html__( 'Unable to save usage and cost settings.', 'oras-ai-assistant' ) );
			}
		}

		$this->audit_changes( $old_config, $new_config );
		wp_safe_redirect( admin_url( 'admin.php?page=oras-ai-cost&settings-updated=1' ) );
		exit;
	}

	private function number_row( $name, $label, $value, $minimum, $maximum ) {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input type="number" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" min="<?php echo esc_attr( $minimum ); ?>" max="<?php echo esc_attr( $maximum ); ?>" step="1" value="<?php echo esc_attr( $value ); ?>"></td>
		</tr>
		<?php
	}

	private function audit_changes( array $old_config, array $new_config ) {
		foreach ( array_keys( ORAS_AI_Cost_Config::defaults() ) as $setting ) {
			if ( 'pricing' === $setting || $old_config[ $setting ] === $new_config[ $setting ] ) {
				continue;
			}
			ORAS_AI_Audit_Log::log_cost_setting_changed( $setting, $old_config[ $setting ], $new_config[ $setting ] );
		}

		foreach ( ORAS_AI_Config::allowed_openai_models() as $model ) {
			$old_pricing = $old_config['pricing'][ $model ] ?? null;
			$new_pricing = $new_config['pricing'][ $model ] ?? null;
			if ( $old_pricing !== $new_pricing ) {
				ORAS_AI_Audit_Log::log_model_pricing_changed( $model, $old_pricing, $new_pricing );
			}
		}
	}
}
