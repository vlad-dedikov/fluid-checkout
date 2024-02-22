<?php
defined( 'ABSPATH' ) || exit;

/**
 * Compatibility with plugin: Breakdance (by Breakdance).
 */
class FluidCheckout_Breakdance extends FluidCheckout {

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
		// Very late hooks
		add_action( 'wp', array( $this, 'very_late_hooks' ), 100 );

		// Container class
		add_filter( 'fc_add_container_class', '__return_false', 10 );

		// Sticky elements
		add_filter( 'fc_checkout_progress_bar_attributes', array( $this, 'change_sticky_elements_relative_header' ), 20 );
		add_filter( 'fc_checkout_sidebar_attributes', array( $this, 'change_sticky_elements_relative_header' ), 20 );

		// Buttons
		add_filter( 'fc_apply_button_colors_styles', '__return_true', 10 );
		add_filter( 'fc_apply_button_design_styles', '__return_true', 10 );
	}

	/**
	 * Add or remove very late hooks.
	 */
	public function very_late_hooks() {
		// Template files
		// Needs to run at priority 11, right after the Breakdance filter
		add_filter( 'wc_get_template', array( $this, 'maybe_revert_wc_get_template' ), 11, 5 );

		// Checkout fields
		remove_filter( 'woocommerce_billing_fields', '\Breakdance\WooCommerce\reorderCheckoutFields', 10 );

		// Order summary
		remove_action( 'woocommerce_checkout_before_order_review_heading', '\Breakdance\WooCommerce\beforeOrderReview', 10 );
		remove_action( 'woocommerce_checkout_after_order_review', '\Breakdance\WooCommerce\afterOrderReview', 10 );
	}



	/**
	 * Maybe revert template file to use the original file as located by WooCommerce.
	 */
	public function revert_wc_get_template( $template, $template_name, $args, $template_path, $default_path ) {
		// Bail if the template file is not being loaded from Breakdance
		if ( strpos( $template, 'breakdance' ) === false ) { return $template; }

		// Bail if required class from Jetpack package is not available
		if ( ! class_exists( 'Automattic\Jetpack\Constants' ) ) { return $template; }

		// Revert to use the template file as located by WooCommerce
		// Copied from original WooCommerce `wc_get_template` function
		$cache_key = sanitize_key( implode( '-', array( 'template', $template_name, $template_path, $default_path, Automattic\Jetpack\Constants::get_constant( 'WC_VERSION' ) ) ) );
		$template  = (string) wp_cache_get( $cache_key, 'woocommerce' );

		if ( ! $template ) {
			$template = wc_locate_template( $template_name, $template_path, $default_path );

			// Don't cache the absolute path so that it can be shared between web servers with different paths.
			$cache_path = wc_tokenize_path( $template, wc_get_path_define_tokens() );

			wc_set_template_cache( $cache_key, $cache_path );
		} else {
			// Make sure that the absolute path to the template is resolved.
			$template = wc_untokenize_path( $template, wc_get_path_define_tokens() );
		}
		// END - Revert to use the template file as located by WooCommerce

		return $template;
	}

	/**
	 * Maybe revert template file to use the original file as located by WooCommerce.
	 */
	public function maybe_revert_wc_get_template( $template, $template_name, $args, $template_path, $default_path ) {
		// Bail if not on the checkout page
		if ( ! FluidCheckout_Steps::instance()->is_checkout_page_or_fragment() ) { return $template; }

		return $this->revert_wc_get_template( $template, $template_name, $args, $template_path, $default_path );
	}



	/**
	 * Change the sticky element relative ID.
	 *
	 * @param   array   $attributes    HTML element attributes.
	 */
	public function change_sticky_elements_relative_header( $attributes ) {
		// Bail if using distraction free header and footer
		if ( FluidCheckout_CheckoutPageTemplate::instance()->is_distraction_free_header_footer_checkout() ) { return $attributes; }

		$attributes['data-sticky-relative-to'] = '.bde-section.bde-header--sticky.is-sticky';

		return $attributes;
	}

}

FluidCheckout_Breakdance::instance();
