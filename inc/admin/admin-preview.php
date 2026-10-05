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
		add_filter( 'woocommerce_ajax_get_endpoint', array( $this, 'add_token_to_ajax_endpoint' ), 10 );
	}



	/**
	 * Create a preview token for a user.
	 * Self-contained so it also works when the frontend is on a different domain from the admin.
	 *
	 * @param   int  $user_id  User ID. Defaults to the current user.
	 *
	 * @return  string  The preview token, or an empty string when the user cannot use the preview.
	 */
	public function create_token( $user_id = null ) {
		// Maybe use the current user
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		// Get user
		$user = get_userdata( $user_id );

		// Bail if user cannot use the preview
		if ( ! $user || ! user_can( $user, self::CAPABILITY ) ) { return ''; }

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

		// Only allow the frontend and the admin to load preview pages in a frame
		$headers[ 'Content-Security-Policy' ] = "frame-ancestors 'self' " . $this->get_admin_origin();

		return $headers;
	}

	/**
	 * Add the preview token to WooCommerce AJAX endpoint URLs.
	 *
	 * @param   string  $url  AJAX endpoint URL.
	 */
	public function add_token_to_ajax_endpoint( $url ) {
		// Get query string separator
		$separator = false === strpos( $url, '?' ) ? '?' : '&';

		// Append the token directly, as `add_query_arg()` would encode the `%%endpoint%%` placeholder replaced by scripts
		return $url . $separator . self::TOKEN_QUERY_ARG . '=' . rawurlencode( $this->get_request_token() );
	}

}

FluidCheckout_Admin_Preview::instance();
