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
		// Intentionally empty, token methods are called directly.
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

}

FluidCheckout_Admin_Preview::instance();
