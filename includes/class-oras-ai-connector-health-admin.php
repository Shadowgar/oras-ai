<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ORAS_AI_Connector_Health_Admin {

	private $observability;
	private $connectors;

	public function __construct( ORAS_AI_Connector_Observability $observability, array $connectors ) {
		$this->observability = $observability;
		$this->connectors    = array_values(
			array_filter(
				$connectors,
				static function ( $connector ) {
					return $connector instanceof ORAS_AI_Observable_Live_Connector_Interface;
				}
			)
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$labels = array(
			'events_calendar' => __( 'The Events Calendar', 'oras-ai-assistant' ),
			'woocommerce'     => __( 'WooCommerce', 'oras-ai-assistant' ),
			'pmpro'           => __( 'PMPro', 'oras-ai-assistant' ),
		);
		$state = $this->observability->snapshot();
		?>
		<div class="wrap oras-ai-wrap">
			<h1><?php esc_html_e( 'Connector Health', 'oras-ai-assistant' ); ?></h1>
			<p><?php esc_html_e( 'Read-only operational state for the local ORAS live-data connectors.', 'oras-ai-assistant' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Connector', 'oras-ai-assistant' ); ?></th><th><?php esc_html_e( 'Dependency', 'oras-ai-assistant' ); ?></th><th><?php esc_html_e( 'Operational state', 'oras-ai-assistant' ); ?></th><th><?php esc_html_e( 'Failures', 'oras-ai-assistant' ); ?></th><th><?php esc_html_e( 'Last safe failure', 'oras-ai-assistant' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $this->connectors as $connector ) :
					$connector_id = $connector->connector_id();
					$item         = $state[ $connector_id ];
					try {
						$availability = $connector->is_available() ? __( 'Available', 'oras-ai-assistant' ) : __( 'Unavailable', 'oras-ai-assistant' );
					} catch ( Throwable $throwable ) {
						$availability = __( 'Unknown', 'oras-ai-assistant' );
					}
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $labels[ $connector_id ] ); ?></th>
						<td><?php echo esc_html( $availability ); ?></td>
						<td><?php echo esc_html( ucfirst( $item['operational_state'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $item['failure_count'] ) ); ?></td>
						<td><?php echo esc_html( $item['last_failure_reason'] ?: '—' ); ?><?php if ( $item['last_failure_at'] ) : ?><br><?php echo esc_html( $item['last_failure_at'] ); ?><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
