<?php
/**
 * Zoho Inventory API client.
 *
 * Handles OAuth2 access-token refresh/caching and thin wrapper methods for
 * the handful of Zoho Inventory endpoints this integration needs. Not a
 * Module — instantiated directly by {@see OrderSync}, {@see StockWebhook}
 * and {@see StockSync}.
 *
 * @package BlankBrandTheme
 */

namespace BlankBrandTheme\Zoho;

/**
 * Zoho Inventory API client.
 *
 * @package BlankBrandTheme
 */
class Client {

	const TOKEN_TRANSIENT = 'tbb_zoho_access_token';

	/**
	 * Fetch the configured Zoho organisation. Used by the settings screen's
	 * "Test connection" button.
	 *
	 * @return array|\WP_Error
	 */
	public function get_organization() {
		return $this->request( 'GET', '/organizations/' . rawurlencode( (string) Settings::get( 'organization_id' ) ) );
	}

	/**
	 * Find a Zoho contact by email, creating one if none exists.
	 *
	 * @param string $email Contact email.
	 * @param string $name  Contact display name, used only if a new contact is created.
	 * @return int|\WP_Error Zoho contact_id.
	 */
	public function get_or_create_contact_id( string $email, string $name ) {
		$found = $this->request( 'GET', '/contacts', [ 'email' => $email ] );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		if ( ! empty( $found['contacts'][0]['contact_id'] ) ) {
			return (int) $found['contacts'][0]['contact_id'];
		}

		$created = $this->request(
			'POST',
			'/contacts',
			[],
			[
				'contact_name' => $name ? $name : $email,
				'email'        => $email,
			]
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		if ( empty( $created['contact']['contact_id'] ) ) {
			return new \WP_Error( 'zoho_contact_error', __( 'Zoho did not return a contact id.', 'blank-brand-theme' ) );
		}

		return (int) $created['contact']['contact_id'];
	}

	/**
	 * Create a Zoho Inventory sales order.
	 *
	 * @param array $payload Sales order payload (customer_id, line_items, …).
	 * @return array|\WP_Error
	 */
	public function create_sales_order( array $payload ) {
		return $this->request( 'POST', '/salesorders', [], $payload );
	}

	/**
	 * Look up an item by SKU (used by the stock reconciliation job).
	 *
	 * @param string $sku Item SKU.
	 * @return array|\WP_Error
	 */
	public function get_item_by_sku( string $sku ) {
		return $this->request( 'GET', '/items', [ 'sku' => $sku ] );
	}

	/**
	 * List items page by page (used by the stock reconciliation job).
	 *
	 * @param int $page    Page number, 1-indexed.
	 * @param int $per_page Items per page (Zoho's max is 200).
	 * @return array|\WP_Error
	 */
	public function list_items( int $page = 1, int $per_page = 200 ) {
		return $this->request(
			'GET',
			'/items',
			[
				'page'     => $page,
				'per_page' => $per_page,
			]
		);
	}

	/**
	 * Make an authenticated request against the Zoho Inventory API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path relative to /inventory/v1, e.g. "/contacts".
	 * @param array      $query  Query args (organization_id is added automatically).
	 * @param array|null $body   Request body, JSON-encoded when present.
	 * @return array|\WP_Error Decoded JSON response body.
	 */
	private function request( string $method, string $path, array $query = [], ?array $body = null ) {
		$token = $this->get_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$query['organization_id'] = Settings::get( 'organization_id' );

		$url = add_query_arg( $query, trailingslashit( $this->api_base() ) . ltrim( $path, '/' ) );

		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Zoho-oauthtoken ' . $token,
				'Content-Type'  => 'application/json',
			],
		];

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( sprintf( 'Request to %s failed: %s', $path, $response->get_error_message() ) );

			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = $data['message'] ?? sprintf( 'Unexpected HTTP %d from Zoho.', $code );

			$this->log( sprintf( '%s %s returned %d: %s', $method, $path, $code, $message ) );

			return new \WP_Error( 'zoho_api_error', $message, [ 'status' => $code ] );
		}

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Get a cached access token, refreshing it via the refresh token when
	 * missing or expired.
	 *
	 * @return string|\WP_Error
	 */
	private function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );

		if ( is_array( $cached ) && ! empty( $cached['access_token'] ) ) {
			return $cached['access_token'];
		}

		return $this->refresh_access_token();
	}

	/**
	 * Exchange the stored refresh token for a new access token and cache it.
	 *
	 * @return string|\WP_Error
	 */
	private function refresh_access_token() {
		$client_id     = Settings::get( 'client_id' );
		$client_secret = Settings::get( 'client_secret' );
		$refresh_token = Settings::get( 'refresh_token' );

		if ( ! $client_id || ! $client_secret || ! $refresh_token ) {
			return new \WP_Error( 'zoho_missing_credentials', __( 'Zoho credentials are not fully configured.', 'blank-brand-theme' ) );
		}

		$response = wp_remote_post(
			$this->accounts_domain() . '/oauth/v2/token',
			[
				'timeout' => 20,
				'body'    => [
					'refresh_token' => $refresh_token,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'grant_type'    => 'refresh_token',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'Token refresh failed: ' . $response->get_error_message() );

			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['access_token'] ) ) {
			$message = $data['error'] ?? __( 'Unknown error refreshing the Zoho access token.', 'blank-brand-theme' );

			$this->log( 'Token refresh error: ' . $message );

			return new \WP_Error( 'zoho_token_error', $message );
		}

		// Zoho returns the API domain to use alongside the token; cache it
		// so `api_base()` doesn't have to assume a domain pattern per data
		// centre (some, like Canada, don't follow the obvious pattern).
		set_transient(
			self::TOKEN_TRANSIENT,
			[
				'access_token' => $data['access_token'],
				'api_domain'   => $data['api_domain'] ?? '',
			],
			max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 )
		);

		return $data['access_token'];
	}

	/**
	 * Base URL for Inventory API calls, preferring the domain Zoho returned
	 * with the access token and falling back to the configured data centre.
	 *
	 * @return string
	 */
	private function api_base(): string {
		$cached = get_transient( self::TOKEN_TRANSIENT );

		if ( is_array( $cached ) && ! empty( $cached['api_domain'] ) ) {
			return $cached['api_domain'] . '/inventory/v1';
		}

		$dc = Settings::get( 'data_center', 'com' );

		return 'https://www.zohoapis.' . $dc . '/inventory/v1';
	}

	/**
	 * Accounts (OAuth) domain for the configured data centre.
	 *
	 * @return string
	 */
	private function accounts_domain(): string {
		$dc = Settings::get( 'data_center', 'com' );

		return 'https://' . ( Settings::DATA_CENTERS[ $dc ] ?? Settings::DATA_CENTERS['com'] );
	}

	/**
	 * Log a Zoho integration error via WooCommerce's logger.
	 *
	 * @param string $message Log message.
	 * @return void
	 */
	private function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, [ 'source' => 'zoho' ] );
		}
	}
}
