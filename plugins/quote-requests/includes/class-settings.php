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

final class Settings {

	public const OPTION = 'quote_requests_settings';

	/** Value of the hidden "_form" input of the settings form. It tells a box that is not ticked from a form that has no such box. */
	public const FORM = '2';

	private const BOOLS = array( 'customer_confirmation', 'require_consent', 'region_outside', 'hide_prices', 'remove_add_to_cart', 'redirect_cart', 'auto_button_single', 'auto_button_loop', 'store_client', 'trust_proxy' );
	private const TEXTS = array( 'subject_prefix', 'consent_text', 'button_label', 'button_added_label', 'empty_text', 'thanks_text', 'fallback_contact' );
	private const RULES = array( 'either', 'phone', 'email', 'both' );
	private const MODES = array( 'single', 'choose' );

	public static function defaults(): array {
		return array(
			'recipients'            => array_filter( array( (string) get_option( 'admin_email' ) ) ),
			'customer_confirmation' => true,
			'subject_prefix'        => '',
			'quote_page'            => 0,
			'privacy_page'          => (int) get_option( 'wp_page_for_privacy_policy' ),
			'fields'                => Fields::defaults(),
			'contact_rule'          => 'either',
			'require_consent'       => true,
			'region_mode'           => 'single',
			'region_country'        => '',
			'region_countries'      => array(),
			'region_outside'        => true,
			'consent_text'          => '',
			'button_label'          => __( 'Add to quote', 'quote-requests' ),
			/* translators: %d: number of this product in the quote list */
			'button_added_label'    => __( 'In your quote (%d)', 'quote-requests' ),
			'empty_text'            => __( 'No products yet. Browse products, or describe what you need below.', 'quote-requests' ),
			'thanks_text'           => __( 'Thank you, %name%. We will contact you as soon as possible.', 'quote-requests' ),
			'fallback_contact'      => '',
			'hide_prices'           => false,
			'remove_add_to_cart'    => false,
			'redirect_cart'         => false,
			'auto_button_single'    => true,
			'auto_button_loop'      => true,
			'retention_months'      => 24,
			'store_client'          => true,
			'trust_proxy'           => false,
			'rate_limit'            => 5,
		);
	}

	/**
	 * The settings as all() built them, kept for the rest of the request. Null until the first call and after flush().
	 *
	 * @var array|null
	 */
	private static ?array $memo = null;

	/** Drops the kept settings when the option, an option a default is read from, the language or the site changes. */
	public static function register(): void {
		foreach ( array( 'update_option_', 'add_option_', 'delete_option_' ) as $hook ) {
			add_action( $hook . self::OPTION, array( self::class, 'flush' ) );
		}
		foreach ( array( 'update_option_admin_email', 'update_option_wp_page_for_privacy_policy', 'switch_locale', 'restore_previous_locale', 'switch_blog' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush' ) );
		}
	}

	/**
	 * Forgets the settings and the field list kept for this request, so the next call reads them again.
	 * Code that changes what get_option() returns for the settings without updating the option (a filter added
	 * late, a test) calls this.
	 */
	public static function flush(): void {
		self::$memo = null;
		Fields::flush();
	}

	/** Every setting, cleaned and completed with the defaults. Built once per request: the form filters ask for it many times a page. */
	public static function all(): array {
		if ( null === self::$memo ) {
			self::$memo = self::build();
		}
		return self::$memo;
	}

	private static function build(): array {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		if ( is_string( $stored ) ) {
			$stored = json_decode( $stored, true );
		}
		$stored = is_array( $stored ) ? $stored : array();
		if ( Upgrade::is_legacy( $stored ) ) {
			$stored = Upgrade::convert_settings( $stored ); // Converted on read, never written back here.
		}
		$all                     = array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
		$all['contact_rule']     = self::pick( $all['contact_rule'], self::RULES, 'either' );
		$all['region_mode']      = self::pick( $all['region_mode'], self::MODES, 'single' );
		$all['region_countries'] = self::countries( $all['region_countries'] );
		$all['fields']           = Fields::apply_contact_rule( Fields::normalize( $all['fields'] ), $all['contact_rule'] );
		if ( ! is_array( $all['recipients'] ) || ! $all['recipients'] ) {
			$all['recipients'] = $defaults['recipients'];
		}
		foreach ( self::BOOLS as $b ) {
			$all[ $b ] = (bool) $all[ $b ];
		}
		foreach ( array( 'quote_page', 'privacy_page', 'retention_months', 'rate_limit' ) as $n ) {
			$all[ $n ] = (int) $all[ $n ];
		}
		foreach ( self::TEXTS as $t ) {
			if ( '' === trim( (string) $all[ $t ] ) && '' !== $defaults[ $t ] ) {
				$all[ $t ] = $defaults[ $t ]; // An emptied text falls back to its default.
			}
		}
		return $all;
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Cleans what the settings form posts.
	 *
	 * A setting this version added is only changed by a post that carries it. A post without a field list, a
	 * contact rule or a region mode (an older form, for example one left open in a browser tab during an update)
	 * keeps the current values. The consent box and the country list are read as "not ticked" and "none chosen"
	 * only when the current form posted (its "_form" marker); otherwise they keep their values too.
	 *
	 * @param mixed $input Posted settings.
	 */
	public static function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$out     = self::defaults();
		$current = self::all();
		$form    = is_scalar( $input['_form'] ?? null ) && self::FORM === (string) $input['_form'];

		$recipients        = $input['recipients'] ?? '';
		$recipients        = is_array( $recipients ) ? implode( "\n", $recipients ) : (string) $recipients;
		$emails            = preg_split( '/[\s,;]+/', $recipients, -1, PREG_SPLIT_NO_EMPTY );
		$out['recipients'] = array_values( array_filter( array_map( 'trim', $emails ), 'is_email' ) );
		if ( ! $out['recipients'] ) {
			// Never save an empty list: the team email would silently stop.
			$out['recipients'] = self::defaults()['recipients'];
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( self::OPTION, 'quote_requests_recipients', __( 'No valid recipient address was entered, so quote requests go to the site admin email. Check the Recipients field.', 'quote-requests' ) );
			}
		}

		foreach ( self::BOOLS as $b ) {
			$out[ $b ] = ! empty( $input[ $b ] );
		}
		if ( ! $form && ! array_key_exists( 'require_consent', $input ) ) {
			$out['require_consent'] = $current['require_consent'];
		}
		foreach ( self::TEXTS as $t ) {
			$out[ $t ] = sanitize_text_field( (string) ( $input[ $t ] ?? '' ) );
		}
		$out['quote_page']       = absint( $input['quote_page'] ?? 0 );
		$out['privacy_page']     = absint( $input['privacy_page'] ?? 0 );
		$out['retention_months'] = max( 1, min( 120, absint( $input['retention_months'] ?? 24 ) ) );
		$out['rate_limit']       = max( 1, min( 100, absint( $input['rate_limit'] ?? 5 ) ) );
		$country                 = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $input['region_country'] ?? '' ) ) );
		$out['region_country']   = 2 === strlen( $country ) ? $country : '';
		$out['region_countries'] = $form || array_key_exists( 'region_countries', $input ) ? self::countries( $input['region_countries'] ?? array() ) : $current['region_countries'];
		$out['region_mode']      = array_key_exists( 'region_mode', $input ) ? self::pick( $input['region_mode'], self::MODES, 'single' ) : $current['region_mode'];
		$out['contact_rule']     = array_key_exists( 'contact_rule', $input ) ? self::pick( $input['contact_rule'], self::RULES, 'either' ) : $current['contact_rule'];

		$fields = $current['fields'];
		$posted = $input['fields'] ?? null;
		if ( self::is_field_list( $posted ) ) {
			$result = Fields::accept( $posted, $current['fields'] );
			$fields = $result['fields'];
			foreach ( $result['problems'] as $n => $problem ) {
				if ( function_exists( 'add_settings_error' ) ) {
					add_settings_error( self::OPTION, 'quote_requests_field_' . $n, self::field_problem( $problem ) );
				}
			}
		}
		$out['fields'] = Fields::apply_contact_rule( $fields, $out['contact_rule'] );
		return $out;
	}

	/** The sentence the settings screen shows for a row Fields::accept() did not take as posted. */
	private static function field_problem( array $problem ): string {
		$name = esc_html( (string) $problem['name'] ); // The settings screen prints the message as HTML.
		switch ( $problem['code'] ) {
			case 'choices_kept':
				/* translators: %s: field label */
				return sprintf( __( '%s: add at least one choice. The field was not changed.', 'quote-requests' ), $name );
			case 'choices':
				/* translators: %s: field label */
				return sprintf( __( '%s: add at least one choice. The field was not added.', 'quote-requests' ), $name );
			case 'reserved':
				/* translators: 1: field label, 2: field key */
				return sprintf( __( '%1$s: the key "%2$s" is reserved. The field was not added.', 'quote-requests' ), $name, $problem['key'] );
			case 'duplicate':
				/* translators: 1: field label, 2: field key */
				return sprintf( __( '%1$s: the key "%2$s" is already used by another field. The field was not added.', 'quote-requests' ), $name, $problem['key'] );
			default:
				/* translators: 1: field label, 2: the most custom fields a form can have */
				return sprintf( __( '%1$s: a form can have %2$d custom fields. The field was not added.', 'quote-requests' ), $name, Fields::MAX_CUSTOM );
		}
	}

	/** True for a posted list of field rows: an empty list, or rows that carry a key or an id. Not the switches of the 0.1 form. */
	private static function is_field_list( $posted ): bool {
		if ( ! is_array( $posted ) ) {
			return false;
		}
		foreach ( $posted as $row ) {
			if ( is_array( $row ) && ( array_key_exists( 'key', $row ) || array_key_exists( 'id', $row ) ) ) {
				return true;
			}
		}
		return ! $posted;
	}

	/** The value when it is one of the allowed strings, otherwise the default. */
	private static function pick( $value, array $allowed, string $fallback ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/** A list of unique upper case two-letter country codes from a list or a separated string. */
	private static function countries( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
		}
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $code ) {
			$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', is_string( $code ) ? $code : '' ) );
			if ( 2 === strlen( $code ) ) {
				$out[ $code ] = $code;
			}
		}
		return array_values( $out );
	}

	public static function consent_text(): string {
		$text = trim( (string) self::get( 'consent_text' ) );
		if ( '' !== $text ) {
			return $text;
		}
		/* translators: %s: site name */
		return sprintf( __( 'I agree that %s may use these details to reply to my quote request, as described in the privacy policy.', 'quote-requests' ), get_bloginfo( 'name' ) );
	}

	public static function region_country(): string {
		$c = (string) self::get( 'region_country' );
		if ( '' === $c && function_exists( 'WC' ) && WC()->countries ) {
			$c = (string) WC()->countries->get_base_country();
		}
		return $c;
	}

	public static function subject_prefix(): string {
		$p = trim( (string) self::get( 'subject_prefix' ) );
		return '' !== $p ? $p : (string) get_bloginfo( 'name' );
	}
}
