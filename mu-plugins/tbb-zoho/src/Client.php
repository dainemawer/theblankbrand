<?php
/**
 * Zoho Inventory API client.
 *
 * Handles OAuth2 access-token refresh/caching and thin wrapper methods for
 * the handful of Zoho Inventory endpoints this integration needs. Not a
 * Module — instantiated directly by {@see OrderSync}, {@see StockWebhook}
 * and {@see StockSync}.
 *
 * @package TBB\Zoho
 */

namespace TBB\Zoho;

/**
 * Zoho Inventory API client.
 *
 * @package TBB\Zoho
 */
class Client {

	const TOKEN_TRANSIENT = 'tbb_zoho_access_token';

	/**
	 * Remembers that the last token refresh failed, so a burst of requests does
	 * not each call Zoho's token endpoint again. Zoho limits how many access
	 * tokens one refresh token can mint in a window, and hammering it during an
	 * outage only makes the outage last longer.
	 *
	 * @var string
	 */
	const TOKEN_FAILURE_TRANSIENT = 'tbb_zoho_token_failure';

	/**
	 * Seconds to wait before trying a failed token refresh again.
	 *
	 * @var int
	 */
	const TOKEN_BACKOFF = 60;

	/**
	 * The only writes this integration is permitted to make, as
	 * `METHOD => [ first path segment, … ]`.
	 *
	 * Zoho is the source of truth for stock and pricing, and that direction is
	 * enforced here rather than left to convention: any request that is not a
	 * GET, and is not listed below, is refused before a token is even minted.
	 * Adding a write means editing this list, which makes it visible in review
	 * instead of arriving quietly inside a new method.
	 *
	 * Deliberately a constant, not a filter — a filter would let the boundary
	 * be widened from anywhere in the codebase, which is the thing it exists
	 * to prevent.
	 *
	 * @var array<string,string[]>
	 */
	const WRITE_ALLOWLIST = [
		'POST' => [ 'contacts', 'salesorders', 'packages', 'shipmentorders' ],
	];

	/**
	 * Where dry-run calls are recorded for inspection.
	 *
	 * @var string
	 */
	const DRY_RUN_LOG = 'tbb_zoho_dry_run_log';

	/**
	 * How many dry-run calls to keep.
	 *
	 * @var int
	 */
	const DRY_RUN_LOG_LIMIT = 50;

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
	 * Find a Zoho customer by email, creating one if none exists.
	 *
	 * The email has to live on a contact person. The first version sent it as a
	 * top-level `email` on the contact, which Zoho silently ignored: the contact
	 * was created with no email at all, so looking customers up by email never
	 * found them again and every later order tried to create them afresh. Dry
	 * run could not have shown this, because it sends nothing.
	 *
	 * The lookup is limited to customers. An email can also belong to a vendor,
	 * and a sales order raised against one would be refused.
	 *
	 * @param string $email Contact email.
	 * @param string $name  Contact display name, used only if a new contact is created.
	 * @return int|\WP_Error Zoho contact_id.
	 */
	public function get_or_create_contact_id( string $email, string $name ) {
		$found = $this->request(
			'GET',
			'/contacts',
			[
				'email'        => $email,
				'contact_type' => 'customer',
			]
		);

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		if ( ! empty( $found['contacts'][0]['contact_id'] ) ) {
			return (int) $found['contacts'][0]['contact_id'];
		}

		$name = '' !== trim( $name ) ? trim( $name ) : $email;

		$created = $this->create_contact( $name, $email );

		// Zoho can refuse a second customer with the same name, and two people
		// called the same thing is ordinary. The email is what tells them apart,
		// so retry once with it appended before treating this as a failure.
		if ( is_wp_error( $created ) && preg_match( '/already exist/i', $created->get_error_message() ) ) {
			$created = $this->create_contact( sprintf( '%s (%s)', $name, $email ), $email, $name );
		}

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		if ( empty( $created['contact']['contact_id'] ) ) {
			return new \WP_Error( 'zoho_contact_error', __( 'Zoho did not return a contact id.', 'blank-brand-theme' ) );
		}

		return (int) $created['contact']['contact_id'];
	}

	/**
	 * Create a Zoho customer with its email on a primary contact person.
	 *
	 * @param string      $contact_name Name the contact is filed under.
	 * @param string      $email        Customer email.
	 * @param string|null $person_name  Name for the contact person, when it differs from the contact name.
	 * @return array|\WP_Error
	 */
	private function create_contact( string $contact_name, string $email, ?string $person_name = null ) {
		$parts = preg_split( '/\s+/', trim( $person_name ?? $contact_name ), 2 );

		return $this->request(
			'POST',
			'/contacts',
			[],
			[
				'contact_name'    => $contact_name,
				'contact_type'    => 'customer',
				'contact_persons' => [
					[
						// Zoho requires a first name on a contact person.
						'first_name'         => '' !== $parts[0] ? $parts[0] : $email,
						'last_name'          => $parts[1] ?? '',
						'email'              => $email,
						'is_primary_contact' => true,
					],
				],
			]
		);
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
	 * Move a sales order out of draft and into confirmed.
	 *
	 * Zoho creates sales orders as drafts, and a draft reserves nothing. The
	 * website's stock figures follow `available_for_sale`, which only drops
	 * once an order is confirmed — so without this step an order would be
	 * recorded in Zoho while the site carried on selling the same units.
	 *
	 * @param string $salesorder_id Zoho sales order id.
	 * @return array|\WP_Error
	 */
	public function confirm_sales_order( string $salesorder_id ) {
		return $this->request( 'POST', '/salesorders/' . rawurlencode( $salesorder_id ) . '/status/confirmed' );
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
	 * List the organisation's price books.
	 *
	 * @return array|\WP_Error
	 */
	public function list_pricebooks() {
		return $this->request( 'GET', '/pricebooks' );
	}

	/**
	 * Fetch one price book, including its per-item rates.
	 *
	 * @param string $pricebook_id Zoho price book id.
	 * @return array|\WP_Error
	 */
	public function get_pricebook( string $pricebook_id ) {
		return $this->request( 'GET', '/pricebooks/' . rawurlencode( $pricebook_id ) );
	}

	/**
	 * Map item id => rate for a named price book.
	 *
	 * Zoho stores book names as typed, and this organisation's retail book is
	 * literally `"Retail "` — trailing space and all — so names are compared
	 * trimmed. An empty or implausibly small book is treated as a failure
	 * rather than as "these items have no price", which would otherwise let a
	 * bad response wipe the catalogue's pricing.
	 *
	 * @param string $name    Price book name, compared case-insensitively and trimmed.
	 * @param int    $minimum Reject the response if it holds fewer entries than this.
	 * @return array<string,float>|\WP_Error
	 */
	public function get_pricebook_rates( string $name, int $minimum = 100 ) {
		$books = $this->list_pricebooks();

		if ( is_wp_error( $books ) ) {
			return $books;
		}

		$match = null;

		foreach ( $books['pricebooks'] ?? [] as $book ) {
			if ( 0 === strcasecmp( trim( (string) $book['name'] ), trim( $name ) ) ) {
				$match = $book['pricebook_id'];
				break;
			}
		}

		if ( ! $match ) {
			/* translators: %s: price book name. */
			return new \WP_Error( 'zoho_pricebook_missing', sprintf( __( 'No Zoho price book named "%s".', 'blank-brand-theme' ), $name ) );
		}

		$book = $this->get_pricebook( $match );

		if ( is_wp_error( $book ) ) {
			return $book;
		}

		$rates = [];

		foreach ( $book['pricebook']['pricebook_items'] ?? [] as $item ) {
			$rates[ $item['item_id'] ] = (float) $item['pricebook_rate'];
		}

		if ( count( $rates ) < $minimum ) {
			return new \WP_Error(
				'zoho_pricebook_short',
				sprintf(
					/* translators: 1: price book name, 2: entries returned, 3: minimum expected. */
					__( 'Zoho price book "%1$s" returned %2$d entries, expected at least %3$d.', 'blank-brand-theme' ),
					$name,
					count( $rates ),
					$minimum
				)
			);
		}

		return $rates;
	}

	/**
	 * Make an authenticated request against the Zoho Inventory API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path relative to /inventory/v1, e.g. "/contacts".
	 * @param array      $query  Query args (organization_id is added automatically).
	 * @param array|null $body   Request body, JSON-encoded when present.
	 * @param bool       $retried Whether this is already the retry after a 401. Internal.
	 * @return array|\WP_Error Decoded JSON response body.
	 */
	private function request( string $method, string $path, array $query = [], ?array $body = null, bool $retried = false ) {
		if ( ! $this->is_permitted( $method, $path ) ) {
			$message = sprintf( 'Blocked disallowed Zoho write: %s %s', strtoupper( $method ), $path );

			$this->log( $message );

			return new \WP_Error( 'zoho_write_blocked', $message );
		}

		// Dry run intercepts here, below the allow-list and above the token, so
		// nothing leaves the site and every write path is covered — including
		// any added later, which is the point of doing it in the client rather
		// than in each caller.
		if ( 'GET' !== strtoupper( $method ) && self::is_dry_run() ) {
			return $this->simulate( $method, $path, $query, $body );
		}

		$token = $this->get_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$query['organization_id'] = Settings::get( 'organization_id' );

		// Not add_query_arg(): it does not encode values. A customer email such as
		// "name+shop@gmail.com" went out with a bare "+", which Zoho reads as a
		// space and rejects ("Invalid value passed for email"), so the contact
		// lookup failed, every retry failed with it, and the order never reached
		// Zoho. An "&" inside a value would likewise have split the query and let
		// a customer-supplied string add parameters of its own.
		$url = trailingslashit( $this->api_base() ) . ltrim( $path, '/' ) . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );

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

		// A rejected token is not the end of the road: access tokens can be
		// revoked or invalidated before they expire (regenerating the refresh
		// token does it), and the cached one would otherwise keep failing every
		// call for the rest of its life. Drop it and try once with a fresh one.
		// A 401 means Zoho refused the request before acting on it, so repeating
		// a write is safe.
		if ( 401 === $code && ! $retried ) {
			delete_transient( self::TOKEN_TRANSIENT );
			$this->log( sprintf( '%s %s was refused with 401; refreshing the access token and retrying once.', $method, $path ) );

			return $this->request( $method, $path, $query, $body, true );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = $data['message'] ?? sprintf( 'Unexpected HTTP %d from Zoho.', $code );

			$this->log( sprintf( '%s %s returned %d: %s', $method, $path, $code, $message ) );

			return new \WP_Error( 'zoho_api_error', $message, [ 'status' => $code ] );
		}

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Whether writes are being simulated rather than sent.
	 *
	 * @return bool
	 */
	public static function is_dry_run(): bool {
		return 'yes' === Settings::get( 'dry_run' );
	}

	/**
	 * Record a write that would have been sent, and answer with a response
	 * shaped like Zoho's so the caller carries on through the whole sequence.
	 *
	 * Returning a plausible id matters: a sales order's id is what a package
	 * is created against, and a package's id is what a shipment needs. Failing
	 * at the first call would only ever show the first call.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Request path.
	 * @param array      $query  Query args.
	 * @param array|null $body   Request body.
	 * @return array
	 */
	private function simulate( string $method, string $path, array $query, ?array $body ): array {
		$entity = strtok( ltrim( $path, '/' ), '/?' );

		// Zoho ids are long numeric strings, and callers cast them to int.
		// A simulated id has to be numeric or the simulation misrepresents the
		// flow — the first version of this returned a prefixed string and the
		// sales order came out with customer_id 0.
		$id = '9' . substr( str_pad( (string) abs( crc32( $entity . wp_json_encode( $body ) . microtime() ) ), 18, '0', STR_PAD_LEFT ), 0, 18 );

		$this->record_dry_run( $method, $path, $query, $body, $id );

		$shapes = [
			'contacts'       => [ 'contact' => [ 'contact_id' => $id ] ],
			'salesorders'    => [
				'salesorder' => [
					'salesorder_id'     => $id,
					'salesorder_number' => 'SO-DRY-' . substr( $id, -6 ),
					// Packages are built from sales-order line ids, so the
					// simulation has to hand them back or the next step has
					// nothing to pack.
					'line_items'        => array_map(
						static function ( $line, $index ) use ( $id ) {
							return [
								'line_item_id' => $id . ( $index + 1 ),
								'item_id'      => $line['item_id'] ?? '',
								'quantity'     => $line['quantity'] ?? 0,
							];
						},
						$body['line_items'] ?? [],
						array_keys( $body['line_items'] ?? [] )
					),
				],
			],
			'packages'       => [
				'package' => [
					'package_id'     => $id,
					'package_number' => 'PKG-DRY-' . substr( $id, -6 ),
				],
			],
			'shipmentorders' => [
				'shipmentorder' => [
					'shipment_id'     => $id,
					'shipment_number' => 'SHP-DRY-' . substr( $id, -6 ),
				],
			],
		];

		return array_merge(
			[
				'code'        => 0,
				'message'     => 'dry run — not sent to Zoho',
				'tbb_dry_run' => true,
			],
			$shapes[ $entity ] ?? []
		);
	}

	/**
	 * Store a dry-run call so it can be read back and shown.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Request path.
	 * @param array      $query  Query args.
	 * @param array|null $body   Request body.
	 * @param string     $id     Simulated id returned to the caller.
	 * @return void
	 */
	private function record_dry_run( string $method, string $path, array $query, ?array $body, string $id ): void {
		$entry = [
			'at'     => time(),
			'method' => strtoupper( $method ),
			'path'   => $path,
			'query'  => array_diff_key( $query, [ 'organization_id' => '' ] ),
			'body'   => $body,
			'id'     => $id,
		];

		$log = get_option( self::DRY_RUN_LOG, [] );
		$log = is_array( $log ) ? $log : [];

		array_unshift( $log, $entry );

		update_option( self::DRY_RUN_LOG, array_slice( $log, 0, self::DRY_RUN_LOG_LIMIT ), false );

		$this->log_info(
			sprintf(
				'DRY RUN — would have sent %s %s%s',
				$entry['method'],
				$path,
				$entry['query'] ? ' ?' . http_build_query( $entry['query'] ) : ''
			)
		);
	}

	/**
	 * Log an informational line via WooCommerce's logger.
	 *
	 * @param string $message Log message.
	 * @return void
	 */
	private function log_info( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( $message, [ 'source' => 'zoho' ] );
		}
	}

	/**
	 * Whether a request is allowed out of this client.
	 *
	 * Reads are always permitted. Anything else must match
	 * {@see Client::WRITE_ALLOWLIST} on both method and the path's first
	 * segment, so `/salesorders` and `/salesorders/123` are treated alike
	 * while `/items` stays closed to writes.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path relative to /inventory/v1.
	 * @return bool
	 */
	private function is_permitted( string $method, string $path ): bool {
		$method = strtoupper( $method );

		if ( 'GET' === $method ) {
			return true;
		}

		$segment = strtok( ltrim( $path, '/' ), '/?' );

		return in_array( $segment, self::WRITE_ALLOWLIST[ $method ] ?? [], true );
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
		$backoff = get_transient( self::TOKEN_FAILURE_TRANSIENT );

		if ( $backoff ) {
			return new \WP_Error( 'zoho_token_backoff', (string) $backoff );
		}

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
			set_transient( self::TOKEN_FAILURE_TRANSIENT, $response->get_error_message(), self::TOKEN_BACKOFF );

			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['access_token'] ) ) {
			$message = $data['error'] ?? __( 'Unknown error refreshing the Zoho access token.', 'blank-brand-theme' );

			$this->log( 'Token refresh error: ' . $message );
			set_transient( self::TOKEN_FAILURE_TRANSIENT, $message, self::TOKEN_BACKOFF );

			return new \WP_Error( 'zoho_token_error', $message );
		}

		// Zoho returns the API domain to use alongside the token; cache it
		// so `api_base()` doesn't have to assume a domain pattern per data
		// centre (some, like Canada, don't follow the obvious pattern).
		delete_transient( self::TOKEN_FAILURE_TRANSIENT );

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
