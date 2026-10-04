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

final class Catalog_Mode {

	public static function register(): void {
		foreach ( array( 'woocommerce_get_price_html', 'woocommerce_variable_price_html', 'woocommerce_variable_sale_price_html' ) as $hook ) {
			add_filter( $hook, array( self::class, 'price_html' ), 9999 );
		}
		add_filter( 'woocommerce_is_purchasable', array( self::class, 'purchasable' ), 9999 );
		add_filter( 'woocommerce_variation_is_purchasable', array( self::class, 'purchasable' ), 9999 );
		add_filter( 'woocommerce_structured_data_product_offer', array( self::class, 'offer' ), 9999 );
		add_action( 'template_redirect', array( self::class, 'maybe_redirect' ), 1 );
	}

	public static function price_html( $html ) {
		return Settings::get( 'hide_prices' ) ? '' : $html;
	}

	public static function purchasable( $purchasable ) {
		return Settings::get( 'remove_add_to_cart' ) ? false : $purchasable;
	}

	public static function offer( $offer ) {
		return Settings::get( 'hide_prices' ) ? false : $offer;
	}

	public static function redirect_target(): string {
		return Settings::get( 'redirect_cart' ) ? Fallback::quote_url() : '';
	}

	public static function maybe_redirect(): void {
		if ( ! function_exists( 'is_cart' ) || ! ( is_cart() || is_checkout() ) || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		$to = self::redirect_target();
		if ( '' !== $to ) {
			wp_safe_redirect( $to, 302 );
			exit;
		}
	}
}
