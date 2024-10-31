<?php

namespace PIWA;

class PIWA {
	use Singleton;

	public $plugin_file = null;

	public $option_key = 'piwa';

	public $options = [];

	public $default_atts = [];

	/**
	 * Class instances.
	 * Assigned an instance if [ $this, $is_context_callback ] or $is_context_callback() returns true.
	 *
	 * These vars are private, but will be returned as public instances of the appropriate class
	 * if accessed from a public context or unexpected context:
	 *  e.g., (from frontend):
	 *      printf( '<pre>%s</pre>', print_r( piwa()->admin_settings->sections_fields(), true ) ); exit;
	 *
	 * @see $this->maybe_instantiate_objects()
	 * @see Singleton::__get()
	 */
	private $client           = null;
	private $render           = null;
	private $invoices         = null;
	private $payments         = null;
	private $admin_settings   = null;
	private $gutenberg_blocks = null;

	/**
	 * Gutenberg block or shortcode render attribute:
	 *
	 * Not used.
	 */
	public $content = '';

	/**
	 * Gutenberg block or shortcode render attribute:
	 *
	 * @var string|WP_Block Either a string of the shortcode used or a WP_Block object of the instantiating block.
	 */
	public $block_or_tagname = '';

	/**
	 * Autoload index.
	 *
	 * Keys name a class method or global function that will return boolean true|false.
	 * Sub-keys name a class associated with a file to include.
	 *
	 * @see $this->maybe_instantiate_objects()
	 * @see Singleton::__get()
	 */
	private $class_autoload_index = [
		'__return_true' => [ // Always instantiated.
			/**
			 * Render and other classes get passed default configuration from Admin_Settings,
			 * therefore Admin_Settings must be instantiated first, and the Singleton trait checks for it.
			 *
			 * @see $this->get_default_atts()
			 * @see Singleton::maybe_instantiate_a_class()
			 */
			'Admin_Settings' => 'src/class-admin-settings.php',
			'Client'         => 'src/class-client.php',
			'Render'         => 'src/class-render.php',
			'Payments'       => 'src/class-payments.php',
		],
		'is_gutenberg'  => [
			'Gutenberg_Blocks' => 'src/class-gutenberg-blocks.php',
		],
	];

	private $i18n = [];

	private function __construct() {
		require $this->plugin_dir( 'vendor/autoload.php' );
		spl_autoload_register( [ $this, 'autoload' ] );

		$this->options = get_option( $this->option_key );

		add_action( 'init', [ $this, 'init' ], 0 );
		if ( is_admin() ) {
			add_action( 'admin_notices', [ $this, 'please_configure' ] );
		}

		add_shortcode( 'piwa', [ $this, 'shortcode' ] );
	}

	public function __toString() {
		return $this->render->render( $this->atts, $this->content, $this->block_or_tagname );
	}

	/**
	 * Loads child-class instances to vars in appropriate contexts.
	 *
	 * If the callback method or function named by the keys in $this->class_autoload_index returns true,
	 * include the named file and instantiate the contained class to a var on this controller instance.
	 *
	 * Includes are handled by $this->autoload(), and instantiated by $this->__get(),
	 * so if a child class is needed in an unanticipated context,
	 * it can still be instantiated (and included) at any time by referencing the expected var.
	 * For example:
	 *      print_r( piwa()->admin_settings->sections_fields() );
	 *      ...will automatically instantiate the Admin_Settings class and call the requested method if used in a frontend context.
	 *
	 * @see Singleton::__get()
	 * @see PIWA::autoload()
	 */
	private function maybe_instantiate_objects() {
		foreach ( $this->class_autoload_index as $is_context_callback => $classes ) {
			if (
				( method_exists( $this, $is_context_callback ) && call_user_func( [ $this, $is_context_callback ] ) )
				|| ( function_exists( $is_context_callback ) && call_user_func( $is_context_callback ) )
			) {
				foreach ( $classes as $class => $include_file ) {
					$this->__get( $class );
				}
			}
		}
	}

	public function autoload( $class_name ) {
		$exploded = explode( '\\', $class_name );

		// Do nothing if class is not in the current namespace.
		if ( ! is_array( $exploded ) || __NAMESPACE__ !== array_shift( $exploded ) ) {
			return;
		}

		$class_name = implode( '\\', $exploded );

		foreach ( $this->class_autoload_index as $is_context_callback => $classes ) {
			if ( array_key_exists( $class_name, $classes ) ) {
				include $this->plugin_dir( $classes[ $class_name ] );
				return;
			}
		}
	}

	public function init() {
		load_plugin_textdomain( 'piwa', false, dirname( plugin_basename( $this->plugin_file ) ) . '/languages/' );

		$this->maybe_instantiate_objects();
	}

	public function please_configure() {
		if ( $this->plugin_is_configured() ) {
			return;
		}

		printf(
			'<div class="notice notice-success"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( $this->i18n( 'plugin_is_active' ) ),
			esc_url( admin_url( 'admin.php?page=' . $this->option_key ) ),
			esc_html( $this->i18n( 'please_connect_account' ) )
		);
	}

	public function plugin_dir( $file_path = '' ) {
		if ( is_null( $this->plugin_file ) ) {
			$this->plugin_file = dirname( __DIR__ ) . '/piwa.php';
		}

		return sprintf(
			'%s%s',
			plugin_dir_path( $this->plugin_file ),
			trim( $file_path, '/' )
		);
	}

	public function i18n( $key ) {
		if ( empty( $this->i18n ) ) {
			$this->i18n = include $this->plugin_dir( 'src/i18n.php' );
		}
		if ( array_key_exists( $key, $this->i18n ) ) {
			return $this->i18n[ $key ];
		}
		throw new \Exception( sprintf( 'Internationalization key not found: %s', esc_html( $key ) ) );
	}

	public function is_option_set_by_env( $field_id ) {
		return ! empty( getenv( sprintf( 'piwa_%s', $field_id ) ) );
	}

	public function get_option( $field_id ) {
		$env = getenv( sprintf( 'piwa_%s', $field_id ) );

		/**
		 * It is not possible to store boolean false in an environment variable,
		 * as getenv() will return false if the variable is not set.
		 * sandbox_mode = false is stored as integer 0.
		 */
		if ( false !== $env ) {
			return $env;
		}
		if ( array_key_exists( $field_id, (array) $this->options ) && ! is_null( $this->options[ $field_id ] ) ) {
			return $this->options[ $field_id ];
		}
		if ( is_object( $this->admin_settings ) ) {
			$defaults = $this->admin_settings->defaults();
			if ( array_key_exists( $field_id, $defaults ) ) {
				return $defaults[ $field_id ];
			}
		}
		return null;
	}

	public function parse_attributes( $atts = [] ) {
		$this->atts             = [];
		$this->content          = '';
		$this->block_or_tagname = '';

		// Called piwa( 123 ).
		if ( ! is_array( $atts ) && is_numeric( $atts ) ) {
			$this->atts['amount'] = number_format( (float) $atts, 2 );
			return;
		}
		// Called piwa(): Set the ID from current post object if it contains meta "_price".
		if ( empty( $atts ) ) {
			if ( is_numeric( get_post_meta( get_the_ID(), '_price', true ) ) ) {
				$this->atts['amount'] = number_format( (float) get_post_meta( get_the_ID(), '_price', true ), 2 );
			}
			return;
		}

		if ( array_key_exists( 'content', (array) $atts ) ) {
			$this->content = $atts['content'];
		}
		if ( array_key_exists( 'block_or_tagname', (array) $atts ) ) {
			$this->block_or_tagname = $atts['block_or_tagname'];
		}
		if ( array_key_exists( 'show_customer_invoice_input', (array) $atts ) ) {
			$this->atts['show_customer_invoice_input'] = (bool) $atts['show_customer_invoice_input'];
		} else {
			// Default is false so when that is the value, WP will pass nothing.
			$this->atts['show_customer_invoice_input'] = false;
		}
		if ( array_key_exists( 'amount', (array) $atts ) && is_numeric( $atts['amount'] ) ) {
			$this->atts['amount'] = number_format( (float) $atts['amount'], 2 );
		}
		if ( array_key_exists( 'title', (array) $atts ) ) {
			$this->atts['title'] = $atts['title'];
		}
	}

	public function is_admin_or_rest() {
		return (
			is_admin()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		);
	}

	/**
	 * Check if Gutenberg is active.
	 * Must run after plugins_loaded action.
	 *
	 * @return bool
	 */
	public function is_gutenberg() {
		if (
			! has_filter( 'replace_editor', 'gutenberg_init' ) // Gutenberg is installed and activated.
			&& ! version_compare( $GLOBALS['wp_version'], '5.0-beta', '>' ) // Block editor is the default.
		) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( 'classic-editor/classic-editor.php' ) ) {
			return true;
		}

		return in_array( get_option( 'classic-editor-replace' ), [ 'no-replace', 'block' ], true );
	}

	public function get_default_atts() {
		if ( empty( $this->default_atts ) ) {
			foreach ( $this->admin_settings->get_field_keys() as $field_key ) {
				$this->default_atts[ $field_key ] = $this->get_option( $field_key );
			}

			// Defined outside of WP field API.
			$this->default_atts['merchant_account'] = $this->get_option( 'merchant_account' );

			/**
			 * @see class-admin-settings.php::sanitize()
			 */
			$keys = [];
			foreach ( [ 'registration', 'sent' ] as $context ) {
				if (
					is_object( $this->controller )
					&& array_key_exists( 'keys', (array) $this->controller->options )
					&& array_key_exists( $context, (array) $this->controller->options['keys'] )
					&& ! empty( $this->controller->options['keys'][ $context ]['public'] )
				) {
					$keys[ $context ] = $this->controller->options['keys'][ $context ];
				} else {
					$keys[ $context ] = [
						'public'  => $this->admin_settings->get_key( 'public', $context ),
						'private' => $this->admin_settings->get_key( 'private', $context ),
					];
				}
			}
			$this->default_atts['keys'] = $keys;
		}
		return $this->default_atts;
	}

	/**
	 * Shortcode usage:
	 *
	 * Short form:
	 * [piwa] - Displays a payment form where customer sets the payment amount.
	 * [piwa 100.50] - Displays a payment button where the amount is $100.50.
	 * [piwa 100.50 "Business Consulting"] - Displays a payment button where the amount is $100.50 and the title is Business Consulting.
	 * [piwa input-invoice] - Displays a payment form where the customer sets the price and specifies an invoice reference number.
	 *
	 * Long form:
	 * [piwa amount="100.50" title="Business Consulting"] - Displays a button to pay $100.50 for Business Consulting.
	 *
	 * Invoice Number input can only be displayed if an amount is not set.
	 * Title can only be displayed if an amount is set.
	 */
	public function shortcode( $atts = [], $content = '', $tag = '' ) {
		$atts            = (array) $atts;
		$atts_output     = [
			'show_customer_invoice_input' => false,
			'amount'                      => null,
			'title'                       => null,
		];
		$currency_symbol = $this->i18n( 'currency_symbols_plain' )[ $this->get_option( 'payment_currency' ) ];

		/**
		 * Allow attributes to be set with short form, only specifying a key:
		 *
		 * If a key is numeric, e.g., 100 or 100.50, it's the amount.
		 * If a key is invoice-number or invoice_number, display the invoice number input.
		 * If a key is not numeric and not invoice-number, it's the title.
		 */
		foreach ( (array) $atts as $key => $value ) {
			if ( is_int( $key ) && ! empty( $value ) ) {
				if ( is_numeric( $value ) || is_numeric( trim( $value, $currency_symbol ) ) ) {
					$atts_output['amount'] = number_format( floatval( trim( $value, $currency_symbol ) ), 2 );
				} elseif ( in_array( $value, [ 'input-invoice', 'input_invoice' ], true ) ) {
					$atts_output['show_customer_invoice_input'] = true;
				} elseif ( ! is_numeric( $value ) ) {
					$atts_output['title'] = $value;
				}
			}
		}

		// Longhand attributes.
		if ( array_key_exists( 'amount', $atts ) && is_numeric( trim( $atts['amount'], $currency_symbol ) ) ) {
			$atts_output['amount'] = number_format( floatval( trim( $atts['amount'], $currency_symbol ) ), 2 );
		}
		if ( array_key_exists( 'title', $atts ) ) {
			$atts_output['title'] = $atts['title'];
		}
		if ( array_key_exists( 'input-invoice', $atts ) && 'false' !== $atts['input-invoice'] ) {
			$atts_output['show_customer_invoice_input'] = true;
		}
		if ( array_key_exists( 'input_invoice', $atts ) && 'false' !== $atts['input_invoice'] ) {
			$atts_output['show_customer_invoice_input'] = true;
		}

		return (string) piwa( $atts_output );
	}

	public function plugin_is_configured( $connection_type = null ) {
		$keys = $this->get_option( 'keys' );

		if ( is_null( $connection_type ) ) {
			$connection_type = $this->get_option( 'merchant_account' )['connection_type'];
		}

		if ( empty( $keys[ $connection_type ]['public_key_id'] ) ) {
			return false;
		}

		return (
			! empty( $this->get_option( 'merchant_id' ) )
			&& ! empty( $this->get_option( 'client_id_store_id' ) )
		);
	}

	public function credentials_can_connect( $connection_type = null ) {
		if ( is_null( $connection_type ) ) {
			$connection_type = $this->get_option( 'merchant_account' )['connection_type'];
		}

		if ( $this->plugin_is_configured( $connection_type ) ) {
			$client_config = array_merge(
				(array) $this->options,
				[
					'merchant_account' => [ 'connection_type' => $connection_type ],
				]
			);

			try {
				$response = $this->client->get( $client_config )->getReports();
				if ( array_key_exists( 'status', $response ) && 200 === intval( $response['status'] ) ) {
					return [
						'status'  => 'success',
						'tooltip' => $this->i18n( 'amazon_account_connected_tooltip' ),
						'message' => $this->i18n( 'amazon_account_connected' ),
					];
				} else {
					$message = $this->i18n( 'amazon_account_credentials_invalid' );
					if ( is_array( $response ) && array_key_exists( 'response', $response ) ) {
						$response = json_decode( $response['response'], true );
					}
					if ( array_key_exists( 'message', (array) $response ) ) {
						$message = $response['message'];
					} elseif ( array_key_exists( 'response', (array) $response ) ) {
						$message = (string) $response['response'];
					} else {
						$message = (string) $response;
					}

					return [
						'status'  => 'invalid',
						'tooltip' => '',
						'message' => $message,
					];
				}
			} catch ( \Exception $e ) {
				return [
					'status'  => 'error',
					'tooltip' => '',
					'message' => $this->i18n( 'api_merchant_failure' ) . $e->getMessage(),
				];
			}
		}
		return [
			'status'  => 'credentials-incomplete',
			'tooltip' => '',
			'message' => '',
		];
	}

	public function maybe_get_payment() {
		if ( array_key_exists( 'amazonCheckoutSessionId', $_GET ) && array_key_exists( 'ap-payment-id', $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$checkout_session_id                              = sanitize_key( $_GET['amazonCheckoutSessionId'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$payment_source_id                                = intval( explode( '-', sanitize_text_field($_GET['ap-payment-id']) )[0] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			list($payment_source_id, $payment_source_counter) = explode( '-', sanitize_key( $_GET['ap-payment-id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$payments = get_posts(
				[
					'post_type'      => Payments::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'post_parent'    => $payment_source_id,
					'meta_key'       => 'checkout_session_id',
					'meta_value'     => $checkout_session_id,
				]
			);
			if ( ! empty( $payments ) && is_array( $payments ) ) {
				return $payments[0];
			} elseif ( is_a( $payments, 'WP_Post' ) ) {
				return $payments;
			}
		}
		return null;
	}

	public function get_modal_config_init_i18n( $config = [] ) {

		$payment = $this->maybe_get_payment();

		if ( is_a( $payment, 'WP_Post' ) ) {
			$status      = 'error';
			$status_meta = get_post_meta( $payment->ID, 'payment_status', true );
			if ( ! empty( $status_meta ) ) {
				$status = $status_meta;
			}
			$payment_i18n = $this->i18n( sprintf( 'payment_%s', $status ) );

			$invoice_number = get_post_meta( $payment->ID, 'invoice_number', true );
			if ( empty( $invoice_number ) || intval( $invoice_number ) === intval( $payment->post_parent ) ) {
				$invoice_number = '';
			}
		} else {
			return [];
		}

		return array_merge(
			[
				'continue'        => $this->i18n( 'continue' ),
				'payment'         => $payment_i18n,
				'currency_symbol' => $this->i18n( 'currency_symbols_plain' )[ $this->get_option( 'payment_currency' ) ],
				'status'          => $status,
				'title'           => ( array_key_exists( 'title', $config ) && ! empty( $config['title'] ) )
					? wp_strip_all_tags( $config['title'] )
					: '',
				'amount'          => '',
				'invoice_number'  => '',
			],
			( in_array( $status, [ 'paid', 'pending_authorization' ], true ) )
				? [
					'amount'         => get_post_meta( $payment->ID, 'amount', true ),
					'invoice_number' => $invoice_number,
				]
				: []
		);
	}

	public function get_buttons_init_i18n() {
		return [
			'sign_url'            => get_rest_url( null, 'piwa/v1/sign' ),
			'enter_above_amount'  => $this->i18n( 'enter_above_amount' ),
			'enter_below_amount'  => $this->i18n( 'enter_below_amount' ),
			'currency_symbol'     => $this->i18n( 'currency_symbols_plain' )[ $this->get_option( 'payment_currency' ) ],
			'api_failure'         => $this->i18n( 'api_failure' ),
			'prod_key_in_sandbox' => $this->i18n( 'prod_key_in_sandbox' ),
		];
	}
}
