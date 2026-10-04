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

final class Regions {

	private const LIMIT_TEXT = 100;

	/**
	 * Test hook, unit tests only: callable( string $country ): array returning code => name.
	 * When null the class reads WooCommerce.
	 *
	 * @var callable|null
	 */
	public static $provider = null;

	/**
	 * Test hook, unit tests only: country code => name. When null the class reads WooCommerce.
	 *
	 * @var array|null
	 */
	public static $country_names = null;

	/** The region mode: "single" (one country) or "choose" (the visitor picks the country). */
	public static function mode(): string {
		return 'choose' === Settings::get( 'region_mode' ) ? 'choose' : 'single';
	}

	/**
	 * A short hash of the settings the regions route answers from: mode, default country, "Outside" and the
	 * allowed countries. It goes into the address the form asks, so a cached answer is not used after they change.
	 */
	public static function settings_hash(): string {
		$parts = array( self::mode(), self::default_country(), (bool) Settings::get( 'region_outside' ), array_values( (array) Settings::get( 'region_countries' ) ) );
		return substr( md5( (string) wp_json_encode( $parts ) ), 0, 8 );
	}

	/** The country that is preselected and that an empty posted country stands for. Empty when none is known. */
	public static function default_country(): string {
		$country = Settings::region_country();
		if ( 'choose' === self::mode() ) {
			$allowed = self::countries();
			if ( $allowed && (array) Settings::get( 'region_countries' ) && ! isset( $allowed[ $country ] ) ) {
				$country = (string) array_key_first( $allowed ); // The first country of the list as it is shown.
			}
		}
		return $country;
	}

	/**
	 * The countries the visitor may use, code => name (HTML-decoded).
	 * One entry in "single" mode; the chosen list, or every WooCommerce country, in "choose" mode.
	 */
	public static function countries(): array {
		$names = self::all_country_names();
		if ( 'single' === self::mode() ) {
			$country = self::default_country();
			return '' === $country ? array() : array( $country => $names[ $country ] ?? $country );
		}
		$chosen = (array) Settings::get( 'region_countries' );
		if ( ! $chosen ) {
			return $names;
		}
		if ( ! $names ) {
			return array_combine( $chosen, $chosen );
		}
		return array_intersect_key( $names, array_flip( $chosen ) );
	}

	/**
	 * The regions of a country, code => name (HTML-decoded). Empty when the country has no list.
	 * In "single" mode, with "Outside" on and a non-empty list, the last entry is OUTSIDE => "Outside <country>".
	 */
	public static function states( string $country ): array {
		$country = self::clean_country( $country );
		if ( '' === $country ) {
			return array();
		}
		$out = array();
		foreach ( self::raw_states( $country ) as $code => $name ) {
			if ( is_string( $name ) ) {
				$out[ (string) $code ] = self::decode( $name );
			}
		}
		if ( $out && 'single' === self::mode() && self::default_country() === $country && Settings::get( 'region_outside' ) ) {
			$names = self::all_country_names();
			/* translators: %s: country name */
			$out['OUTSIDE'] = sprintf( __( 'Outside %s', 'quote-requests' ), $names[ $country ] ?? $country );
		}
		return $out;
	}

	/**
	 * Turns a posted country and region into array( country, code, name ).
	 * Null when the country is not allowed, or the value matches nothing in a country that has a list.
	 * An empty value gives an empty code and name. A country without a list takes one line of text.
	 */
	public static function resolve( string $country, string $value ): ?array {
		$country = self::clean_country( $country );
		if ( '' === $country ) {
			$country = self::default_country();
		}
		if ( '' === $country || ! array_key_exists( $country, self::countries() ) ) {
			return null;
		}
		$line = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		if ( '' === $line ) {
			return array(
				'country' => $country,
				'code'    => '',
				'name'    => '',
			);
		}
		$states = self::states( $country );
		if ( ! $states ) {
			return array(
				'country' => $country,
				'code'    => '',
				'name'    => rtrim( mb_substr( $line, 0, self::LIMIT_TEXT ) ),
			);
		}
		$wanted = self::fold( $value );
		foreach ( array( false, true ) as $plain ) { // Exact first, then with accents removed.
			$needle = $plain ? remove_accents( $wanted ) : $wanted;
			foreach ( $states as $code => $name ) {
				foreach ( array( (string) $code, $name ) as $candidate ) {
					$hay = self::fold( $candidate );
					if ( ( $plain ? remove_accents( $hay ) : $hay ) === $needle ) {
						return array(
							'country' => $country,
							'code'    => (string) $code,
							'name'    => $name,
						);
					}
				}
			}
		}
		return null;
	}

	/**
	 * The name of a country by its two-letter code, whatever the allowed list is now.
	 * The code itself when no name is known; empty when there is no code.
	 */
	public static function country_name( string $code ): string {
		$code = self::clean_country( $code );
		if ( '' === $code ) {
			return '';
		}
		return self::all_country_names()[ $code ] ?? $code;
	}

	/**
	 * Where a stored quote comes from, as one line: the one rule for every place that shows it.
	 * The region name, with its code in brackets when asked for, and the country name after it when the site
	 * lets the visitor choose the country or the record's country is not the site's default country.
	 * A country without a region gives the country alone; a record with neither gives an empty string.
	 *
	 * @param array $quote     Stored quote (region_name, region_code, region_country).
	 * @param bool  $with_code Whether the region code follows the region name in brackets.
	 */
	public static function location( array $quote, bool $with_code = false ): string {
		$name    = trim( (string) ( $quote['region_name'] ?? '' ) );
		$code    = trim( (string) ( $quote['region_code'] ?? '' ) );
		$country = self::clean_country( (string) ( $quote['region_country'] ?? '' ) );
		$text    = $name;
		if ( $with_code && '' !== $code ) {
			$text = '' !== $name ? $name . ' (' . $code . ')' : $code;
		}
		if ( '' !== $country && ( 'choose' === self::mode() || self::default_country() !== $country ) ) {
			$text = '' !== $text ? $text . ', ' . self::country_name( $country ) : self::country_name( $country );
		}
		return $text;
	}

	/** HTML-decoded, trimmed, spaces collapsed, lower case. */
	private static function fold( string $text ): string {
		return self::lower( trim( (string) preg_replace( '/\s+/u', ' ', self::decode( $text ) ) ) );
	}

	/** Lower case. WordPress has no stand-in for this mbstring function: without the extension only the letters a to z are folded. */
	private static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	private static function decode( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function clean_country( string $country ): string {
		return strtoupper( preg_replace( '/[^A-Za-z]/', '', $country ) );
	}

	private static function woocommerce(): bool {
		return function_exists( 'WC' ) && WC() && WC()->countries;
	}

	/** Region list of a country as the store (or the test hook) gives it. */
	private static function raw_states( string $country ): array {
		if ( null !== self::$provider ) {
			$states = call_user_func( self::$provider, $country );
		} elseif ( self::woocommerce() ) {
			$states = WC()->countries->get_states( $country );
		} else {
			$states = array();
		}
		return is_array( $states ) ? $states : array();
	}

	/** Every known country, code => name (HTML-decoded). */
	public static function all_country_names(): array {
		if ( null !== self::$country_names ) {
			$names = self::$country_names;
		} elseif ( self::woocommerce() ) {
			$names = WC()->countries->get_countries();
		} else {
			$names = array();
		}
		$out = array();
		foreach ( is_array( $names ) ? $names : array() as $code => $name ) {
			if ( is_string( $name ) ) {
				$out[ (string) $code ] = self::decode( $name );
			}
		}
		return $out;
	}
}
