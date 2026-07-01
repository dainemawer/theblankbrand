<?php
/**
 * Zoho Inventory — settings screen.
 *
 * Adds a "Zoho Inventory" settings page under Settings, storing OAuth
 * credentials, the organisation id, the warehouse notification address and
 * the webhook secret used by {@see StockWebhook}. Read everywhere else via
 * {@see Settings::get()} / {@see Settings::is_enabled()}.
 *
 * @package BlankBrandTheme
 */

namespace BlankBrandTheme\Zoho;

use TenupFramework\Module;
use TenupFramework\ModuleInterface;

/**
 * Zoho Inventory settings module.
 *
 * @package BlankBrandTheme
 */
class Settings implements ModuleInterface {

	use Module;

	const OPTION = 'tbb_zoho_settings';

	const PAGE = 'tbb-zoho-inventory';

	const GROUP = 'tbb_zoho_settings_group';

	/**
	 * Supported Zoho data centres and their accounts (OAuth) domain.
	 * The API domain is confirmed from the token refresh response at
	 * request time rather than assumed from this list.
	 *
	 * @var array<string,string>
	 */
	const DATA_CENTERS = [
		'com'    => 'accounts.zoho.com',
		'eu'     => 'accounts.zoho.eu',
		'in'     => 'accounts.zoho.in',
		'com.au' => 'accounts.zoho.com.au',
		'ca'     => 'accounts.zohocloud.ca',
	];

	/**
	 * Can this module be registered?
	 *
	 * @return bool
	 */
	public function can_register(): bool {
		return is_admin() && class_exists( 'WooCommerce' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_test_connection_script' ] );
		add_action( 'wp_ajax_tbb_zoho_test_connection', [ $this, 'ajax_test_connection' ] );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = '' ) {
		$settings = get_option( self::OPTION, [] );

		return $settings[ $key ] ?? $fallback;
	}

	/**
	 * Whether the integration is switched on and minimally configured.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( 'yes' !== self::get( 'enabled' ) ) {
			return false;
		}

		foreach ( [ 'client_id', 'client_secret', 'refresh_token', 'organization_id' ] as $key ) {
			if ( '' === self::get( $key ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get (and lazily create) the shared secret used to authenticate
	 * inbound Zoho stock webhooks.
	 *
	 * @return string
	 */
	public static function get_webhook_secret(): string {
		$secret = self::get( 'webhook_secret' );

		if ( '' === $secret ) {
			$secret   = wp_generate_password( 40, false );
			$settings = get_option( self::OPTION, [] );

			$settings['webhook_secret'] = $secret;
			update_option( self::OPTION, $settings );
		}

		return $secret;
	}

	/**
	 * Register the "Settings > Zoho Inventory" page.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'Zoho Inventory', 'blank-brand-theme' ),
			__( 'Zoho Inventory', 'blank-brand-theme' ),
			'manage_woocommerce',
			self::PAGE,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Register settings, sections and fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting( self::GROUP, self::OPTION, [ $this, 'sanitize' ] );

		add_settings_section( 'tbb_zoho_main', __( 'Connection', 'blank-brand-theme' ), '__return_false', self::PAGE );

		$fields = [
			'enabled'         => __( 'Enable sync', 'blank-brand-theme' ),
			'data_center'     => __( 'Data centre', 'blank-brand-theme' ),
			'organization_id' => __( 'Organization ID', 'blank-brand-theme' ),
			'client_id'       => __( 'Client ID', 'blank-brand-theme' ),
			'client_secret'   => __( 'Client secret', 'blank-brand-theme' ),
			'refresh_token'   => __( 'Refresh token', 'blank-brand-theme' ),
			'warehouse_email' => __( 'Warehouse notification email', 'blank-brand-theme' ),
		];

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'tbb_zoho_' . $key,
				$label,
				[ $this, 'render_field' ],
				self::PAGE,
				'tbb_zoho_main',
				[ 'key' => $key ]
			);
		}
	}

	/**
	 * Render a single settings field.
	 *
	 * @param array $args Field args, contains the setting 'key'.
	 * @return void
	 */
	public function render_field( array $args ): void {
		$key   = $args['key'];
		$name  = self::OPTION . "[$key]";
		$value = self::get( $key );

		switch ( $key ) {
			case 'enabled':
				printf(
					'<label><input type="checkbox" name="%1$s" value="yes" %2$s /> %3$s</label>',
					esc_attr( $name ),
					checked( 'yes', $value, false ),
					esc_html__( 'Push completed orders to Zoho and accept stock updates from it.', 'blank-brand-theme' )
				);
				break;

			case 'data_center':
				echo '<select name="' . esc_attr( $name ) . '">';
				foreach ( array_keys( self::DATA_CENTERS ) as $dc ) {
					printf(
						'<option value="%1$s" %2$s>%1$s</option>',
						esc_attr( $dc ),
						selected( $dc, $value ? $value : 'com', false )
					);
				}
				echo '</select>';
				break;

			case 'client_secret':
			case 'refresh_token':
				printf(
					'<input type="password" name="%1$s" value="" autocomplete="off" class="regular-text" placeholder="%2$s" />',
					esc_attr( $name ),
					'' !== $value ? esc_attr__( 'Leave blank to keep the current value', 'blank-brand-theme' ) : ''
				);
				break;

			case 'warehouse_email':
				printf(
					'<input type="email" name="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			default:
				printf(
					'<input type="text" name="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;
		}

		if ( 'refresh_token' === $key ) {
			$url = rest_url( 'tbb/v1/zoho-stock' );
			printf(
				'<p class="description">%s<br><code>%s</code></p>',
				esc_html__( 'Stock webhook URL — point a Zoho Inventory workflow rule at this, sending the shared secret below as a header or query arg named "secret".', 'blank-brand-theme' ),
				esc_html( $url )
			);
		}

		if ( 'organization_id' === $key ) {
			printf(
				'<p class="description">%s <code>%s</code></p>',
				esc_html__( 'Webhook shared secret:', 'blank-brand-theme' ),
				esc_html( self::get_webhook_secret() )
			);
		}
	}

	/**
	 * Sanitize submitted settings, preserving existing secrets when the
	 * password fields are left blank.
	 *
	 * @param array $input Raw submitted values.
	 * @return array
	 */
	public function sanitize( $input ): array {
		$existing = get_option( self::OPTION, [] );

		$output = [
			'enabled'         => isset( $input['enabled'] ) ? 'yes' : 'no',
			'data_center'     => array_key_exists( $input['data_center'] ?? '', self::DATA_CENTERS ) ? $input['data_center'] : 'com',
			'organization_id' => sanitize_text_field( $input['organization_id'] ?? '' ),
			'client_id'       => sanitize_text_field( $input['client_id'] ?? '' ),
			'warehouse_email' => sanitize_email( $input['warehouse_email'] ?? '' ),
			'webhook_secret'  => $existing['webhook_secret'] ?? '',
		];

		foreach ( [ 'client_secret', 'refresh_token' ] as $key ) {
			$output[ $key ] = '' !== ( $input[ $key ] ?? '' )
				? sanitize_text_field( $input[ $key ] )
				: ( $existing[ $key ] ?? '' );
		}

		delete_transient( Client::TOKEN_TRANSIENT );

		return $output;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zoho Inventory', 'blank-brand-theme' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
			<p>
				<button type="button" class="button" id="tbb-zoho-test-connection"><?php esc_html_e( 'Test connection', 'blank-brand-theme' ); ?></button>
				<span id="tbb-zoho-test-connection-result"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Enqueue the small inline script behind the "Test connection" button.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_test_connection_script( string $hook ): void {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_script( 'jquery' );

		$script = <<<'JS'
		jQuery( function ( $ ) {
			$( '#tbb-zoho-test-connection' ).on( 'click', function () {
				var $button = $( this );
				var $result = $( '#tbb-zoho-test-connection-result' );

				$button.prop( 'disabled', true );
				$result.text( tbbZohoTestConnection.testing );

				$.post( tbbZohoTestConnection.ajaxUrl, {
					action: 'tbb_zoho_test_connection',
					nonce: tbbZohoTestConnection.nonce,
				} ).done( function ( response ) {
					$result.text( response.success ? response.data.message : response.data.message );
				} ).fail( function () {
					$result.text( tbbZohoTestConnection.error );
				} ).always( function () {
					$button.prop( 'disabled', false );
				} );
			} );
		} );
		JS;

		wp_add_inline_script( 'jquery', $script );

		wp_localize_script(
			'jquery',
			'tbbZohoTestConnection',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'tbb_zoho_test_connection' ),
				'testing' => __( 'Testing…', 'blank-brand-theme' ),
				'error'   => __( 'Request failed.', 'blank-brand-theme' ),
			]
		);
	}

	/**
	 * AJAX handler backing the "Test connection" button — fetches the
	 * configured Zoho organisation to confirm the credentials work.
	 *
	 * @return void
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'tbb_zoho_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'blank-brand-theme' ) ], 403 );
		}

		$client       = new Client();
		$organization = $client->get_organization();

		if ( is_wp_error( $organization ) ) {
			wp_send_json_error( [ 'message' => $organization->get_error_message() ] );
		}

		$name = $organization['organization']['name'] ?? __( 'Unknown organisation', 'blank-brand-theme' );

		wp_send_json_success(
			[
				/* translators: %s: Zoho organisation name. */
				'message' => sprintf( __( 'Connected to "%s".', 'blank-brand-theme' ), $name ),
			]
		);
	}
}
