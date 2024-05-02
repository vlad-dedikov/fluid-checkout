<?php
defined( 'ABSPATH' ) || exit;

/**
 * Compatibility with plugin: Omniva shipping (by Omniva).
 */
class FluidCheckout_OmnivaWooCommerce extends FluidCheckout {

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
		// Register assets
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );

		// Enqueue assets
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ), 10 );

		// JS settings object
		add_filter( 'fc_js_settings', array( $this, 'add_js_settings' ), 10 );

		// Checkout validation settings
		add_filter( 'fc_checkout_validation_script_settings', array( $this, 'change_js_settings_checkout_validation' ), 10 );

		// Persisted data
		add_action( 'fc_set_parsed_posted_data', array( $this, 'maybe_set_terminals_field_session_values' ), 10 );

		// Maybe set step as incomplete
		add_filter( 'fc_is_step_complete_shipping', array( $this, 'maybe_set_step_incomplete_shipping' ), 10 );

		// Add substep review text lines
		add_filter( 'fc_substep_shipping_method_text_lines', array( $this, 'add_substep_text_lines_shipping_method' ), 10 );
	}



	/**
	 * Register assets.
	 */
	public function register_assets() {
		// Add validation script
		wp_register_script( 'fc-checkout-validation-omniva', FluidCheckout_Enqueue::instance()->get_script_url( 'js/compat/plugins/omniva-woocommerce/checkout-validation-omniva' ), array( 'jquery', 'fc-utils', 'fc-checkout-validation' ), NULL, true );
		wp_add_inline_script( 'fc-checkout-validation-omniva', 'window.addEventListener("load",function(){CheckoutValidationOmniva.init(fcSettings.checkoutValidationOmniva);})' );
	}

	/**
	 * Enqueue scripts.
	 */
	public function enqueue_assets() {
		// Scripts
		wp_enqueue_script( 'fc-checkout-validation-omniva' );
	}

	/**
	 * Maybe enqueue assets.
	 */
	public function maybe_enqueue_assets() {
		// Bail if not at checkout
		if( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || is_checkout_pay_page() ) { return; }

		$this->enqueue_assets();
	}



	/**
	 * Add settings to the plugin settings JS object.
	 *
	 * @param   array  $settings  JS settings object of the plugin.
	 */
	public function add_js_settings( $settings ) {
		// Add validation settings
		$settings[ 'checkoutValidationOmniva' ] = array(
			'validationMessages'  => array(
				'pickup_point_not_selected' => __( 'Selecting a pickup point is required before proceeding.', 'fluid-checkout' ),
			),
		);

		return $settings;
	}


	/**
	 * Add settings to the plugin settings JS object for the checkout validation.
	 *
	 * @param   array  $settings  JS settings object of the plugin.
	 */
	public function change_js_settings_checkout_validation( $settings ) {
		// Get current values
		$current_validate_field_selector = array_key_exists( 'validateFieldsSelector', $settings ) ? $settings[ 'validateFieldsSelector' ] : '';
		$current_reference_node_selector = array_key_exists( 'referenceNodeSelector', $settings ) ? $settings[ 'referenceNodeSelector' ] : '';
		$current_always_validate_selector = array_key_exists( 'alwaysValidateFieldsSelector', $settings ) ? $settings[ 'alwaysValidateFieldsSelector' ] : '';

		// Prepend new values to existing settings
		$settings[ 'validateFieldsSelector' ] = 'select[name="omnivalt_terminal"]' . ( ! empty( $current_validate_field_selector ) ? ', ' : '' ) . $current_validate_field_selector;
		$settings[ 'referenceNodeSelector' ] = 'select[name="omnivalt_terminal"]' . ( ! empty( $current_reference_node_selector ) ? ', ' : '' ) . $current_reference_node_selector;
		$settings[ 'alwaysValidateFieldsSelector' ] = 'select[name="omnivalt_terminal"]' . ( ! empty( $current_always_validate_selector ) ? ', ' : '' ) . $current_always_validate_selector;

		return $settings;
	}



	/**
	 * Maybe set session data for the terminals field.
	 *
	 * @param  array  $posted_data   Post data for all checkout fields.
	 */
	public function maybe_set_terminals_field_session_values( $posted_data ) {
		$field_key = 'omnivalt_terminal';
		$session_field_key = 'omnivalt_terminal_id';

		// Bail if field value was not posted
		if ( ! array_key_exists( $field_key, $posted_data ) ) { return $posted_data; }

		// Save field value to session, as it is needed for the plugin to recover its value
		WC()->session->set( $session_field_key, $posted_data[ $field_key ] );

		// Return unchanged posted data
		return $posted_data;
	}



	/**
	 * Get whether the shipping method is a local pickup method from this plugin.
	 * 
	 * @param  string  $method_id   The shipping method id.
	 */
	public function is_shipping_method_local_pickup( $method_id ) {
		// Define local pickup shipping method ids
		$local_pickup_methods = array(
			'omnivalt_pt',
			'omnivalt_pn',
			'omnivalt_ps',
		);

		// Check if shipping method is local pickup
		if ( in_array( $method_id, $local_pickup_methods ) ) {
			return true;
		}

		// Otherwise, not a local pickup shipping method
		return false;
	}

	/**
	 * Check if the shipping method requires pickup location selection by the customer.
	 * 
	 * @param  string  $method_id   The shipping method id.
	 */
	public function shipping_method_needs_pickup_location( $method_id ) {
		// Define local pickup shipping method ids
		$local_pickup_methods = array(
			'omnivalt_pt',
			'omnivalt_ps',
		);

		// Check if shipping method is local pickup
		if ( in_array( $method_id, $local_pickup_methods ) ) {
			return true;
		}

		// Otherwise, not a local pickup shipping method
		return false;
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

			// Skip if no shipping method selected for the package
			if ( empty( $chosen_method ) ) { continue; }

			// Skip if not local pickup shipping method
			if ( ! $this->shipping_method_needs_pickup_location( $chosen_method ) ) { continue; }

			// Get location id
			$selected_terminal_id = WC()->session->get( 'omnivalt_terminal_id' );

			// Maybe set step as incomplete
			if ( empty( $selected_terminal_id ) ) {
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

		// Bail if plugin classes or functions not available
		if ( ! class_exists( 'OmnivaLt_Terminals' ) ) { return $review_text_lines; }

		// Get shipping packages
		$packages = WC()->shipping()->get_packages();

		// Check whether target shipping method is selected
		// Iterate shipping packages
		$has_target_shipping_method = false;
		foreach ( $packages as $i => $package ) {
			// Get selected shipping method
			$available_methods = $package['rates'];
			$chosen_method = isset( WC()->session->chosen_shipping_methods[ $i ] ) ? WC()->session->chosen_shipping_methods[ $i ] : '';

			// Check if shipping method is local pickup
			if ( $this->is_shipping_method_local_pickup( $chosen_method ) && $this->shipping_method_needs_pickup_location( $chosen_method ) ) {
				$has_target_shipping_method = true;
				break;
			}
		}

		// Bail if target shipping method is not selected
		if ( ! $has_target_shipping_method ) { return $review_text_lines; }

		// Get location id
		$selected_terminal_id = WC()->session->get( 'omnivalt_terminal_id' );

		// Maybe set add pickup point address as not selected
		// to the review text lines, then bail
		if ( empty( $selected_terminal_id ) ) {
			$review_text_lines[] = '<em>' . __( 'Pickup point not selected yet.', 'fluid-checkout' ) . '</em>';
			return $review_text_lines;
		}

		// Get terminal data, with country.
		$selected_terminal = OmnivaLt_Terminals::get_terminal_address( $selected_terminal_id, true );

		// Maybe set add pickup point address as not selected
		// to the review text lines, then bail
		if ( empty( $selected_terminal ) ) {
			$review_text_lines[] = '<em>' . __( 'Pickup point not selected yet.', 'fluid-checkout' ) . '</em>';
			return $review_text_lines;
		}

		// Add terminal name as review text line
		$review_text_lines[] = '<strong>' . __( 'Pickup point:', 'fluid-checkout' ) . '</strong>';
		$review_text_lines[] = $selected_terminal;

		return $review_text_lines;
	}

}

FluidCheckout_OmnivaWooCommerce::instance();
