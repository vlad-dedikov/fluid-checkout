<?php
defined( 'ABSPATH' ) || exit;

/**
 * Compatibility with theme: OnePress (by FameThemes).
 */
class FluidCheckout_ThemeCompat_OnePress extends FluidCheckout {

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
		// CSS variables
		add_action( 'fc_css_variables', array( $this, 'add_css_variables' ), 20 );
	}



	/**
	 * Add CSS variables.
	 * 
	 * @param  array  $css_variables  The CSS variables key/value pairs.
	 */
	public function add_css_variables( $css_variables ) {
		$theme_color_accent = get_theme_mod( 'qt_color_accent', '#333333' );

		// Add CSS variables
		$new_css_variables = array(
			':root' => array(
				// Form field styles
				'--fluidcheckout--field--height' => '41px',
				'--fluidcheckout--field--padding-left' => '10px',
				'--fluidcheckout--field--background-color--accent' => $theme_color_accent,

				// Checkout validation styles
				'--fluidcheckout--validation-check--horizontal-spacing--password' => '32px',
			),
		);

		return FluidCheckout_DesignTemplates::instance()->merge_css_variables( $css_variables, $new_css_variables );
	}

}

FluidCheckout_ThemeCompat_OnePress::instance();
