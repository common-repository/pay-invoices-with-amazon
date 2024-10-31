<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Plugin Name: Pay Invoices With Amazon
 * Description: Enables a smooth Amazon Pay integration using the WordPress block editor. Accept payments using Amazon Pay, providing a seamless experience for your customers.
 * Author: Amazon Pay
 * Author URI: https://pay.amazon.com/business/pay-invoices-with-amazon
 * Plugin URI: https://wordpress.org/plugins/pay-invoices-with-amazon/
 * Contributors: zengy, aaronholbrook, ivande, chetmac, pdclark
 * Version: 1.3.1
 * Text Domain: piwa
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 6.4.1
 * Requires PHP: 5.6.20
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

use PIWA\PIWA;

if ( ! class_exists( 'PIWA\PIWA' ) ) {
	require __DIR__ . '/src/trait-singleton.php';
	require __DIR__ . '/src/class-piwa.php';
}

function piwa( $atts = [], $content = '', $block_or_tagname = '' ) {
	return PIWA::get_instance( $atts, $content, $block_or_tagname );
}

add_action( 'plugins_loaded', 'piwa' );
