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

/**
 * The links under the thank-you text of a sent request: by default one to the shop page and one to the home page.
 * Built once, in Submission, and carried in the answer, so the script and the page without the script show the same.
 */
final class Thanks {

	/** The most links the panel shows. */
	public const MAX_LINKS = 4;

	/** The longest label of a link, in characters. */
	public const MAX_LABEL = 100;

	/**
	 * The links for a request that was just stored: the built-in ones, passed through the quote_requests_thanks_links
	 * filter and cleaned. An empty list when the setting is off; the filter does not run then.
	 *
	 * @param array $quote The new request as Store::get() returns it.
	 * @return array List of array( 'key' => string, 'url' => string, 'label' => string ).
	 */
	public static function links( array $quote ): array {
		$s = Settings::all();
		if ( ! $s['thanks_links'] ) {
			return array();
		}
		$built = self::defaults( self::shop_url(), home_url( '/' ), $s['thanks_continue_label'], $s['thanks_home_label'] );
		// The request is stored and mailed by now: a callback that fails must not turn the answer into an error.
		try {
			/**
			 * Filters the links shown under the thank-you text after a quote request is sent.
			 *
			 * @param array $built List of array( 'key' => string, 'url' => string, 'label' => string ). The first link is shown as a button.
			 *                     The result is cleaned (see harden()). A result that is not an array is ignored and the list stays as it was passed in.
			 *                     A callback that throws is ignored in the same way.
			 * @param array $quote The new request as Quote_Requests\Store::get() returns it.
			 */
			$filtered = apply_filters( 'quote_requests_thanks_links', $built, $quote );
		} catch ( \Throwable $e ) {
			$filtered = $built;
		}
		return self::harden( is_array( $filtered ) ? $filtered : $built );
	}

	/** The address of the WooCommerce shop page, or an empty string when the store has none that visitors can open (it must be published). */
	private static function shop_url(): string {
		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'wc_get_page_permalink' ) ) {
			return '';
		}
		$id = (int) wc_get_page_id( 'shop' );
		if ( $id <= 0 || 'publish' !== get_post_status( $id ) ) {
			return '';
		}
		return (string) wc_get_page_permalink( 'shop' );
	}

	/**
	 * The built-in links: "continue" to the shop page, left out when there is none, then "home".
	 *
	 * @param string $shop_url       Address of the shop page, or an empty string.
	 * @param string $home_url       Address of the home page.
	 * @param string $continue_label Label of the shop page link.
	 * @param string $home_label     Label of the home page link.
	 */
	public static function defaults( string $shop_url, string $home_url, string $continue_label, string $home_label ): array {
		$links = array();
		if ( '' !== $shop_url ) {
			$links[] = array(
				'key'   => 'continue',
				'url'   => $shop_url,
				'label' => $continue_label,
			);
		}
		$links[] = array(
			'key'   => 'home',
			'url'   => $home_url,
			'label' => $home_label,
		);
		return $links;
	}

	/**
	 * Cleans a list of links, from the filter or from a stored answer. An entry without a usable address or label is
	 * dropped. An address must start with http://, https:// or one slash (a path on this site) and goes through
	 * esc_url_raw(). A label is plain text (the characters themselves, no entities) of at most MAX_LABEL characters.
	 * At most MAX_LINKS links are kept, in their order. Anything that is not an array gives an empty list.
	 *
	 * @param mixed $links The list to clean.
	 * @return array List of array( 'key' => string, 'url' => string, 'label' => string ).
	 */
	public static function harden( $links ): array {
		$out = array();
		foreach ( is_array( $links ) ? $links : array() as $link ) {
			if ( count( $out ) >= self::MAX_LINKS ) {
				break;
			}
			if ( ! is_array( $link ) || ! is_string( $link['url'] ?? null ) || ! is_scalar( $link['label'] ?? null ) ) {
				continue;
			}
			// sanitize_text_field() returns a "<" that opens no tag as an entity. A label is plain text, escaped where it is printed.
			$url   = self::url( $link['url'] );
			$label = mb_substr( wp_specialchars_decode( sanitize_text_field( (string) $link['label'] ), ENT_QUOTES ), 0, self::MAX_LABEL );
			if ( '' === $url || '' === $label ) {
				continue;
			}
			$key   = is_string( $link['key'] ?? null ) ? (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $link['key'] ) ) : '';
			$out[] = array(
				'key'   => '' !== $key ? $key : 'link',
				'url'   => $url,
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * The cleaned address, or an empty string for one that may not be linked. The form is checked before and after
	 * esc_url_raw(): that function removes characters, and what is left must still be one of the allowed forms.
	 */
	private static function url( string $url ): string {
		$allowed = '#^(?:https?://[^/\\\\\s]|/(?![/\\\\]))#i'; // http://host, https://host, or one slash that no slash or backslash follows.
		$url     = trim( $url );
		if ( 1 !== preg_match( $allowed, $url ) ) {
			return '';
		}
		$url = (string) esc_url_raw( $url, array( 'http', 'https' ) );
		return 1 === preg_match( $allowed, $url ) ? $url : '';
	}
}
