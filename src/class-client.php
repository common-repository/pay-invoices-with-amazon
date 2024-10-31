<?php

namespace PIWA;

class Client {
	use Singleton;

	const PLATFORM_ID = 'AK1HULRFHGOGD';

	public $config = [];

	public $api = null;

	public $checkout_js_url = 'https://static-na.payments-amazon.com/checkout.js';

	/**
	 * Sets client configuration from plugin settings on class instantiation.
	 *
	 * @param array $atts Contains all the settings from Admin_Settings.
	 *
	 * @see https://developer.amazon.com/docs/amazon-pay-apb-checkout/add-the-amazon-pay-button.html
	 */
	public function parse_attributes( $atts ) {

		$connection_type = false;
		if ( array_key_exists( 'merchant_account', (array) $atts ) && array_key_exists( 'connection_type', (array) $atts['merchant_account'] ) ) { // phpcs:ignore Generic.PHP.Syntax.PHPSyntax
			$connection_type = (string) @$atts['merchant_account']['connection_type']; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$this->config['sandbox']   = (bool) ! empty( $atts['sandbox_mode'] );
		$this->config['algorithm'] = 'AMZN-PAY-RSASSA-PSS-V2';

		if ( $this->plugin_is_configured( $connection_type ) ) {
			switch ( $connection_type ) {
				default:
				case 'automatic':
					$this->config['public_key_id'] = $this->admin_settings->get_key( 'public_key_id', 'automatic' );
					$this->config['private_key']   = $this->admin_settings->get_key( 'private', 'automatic' );
					break;
				case 'sent':
					$this->config['public_key_id'] = $this->admin_settings->get_key( 'public_key_id', 'sent' );
					$this->config['private_key']   = $this->admin_settings->get_key( 'private', 'sent' );
					break;
				case 'receive':
					$this->config['public_key_id'] = $this->admin_settings->get_key( 'public_key_id', 'receive' );
					$this->config['private_key']   = $this->admin_settings->get_key( 'private', 'receive' );
					break;
			}
		}

		/**
		 * @see https://developer.amazon.com/docs/amazon-pay-apb-checkout/add-the-amazon-pay-button.html
		 * @see https://developer.amazon.com/docs/amazon-pay-checkout/multi-currency-integration.html
		 * @see https://github.com/amzn/amazon-pay-api-sdk-php/blob/4583b7320e96e18fe32bef1830d7a968c0cebf49/Amazon/Pay/API/Client.php#L26C5-L43
		 */
		switch ( $atts['region'] ) {
			default:
			case 'NA':
			case 'US':
				$this->config['region'] = 'NA';
				$this->checkout_js_url  = 'https://static-na.payments-amazon.com/checkout.js';
				break;
			case 'EU':
			case 'UK':
				$this->config['region'] = 'EU';
				$this->checkout_js_url  = 'https://static-eu.payments-amazon.com/checkout.js';
				break;
			case 'JP':
				$this->config['region'] = 'JP';
				$this->checkout_js_url  = 'https://static-fe.payments-amazon.com/checkout.js';
				break;
		}

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		wp_register_script(
			'amazon-pay-modal',
			plugins_url( 'src/js/tingle' . $suffix . '.js', $this->plugin_file ),
			[],
			md5_file( dirname( $this->plugin_file ) . '/src/js/tingle' . $suffix . '.js' ),
			true
		);

		wp_register_script(
			'amazon-pay-modal-init',
			plugins_url( 'src/js/amazon-pay-modal-init.js', $this->plugin_file ),
			[],
			md5_file( dirname( $this->plugin_file ) . '/src/js/amazon-pay-modal-init.js' ),
			true
		);

		wp_register_script( 'amazon-pay-checkout', $this->checkout_js_url, [], 1, true );
		wp_register_script(
			'amazon-pay-buttons-init',
			plugins_url( 'src/js/amazon-pay-buttons-init.js', $this->plugin_file ),
			[ 'amazon-pay-checkout' ],
			md5_file( dirname( $this->plugin_file ) . '/src/js/amazon-pay-buttons-init.js' ),
			true
		);
	}

	public function get( $config = [] ) {
		if ( ! empty( $config ) ) {
			$this->parse_attributes( $config );
			$this->api = new \Amazon\Pay\API\Client( $this->config );
		} elseif ( is_null( $this->api ) ) {
			$this->api = new \Amazon\Pay\API\Client( $this->config );
		}
		return $this->api;
	}

	/**
	 * @param array $atts Attributes passed by Render::render(). The values might be overridden compared to plugin configuration.
	 */
	public function get_button_config( $atts ) {
		if ( ! $this->plugin_is_configured() ) {
			return [];
		}

		if ( array_key_exists( 'figure_id', $atts ) ) {
			// Counter and ID provided by a rest request.
			// See Render::rest_sign() and amazon-pay-buttons-init.js.
			$figure_id_attr     = explode( '-', $atts['figure_id'] );
			$atts['invoice_id'] = $figure_id_attr[2];
			$counter            = $figure_id_attr[3];
		} else {
			static $counter = -1;
			++$counter;

			if ( ! array_key_exists( 'invoice_id', $atts ) || empty( $atts['invoice_id'] ) ) {
				$atts['invoice_id'] = get_the_ID();
			}
		}

		if ( array_key_exists( 'invoice_number', $atts ) && ! empty( $atts['invoice_number'] ) ) {
			$invoice_number = $atts['invoice_number'];
		} else {
			$invoice_number = intval( $atts['invoice_id'] );
		}

		if ( empty( $atts['amount'] ) ) {
			$atts['amount'] = floatval( 0.50 );
		}

		/**
		 * @see https://developer.amazon.com/docs/amazon-pay-apb-checkout/add-the-amazon-pay-button.html
		 */
		$payload = [
			'webCheckoutDetails'   => [
				'checkoutResultReturnUrl' => esc_url_raw(
					add_query_arg(
						array_merge(
							[
								'ap-payment-id' => sprintf( '%d-%d', $atts['invoice_id'], $counter ),
							],
							( array_key_exists( 'title', $atts ) && ! empty( $atts['title'] ) )
								? [ 'ap-title' => $atts['title'] ]
								: []
						),
						str_replace(
							// Payment processing will not work if the redirect is HTTP.
							'http://',
							'https://',
							( defined( 'REST_REQUEST' ) && REST_REQUEST ) ? filter_var($_SERVER['HTTP_REFERER'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) : get_permalink( (int) $atts['invoice_id'] )
						)
					) . sprintf(
						'#container-amazon-pay-%d%s',
						$atts['invoice_id'],
						( 0 === $counter ) ? '' : '-' . intval( $counter )
					)
				),
				'checkoutMode'            => 'ProcessOrder',
			],
			'paymentDetails'       => [
				'paymentIntent'                 => 'Authorize',
				'chargeAmount'                  => [
					'amount'       => $atts['amount'],
					'currencyCode' => $this->get_option( 'payment_currency' ),
				],
				'presentmentCurrency'           => $this->get_option( 'payment_currency' ),
				'canHandlePendingAuthorization' => true,
			],
			'merchantMetadata'     => [
				'merchantReferenceId' => $invoice_number,
				'merchantStoreName'   => mb_substr(
					$atts['store_name'],
					0,
					50 // Max length: 50 characters/bytes.
				),
				'noteToBuyer'         => mb_substr(
					$atts['note_to_buyer'],
					0,
					255 // Max length: 255 characters/bytes.
				),
			],
			'platformId'           => self::PLATFORM_ID,
			'chargePermissionType' => 'OneTime',
			'storeId'              => $atts['client_id_store_id'],
		];

		/**
		 * json_encode() will fail if non-UTF-8 encodings are present.
		 *
		 * @see vendor/amzn/amazon-pay-api-sdk-php/Amazon/Pay/API/Client.php:491
		 */
		array_walk_recursive(
			$payload,
			function ( &$item ) {
				if ( is_string( $item ) && false === mb_detect_encoding( $item, 'UTF-8', true ) ) {
					$item = mb_convert_encoding( $item, 'UTF-8', mb_detect_encoding( $item ) );
				}
			}
		);
		/**
		 * Format needs to match Amazon SDK signature encoding exactly, so wp_json_encode() is not appropriate.
		 */
		$payload_json = stripcslashes( json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		if ( (float) 0.50 === floatval( $atts['amount'] ) ) {
			$transient_key = sprintf( '%s_%s', $this->option_key, md5( $payload_json ) );
			$signature     = get_transient( $transient_key );
			if ( empty( $signature ) ) {
				$signature = $this->get()->generateButtonSignature( $payload_json );
				set_transient( $transient_key, $signature, DAY_IN_SECONDS );
			}
		} else {
			$signature = $this->get()->generateButtonSignature( $payload_json );
		}

		return [
			'id'       => sprintf(
				'amazon-pay-%d%s',
				$atts['invoice_id'],
				( 0 === $counter ) ? '' : '-' . intval( $counter )
			),
			'config'   => [
				'merchantId'                  => $atts['merchant_id'],
				'publicKeyId'                 => $this->config['public_key_id'],
				'sandbox'                     => $this->config['sandbox'],
				'ledgerCurrency'              => $this->get_option( 'ledger_currency' ),
				'checkoutLanguage'            => $this->get_option( 'language' ),
				'productType'                 => 'PayOnly', // PayAndShip or PayOnly
				'placement'                   => 'Other',
				'buttonColor'                 => 'Gold',
				'createCheckoutSessionConfig' => [
					/**
					 * payloadJSON is JSON as string within JSON as object within an HTML data attribute or REST response. payloadJSON string needs to match signature exactly or payment will fail, so data is encoded to preserve integrity. Decoding occurs with atob() in amazon-pay-buttons-init.js::maybe_render_amazon_buttons().
					 */
					'payloadJSON' => base64_encode( $payload_json ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'signature'   => $signature,
					'algorithm'   => 'AMZN-PAY-RSASSA-PSS-V2',
				],
			],
			'disabled' => ( floatval( 0.50 ) === floatval( $atts['amount'] ) ) ? 'true' : 'false',
		];
	}

	public function get_checkout_session( $session_id ) {
		try {
			$result = $this->get()->getCheckoutSession( $session_id );
			if ( 200 === $result['status'] ) {
				return json_decode( $result['response'], true );
			} else {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					sprintf(
						'Amazon Pay: Failed to get checkout session %s. Status: %s. API Response: %s',
						esc_html( $session_id ),
						esc_html( $result['status'] ),
						esc_html( print_r( $result['response'], true ) ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
					)
				);
			}
		} catch ( \Exception $e ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'Amazon Pay: Attempt to get checkout session %s failed with Exception: %s',
					esc_html( $session_id ),
					esc_html( $e->getMessage() )
				)
			);
		}
	}

	/**
	 * @return array Array containing key of post_status and value of message.
	 */
	public function maybe_complete_checkout_session( $response ) {
		try {
			$payload = [ 'chargeAmount' => $response['paymentDetails']['chargeAmount'] ];
			if ( ! empty( $response['paymentDetails']['totalOrderAmount'] ) ) {
				$payload['totalOrderAmount'] = $response['paymentDetails']['totalOrderAmount'];
			}

			$checkout_session = $this->get()->completeCheckoutSession( $response['checkoutSessionId'], $payload );

			$status = 999;
			if ( array_key_exists( 'status', (array) $checkout_session ) ) {
				$status = intval( $checkout_session['status'] );
			}
			$checkout_session_response = [];
			if ( array_key_exists( 'response', $checkout_session ) ) {
				$checkout_session_response = json_decode( $checkout_session['response'], true, 512, JSON_THROW_ON_ERROR );
			}

			switch ( $status ) {
				case 200:
					// Success.
					if (
						array_key_exists( 'statusDetails', (array) $checkout_session_response )
						&& array_key_exists( 'state', (array) $checkout_session_response['statusDetails'] )
						&& 'Completed' === $checkout_session_response['statusDetails']['state']
					) {
						return [
							'status_code'               => $status,
							'payment_status'            => 'paid',
							'payment_status_message'    => 'Completed',
							'checkout_session_response' => $checkout_session_response,
						];
					}
					// Success, but not "Completed". Should never happen.
					return [
						'status_code'               => $status,
						'payment_status'            => null, // Unexpected. Don't update payment_status.
						'payment_status_message'    => 'Checkout session returned 200 success but not completed.',
						'checkout_session_response' => $checkout_session_response,
					];
				case 202:
					// Pending.
					return [
						'status_code'               => $status,
						'payment_status'            => 'pending_authorization',
						'payment_status_message'    => 'Authorization Pending.',
						'checkout_session_response' => $checkout_session_response,
					];
			}
			// Authorization denied could return anything in the 4xx to 5xx range.
			if ( 4 === intval( substr( (string) $status, 0, 1 ) ) || 5 === intval( substr( (string) $status, 0, 1 ) ) ) {
				return [
					'status_code'               => $status,
					'payment_status'            => 'declined',
					'payment_status_message'    => 'Payment Declined',
					'checkout_session_response' => $checkout_session_response,
				];
			}
			return [
				'status_code'               => $status,
				'payment_status'            => 'error',
				'payment_status_message'    => sprintf(
					'Amazon Pay: Failed to complete checkout session %s. Status: %s. API Response: %s',
					esc_html( $response['checkoutSessionId'] ),
					esc_html( $checkout_session['status'] ),
					esc_html( print_r( $checkout_session['response'], true ) ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
				),
				'checkout_session_response' => $checkout_session_response,
			];
		} catch ( \Exception $e ) {
			return [
				'status_code'               => $status,
				'payment_status'            => 'error',
				'payment_status_message'    => sprintf(
					'Attempt to complete checkout session %s failed with Exception: %s',
					esc_html( $response['checkoutSessionId'] ),
					esc_html( $e->getMessage() )
				),
				'checkout_session_response' => $checkout_session_response,
			];
		}
	}
}
