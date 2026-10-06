<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin preview of the frontend pages, accessed with a signed preview token.
 */
class FluidCheckout_Admin_Preview extends FluidCheckout {

	/**
	 * Capability required to use the preview, same as the settings page.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Query argument that carries the preview token.
	 */
	const TOKEN_QUERY_ARG = 'fc_preview';

	/**
	 * Preview token lifetime, in seconds.
	 */
	const TOKEN_LIFETIME = DAY_IN_SECONDS;

	/**
	 * Query argument that carries the preview mode.
	 */
	const MODE_QUERY_ARG = 'fc_preview_mode';

	/**
	 * Preview mode that renders pages for a guest.
	 */
	const MODE_GUEST = 'guest';

	/**
	 * Preview mode that renders pages for the dummy logged-in customer.
	 */
	const MODE_LOGGED_IN = 'logged_in';

	/**
	 * User ID of the dummy logged-in customer, high enough to never belong to a real user.
	 */
	const DUMMY_CUSTOMER_ID = 2147483647;

	/**
	 * Whether the current request is a preview request, cached after first check.
	 *
	 * @var bool|null
	 */
	private $is_preview_request = null;



	/**
	 * __construct function.
	 */
	public function __construct() {
		$this->hooks();
	}



	/**
	 * Initialize hooks.
	 */
	public function hooks() {
		// Preview requests
		add_action( 'init', array( $this, 'preview_request_hooks' ), 100 ); // Late so the hooks removed from other plugins are already registered

		// Preview session
		$this->preview_session_hooks();
	}

	/**
	 * Add or remove preview session hooks.
	 */
	public function preview_session_hooks() {
		// Bail if not a preview request
		if ( ! $this->is_preview_request() ) { return; }

		// Preview customer
		add_action( 'init', array( $this, 'switch_to_preview_customer' ), -100 ); // Before WooCommerce sets up the session and customer at priority `0`

		// Session
		add_filter( 'woocommerce_session_handler', array( $this, 'get_session_handler_class' ), 100 ); // Late to override session handlers from other plugins
		add_filter( 'woocommerce_persistent_cart_enabled', '__return_false', 10 );

		// Cookies
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false', 10 ); // Required to keep the visitor's own session cookies unchanged when the preview is on the same domain

		// Dummy customer meta
		add_filter( 'get_user_metadata', array( $this, 'maybe_get_dummy_customer_meta' ), 10, 4 );
		add_filter( 'add_user_metadata', array( $this, 'maybe_skip_dummy_customer_meta_write' ), 10, 2 );
		add_filter( 'update_user_metadata', array( $this, 'maybe_skip_dummy_customer_meta_write' ), 10, 2 );
		add_filter( 'delete_user_metadata', array( $this, 'maybe_skip_dummy_customer_meta_write' ), 10, 2 );
	}

	/**
	 * Add or remove preview request hooks.
	 */
	public function preview_request_hooks() {
		// Bail if not a preview request
		if ( ! $this->is_preview_request() ) { return; }

		// Response headers
		remove_action( 'template_redirect', 'wc_send_frame_options_header', 10 ); // Replaced by the `frame-ancestors` policy, which also allows the admin origin
		add_filter( 'wp_headers', array( $this, 'add_preview_headers' ), 100 ); // Late to override values set by other plugins

		// Admin bar
		add_filter( 'show_admin_bar', '__return_false', 10 );

		// Empty cart
		add_filter( 'woocommerce_checkout_redirect_empty_cart', '__return_false', 10 );
		add_filter( 'woocommerce_checkout_update_order_review_expired', '__return_false', 10 );

		// Coming soon mode
		add_filter( 'woocommerce_coming_soon_exclude', '__return_true', 10 );

		// AJAX
		add_filter( 'woocommerce_ajax_get_endpoint', array( $this, 'add_preview_args_to_ajax_endpoint' ), 10 );
	}



	/**
	 * Create a preview token for the current user.
	 * Self-contained so it also works when the frontend is on a different domain from the admin.
	 *
	 * @return  string  The preview token, or an empty string when the current user cannot use the preview.
	 */
	public function create_token() {
		// Bail if the current user cannot use the preview
		if ( ! current_user_can( self::CAPABILITY ) ) { return ''; }

		// Get current user
		$user = wp_get_current_user();

		// Define token payload
		$payload = $user->ID . '.' . ( time() + self::TOKEN_LIFETIME );

		return $payload . '.' . $this->get_token_signature( $payload, $user );
	}

	/**
	 * Get the signature of a preview token payload.
	 *
	 * @param   string   $payload  Token payload, as `{user_id}.{expiration}`.
	 * @param   WP_User  $user     Token user.
	 *
	 * @return  string  The token signature.
	 */
	public function get_token_signature( $payload, $user ) {
		// Include a password fragment, like WordPress auth cookies, so changing the password invalidates the token
		return hash_hmac( 'sha256', 'fc_preview|' . $payload . '|' . substr( $user->user_pass, -4 ), wp_salt() );
	}

	/**
	 * Get the user ID of a valid preview token.
	 *
	 * @param   string  $token  Preview token.
	 *
	 * @return  int  The token user ID, or `0` when the token is not valid.
	 */
	public function get_token_user_id( $token ) {
		// Bail if token format is not valid
		if ( ! is_string( $token ) || ! preg_match( '/^(?<payload>(?<user_id>\d+)\.(?<expiration>\d+))\.(?<signature>[a-f0-9]{64})$/D', $token, $token_parts ) ) { return 0; }

		// Bail if token has expired
		if ( (int) $token_parts[ 'expiration' ] < time() ) { return 0; }

		// Get token user
		$user = get_userdata( (int) $token_parts[ 'user_id' ] );

		// Bail if user does not exist
		if ( ! $user ) { return 0; }

		// Bail if signature does not match
		if ( ! hash_equals( $this->get_token_signature( $token_parts[ 'payload' ], $user ), $token_parts[ 'signature' ] ) ) { return 0; }

		// Bail if user cannot use the preview anymore
		if ( ! user_can( $user, self::CAPABILITY ) ) { return 0; }

		return $user->ID;
	}



	/**
	 * Get the preview token from the current request.
	 *
	 * @return  string  The preview token, or an empty string when the request has none.
	 */
	public function get_request_token() {
		// Bail if request has no preview token
		if ( ! isset( $_GET[ self::TOKEN_QUERY_ARG ] ) ) { return ''; }

		return sanitize_text_field( wp_unslash( $_GET[ self::TOKEN_QUERY_ARG ] ) );
	}

	/**
	 * Check whether the current request carries a valid preview token.
	 *
	 * @return  bool
	 */
	public function is_preview_request() {
		// Maybe validate the request token, only once per request
		if ( null === $this->is_preview_request ) {
			$this->is_preview_request = 0 < $this->get_token_user_id( $this->get_request_token() );
		}

		return $this->is_preview_request;
	}

	/**
	 * Get the preview mode from the current request.
	 *
	 * @return  string  `logged_in` for the dummy logged-in customer, or `guest` otherwise.
	 */
	public function get_preview_mode() {
		// Get requested mode
		$mode = isset( $_GET[ self::MODE_QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::MODE_QUERY_ARG ] ) ) : '';

		return self::MODE_LOGGED_IN === $mode ? self::MODE_LOGGED_IN : self::MODE_GUEST;
	}



	/**
	 * Get the admin origin, as `{scheme}://{host}` with the port when present.
	 *
	 * @return  string
	 */
	public function get_admin_origin() {
		// Get admin URL parts
		$admin_url_parts = wp_parse_url( admin_url() );

		// Bail if admin URL has no origin
		if ( empty( $admin_url_parts[ 'scheme' ] ) || empty( $admin_url_parts[ 'host' ] ) ) { return ''; }

		// Get admin origin
		$admin_origin = $admin_url_parts[ 'scheme' ] . '://' . $admin_url_parts[ 'host' ];

		// Maybe add the port
		if ( array_key_exists( 'port', $admin_url_parts ) ) {
			$admin_origin .= ':' . $admin_url_parts[ 'port' ];
		}

		return $admin_origin;
	}



	/**
	 * Add the response headers for preview requests.
	 *
	 * @param   array  $headers  Response headers.
	 */
	public function add_preview_headers( $headers ) {
		// Prevent caching, as preview pages must never be served to visitors
		WC_Cache_Helper::set_nocache_constants();
		$headers = array_merge( $headers, wp_get_nocache_headers() );

		// Keep preview pages out of search engines, and the token out of referrers sent to other domains
		$headers[ 'X-Robots-Tag' ] = 'noindex, nofollow';
		$headers[ 'Referrer-Policy' ] = 'same-origin';

		// Get directives of an existing content security policy, except frame ancestors which preview pages set
		$policy = array_key_exists( 'Content-Security-Policy', $headers ) ? $headers[ 'Content-Security-Policy' ] : '';
		$policy_directives = array_filter( array_map( 'trim', explode( ';', $policy ) ), function( $directive ) {
			return '' !== $directive && 0 !== stripos( $directive, 'frame-ancestors' );
		} );

		// Only allow the frontend and the admin to load preview pages in a frame, replacing frame options set by other plugins
		$policy_directives[] = "frame-ancestors 'self' " . $this->get_admin_origin();
		$headers[ 'Content-Security-Policy' ] = implode( '; ', $policy_directives );
		unset( $headers[ 'X-Frame-Options' ] );

		return $headers;
	}

	/**
	 * Add the preview token and mode to WooCommerce AJAX endpoint URLs.
	 *
	 * @param   string  $url  AJAX endpoint URL.
	 */
	public function add_preview_args_to_ajax_endpoint( $url ) {
		// Get query string separator
		$separator = false === strpos( $url, '?' ) ? '?' : '&';

		// Append the token directly, as `add_query_arg()` would encode the `%%endpoint%%` placeholder replaced by scripts
		$url .= $separator . self::TOKEN_QUERY_ARG . '=' . rawurlencode( $this->get_request_token() );

		// Maybe append the logged-in mode
		if ( self::MODE_LOGGED_IN === $this->get_preview_mode() ) {
			$url .= '&' . self::MODE_QUERY_ARG . '=' . self::MODE_LOGGED_IN;
		}

		return $url;
	}



	/**
	 * Switch the current user to the preview customer: the dummy customer in logged-in mode, or a guest.
	 */
	public function switch_to_preview_customer() {
		// Maybe use the dummy customer in logged-in mode
		if ( self::MODE_LOGGED_IN === $this->get_preview_mode() ) {
			// Cache the dummy user in memory only, so WordPress and WooCommerce load it like a real user
			wp_cache_add_non_persistent_groups( array( 'users' ) );
			wp_cache_set( self::DUMMY_CUSTOMER_ID, $this->get_dummy_customer_user_data(), 'users' );

			wp_set_current_user( self::DUMMY_CUSTOMER_ID );
		}
		// Otherwise, use a guest
		else {
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Get the WooCommerce session customer ID for the preview request.
	 *
	 * @return  string  The dummy customer user ID in logged-in mode, or a guest ID.
	 */
	public function get_preview_customer_id() {
		// Return the dummy customer ID in logged-in mode
		if ( self::MODE_LOGGED_IN === $this->get_preview_mode() ) {
			return (string) self::DUMMY_CUSTOMER_ID;
		}

		// Derive the guest ID from the token, so it stays the same across requests and nonces keep working
		return 't_' . substr( md5( $this->get_request_token() ), 0, 30 );
	}

	/**
	 * Get the WooCommerce session handler class for preview requests.
	 *
	 * @param   string  $class_name  Session handler class name.
	 */
	public function get_session_handler_class( $class_name ) {
		return 'FluidCheckout_Admin_Preview_Session_Handler';
	}



	/**
	 * Get the user data of the dummy logged-in customer, in the same shape as a row of the users table.
	 *
	 * @return  object
	 */
	public function get_dummy_customer_user_data() {
		return (object) array(
			'ID'                     => self::DUMMY_CUSTOMER_ID,
			'user_login'             => 'fc-preview-customer',
			'user_pass'              => '',
			'user_nicename'          => 'fc-preview-customer',
			'user_email'             => 'customer@example.com',
			'user_url'               => '',
			'user_registered'        => current_time( 'mysql', true ),
			'user_activation_key'    => '',
			'user_status'            => '0',
			'display_name'           => __( 'Preview customer', 'fluid-checkout' ),
		);
	}

	/**
	 * Get the meta values of the dummy logged-in customer.
	 *
	 * @return  array  Meta values by meta key.
	 */
	public function get_dummy_customer_meta() {
		global $wpdb;

		return array(
			$wpdb->get_blog_prefix() . 'capabilities' => array( 'customer' => true ),
		);
	}

	/**
	 * Get the meta values of the dummy logged-in customer without reading the database.
	 *
	 * @param   mixed   $value     Meta value, `null` to read it from the database.
	 * @param   int     $user_id   User ID.
	 * @param   string  $meta_key  Meta key, or an empty string for all meta values.
	 * @param   bool    $single    Whether a single value is requested.
	 */
	public function maybe_get_dummy_customer_meta( $value, $user_id, $meta_key, $single ) {
		// Bail if not the dummy customer
		if ( self::DUMMY_CUSTOMER_ID !== (int) $user_id ) { return $value; }

		// Get dummy customer meta
		$dummy_meta = $this->get_dummy_customer_meta();

		// Maybe return all meta values, each wrapped in an array like the database results
		if ( '' === $meta_key ) {
			return array_map( function( $meta_value ) { return array( $meta_value ); }, $dummy_meta );
		}

		// Maybe return the meta value, wrapped in an array as WordPress unwraps single values
		if ( array_key_exists( $meta_key, $dummy_meta ) ) {
			return array( $dummy_meta[ $meta_key ] );
		}

		// Return an empty value, as the dummy customer has no other meta
		return $single ? '' : array();
	}

	/**
	 * Skip writing meta values of the dummy logged-in customer to the database.
	 *
	 * @param   null|bool  $check    Whether to skip writing, `null` to write the meta value.
	 * @param   int        $user_id  User ID.
	 */
	public function maybe_skip_dummy_customer_meta_write( $check, $user_id ) {
		// Bail if not the dummy customer
		if ( self::DUMMY_CUSTOMER_ID !== (int) $user_id ) { return $check; }

		return true;
	}

}

FluidCheckout_Admin_Preview::instance();
