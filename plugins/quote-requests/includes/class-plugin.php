<?php
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

namespace Quote_Requests;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	/** Class names (without namespace) whose static register() is called on boot, in order. */
	private const UNITS = array( 'Settings', 'Store', 'Rest', 'Assets', 'Quote_Page', 'Fallback', 'Buttons', 'Catalog_Mode', 'Admin', 'Settings_Page', 'Retention', 'Legacy_Statuses', 'Privacy', 'Upgrade' );

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return; // "Requires Plugins" normally prevents this; stay silent if WooCommerce is gone.
		}
		load_plugin_textdomain( 'quote-requests', false, dirname( plugin_basename( QUOTE_REQUESTS_FILE ) ) . '/languages' );
		foreach ( self::UNITS as $unit ) {
			$class = __NAMESPACE__ . '\\' . $unit;
			$class::register();
		}
		// The Elementor Pro adapter. Its class is read from its file when Elementor Pro fires one of these hooks: on a
		// site without Elementor Pro nothing of the adapter is loaded. Should other code fire these hook names there,
		// the callbacks return at once, because the adapter cannot exist without the class it extends.
		add_action( 'elementor_pro/forms/actions/register', self::elementor( 'add' ) );
		add_action( 'elementor_pro/forms/validation', self::elementor( 'validate' ), 20, 2 );
	}

	/**
	 * A callback for a hook of Elementor Pro's forms that calls the adapter only when Elementor Pro's form classes exist.
	 *
	 * @param string $method The static method of Elementor_Action to call with the arguments of the hook.
	 */
	private static function elementor( string $method ): \Closure {
		return static function ( ...$args ) use ( $method ): void {
			if ( ! class_exists( 'ElementorPro\Modules\Forms\Classes\Action_Base' ) ) {
				return;
			}
			call_user_func_array( array( __NAMESPACE__ . '\\Elementor_Action', $method ), $args );
		};
	}

	public static function activate(): void {
		Upgrade::maybe_run();
		if ( ! Upgrade::held() ) {
			Legacy_Statuses::detect(); // Asked again on every activation, so the answer can be renewed by hand.
		}
		do_action( 'quote_requests_activate' );
	}

	public static function deactivate(): void {
		do_action( 'quote_requests_deactivate' );
	}
}
