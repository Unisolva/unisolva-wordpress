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

final class Buttons {

	public static function register(): void {
		add_shortcode( 'quote_requests_button', array( self::class, 'shortcode_button' ) );
		add_shortcode( 'quote_requests_link', array( self::class, 'link' ) );
		add_action( 'wp', array( self::class, 'hook_auto' ) );
	}

	public static function hook_auto(): void {
		if ( Settings::get( 'auto_button_single' ) ) {
			add_action( 'woocommerce_single_product_summary', array( self::class, 'auto_single' ), 31 );
		}
		if ( Settings::get( 'auto_button_loop' ) ) {
			add_action( 'woocommerce_after_shop_loop_item', array( self::class, 'auto_loop' ), 15 );
		}
	}

	public static function auto_single(): void {
		echo self::button( (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped in button().
	}

	public static function auto_loop(): void {
		echo self::button( (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped in button().
	}

	public static function shortcode_button( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'quote_requests_button' );
		$id   = absint( $atts['id'] ) ? absint( $atts['id'] ) : (int) get_the_ID();
		return self::button( $id );
	}

	public static function button( int $product_id ): string {
		$url = Fallback::quote_url( array( 'add' => $product_id ) );
		if ( '' === $url || ! Products::snapshot( $product_id ) ) {
			return '';
		}
		return '<span class="qr-button-wrap"><a class="qr-button" href="' . esc_url( $url ) . '" data-qr-add="' . (int) $product_id . '" rel="nofollow"><span data-qr-label>' . esc_html( (string) Settings::get( 'button_label' ) ) . '</span></a></span>';
	}

	public static function link(): string {
		$url = Fallback::quote_url();
		if ( '' === $url ) {
			return '';
		}
		/* translators: %d: number of products in the quote list */
		$label = sprintf( __( 'Quote list, %d products', 'quote-requests' ), 0 );
		$svg   = '<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 10h6M9 14h6M9 18h4"/></svg>';
		return '<a class="qr-link" href="' . esc_url( $url ) . '" data-qr-link aria-label="' . esc_attr( $label ) . '">' . $svg . '<span class="qr-link__count" data-qr-count hidden>0</span></a>';
	}
}
