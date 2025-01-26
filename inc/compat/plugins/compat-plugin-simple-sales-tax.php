<?php
defined( 'ABSPATH' ) || exit;

/**
 * Compatibility with plugin: Simple Sales Tax (by TaxCloud).
 */
class FluidCheckout_SimpleSalesTax extends FluidCheckout {

	/**
	 * __construct function.
	 */
	public function __construct( ) {
		$this->hooks();
	}



	/**
	 * Initialize hooks.
	 */
	public function hooks() {
		// Optional fields
		add_filter( 'fc_hide_optional_fields_skip_list', array( $this, 'add_optional_fields_skip_fields' ), 10, 2 );
	}



	/**
	 * Adds fields to skip hiding behind a link button.
	 *
	 * @param  array  $skip_field_keys     Checkout field keys to skip from hiding behind a link button.
	 */
	public function add_optional_fields_skip_fields( $skip_field_keys ) {
		$skip_field_keys[] = 'certificate[PurchaserExemptionReasonOtherValue]';
		return $skip_field_keys;
	}

}

FluidCheckout_SimpleSalesTax::instance();
