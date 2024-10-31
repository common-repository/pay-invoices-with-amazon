<?php

namespace PIWA;

class Gutenberg_Blocks {
	use Singleton;

	public function __construct() {
		add_action( 'init', [ $this, 'init' ], 5 );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_assets' ] );
	}

	public function init() {
		register_block_type(
			'piwa/customer-price',
			[
				'render_callback' => 'piwa',
				'attributes'      => [
					'show_customer_invoice_input' => [ 'type' => 'boolean' ],
				],
			]
		);
		register_block_type(
			'piwa/merchant-price',
			[
				'render_callback' => 'piwa',
				'attributes'      => [
					'title'  => [ 'type' => 'string' ],
					'amount' => [ 'type' => 'string' ],
				],
			]
		);
	}

	public function enqueue_block_editor_assets() {
		/**
		 * Frontend CSS.
		 *
		 * @todo Move to a static enqueue.
		 */
		add_action( 'admin_footer', [ $this->render, 'output_css' ] );

		// Block Editor CSS.
		wp_enqueue_style(
			'piwa-admin-block-editor',
			plugins_url( 'src/css/admin-block-editor.css', $this->plugin_file ),
			[],
			md5_file( dirname( __DIR__ ) . '/src/css/admin-block-editor.css' )
		);

		wp_enqueue_script(
			'amazon-pay-buttons-init-block-editor',
			plugins_url( 'src/js/amazon-pay-buttons-init-block-editor.js', $this->plugin_file ),
			[ 'amazon-pay-buttons-init', 'jquery-ui-autocomplete' ],
			md5_file( dirname( $this->plugin_file ) . '/src/js/amazon-pay-buttons-init-block-editor.js' ),
			true
		);

		wp_localize_script( 'amazon-pay-buttons-init', 'payInvoicesWithAmazon', $this->get_buttons_init_i18n() );

		wp_localize_script(
			'amazon-pay-buttons-init-block-editor',
			'PIWABlock',
			[
				'nonce'                             => wp_create_nonce( 'amazon-pay-block-edits' ),
				'block_title_customer_price'        => $this->i18n( 'block_title_customer_price' ),
				'block_title_merchant_price'        => $this->i18n( 'block_title_merchant_price' ),
				'placeholder'                       => $this->i18n( 'block_input_placeholder' ),
				'amount'                            => $this->i18n( 'amount' ),
				'price'                             => $this->i18n( 'price' ),
				'for'                               => $this->i18n( 'for' ),
				'currency_symbol'                   => $this->i18n( 'currency_symbols_plain' )[ $this->get_option( 'payment_currency' ) ],
				'invoice'                           => $this->i18n( 'invoice' ),
				'title'                             => $this->i18n( 'title' ),
				'show_customer_invoice_input_label' => $this->i18n( 'show_customer_invoice_input_label' ),
				'customer_input_help_text'          => $this->i18n( 'customer_input_help_text' ),
			]
		);
	}
}
