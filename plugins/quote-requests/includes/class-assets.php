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

final class Assets {

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'admin' ) );
	}

	/**
	 * The script and the style of the settings screen, on that screen only.
	 *
	 * @param string $hook Hook suffix of the admin screen. Its start depends on the language of the parent menu, its end does not.
	 */
	public static function admin( $hook ): void {
		$end = '_page_' . Settings_Page::SLUG;
		if ( ! is_string( $hook ) || substr( $hook, -strlen( $end ) ) !== $end ) {
			return;
		}
		wp_enqueue_style( 'quote-requests-admin', QUOTE_REQUESTS_URL . 'assets/admin.css', array(), QUOTE_REQUESTS_VERSION );
		wp_enqueue_script(
			'quote-requests-admin-fields',
			QUOTE_REQUESTS_URL . 'assets/admin-fields.js',
			array(),
			QUOTE_REQUESTS_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	public static function enqueue(): void {
		$v = QUOTE_REQUESTS_VERSION;
		wp_enqueue_style( 'quote-requests', QUOTE_REQUESTS_URL . 'assets/quote-requests.css', array(), $v );
		wp_enqueue_script(
			'quote-requests-list',
			QUOTE_REQUESTS_URL . 'assets/list.js',
			array(),
			$v,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_enqueue_script(
			'quote-requests',
			QUOTE_REQUESTS_URL . 'assets/quote-requests.js',
			array( 'quote-requests-list' ),
			$v,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		$s = Settings::all();
		wp_add_inline_script(
			'quote-requests',
			'window.QuoteRequestsConfig = ' . wp_json_encode(
				array(
					'rest'      => esc_url_raw( rest_url( Rest::NS . '/' ) ),
					'quotePage' => Fallback::quote_url(),
					'regionMax' => Validator::LIMIT_REGION,
					'labels'    => array(
						'add'         => $s['button_label'],
						'added'       => $s['button_added_label'],
						'view'        => __( 'View quote', 'quote-requests' ),
						'addedNotice' => __( 'Added to your quote.', 'quote-requests' ),
						/* translators: %d: number of products in the quote list */
						'listName'    => __( 'Quote list, %d products', 'quote-requests' ),
						'unavailable' => __( 'Some products are no longer available and were removed from your list.', 'quote-requests' ),
						'sending'     => __( 'Sending…', 'quote-requests' ),
						'send'        => __( 'Send quote request', 'quote-requests' ),
						'failure'     => __( 'Your request could not be sent. Your details are still in the form.', 'quote-requests' ) . ( '' !== trim( $s['fallback_contact'] ) ? ' ' . $s['fallback_contact'] : '' ),
						/* translators: %s: reference such as Q-2026-0001 */
						'yourRef'     => __( 'Your reference: %s', 'quote-requests' ),
						'copySent'    => __( 'We sent a copy of this request to your email address.', 'quote-requests' ),
						'remove'      => __( 'Remove', 'quote-requests' ),
						/* translators: %s: product name */
						'qtyOf'       => __( 'Quantity of %s', 'quote-requests' ),
						// The words the server uses for the same cases.
						'contact'     => __( 'Please enter a phone number or an email address.', 'quote-requests' ),
						'number'      => __( 'Please enter a number.', 'quote-requests' ),
						'choose'      => __( 'Choose…', 'quote-requests' ),
						/* translators: %s: country name */
						'regionList'  => __( 'The region list now shows the regions of %s.', 'quote-requests' ),
						/* translators: %s: country name */
						'regionText'  => __( 'There is no region list for %s. Type your region.', 'quote-requests' ),
						/* translators: %s: country name */
						'regionFail'  => __( 'The region list for %s could not be loaded. Type your region.', 'quote-requests' ),
						'regionWait'  => __( 'The region list is still loading. Please wait a moment, choose your region and send again.', 'quote-requests' ),
					),
				)
			) . ';',
			'before'
		);
	}
}
