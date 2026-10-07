<?php
defined( 'ABSPATH' ) || exit;

/**
 * Dummy customer and product data for the admin preview, which exists in memory only.
 */
class FluidCheckout_Admin_Preview_Dummy_Data extends FluidCheckout {

	/**
	 * User ID of the dummy logged-in customer, high enough to never belong to a real user.
	 */
	const CUSTOMER_ID = 2147483647;

	/**
	 * Email address of the dummy customer, on a domain reserved for examples.
	 */
	const CUSTOMER_EMAIL = 'john@example.com';

	/**
	 * First product ID used for dummy products, high enough to never belong to a real product.
	 */
	const PRODUCT_ID_START = 2147483600;

	/**
	 * Dummy customer address, cached after first retrieval.
	 *
	 * @var array|null
	 */
	private $customer_address = null;

	/**
	 * Dummy customer meta values by meta key, cached after first retrieval.
	 *
	 * @var array|null
	 */
	private $customer_meta = null;

	/**
	 * Dummy product properties by dummy product ID, cached after first retrieval.
	 *
	 * @var array|null
	 */
	private $products = null;



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
		// Intentionally empty, the admin preview calls the dummy data getters directly.
	}



	/**
	 * Get the user data of the dummy logged-in customer, in the same shape as a row of the users table.
	 *
	 * @return  object
	 */
	public function get_customer_user_data() {
		// Get dummy customer name
		$name = $this->get_customer_name();

		return (object) array(
			'ID'                     => self::CUSTOMER_ID,
			'user_login'             => 'fc-preview-customer',
			'user_pass'              => '',
			'user_nicename'          => 'fc-preview-customer',
			'user_email'             => self::CUSTOMER_EMAIL,
			'user_url'               => '',
			'user_registered'        => current_time( 'mysql', true ),
			'user_activation_key'    => '',
			'user_status'            => '0',
			'display_name'           => $name[ 'first_name' ] . ' ' . $name[ 'last_name' ],
		);
	}

	/**
	 * Get the first and last name of the dummy customer.
	 *
	 * @return  array  First and last name by field key.
	 */
	public function get_customer_name() {
		return array(
			'first_name'   => _x( 'John', 'Dummy customer first name in the admin preview', 'fluid-checkout' ),
			'last_name'    => _x( 'Doe', 'Dummy customer last name in the admin preview', 'fluid-checkout' ),
		);
	}

	/**
	 * Get the company name of the dummy customer, for when a company is needed.
	 *
	 * @return  string
	 */
	public function get_customer_company() {
		return _x( 'Example Company', 'Dummy customer company in the admin preview', 'fluid-checkout' );
	}

	/**
	 * Get the address of the dummy customer: the store address, when the store sells and ships to its own country.
	 *
	 * @return  array  Address values by field key, without the `billing_` or `shipping_` prefix.
	 */
	public function get_customer_address() {
		// Return cached address
		if ( null !== $this->customer_address ) { return $this->customer_address; }

		// Get countries the store sells and ships to
		$countries = WC()->countries;
		$allowed_countries = $countries->get_allowed_countries();
		$shipping_countries = $countries->get_shipping_countries();

		// Maybe limit to countries the store ships to, as the list is empty when shipping is disabled
		if ( ! empty( $shipping_countries ) ) {
			$allowed_countries = array_intersect_key( $allowed_countries, $shipping_countries );
		}

		// Get company name, only when the company field is required, as the dummy customer is otherwise a private customer
		$company = 'required' === FluidCheckout_Settings::instance()->get_option( 'woocommerce_checkout_company_field' ) ? $this->get_customer_company() : '';

		// Define dummy address with the store address
		$address = array_merge( $this->get_customer_name(), array(
			'company'      => $company,
			'address_1'    => $countries->get_base_address(),
			'address_2'    => $countries->get_base_address_2(),
			'city'         => $countries->get_base_city(),
			'state'        => $countries->get_base_state(),
			'postcode'     => $countries->get_base_postcode(),
			'country'      => $countries->get_base_country(),
			'email'        => self::CUSTOMER_EMAIL,
			'phone'        => '555-0100',
		) );

		// Maybe use the first allowed country with a generic address, as the store address would not be accepted
		// The postcode stays empty, as its format depends on the country
		if ( ! empty( $allowed_countries ) && ! array_key_exists( $address[ 'country' ], $allowed_countries ) ) {
			// Get fallback country and its states
			$country = key( $allowed_countries );
			$states = $countries->get_states( $country );

			$address = array_merge( $address, array(
				'address_1'    => _x( '123 Main Street', 'Dummy customer street address in the admin preview', 'fluid-checkout' ),
				'address_2'    => '',
				'city'         => _x( 'Anytown', 'Dummy customer city in the admin preview', 'fluid-checkout' ),
				'state'        => is_array( $states ) && ! empty( $states ) ? key( $states ) : '',
				'postcode'     => '',
				'country'      => $country,
			) );
		}

		// Cache the address before filtering, as filter callbacks may read it again
		$this->customer_address = $address;

		/**
		 * Filter the address of the dummy customer in the admin preview, for example to fill required fields added by other plugins.
		 */
		$this->customer_address = apply_filters( 'fc_admin_preview_dummy_customer_address', $address );

		return $this->customer_address;
	}

	/**
	 * Get the billing and shipping fields of the dummy customer, as saved on a customer account.
	 *
	 * @return  array  Field values by field key, with the `billing_` or `shipping_` prefix.
	 */
	public function get_customer_fields() {
		// Define fields
		$fields = array();

		// Iterate address fields
		foreach ( $this->get_customer_address() as $field_key => $value ) {
			$fields[ 'billing_' . $field_key ] = $value;

			// Skip fields that WooCommerce shipping addresses do not have, such as the email or fields added by other plugins
			if ( ! method_exists( 'WC_Customer', 'set_shipping_' . $field_key ) ) { continue; }

			$fields[ 'shipping_' . $field_key ] = $value;
		}

		return $fields;
	}

	/**
	 * Get the meta values of the dummy logged-in customer: its role, name and saved addresses.
	 *
	 * @return  array  Meta values by meta key.
	 */
	public function get_customer_meta() {
		global $wpdb;

		// Return cached meta values
		if ( null !== $this->customer_meta ) { return $this->customer_meta; }

		// Define role meta value, read when the dummy customer becomes the current user
		$meta = array( $wpdb->get_blog_prefix() . 'capabilities' => array( 'customer' => true ) );

		// Bail with the role only until WooCommerce sets up its countries at `init`, as the saved addresses need them
		if ( ! WC()->countries instanceof WC_Countries ) { return $meta; }

		// Cache the role only while getting the addresses, as country filters from other plugins may read this meta again
		$this->customer_meta = $meta;
		$this->customer_meta = array_merge( $meta, $this->get_customer_name(), $this->get_customer_fields() );

		return $this->customer_meta;
	}



	/**
	 * Get the dummy products used to top up the preview cart.
	 *
	 * @return  array  Product properties by dummy product ID.
	 */
	public function get_products() {
		// Maybe build the dummy products list, only once per request
		if ( null === $this->products ) {
			// Cache an empty list before filtering, as filter callbacks may load products again
			$this->products = array();

			/**
			 * Filter the dummy products used to top up the preview cart when the catalog has fewer products.
			 * Each item holds product properties as accepted by `WC_Product::set_props()`.
			 */
			$products = apply_filters( 'fc_admin_preview_dummy_products', array(
				array( 'name' => __( 'Beanie', 'fluid-checkout' ), 'regular_price' => '20', 'sale_price' => '18', 'sku' => 'fc-preview-beanie' ),
				array( 'name' => __( 'Cap', 'fluid-checkout' ), 'regular_price' => '18', 'sale_price' => '16', 'sku' => 'fc-preview-cap' ),
				array( 'name' => __( 'T-Shirt', 'fluid-checkout' ), 'regular_price' => '18', 'sku' => 'fc-preview-t-shirt' ),
			) );

			// Iterate dummy products, assigning IDs that no real product uses
			foreach ( array_values( (array) $products ) as $index => $props ) {
				$this->products[ self::PRODUCT_ID_START + $index ] = $props;
			}
		}

		return $this->products;
	}

	/**
	 * Check whether a product ID belongs to a dummy product.
	 *
	 * @param   int  $product_id  Product ID.
	 *
	 * @return  bool
	 */
	public function is_dummy_product_id( $product_id ) {
		return array_key_exists( (int) $product_id, $this->get_products() );
	}

	/**
	 * Get the properties of a dummy product, with defaults for a published product hidden from the catalog.
	 *
	 * @param   int  $product_id  Dummy product ID.
	 *
	 * @return  array  Product properties as accepted by `WC_Product::set_props()`.
	 */
	public function get_product_props( $product_id ) {
		// Bail if not a dummy product
		if ( ! $this->is_dummy_product_id( $product_id ) ) { return array(); }

		// Get product properties
		$products = $this->get_products();
		$props = (array) $products[ (int) $product_id ];

		// Get active price, the sale price when set
		$regular_price = array_key_exists( 'regular_price', $props ) ? $props[ 'regular_price' ] : '';
		$price = array_key_exists( 'sale_price', $props ) && '' !== $props[ 'sale_price' ] ? $props[ 'sale_price' ] : $regular_price;

		return array_merge( array(
			'status'                 => 'publish',
			'catalog_visibility'     => 'hidden',
			'price'                  => $price,
		), $props );
	}

}

FluidCheckout_Admin_Preview_Dummy_Data::instance();
