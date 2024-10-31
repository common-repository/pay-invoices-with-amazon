<?php

namespace PIWA;

class Render {
	use Singleton;

	public $defaults = [];

	public $buttons_config = [];


	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
		add_action( 'wp_footer', [ $this, 'wp_footer' ] );
	}

	public function rest_api_init() {
		register_rest_route(
			'piwa/v1',
			'/sign',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_sign' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function rest_sign( \WP_REST_Request $request ) {

		if ( $request->get_param( 'invoice_number' ) ) {
			$invoice_number = $request->get_param( 'invoice_number' );
		} else {
			$invoice_number = null;
		}

		$atts = wp_parse_args(
			[
				// See Client::get_button_config() and amazon-pay-buttons-init.js.
				'figure_id'      => $request->get_param( 'figure_id' ),
				'amount'         => $request->get_param( 'amount' ),
				'invoice_number' => $invoice_number,
			],
			$this->get_default_atts()
		);

		return new \WP_REST_Response(
			$this->client->get_button_config( $atts ),
			200
		);
	}

	public function wp_footer() {
		if ( empty( $this->buttons_config ) ) {
			return;
		}

		$this->output_css();
		wp_enqueue_style(
			'amazon-pay-modal',
			plugins_url( 'src/css/tingle.min.css', $this->plugin_file ),
			[],
			md5_file( dirname( $this->plugin_file ) . '/src/css/tingle.min.css' )
		);
		wp_enqueue_script( 'amazon-pay-modal' );

		wp_enqueue_script( 'amazon-pay-buttons-init' );
		wp_localize_script( 'amazon-pay-buttons-init', 'payInvoicesWithAmazon', $this->get_buttons_init_i18n() );

		/**
		 * In order to display appropriate title if set, it's necessary to identify which block was the source of payment.
		 */
		$config = [];
		if ( array_key_exists( 'ap-payment-id', $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			foreach ( $this->buttons_config as $button_config ) {
				if ( sanitize_text_field($_GET['ap-payment-id']) === $button_config['payment_id'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$config = $button_config;
				}
			}
		}
		wp_enqueue_script( 'amazon-pay-modal-init' );
		wp_localize_script( 'amazon-pay-modal-init', 'PIWAModal', $this->get_modal_config_init_i18n( $config ) );
	}

	public function output_css() {
		?>
		<style id="amazon-pay-button-css">
			/* Button and form wrapper. */
			figure.amazon-pay-container > div {
				display: inline-block;
				flex-direction: column;
				justify-content: center;
				align-items: center;
				background-color: white;
				padding: 1.5em;
				padding-top:1em;
				border-radius: 10px;
				position: relative;
				transition: height 3s ease-out;
				overflow: hidden;
				border: 1px solid;
				margin: 1em 0;
				min-width: 350px;
			}
			figure.amazon-pay-container form {
				display: block;
				flex-direction: column;
				justify-content: center;
			}
			figure.amazon-pay-container form input[type="submit"] {
				display: none;
			}
			/* Items that should act like rows within the container. */
			figure.amazon-pay-container form, figure.amazon-pay-container figcaption, figure.amazon-pay-container .amazon-pay {
				display: flex;
				width: 100%;
				justify-content: center;
				align-items: center;
			}
			figure.amazon-pay-container > div > figcaption:first-child {
				margin-top:0;
			}
			figure.amazon-pay-container > div > figcaption, figure.amazon-pay-container label {
				white-space: nowrap;
				font-weight: 700;
				font-size:.9rem;
				width:100%;
			}
			figure.amazon-pay-container:not( .amazon-pay-flexible ) > div > figcaption {
				font-size: 1.1rem;
				margin-bottom: 10px;
			}
			figure.amazon-pay-container span[data-currency-symbol] {
				position: relative;
				display: inline-block;
				font-size: .9rem;
			}
			figure.amazon-pay-container span[data-currency-symbol]:before {
				content: attr( data-currency-symbol );
				position: absolute;
				left: 5px;
				bottom: 1rem;
				color: rgba( 10, 80, 30, .7 );
			}

			figure.amazon-pay-container .piwa_currency_field, figure.amazon-pay-container .piwa_invoice_number_field {
				padding: 4px 4px 4px 10px;
				border-radius: 5px;
				border: 1px solid rgba( 0,0,0, .5 );
				font-size: .9rem;
				margin: .5em auto;
			}

			figure.amazon-pay-container .piwa_currency_field .piwa_currency_symbol {
				color: rgba( 0, 0, 0, 5 );
			}
			figure.amazon-pay-container input[type="text"] {
				border:0;
				width: 95%;
			}
			/* Form feedback, payment descriptions, and thank you messages. */
			figure.amazon-pay-container figcaption {
				text-align: center;
				justify-content: center;
				color: rgba( 0, 0, 0, .7 );
				font-size: .7rem;
				font-weight: 700;
				margin: 10px 0 0 0;
				position: relative;
			}
			/* Feedback. */
			figure.amazon-pay-container figcaption.amazon-pay-feedback {
				background-color: rgb(240, 223, 147);
				background: linear-gradient(to bottom, rgb(240, 223, 147), rgb(231, 197, 62));
				padding: 3px;
				border-radius: 2px;
				border: 1px solid rgba( 0,0,0, .7 );
			}
			/* Thank you. */
			figure.amazon-pay-container figcaption.amazon-pay-thank-you,
			figure.amazon-pay-container figcaption.amazon-pay-declined {
				background-color: rgba(10, 80, 30, .7);
				background: linear-gradient(to bottom, rgba(20, 100, 40, .7), rgba(10, 80, 30, .7));
				color: rgba( 255,255,255, .7);
				text-align: center;
				font-size: .7rem;
				padding: 3px;
				border-radius: 2px;
				margin: 0 0 10px 0;
			}
			figure.amazon-pay-container figcaption.amazon-pay-declined {
				background-color: rgba(80, 10, 30, .7);
				background: linear-gradient(to bottom, rgba(100, 20, 40, .7), rgba(80, 10, 30, .7));
			}
			/* Flexible form displaying button. */
			figure.amazon-pay-flexible .amazon-pay {
				margin-top: 10px;
			}
			/*
			 * Make the block non-interactive and greyscale.
			 */
			figure.amazon-pay-container .amazon-pay-disabled {
				position: relative;
			}
			figure.amazon-pay-container .amazon-pay-disabled * {
				filter: grayscale(100%);
				opacity: .8;
			}
			figure.amazon-pay-container .amazon-pay-disabled::before {
				content: "";
				width: 100%;
				height: 100%;
				position:absolute;
				z-index: 1000;
				display:block;
			}
		</style>
		<?php
	}

	public function payment_processed( $invoice_id, $counter ) {
		if (
			array_key_exists( 'amazonCheckoutSessionId', $_GET ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& array_key_exists( 'ap-payment-id', $_GET ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {

			$checkout_session_id                                = sanitize_key( $_GET['amazonCheckoutSessionId'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$payment_source_id                                  = intval( explode( '-', sanitize_text_field($_GET['ap-payment-id']) )[0] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			list( $payment_source_id, $payment_source_counter ) = explode( '-', sanitize_key( $_GET['ap-payment-id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if (
				intval( $payment_source_id ) === intval( $invoice_id )
				&& intval( $payment_source_counter ) === intval( $counter )
			) {
				$payments = (array) get_posts(
					[
						'post_type'      => Payments::POST_TYPE,
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'post_parent'    => $payment_source_id,
						'meta_key'       => 'checkout_session_id',
						'meta_value'     => $checkout_session_id,
					]
				);
				if ( ! empty( $payments ) ) {
					$payment = $payments[0];
					if ( 'publish' === $payment->post_status ) {
						return true;
					} else {
						return false;
					}
				}
			}
		}
		return null;
	}

	public function render( $atts = [], $content = '', $block_or_tagname = '' ) {
		ob_start();

		static $counter = -1;
		++$counter;

		$atts               = wp_parse_args( $atts, $this->get_default_atts() );
		$atts['invoice_id'] = intval( get_the_ID() );

		if (
			(
				// Block.
				is_a( $block_or_tagname, '\WP_Block' )
				&& 'piwa/customer-price' === $block_or_tagname->parsed_block['blockName']
			)
			|| (
				/**
				 * Shortcode.
				 *
				 * @todo Verify functionality. Changes have been made for the blocks. Shortcode may yield unexpected results.
				 */
				is_string( $block_or_tagname )
				&& empty( $atts['amount'] )
			)
		) {
			/**
			 * Customer sets price.
			 */
			$atts['figure_id'] = sprintf(
				'container-flexible-%d-%d',
				$atts['invoice_id'],
				intval( $counter )
			);

			if ( empty( $atts['amount'] ) || 1 >= intval( $atts['amount'] ) ) {
				$atts['amount'] = false;
			}

			$config                 = (array) $this->client->get_button_config( $atts );
			$this->buttons_config[] = array_merge(
				[
					'invoice_id'   => $atts['invoice_id'],
					'container_id' => sprintf( 'container-flexible-%d-%d', intval( get_the_ID() ), intval( $counter ) ),
					'payment_id'   => sprintf( '%d-%d', intval( get_the_ID() ), intval( $counter ) ),
					'title'        => ( array_key_exists( 'title', $atts ) ) ? $atts['title'] : '',
				],
				$config
			);
			if ( array_key_exists( 'config', $config ) ) {
				$button = sprintf(
					'<div id="%s" class="amazon-pay" data-disabled="%s" data-config="%s"></div>',
					esc_attr( $config['id'] ),
					( empty( $atts['amount'] ) || floatval( 0.5 ) === floatval( $atts['amount'] ) ) ? 'true' : 'false',
					esc_attr( wp_json_encode( (array) $config['config'] ) )
				);
			} else {
				ob_start();
				$this->please_configure();
				$button = ob_get_clean();
			}

			$min_max          = (array) $this->get_option( 'min_max' );
			$min              = ( array_key_exists( 'min', $min_max ) && is_numeric( $min_max['min'] ) )
				? number_format( floatval( $min_max['min'] ), 2 ) : 1; // Minimum needs to be at least 1.
			$max              = ( array_key_exists( 'max', $min_max ) && is_numeric( $min_max['max'] ) )
				? number_format( floatval( $min_max['max'] ), 2 ) : '';
			$display_feedback = ( array_key_exists( 'display_feedback', $min_max ) && 'yes' === $min_max['display_feedback'] )
				? 'yes' : 'no';

			printf(
				'<figure class="amazon-pay-container amazon-pay-flexible" id="%s">
					<div>
						<form>
							<input name="figure_id" type="hidden" value="%s" />
							%s
							<label>
								%s*
								<div class="piwa_currency_field" data-currency-symbol="%s">
									<span class="piwa_currency_symbol">%s</span> <input name="amount" type="text" placeholder="%s" min="%s" max="%s" data-display-feedback="%s" />
								</div>
							</label>
							<input type="submit" />
						</form>
						<div>
							%s
						</div>
					</div>
				</figure>',
				esc_attr( $atts['figure_id'] ),
				esc_attr( $atts['figure_id'] ),
				( true === (bool) $atts['show_customer_invoice_input'] )
					? sprintf(
						'<label>
							%s
							<div class="piwa_invoice_number_field">
								<input name="invoice_number" type="text" placeholder="%s"/>
							</div>
						</label>',
						esc_html( $this->i18n( 'invoice_number' ) ),
						esc_attr( $this->i18n( 'enter_invoice_number' ) )
					)
					: '',
				esc_html( $this->i18n( 'pay' ) ),
				esc_attr( $this->i18n( 'currency_symbols_plain' )[ $this->get_option( 'payment_currency' ) ] ),
				esc_attr( $this->i18n( 'currency_symbols_plain' )[ $this->get_option( 'payment_currency' ) ] ),
				esc_attr( $this->i18n( 'enter_amount' ) ),
				esc_attr( $min ),
				esc_attr( $max ),
				esc_attr( $display_feedback ),
				wp_kses(
					$button,
					[
						'div' => [
							'data-disabled' => true,
							'data-config'   => true,
							'id'            => true,
							'class'         => true,
						],
						'a'   => [ 'href' => true ],
					]
				)
			);

			return ob_get_clean();
		} else {
			/**
			 * Merchant sets price.
			 */
			if (
				! array_key_exists( 'amount', $atts )
				|| (
					empty( $atts['amount'] )
					&& ! empty( get_post_meta( $atts['invoice_id'], '_price', true ) )
				)
			) {
				$atts['amount'] = get_post_meta( $atts['invoice_id'], '_price', true );
			}
			if ( empty( $atts['amount'] ) ) {
				// The default is 100. Block editor appears to not save the attribute if it is the default value.
				$atts['amount'] = 100;
			}
		}

		$atts['figure_id'] = sprintf(
			'container-fixed-%d-%d',
			$atts['invoice_id'],
			intval( $counter )
		);

		$config                 = $this->client->get_button_config( $atts );
		$this->buttons_config[] = array_merge(
			[
				'payment_id' => sprintf( '%d-%d', intval( get_the_ID() ), intval( $counter ) ),
				'title'      => ( array_key_exists( 'title', $atts ) ) ? $atts['title'] : '',
			],
			(array) $config
		);

		?>
		<figure class="amazon-pay-container" id="<?php echo esc_attr( $atts['figure_id'] ); ?>">
			<?php if ( ! empty( $atts['invoice_id'] ) && ! empty( $atts['amount'] ) ) : ?>
				<div>
					<figcaption>
					<?php
						printf(
							'%s %s%s %s',
							esc_html( $this->i18n( 'pay_merchant_label' ) ),
							esc_html( $this->i18n( 'currency_symbols_plain' )[ $this->get_option( 'payment_currency' ) ] ),
							esc_html( number_format_i18n( (float) $atts['amount'], 2 ) ),
							( ! empty( $atts['title'] ) )
								? sprintf(
									'%s %s',
									esc_html( $this->i18n( 'for' ) ),
									esc_html( $atts['title'] )
								)
								: ''
						);
					?>
					</figcaption>
					<?php
					if ( array_key_exists( 'config', $config ) ) {
						printf(
							'<div id="%s" class="amazon-pay" data-config="%s"></div>',
							esc_attr( $config['id'] ),
							esc_attr( wp_json_encode( $config['config'] ) )
						);
					} else {
						$this->please_configure();
					}
					?>
				</div>
			<?php endif; ?>
		</figure>
		<?php

		return ob_get_clean();
	}
}
