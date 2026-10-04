<?php
/**
 * Plugin Name:       Quote Requests
 * Description:       Lets visitors collect products into a quote list and send one quote request. Stores every request, emails the sales team, hides prices and replaces the cart with a quote page.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Unisolva
 * Author URI:        https://unisolva.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       quote-requests
 * WC requires at least: 8.0
 * WC tested up to:   11.1
 */

/**
 * Quote Requests
 *
 * Copyright (C) 2026 Unisolva for Information Technology and App Development L.L.C.
 *
 * This program is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software Foundation;
 * either version 2 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * @package Quote_Requests
 */

defined( 'ABSPATH' ) || exit;

define( 'QUOTE_REQUESTS_VERSION', '0.2.0' );
define( 'QUOTE_REQUESTS_FILE', __FILE__ );
define( 'QUOTE_REQUESTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'QUOTE_REQUESTS_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Quote_Requests\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$name = strtolower( str_replace( '_', '-', substr( $class_name, strlen( $prefix ) ) ) );
		$file = QUOTE_REQUESTS_DIR . 'includes/class-' . $name . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

require QUOTE_REQUESTS_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( 'Quote_Requests\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Quote_Requests\\Plugin', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( Quote_Requests\Plugin::instance(), 'boot' ) );
