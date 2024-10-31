<?php

namespace PIWA;

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\PublicKeyLoader;

class Admin_Settings {
	use Singleton;

	public $merchant_account_types = [];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'admin_menu' ], 0 );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
		add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
		add_action( 'wp_ajax_poll-auto-connect-results', [ $this, 'poll_auto_connect_results' ] );
	}

	public function rest_api_init() {
		register_rest_route(
			'piwa/v1',
			'/get-keys',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_get_keys' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * @see https://developer.amazon.com/docs/amazon-pay-registration/automatickeyexchange.html
	 * @see https://developer.amazon.com/docs/amazon-pay-registration/decryption.html
	 */
	public function rest_get_keys( \WP_REST_Request $request ) {
		// Documentation indicates origin will be payments, but recent source is sellercentral.
		$valid_origins = [
			'https://payments.amazon.com',
			'https://sellercentral.amazon.com',
		];

		$origin = sanitize_url( $_SERVER['HTTP_ORIGIN']);

		if ( !in_array( $origin, $valid_origins, true ) ) {
			return new \WP_REST_Response( [ 'result' => 'error' ], 400 );
		}

		header( sprintf( 'Access-Control-Allow-Origin: %s', $origin ) );
		header( 'Access-Control-Allow-Methods: POST' );
		header( 'Access-Control-Allow-Headers: Content-Type' );

		try {
			$this->register_option_sanitization_callbacks();

			$payload = json_decode( $request->get_params()['payload'], true );

			// Check expected values.
			if (
				! array_key_exists( 'merchantId', $payload )
				|| ! array_key_exists( 'storeId', $payload )
				|| ! array_key_exists( 'publicKeyId', $payload )
			) {
				throw new \Exception( $this->i18n( 'auto_connect_incomplete_credentials' ) );
			}

			// Decrypt the Public Key ID.
			$rsa = PublicKeyLoader::load(
				$this->get_key( 'private', 'registration' )
			)->withPadding( RSA::ENCRYPTION_PKCS1 );

			$payload['publicKeyId'] = $rsa->decrypt( base64_decode( urldecode( $payload['publicKeyId'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		} catch ( \Error | \Exception $e ) {
			$message = sprintf(
				$this->i18n( 'auto_connect_could_not_decode' ),
				$e->getMessage()
			);

			// Before calling update_option we are calling register_option_sanitization_callbacks() to ensure the sanitize callback is registered.
			// The sanitize method on this class is used to sanitize the options by data type before saving them to the database
			update_option(
				$this->option_key,
				array_merge(
					$this->defaults(),
					(array) get_option( $this->option_key ),
					[
						'auto_connect' => [
							'result'  => 'error',
							'message' => $message,
						],
					]
				)
			);
			return new \WP_REST_Response(
				[
					'result'  => 'error',
					'message' => $message,
				],
				400
			);
		}

		$current_options = (array) get_option( $this->option_key );
		$current_keys    = array_key_exists( 'keys', $current_options ) ? (array) $current_options['keys'] : [];
		if ( array_key_exists( 'registration', $current_keys ) ) {
			$current_keys['automatic']                  = $current_keys['registration'];
			$current_keys['automatic']['public_key_id'] = $payload['publicKeyId'];

			unset( $current_keys['registration'] );
		} else {
			error_log( 'PIWA: When attempting auto-configuration, a registration key was expected but not found.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		update_option(
			$this->option_key,
			array_merge(
				$this->defaults(),
				$current_options,
				[
					/**
					 * Key pair used for registration has been saved and configured.
					 * Replace payment keys with registration keys,
					 * generate new registration keys,
					 * update merchant config.
					 */
					'keys'               => $current_keys,
					'merchant_id'        => $payload['merchantId'],
					'client_id_store_id' => $payload['storeId'],
					'merchant_account'   => [ 'connection_type' => 'automatic' ],
					'auto_connect'       => [
						'result'  => 'success',
						'message' => $this->i18n( 'auto_connect_success' ),
					],
				]
			)
		);

		return new \WP_REST_Response( [ 'result' => 'success' ], 200 );
	}

	/**
	 * Auto-connect sends credentials through a background process & has the user close a secondary window.
	 * This polls for results after the Connect button is clicked so the Settings page may be refreshed after success or error.
	 */
	public function poll_auto_connect_results() {
		header( 'Content-Type: application/json' );

		$result = $this->get_option( 'auto_connect' );
		if ( empty( $result ) ) {
			$result = [
				'result'  => 'nothing',
				'message' => '',
			];
		}

		echo wp_json_encode( $result );
		exit;
	}

	public function get_field_keys() {
		$field_keys = [];
		foreach ( $this->sections_fields() as $section_id => $section ) {
			foreach ( $section['fields'] as $field_id => $field ) {
				$field_keys[] = $field_id;
			}
		}
		return $field_keys;
	}

	public function sanitize( $options ) {
		$saved_options    = [];
		$min              = 1;
		$max              = apply_filters( 'piwa_payment_absolute_maximum', 25000 );
		$display_feedback = '';

		/**
		 * Most fields are displayed with WP Settings API callbacks according to datatype.
		 *
		 * Loop through those fields, sanitizing the value according to the datatype.
		 */
		foreach ( $this->sections_fields() as $section_id => $section ) {
			foreach ( $section['fields'] as $field_id => $field ) {
				if ( ! is_array( $field['callback'] ) ) {
					continue;
				}

				switch ( $field['callback'][1] ) {
					case 'text_field':
					case 'textarea_field':
						if ( ! array_key_exists( $field_id, (array) $options ) ) {
							$saved_options[ $field_id ] = '';
						}else {
							// sanitizes text area field
							$saved_options[ $field_id ] = wp_strip_all_tags( sanitize_textarea_field($options[ $field_id ]) );
						}
						break;
					case 'checkbox_field':
						if ( ! array_key_exists( $field_id, (array) $options ) ) {
							$saved_options[ $field_id ] = 0;
						}

						// sanitizes checkbox field
                        if ( ! empty( $options[ $field_id ] ) ) {
                            $checkbox_value = sanitize_text_field( $options[ $field_id ] );
                        } else {
                            $checkbox_value = 0;
                        }

						$saved_options[ $field_id ] = intval( !empty($checkbox_value) );
						break;
					case 'select_field':
						// sanitizes select field
						$select_value = sanitize_text_field( $options[ $field_id ] );

						if (
							array_key_exists( $field_id, $options )
							&& in_array( $select_value, (array) array_keys( $field['args']['options'] ), true )
						) {
							$saved_options[ $field_id ] = $select_value;
						}
						break;
					case 'min_max_field':
						$min = 1;
						$max = 1000000;
						$display_feedback = 'no';

						if (array_key_exists('min_max', $options) && is_array($options['min_max'])) {
							// sanitizes mix and max fields
							$min_field = sanitize_text_field($options['min_max']['min']);
							$max_field = sanitize_text_field($options['min_max']['max']);

							$min = max($min, floatval($min_field ?? $min));
							$max = floatval($max_field ?? $max);

							if ($min >= $max) { // Ensure $min is less than $max, reset to defaults if not
								$min = 1;
								$max = 1000000;
							}

                            if ( ! empty( $options['min_max']['display_feedback'] ) ) {
                                $display_feedback = $options['min_max']['display_feedback'] === 'yes' ? 'yes' : 'no';
                            }
						}

						$saved_options['min_max'] = compact('min', 'max', 'display_feedback');
						break;
				}
			}
		}

		/**
		 * Keys are displayed as a field group callback, rather than individual field callbacks.
		 * Reset field is added by JavaScript only if the reset and confirm buttons are clicked.
		 */
		$options               = $this->maybe_reset_a_key( $options );
		$saved_options['keys'] = $this->save_or_regenerate_keys( $options );

		$saved_options['merchant_account']['connection_type'] = sanitize_key( $options['merchant_account']['connection_type'] );

		/**
		 * Status for the merchant account connection button.
		 *
		 * When a user completes the automatic connection workflow, the admin page will query for this status update intermittently.
		 * The setting gets updated in REST get-keys and is cleared once displayed.
		 */
		$saved_options['auto_connect'] = [];
		if (
			array_key_exists( 'auto_connect', $options )
			&& array_key_exists( 'result', $options['auto_connect'] )
			&& array_key_exists( 'message', $options['auto_connect'] )
		) {
			$saved_options['auto_connect'] = [
				'result'  => wp_kses_post( $options['auto_connect']['result'] ),
				'message' => wp_kses_post( $options['auto_connect']['message'] ),
			];
		}

		// Update the controller options var and clear cache to maintain consistent state.
		$this->controller->options = $saved_options;
		$this->cache_flush();

		return $saved_options;
	}

	public function maybe_reset_a_key( $new_options ) {
		if ( array_key_exists( 'reset-key', $new_options ) ) {
			$context_to_reset = $new_options['reset-key'];
			if (
				array_key_exists( 'keys', (array) $this->controller->options )
				&& array_key_exists( $context_to_reset, (array) $this->controller->options['keys'] )
			) {
				unset( $this->controller->options['keys'][ $context_to_reset ] );
			}
			if (
				'automatic' === $context_to_reset
				&& array_key_exists( 'registration', (array) $this->controller->options['keys'] )
			) {
				unset( $this->controller->options['keys']['registration'] );
			}
			if (
				array_key_exists( 'keys', (array) $new_options )
				&& array_key_exists( $context_to_reset, (array) $new_options['keys'] )
			) {
				unset( $new_options['keys'][ $context_to_reset ] );
			}
			unset( $new_options['reset-key'] );
			unset( $_POST['piwa']['reset-key'] );
		}
		return $new_options;
	}

	public function save_or_regenerate_keys( $new_options ) {
		$valid_contexts = [ 'registration', 'automatic', 'sent', 'receive' ];
		$new_keys       = [];
		$keys_to_update = [];
		if ( array_key_exists( 'keys', (array) $new_options ) ) {
			$new_keys = (array) $new_options['keys'];
		}
		if ( array_key_exists( 'keys', (array) $this->controller->options ) ) {
			$keys_to_update = (array) $this->controller->options['keys'];
		}

		/**
		 * Allows keys to be saved by passing in an option update.
		 *
		 * This would apply to:
		 *     saving 'automatic' over REST get-keys, loaded from 'registration' keys.
		 *     'receive' over a settings page save.
		 *
		 * This would not apply to:
		 *     'registration' - should be generated at initialization, reset button, or after completing connection / transfer to 'automatic'.
		 *     'sent' - should be auto-generated, but takes public_key_id via a Settings Save.
		 */
		foreach ( $valid_contexts as $context ) {
			$key_to_save = [
				'public'        => '',
				'private'       => '',
				'public_key_id' => '',
			];
			if ( array_key_exists( $context, (array) $keys_to_update ) ) {
				if ( ! empty( $keys_to_update[ $context ]['public'] ) ) {
					$key_to_save['public'] = $keys_to_update[ $context ]['public'];
				}
				if ( ! empty( $keys_to_update[ $context ]['private'] ) ) {
					$key_to_save['private'] = $keys_to_update[ $context ]['private'];
				}
				if ( array_key_exists( 'public_key_id', $keys_to_update[ $context ] ) ) {
					$key_to_save['public_key_id'] = $keys_to_update[ $context ]['public_key_id'];
				}
			}
			if ( array_key_exists( $context, $new_keys ) ) {
				if ( ! empty( $new_keys[ $context ]['public'] ) ) {
					$key_to_save['public'] = $new_keys[ $context ]['public'];
				}
				if ( ! empty( $new_keys[ $context ]['private'] ) ) {
					$key_to_save['private'] = $new_keys[ $context ]['private'];
				}
				if ( array_key_exists( 'public_key_id', $new_keys[ $context ] ) ) {
					$key_to_save['public_key_id'] = $new_keys[ $context ]['public_key_id'];
				}
			}
			$keys_to_update[ $context ] = $key_to_save;
		}

		/**
		 * If a blank key is about to be saved, generate a new pair.
		 * Only applies to 'registration' and 'sent'.
		 */
		foreach ( [ 'registration', 'sent' ] as $context ) {
			if (
				! array_key_exists( $context, (array) $keys_to_update )
				|| empty( $keys_to_update[ $context ]['public'] )
				|| empty( $keys_to_update[ $context ]['private'] )
			) {
				$key_pair                              = $this->create_keys();
				$keys_to_update[ $context ]['public']  = $key_pair['public'];
				$keys_to_update[ $context ]['private'] = $key_pair['private'];
			}
		}

		return $keys_to_update;
	}

	public function cache_flush() {
		wp_cache_delete( $this->option_key, 'options' );

		if ( class_exists( 'WpeCommon' ) ) {
			foreach ( [ 'purge_memcached', 'purge_varnish_cache' ] as $method ) {
				if ( method_exists( 'WpeCommon', $method ) ) {
					\WpeCommon::$method();
				}
			}
		}
	}

	public function defaults() {
		return [
			'store_name'       => wp_parse_url( site_url(), PHP_URL_HOST ),
			'note_to_buyer'    => $this->i18n( 'note_to_buyer_default' ),
			'region'           => $this->i18n( 'default_region' ),
			'ledger_currency'  => $this->i18n( 'default_ledger_currency' ),
			'payment_currency' => $this->i18n( 'default_payment_currency' ),
			'language'         => $this->i18n( 'default_language' ),
			'sandbox_mode'     => 1,
			'merchant_account' => [ 'connection_type' => 'automatic' ],
		];
	}

	public function admin_enqueue_scripts( $hook_suffix ) {
		if ( in_array( $hook_suffix, [ 'toplevel_page_piwa', 'edit.php' ], true ) ) {
			/**
			 * Submenu items for plugin post types are children of a settings menu.
			 * While 'parent_file' and 'submenu_file' filters are provided for this purpose in wp-admin/menu-header.php,
			 * the subsequent call to get_admin_page_parent() overrides the globals modified by these filters in the case of post listings.
			 * The result is a bug that does not correctly detect the current page parent, resulting in a menu item of class
			 * .wp-not-current-submenu which contains a submenu with class .current.
			 *
			 * There are no filters for the menu output.
			 * There is no menu walker.
			 * Modifying with output buffering could conflict with other functions.
			 * Therefore, the following JavaScript corrects the issue if the malformed class structure is detected.
			 */
			wp_enqueue_script(
				'admin-fix-current-screen',
				plugins_url( 'src/js/admin-fix-current-screen.js', $this->plugin_file ),
				[],
				md5_file( dirname( $this->plugin_file ) . '/src/js/admin-fix-current-screen.js' ),
				false
			);
		}

		if ( 'toplevel_page_piwa' === $hook_suffix ) {

			wp_enqueue_style(
				'admin-styles',
				plugins_url( 'src/css/admin-settings.css', $this->plugin_file ),
				[],
				md5_file( dirname( __DIR__ ) . '/src/css/admin-settings.css' )
			);
			wp_add_inline_style( 'admin-styles', $this->inline_styles() );

			wp_enqueue_script(
				'admin-settings',
				plugins_url( 'src/js/admin-settings.js', $this->plugin_file ),
				[],
				md5_file( dirname( $this->plugin_file ) . '/src/js/admin-settings.js' ),
				true
			);

			$row_tooltips = [];
			foreach (
				[
					'sandbox_mode',
					'store_name',
					'note_to_buyer',
					'min_max',
				] as $class
			) {
				$row_tooltips[ $class ] = $this->i18n( sprintf( '%s_tooltip', $class ) );
			}

			wp_localize_script(
				'admin-settings',
				'PIWASettingsI18n',
				[
					'region'                         => $this->get_option( 'region' ),
					'ledger_currency'                => $this->get_option( 'ledger_currency' ),
					'payment_currency'               => $this->get_option( 'payment_currency' ),
					'currency_symbols'               => $this->i18n( 'currency_symbols_plain' ),
					'language'                       => $this->get_option( 'language' ),
					'show_block_configuration'       => ( 'customer_input' !== $this->get_option( 'block_type' ) ),
					'show_block_configuration_label' => $this->i18n( 'show_block_configuration' ),
					'key_copied'                     => $this->i18n( 'key_copied' ),
					'row_tooltips'                   => $row_tooltips,
				]
			);
		}
	}

	public function inline_styles() {
		ob_start();
		/**
		 * Match color scheme to WP Admin color user settings.
		 */
		$this->output_admin_colors_as_css_vars();

		/**
		 * Localized label for .env-configured settings.
		 */
		printf(
			'.wrap { --set-by-dot-env: "%s"; }',
			esc_attr( $this->i18n( 'set_by_dot_env' ) )
		);
		return ob_get_clean();
	}

	/**
	 * Must be called before update_option() for sanitization to be called and keys to be preserved.
	 */
	public function register_option_sanitization_callbacks() {
		register_setting(
			'option',
			$this->option_key,
			[
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	public function admin_menu() {
		$this->merchant_account_types = [
			[
				'id'       => 'automatic',
				'label'    => $this->i18n( 'tab_connect_automatic' ),
				'callback' => [ $this, 'account_tab_automatic' ],
			],
			[
				'id'       => 'sent',
				'label'    => $this->i18n( 'tab_send_public_key' ),
				'callback' => [ $this, 'account_tab_send_public_key' ],
			],
			[
				'id'       => 'receive',
				'label'    => $this->i18n( 'tab_receive_public_private_key' ),
				'callback' => [ $this, 'account_tab_receive_public_private_key' ],
			],
		];

		if (
			isset( $_POST['action'] )
			&& 'update' === $_POST['action']
			&& wp_verify_nonce( sanitize_text_field( wp_unslash ($_POST['_wpnonce'] ) ), sprintf( '%s-options', $this->option_key ) )
			&& ! empty( $_POST['piwa'] )
		) {

			// Before saving options with run them through the sanitize method to ensure they are sanitized by data type.
			$sanitizedOptionsArray =  $this->sanitize($_POST['piwa']);
			update_option( $this->option_key, $sanitizedOptionsArray );
		}

		add_menu_page(
			$this->i18n( 'admin_page_title' ),
			$this->i18n( 'admin_menu_title' ),
			'manage_options',
			$this->option_key,
			[ $this, 'admin_settings_page' ],
			'dashicons-amazon'
		);

		add_submenu_page(
			$this->option_key,
			$this->i18n( 'admin_page_title' ),
			$this->i18n( 'admin_submenu_title' ),
			'manage_options',
			$this->option_key,
			[ $this, 'admin_settings_page' ]
		);
	}

	public function register_settings_fields() {
		foreach ( $this->sections_fields() as $section_id => $section ) {
			add_settings_section(
				$section_id,
				$section['title'],
				$section['callback'],
				$this->option_key
			);

			foreach ( $section['fields'] as $field_id => $field ) {
				$set_by_env = $this->is_option_set_by_env( $field_id );

				add_settings_field(
					$field_id,
					$field['title'],
					$field['callback'],
					$this->option_key,
					$section_id,
					array_merge(
						[
							'name'          => sprintf( '%s[%s]', $this->option_key, $field_id ),
							'id'            => sprintf( '%s__%s', $this->option_key, $field_id ),
							'field_id'      => $field_id,
							'label_for'     => sprintf( '%s__%s', $this->option_key, $field_id ),
							'is_set_by_env' => (bool) $set_by_env,
							'class'         => trim(
								implode(
									' ',
									[
										sanitize_html_class( $field_id ),
										( $set_by_env ) ? 'set-by-env' : '',
									]
								)
							),
						],
						array_key_exists( 'args', (array) $field ) ? (array) $field['args'] : []
					)
				);
			}
		}
	}

	public function sections_fields() {
		return [
			'merchant_account' => [
				'title'    => $this->i18n( 'connect_amazon_account' ),
				'callback' => [ $this, 'merchant_account_fields' ],
				'args'     => [],
				'fields'   => [
					'merchant_id'        => [
						'title'    => $this->i18n( 'merchant_id' ),
						'callback' => [ $this, 'text_field' ],
						'args'     => [
							'type'  => 'text',
							'after' => sprintf(
								'<p class="help">%s</p>',
								wp_kses(
									$this->i18n( 'where_to_find_merchant_id' ),
									[
										'code' => [],
										'a'    => [
											'href'    => true,
											'_target' => true,
										],
									]
								)
							),
						],
					],
					'client_id_store_id' => [
						'title'    => $this->i18n( 'client_id_store_id' ),
						'callback' => [ $this, 'text_field' ],
						'args'     => [
							'type'  => 'text',
							'after' => sprintf(
								'<p class="help">%s</p>',
								wp_kses(
									$this->i18n( 'where_to_find_client_id_store_id' ),
									[
										'code' => [],
										'a'    => [
											'href'   => true,
											'class'  => true,
											'target' => true,
										],
									]
								)
							),
						],
					],
					'sandbox_mode'       => [
						'title'    => $this->i18n( 'enable_sandbox_mode' ),
						'callback' => [ $this, 'checkbox_field' ],
						'args'     => [
							'after' => sprintf(
								'<br /><br /><small>%s <mark>%s</mark></small>',
								( $this->get_option( 'sandbox_mode' ) )
									? esc_html( $this->i18n( 'sandbox_mode_active' ) )
									: esc_html( $this->i18n( 'production_mode_active' ) ),
								( $this->get_option( 'sandbox_mode' ) )
									? esc_html( $this->i18n( 'real_payments_will_not_be_processed' ) )
									: esc_html( $this->i18n( 'real_payments_will_be_processed' ) )
							),
						],
					],
				],
			],
			'invoice_details'  => [
				'title'    => $this->i18n( 'invoice_details' ),
				'callback' => function () {},
				'args'     => [],
				'fields'   => [
					'store_name'       => [
						'title'    => $this->i18n( 'store_name' ),
						'callback' => [ $this, 'text_field' ],
						'args'     => [
							'type' => 'text',
						],
					],
					'note_to_buyer'    => [
						'title'    => $this->i18n( 'note_to_buyer_label' ),
						'callback' => [ $this, 'text_field' ],
						'args'     => [
							'type' => 'text',
						],
					],
					'min_max'          => [
						'title'    => $this->i18n( 'allowed_payment_range' ),
						'callback' => [ $this, 'min_max_field' ],
					],
					'region'           => [
						'title'    => $this->i18n( 'region_label' ),
						'callback' => [ $this, 'select_field' ],
						'args'     => [
							'options' => $this->i18n( 'regions' ),
						],
					],
					'ledger_currency'  => [
						'title'    => $this->i18n( 'ledger_currency_label' ),
						'callback' => [ $this, 'select_field' ],
						'args'     => [
							'options' => $this->i18n( 'ledger_currency_symbols' ),
						],
					],
					'payment_currency' => [
						'title'    => $this->i18n( 'payment_currency_label' ),
						'callback' => [ $this, 'select_field' ],
						'args'     => [
							'options' => $this->i18n( 'currency_symbols' ),
						],
					],
					'language'         => [
						'title'    => $this->i18n( 'language_label' ),
						'callback' => [ $this, 'select_field' ],
						'args'     => [
							'options' => $this->i18n( 'languages' ),
						],
					],
				],
			],
		];
	}

	public function admin_settings_page() {
		if ( empty( get_option( $this->option_key ) ) ) {
			update_option( $this->option_key, $this->defaults() );
			$this->controller->options = get_option( $this->option_key );
		}

		$this_page_url = add_query_arg(
			[
				'page' => $this->option_key,
			],
			admin_url( 'admin.php' )
		);

		printf(
			'<div class="wrap"><img class="logo-img" src="%s" alt="Pay Invoices With Amazon">',
			esc_url( plugins_url( 'assets/logo.png', $this->plugin_file ) )
		);

		/**
		 * Auto-connect results message.
		 */
		$auto_connect = (array) $this->get_option( 'auto_connect' );
		if ( array_key_exists( 'result', $auto_connect ) && array_key_exists( 'message', $auto_connect ) ) {
			if ( 'error' === $auto_connect['result'] ) {
				printf(
					'<div class="amazon-pay notice notice-error"><p>%s</p></div>',
					wp_kses_post( $auto_connect['message'] )
				);
			} else {
				printf(
					'<div class="amazon-pay notice notice-success"><p>%s</p></div>',
					wp_kses_post( $auto_connect['message'] )
				);
			}
			// Delete the result now that it has been reported.
			update_option(
				$this->option_key,
				array_merge(
					(array) $this->controller->options,
					[ 'auto_connect' => [] ]
				)
			);
		}

		printf(
			'<form method="POST" action="%s">',
			esc_url( $this_page_url )
		);

		$this->register_settings_fields();
		settings_fields( $this->option_key );
		do_settings_sections( $this->option_key );
		printf(
			'<button type="submit" class="button blue button-primary">%s</button></form>',
			esc_html( $this->i18n( 'save_settings' ) )
		);
		$this->footer_links();
		$this->merchant_registration_form();

		do_action( 'piwa_admin_settings_page_footer' );

		echo '</div>';
	}

	public function footer_links() {
		$link_sections = [
			'manage_payments'      => [
				[
					'label'   => $this->i18n( 'review_payments' ),
					'tooltip' => $this->i18n( 'review_payments_tooltip' ),
					'url'     => add_query_arg( [ 'post_type' => Payments::POST_TYPE ], admin_url( 'edit.php' ) ),
				],
				[
					'label'   => $this->i18n( 'accept_payments' ),
					'tooltip' => $this->i18n( 'accept_payments_tooltip' ),
					'url'     => 'https://sellercentral.amazon.com/hz/me/pmd/manage-transactions/ref=xx_pyoptr_favb_xx',
				],
			],
			'additional_resources' => [
				[
					'label'   => $this->i18n( 'piwa_help_page' ),
					'tooltip' => $this->i18n( 'piwa_help_page_tooltip' ),
					'url'     => 'https://pay.amazon.com/help',
				],
				[
					'label'   => $this->i18n( 'technical_documentation' ),
					'tooltip' => $this->i18n( 'technical_documentation_tooltip' ),
					'url'     => plugins_url( 'docs/index.html', $this->plugin_file ),
				],
			],
		];

		echo '<nav class="footer-links">';
		foreach ( $link_sections as $header => $links ) {
			printf(
				'<ul><h3>%s</h3>',
				esc_html( $this->i18n( $header ) )
			);
			foreach ( $links as $link ) {
				printf(
					'<li><a href="%s" data-tooltip="%s">%s</a></li>',
					esc_url( $link['url'] ),
					esc_attr( $link['tooltip'] ),
					esc_html( $link['label'] )
				);
			}
			echo '</ul>';
		}
		echo '</nav>';
	}

	public function merchant_account_fields( $args ) {
		$connection_type = $this->merchant_account_types[0]['id'];
		if ( array_key_exists( 'connection_type', (array) $this->get_option( 'merchant_account' ) ) ) {
			if ( ! empty( $this->get_option( 'merchant_account' )['connection_type'] ) ) {
				$connection_type = $this->get_option( 'merchant_account' )['connection_type'];
			}
		}

		echo '<nav>';
		foreach ( $this->merchant_account_types as $tab ) {
			printf(
				'<label><input type="radio" name="%s[%s][connection_type]" value="%s" %s /> %s</label> ',
				esc_attr( $this->option_key ),
				esc_attr( $args['id'] ),
				esc_attr( $tab['id'] ),
				checked( $connection_type, $tab['id'], false ),
				esc_html( $tab['label'] )
			);
		}
		echo '</nav>';

		foreach ( $this->merchant_account_types as $tab ) {
			printf(
				'<article id="account-tab-%s">%s</article>',
				esc_attr( $tab['id'] ),
				wp_kses(
					call_user_func_array( $tab['callback'], [ $args ] ),
					[
						'br'       => [],
						'div'      => [
							'class' => true,
							'id'    => true,
						],
						'p'        => [ 'class' => true ],
						'ol'       => [ 'class' => true ],
						'li'       => [ 'class' => true ],
						'code'     => [ 'class' => true ],
						'a'        => [
							'class'  => true,
							'href'   => true,
							'target' => true,
							'id'     => true,
						],
						'textarea' => [
							'class'       => true,
							'cols'        => true,
							'rows'        => true,
							'id'          => true,
							'name'        => true,
							'placeholder' => true,
						],
						'input'    => [
							'class' => true,
							'type'  => true,
							'id'    => true,
							'name'  => true,
							'value' => true,
						],
						'label'    => [
							'class' => true,
							'for'   => true,
							'id'    => true,
						],
						'button'   => [
							'class'        => true,
							'type'         => true,
							'id'           => true,
							'data-tooltip' => true,
							'data-reset'   => true,
						],
					]
				)
			);
		}
	}

	public function account_tab_automatic( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		ob_start();

		$this->merchant_registration_form_proxy_button();

		return ob_get_clean();
	}

	public function account_tab_send_public_key( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		ob_start();

		$connection = $this->credentials_can_connect( 'sent' );

		if ( in_array( $connection['status'], [ 'invalid', 'error' ], true ) ) {
			printf(
				'<div class="amazon-pay notice notice-error inline"><p>%s</p></div>',
				wp_kses_post( $connection['message'] )
			);
		}
		printf(
			'<div class="notice amazon-pay inline"><p>%s</p></div>',
			( 'success' === $connection['status'] )
				? wp_kses_post( sprintf( $this->i18n( 'method_connected' ), $this->i18n( 'tab_send_public_key' ) ) )
				. ( ( 'sent' !== $this->get_option( 'merchant_account' )['connection_type'] )
					? ' ' . wp_kses_post( $this->i18n( 'save_to_use_method' ) )
					: ' ' . wp_kses_post( $this->i18n( 'this_is_active_method' ) ) )
				: wp_kses_post( sprintf( $this->i18n( 'method_not_connected' ), $this->i18n( 'tab_send_public_key' ) ) ),
		);

		printf(
			'<p>
				<button type="button" class="button" data-reset="sent">%s</button>
			</p>
			<p class="reset-confirm">
				%s<br/>
				<button type="button" class="button reset-confirm">%s</button>
			</p>',
			esc_html( $this->i18n( 'reset_key' ) ),
			esc_html( $this->i18n( 'reset_key_verification' ) ),
			wp_kses_post( $this->i18n( 'yes_delete' ) ),
			( 'success' === $connection['status'] )
				? ( ( 'sent' !== $this->get_option( 'merchant_account' )['connection_type'] )
				? wp_kses_post( $this->i18n( 'save_to_use_method' ) )
				: wp_kses_post( $this->i18n( 'this_is_active_method' ) )
			) : ''
		);

		$sent = [
			'public'        => $this->get_key( 'public', 'sent' ),
			'public_key_id' => $this->get_key( 'public_key_id', 'sent' ),
		];

		printf(
			'<div class="public_key"><ol class="help">%s</ol>',
			wp_kses(
				$this->i18n( 'how_to_create_key' ),
				[
					'code' => [],
					'a'    => [
						'href'   => true,
						'class'  => true,
						'target' => true,
					],
					'li'   => [],
				]
			)
		);

		printf(
			'<label for="send_public_key">%s</label><textarea id="send_public_key">%s</textarea></div>',
			esc_html( $this->i18n( 'public_key' ) ),
			esc_html( sanitize_textarea_field( $sent['public'] ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		printf(
			'<p><label for="sent_public_key_id">%s</label><input id="sent_public_key_id" name="%s[keys][sent][public_key_id]" value="%s" /></p>',
			esc_html( $this->i18n( 'public_key_id' ) ),
			esc_attr( $this->option_key ),
			esc_attr( sanitize_text_field( $sent['public_key_id'] ) )
		);

		return ob_get_clean();
	}

	public function account_tab_receive_public_private_key( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		ob_start();

		$connection = $this->credentials_can_connect( 'receive' );

		if ( in_array( $connection['status'], [ 'invalid', 'error' ], true ) ) {
			printf(
				'<div class="amazon-pay notice notice-error inline"><p>%s</p></div>',
				wp_kses_post( $connection['message'] )
			);
		}
		printf(
			'<div class="notice amazon-pay inline"><p>%s</p></div>',
			( 'success' === $connection['status'] )
				? wp_kses_post( sprintf( $this->i18n( 'method_connected' ), $this->i18n( 'tab_receive_public_private_key' ) ) )
				. ( ( 'receive' !== $this->get_option( 'merchant_account' )['connection_type'] )
					? ' ' . wp_kses_post( $this->i18n( 'save_to_use_method' ) )
					: ' ' . wp_kses_post( $this->i18n( 'this_is_active_method' ) ) )
				: wp_kses_post( sprintf( $this->i18n( 'method_not_connected' ), $this->i18n( 'tab_receive_public_private_key' ) ) ),
		);

		printf(
			'<p>
				<button type="button" class="button" data-reset="receive">%s</button>
			</p>
			<p class="reset-confirm">
				%s<br/>
				<button type="button" class="button reset-confirm">%s</button>
			</p>',
			esc_html( $this->i18n( 'reset_key' ) ),
			esc_html( $this->i18n( 'reset_key_verification' ) ),
			wp_kses_post( $this->i18n( 'yes_delete' ) ),
			( 'success' === $connection['status'] )
				? ( ( 'receive' !== $this->get_option( 'merchant_account' )['connection_type'] )
				? wp_kses_post( $this->i18n( 'save_to_use_method' ) )
				: wp_kses_post( $this->i18n( 'this_is_active_method' ) )
			) : ''
		);

		$keys    = (array) $this->get_option( 'keys' );
		$receive = [
			'private'       => '',
			'public_key_id' => '',
		];
		if ( array_key_exists( 'receive', $keys ) ) {
			if ( array_key_exists( 'private', $keys['receive'] ) ) {
				$receive['private'] = $keys['receive']['private'];
			}
			if ( array_key_exists( 'public_key_id', $keys['receive'] ) ) {
				$receive['public_key_id'] = $keys['receive']['public_key_id'];
			}
		}

		printf(
			'<ol class="help">%s</ol>',
			wp_kses(
				$this->i18n( 'how_to_receive_key' ),
				[
					'code' => [],
					'a'    => [
						'href'   => true,
						'class'  => true,
						'target' => true,
					],
					'li'   => [],
				]
			),
		);

		printf(
			'<div id="receive-dropzone"><label for="receive_private_key">%s</label><textarea id="receive_private_key" name="%s[keys][receive][private]" cols="64" rows="9" placeholder="%s">%s</textarea></div>',
			esc_html( $this->i18n( 'private_key' ) ),
			esc_attr( $this->option_key ),
			esc_attr( $this->i18n( 'private_key_source' ) ),
			esc_html( sanitize_textarea_field( $receive['private'] ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		printf(
			'<p><label for="receive_public_key_id">%s</label><input id="receive_public_key_id" name="%s[keys][receive][public_key_id]" value="%s" /></p>',
			esc_html( $this->i18n( 'public_key_id' ) ),
			esc_attr( $this->option_key ),
			esc_attr( sanitize_text_field($receive['public_key_id'] ) )
		);

		return ob_get_clean();
	}

	public function merchant_registration_form_proxy_button() {
		$connection = $this->credentials_can_connect( 'automatic' );

		if ( in_array( $connection['status'], [ 'invalid', 'error' ], true ) ) {
			printf(
				'<div class="amazon-pay notice notice-error"><p>%s</p></div>',
				wp_kses_post( $connection['message'] )
			);
		}
		printf(
			'<p>
				<button type="button" id="connect_amazon_account_button" %s class="button %s">%s</button>
				<button type="button" class="button" data-reset="automatic">%s</button>
			</p>
			<p class="reset-confirm">%s<br/><button type="button" class="button reset-confirm">%s</button></p>
			<p>%s</p>',
			( ! empty( $connection['tooltip'] ) )
				? sprintf( 'data-tooltip="%s"', esc_attr( $connection['tooltip'] ) )
				: '',
			esc_attr( $connection['status'] ),
			( 'success' === $connection['status'] )
				? esc_html( $this->i18n( 'amazon_account_connected' ) )
				: esc_html( $this->i18n( 'connect_amazon_account' ) ),
			esc_html( $this->i18n( 'reset_key' ) ),
			esc_html( $this->i18n( 'reset_key_verification' ) ),
			wp_kses_post( $this->i18n( 'yes_delete' ) ),
			( 'success' === $connection['status'] )
				? ( ( 'automatic' !== $this->get_option( 'merchant_account' )['connection_type'] )
				? wp_kses_post( $this->i18n( 'save_to_use_method' ) )
				: wp_kses_post( $this->i18n( 'this_is_active_method' ) )
			) : ''
		);
	}

	/**
	 * @see https://developer.amazon.com/docs/amazon-pay-registration/unhostedregistration.html
	 */
	private function merchant_registration_form() {
		echo '<form id="merchant_registration" method="POST" action="https://payments.amazon.com/register" target="_blank">';
		echo '<input type="hidden" value="2" name="onboardingVersion" />';
		printf(
			'<input type="hidden" value="%s" name="merchantLoginDomains[]" />',
			esc_url( str_replace( 'http://', 'https://', site_url() ) )
		);
		printf(
			'<input type="hidden" value="%s" name="spId" />',
			esc_attr( Client::PLATFORM_ID )
		);
		$privacy_policy_url = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		if ( ! empty( $privacy_policy_url ) ) {
			printf(
				'<input type="hidden" value="%s" name="merchantPrivacyNoticeURL" />',
				esc_url( $privacy_policy_url )
			);
		}
		$site_name = get_bloginfo( 'name' );
		if ( ! empty( $site_name ) ) {
			printf(
				'<input type="hidden" value="%s" name="merchantStoreDescription" />',
				esc_attr( $site_name )
			);
		}
		printf(
			'<input type="hidden" value="%s" name="keyShareURL" />',
			esc_url(
				str_replace(
					'http://',
					'https://',
					get_rest_url( null, 'piwa/v1/get-keys' )
				)
			)
		);
		require ABSPATH . WPINC . '/version.php'; // $wp_version.
		printf(
			'<input type="hidden" value="%s" name="spSoftwareVersion" />',
			esc_attr(
				sprintf(
					'WordPress %s %s',
					$wp_version,
					get_locale()
				)
			)
		);
		$plugin_data = get_plugin_data( $this->plugin_file, false, true );
		printf(
			'<input type="hidden" value="%s" name="spAmazonPluginVersion" />',
			esc_attr(
				sprintf(
					'%s %s: %s',
					$plugin_data['Name'],
					$plugin_data['Version'],
					$plugin_data['PluginURI']
				)
			)
		);
		printf(
			'<input type="hidden" value="%s" name="locale" />',
			esc_attr( $this->get_option( 'language' ) )
		);
		printf(
			'<input type="hidden" value="%s" name="publicKey" />',
			esc_attr( $this->single_line_key_without_comments( $this->get_key( 'public', 'registration' ) ) )
		);
		echo '<input type="hidden" value="SPPL" name="source" />';
		printf(
			'<input type="submit" value="%s" />',
			esc_attr( $this->i18n( 'connect_amazon_account' ) )
		);
		echo '</form>';
	}

	/**
	 * Create a new key pair.
	 */
	private function create_keys() {
		$private = RSA::createKey( 2048 );
		return [
			'public'  => (string) $private->getPublicKey(),
			'private' => (string) $private,
		];
	}

	/**
	 * Get a public or private key for a given context.
	 *
	 * @param string $type public|private
	 * @param string $context registration|automatic|sent|receive
	 */
	public function get_key( $type = 'public', $context = 'automatic' ) {
		$keys = (array) $this->get_option( 'keys' );

		if ( 'public_key_id' === $type ) {
			if (
				array_key_exists( $context, $keys )
				&& array_key_exists( $type, $keys[ $context ] )
			) {
				return (string) $keys[ $context ][ $type ];
			} else {
				return '';
			}
		}

		if (
			array_key_exists( $context, $keys )
			&& array_key_exists( $type, $keys[ $context ] )
			&& ! empty( $keys[ $context ][ $type ] )
		) {
			return (string) $keys[ $context ][ $type ];
		}

		return false;
	}

	public function single_line_key_without_comments( $key ) {
		$lines = [];
		foreach ( explode( PHP_EOL, $key ) as $line ) {
			if ( false === strpos( $line, 'KEY---' ) ) {
				$lines[] = trim( $line );
			}
		}
		return implode( '', $lines );
	}

	/**
	 * Get hex color values from the current user's WP Admin color settings.
	 */
	public function get_admin_colors() {
		return $GLOBALS['_wp_admin_css_colors'][ get_user_option( 'admin_color' ) ]->colors;
	}

	/**
	 * Output WP Admin color preferences as CSS variables.
	 */
	public function output_admin_colors_as_css_vars() {
		?>
        :root {
		<?php
		foreach ( $this->get_admin_colors() as $key => $admin_color_hex ) {
			printf(
				'--admin-color-%d: %s;',
				sanitize_key( $key ),
				sanitize_hex_color( $admin_color_hex )
			);
		}
		?>
        }
		<?php
	}

	public function text_field( $args ) {
		printf(
			'<input type="%s" id="%s" name="%s" value="%s" %s /> %s',
			esc_attr( $args['type'] ),
			esc_attr( $args['id'] ),
			esc_attr( $args['name'] ),
			esc_attr( $this->get_option( $args['field_id'] ) ),
			disabled( $args['is_set_by_env'], true, false ),
			! empty( $args['after'] ) ? wp_kses_post( $args['after'] ) : ''
		);
	}
	public function checkbox_field( $args ) {
		printf(
			'<input type="checkbox" id="%s" name="%s" value="1" %s %s /> %s',
			esc_attr( $args['id'] ),
			esc_attr( $args['name'] ),
			checked( intval( $this->get_option( $args['field_id'] ) ), 1, false ),
			disabled( $args['is_set_by_env'], true, false ),
			! empty( $args['after'] ) ? wp_kses_post( $args['after'] ) : ''
		);
	}
	public function textarea_field( $args ) {
		printf(
			'<textarea id="%s" name="%s" %s >%s</textarea>',
			esc_attr( $args['id'] ),
			esc_attr( $args['name'] ),
			disabled( $args['is_set_by_env'], true, false ),
			esc_textarea( (string) $this->get_option( $args['field_id'] ) )
		);
	}

	public function select_field( $args ) {
		printf(
			'<select id="%s" name="%s" %s>',
			esc_attr( $args['id'] ),
			esc_attr( $args['name'] ),
			disabled( $args['is_set_by_env'], true, false )
		);

		foreach ( (array) $args['options'] as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $value, $this->get_option( $args['field_id'] ), false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	public function min_max_field( $args ) {
		/**
		 * The absolute highest payment that can be made, overridable with a filter.
		 */
		$absolute_maximum = apply_filters( 'piwa_payment_absolute_maximum', 25000 );
		$min_max          = (array) $this->get_option( 'min_max' );
		$display_feedback = ( array_key_exists( 'display_feedback', $min_max ) && 'yes' === $min_max['display_feedback'] );
		$min_value        = is_array( $min_max ) && array_key_exists( 'min', $min_max ) && is_numeric( $min_max['min'] )
			? str_replace( '.00', '', number_format( floatval( $min_max['min'] ), 2, '.', '' ) )
			: 1;
		$max_value        = is_array( $min_max ) && array_key_exists( 'max', $min_max ) && is_numeric( $min_max['max'] )
			? str_replace( '.00', '', number_format( floatval( $min_max['max'] ), 2, '.', '' ) )
			: $absolute_maximum;
		printf(
			'<label>
				<input type="checkbox" value="yes" name="%s[display_feedback]" %s />
				%s
			</label>
			<div id="%s">
				<input name="%s[min]" type="range" min="1" max="%s" value="%s" />
				<input name="%s[max]" type="range" min="1" max="%s" value="%s" />
				<label class="min-proxy">
					<span class="currency-symbol">%s</span>
					<input value="%s" />
				</label>
				<label class="max-proxy">
					<span class="currency-symbol">%s</span>
					<input value="%s" />
				</label>
			</div>',
			esc_attr( $args['name'] ),
			checked( $display_feedback, true, false ),
			esc_html( $this->i18n( 'set_amount_limits' ) ),
			esc_attr( $args['id'] ),
			esc_attr( $args['name'] ),
			esc_attr( intval( $absolute_maximum ) ),
			esc_attr( $min_value ),
			esc_attr( $args['name'] ),
			esc_attr( intval( $absolute_maximum ) ),
			esc_attr( $max_value ),
			esc_html( $this->i18n['currency_symbols_plain'][ $this->get_option( 'payment_currency' ) ] ),
			esc_html( number_format( floatval( $min_value ), 0 ) ),
			esc_html( $this->i18n['currency_symbols_plain'][ $this->get_option( 'payment_currency' ) ] ),
			esc_html( number_format( floatval( $max_value ), 0 ) )
		);
	}
}
