<?php
defined( 'ABSPATH' ) || exit;

/**
 * Compatibility with plugin: SEUR Oficial (by SEUR Oficial).
 */
class FluidCheckout_Seur extends FluidCheckout {

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
		// Persisted data
		add_action( 'fc_set_parsed_posted_data', array( $this, 'maybe_set_terminals_field_session_values' ), 10 );
		add_action( 'wp', array( $this, 'maybe_set_terminals_field_from_session_to_postdata' ), 20 );

		// Shipping methods hooks
		add_filter( 'fc_shipping_method_option_markup', array( $this, 'change_shipping_method_options_markup_set_selected_value' ), 100, 5 );

		// Maybe set step as incomplete
		add_filter( 'fc_is_step_complete_shipping', array( $this, 'maybe_set_step_incomplete_shipping' ), 10 );

		// Add substep review text lines
		add_filter( 'fc_substep_shipping_method_text_lines', array( $this, 'add_substep_text_lines_shipping_method' ), 10 );
	}



	/**
	 * Maybe set session data for the terminals field.
	 *
	 * @param  array  $posted_data   Post data for all checkout fields.
	 */
	public function maybe_set_terminals_field_session_values( $posted_data ) {
		$field_key = 'seur_pickup';
		
		// Bail if field value was not posted
		if ( ! array_key_exists( $field_key, $posted_data ) ) { return $posted_data; }

		// Save field value to session, as it is needed for the plugin to recover its value
		WC()->session->set( $field_key, $posted_data[ $field_key ] );

		// Return unchanged posted data
		return $posted_data;
	}

	/**
	 * Maybe set `$_POST` data for the terminals field.
	 */
	public function maybe_set_terminals_field_from_session_to_postdata() {
		// Bail if not checkout page
		if ( ! FluidCheckout_Steps::instance()->is_checkout_page_or_fragment() ) { return; }

		// Bail if doing ajax
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) { return; }

		// Bail if post data is already set
		if ( array_key_exists( 'post_data', $_POST ) ) { return; }

		// Get location id
		$location_id_session = FluidCheckout_Steps::instance()->get_checkout_field_value_from_session( 'seur_pickup' );

		// Maybe set value to `$_POST` data
		if ( ! empty( $location_id_session ) ) {
			$post_data = array(
				'seur_pickup' => $location_id_session,
				'shipping_country' => WC()->checkout->get_value( 'shipping_country' ),
				'shipping_city' => WC()->checkout->get_value( 'shipping_city' ),
				'shipping_postcode' => WC()->checkout->get_value( 'shipping_postcode' ),
			);

			$_POST[ 'post_data' ] = http_build_query( $post_data );
		}
	}



	/**
	 * Change the shipping method options markup to set the selected value
	 * at the component initialization, rather than after adding the component to the DOM.
	 */
	public function change_shipping_method_options_markup_set_selected_value( $markup, $method, $package_index, $chosen_method, $first ) {
		// Get variables
		$custom_name_seur_2shop = get_option( 'seur_2shop_custom_name_field' );
		$custom_name_classic_2shop = get_option( 'seur_classic_int_2shop_custom_name_field' );

		// Get default values if custom names are not set
		if ( empty( $custom_name_seur_2shop ) ) { $custom_name_seur_2shop = 'SEUR 2SHOP'; }
		if ( empty( $custom_name_classic_2shop ) ) { $custom_name_classic_2shop = 'SEUR CLASSIC 2SHOP'; }

		// Bail if not SEUR shipping method
		if ( ! ( $method->label === $custom_name_seur_2shop || $method->label === $custom_name_classic_2shop ) ) { return $markup; }

		// Get location id
		$location_id = WC()->checkout->get_value( 'seur_pickup' );

		// Change script in the markup
		// to set location id option as selected
		$replace = "html += '<option value=\"' + (a + 1) + '\">' + (this.o.locations[a].title || ('#' + (a + 1))) + '</option>';";
		$with = "html += '<option value=\"' + (a + 1) + '\" ' + ( (a + 1) == '" . esc_html( $location_id ) . "' ? 'selected=\"selected\"' : '' ) + '>' + (this.o.locations[a].title || ('#' + (a + 1))) + '</option>';";
		$markup = str_replace( $replace, $with, $markup );

		return $markup;
	}



	/**
	 * Set the shipping step as incomplete.
	 *
	 * @param   bool  $is_step_complete  Whether the step is complete or not.
	 */
	public function maybe_set_step_incomplete_shipping( $is_step_complete ) {
		// Bail if step is already incomplete
		if ( ! $is_step_complete ) { return $is_step_complete; }

		// Get shipping packages
		$packages = WC()->shipping()->get_packages();

		// Iterate shipping packages
		foreach ( $packages as $i => $package ) {
			// Get selected shipping method
			$available_methods = $package['rates'];
			$chosen_method = isset( WC()->session->chosen_shipping_methods[ $i ] ) ? WC()->session->chosen_shipping_methods[ $i ] : '';
			$method = $available_methods && array_key_exists( $chosen_method, $available_methods ) ? $available_methods[ $chosen_method ] : null;

			// Skip if no shipping method selected for the package
			if ( empty( $method ) ) { continue; }

			// Get variables
			$custom_name_seur_2shop = get_option( 'seur_2shop_custom_name_field' );
			$custom_name_classic_2shop = get_option( 'seur_classic_int_2shop_custom_name_field' );

			// Get default values if custom names are not set
			if ( empty( $custom_name_seur_2shop ) ) { $custom_name_seur_2shop = 'SEUR 2SHOP'; }
			if ( empty( $custom_name_classic_2shop ) ) { $custom_name_classic_2shop = 'SEUR CLASSIC 2SHOP'; }

			// Skip if not SEUR shipping method for the package
			if ( ! ( $method->label === $custom_name_seur_2shop || $method->label === $custom_name_classic_2shop ) ) { continue; }

			// Get location id
			$location_id = WC()->checkout->get_value( 'seur_pickup' );

			// Maybe set step as incomplete
			if ( empty( $location_id ) || 'all' === $location_id ) {
				$is_step_complete = false;
				break;
			}
		}

		return $is_step_complete;
	}



	/**
	 * Add the shipping methods substep review text lines.
	 * 
	 * @param  array  $review_text_lines  The list of lines to show in the substep review text.
	 */
	public function add_substep_text_lines_shipping_method( $review_text_lines = array() ) {
		// Bail if not an array
		if ( ! is_array( $review_text_lines ) ) { return $review_text_lines; }

		// Bail if SEUR function not available
		if ( ! function_exists( 'seur_get_local_pickups' ) ) { return $review_text_lines; }

		// Get location id
		$location_id = WC()->checkout->get_value( 'seur_pickup' );

		// Bail if no location has been selected
		if ( empty( $location_id ) || 'all' === $location_id ) { return $review_text_lines; }

		// Get shipping country, or fallback to billing country
		$country = WC()->checkout->get_value( 'shipping_country' );
		if ( empty( $country ) ) {
			$country = WC()->checkout->get_value( 'billing_country' );
		}

		// Get shipping city, or fallback to billing city
		$city = WC()->checkout->get_value( 'shipping_city' );
		if ( empty( $city ) ) {
			$city = WC()->checkout->get_value( 'billing_city' );
		}

		// Get shipping postcode, or fallback to billing postcode
		$postcode = WC()->checkout->get_value( 'shipping_postcode' );
		if ( empty( $postcode ) ) {
			$postcode = WC()->checkout->get_value( 'billing_postcode' );
		}

		// Get available local pickup locations
		$pickup_locations = seur_get_local_pickups( $country, $city, $postcode );

		// Get selected location data
		// Location id and array index do not match, so we need to subtract 1
		// to get the correct location data as the location id is 1-based
		// and the pickup locations array index is 0-based
		$location_index = intval( $location_id ) - 1;
		$selected_location = array_key_exists( $location_index, $pickup_locations ) ? $pickup_locations[ $location_index ] : null;

		// Bail if no location data is available
		if ( empty( $selected_location ) ) { return $review_text_lines; }

		// Add terminal name as review text line
		$review_text_lines[] = '<strong>' . __( 'Pickup point:', 'fluid-checkout' ) . '</strong>';

		// Add location title
		$review_text_lines[] = $selected_location[ 'company' ];

		// Get address data
		$address_data = array(
			'address_1' => $selected_location[ 'address' ] . ' ' . $selected_location[ 'numvia' ],
			'city' => $selected_location[ 'city' ],
			'postcode' => $selected_location[ 'post_code' ],
		);

		// Add formatted address
		$formatted_address = WC()->countries->get_formatted_address( $address_data );
		$review_text_lines[] = $formatted_address;

		return $review_text_lines;
	}

}

FluidCheckout_Seur::instance();
