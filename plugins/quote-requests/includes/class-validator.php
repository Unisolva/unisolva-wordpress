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

final class Validator {

	public const LIMITS    = array(
		'name'    => 100,
		'phone'   => 20,
		'email'   => 254,
		'company' => 150,
		'message' => 2000,
	);
	public const MAX_LINES = 50;
	public const MAX_QTY   = 9999;

	/** Rows of a submitted list that are looked at; the rest are dropped unread. */
	public const MAX_ROWS_READ = 200;

	public const LIMIT_LINE   = 200;
	public const LIMIT_NUMBER = 30;
	public const LIMIT_REGION = 100;

	/** Fields whose value goes to a key of the same name in the returned data; the others are custom. */
	private const FLAT = array( 'name', 'phone', 'email', 'company', 'message' );

	/**
	 * Checks the posted contact data against the visible field definitions.
	 *
	 * $cfg: fields (visible definitions), contact_rule (phone|email|either|both), require_consent,
	 * has_items, region (callable( string $country, string $value ): ?array, returns country, code, name).
	 */
	public static function contact( array $raw, array $cfg ): array {
		$rule             = (string) ( $cfg['contact_rule'] ?? 'either' );
		$errors           = array();
		$shown            = array();
		$data             = array(
			'name'           => '',
			'phone'          => '',
			'email'          => '',
			'company'        => '',
			'message'        => '',
			'region_country' => '',
			'region'         => '',
			'region_name'    => '',
			'consent'        => false,
			'custom'         => array(),
		);
		$message_required = false;

		foreach ( (array) ( $cfg['fields'] ?? array() ) as $def ) {
			if ( ! is_array( $def ) || ! is_string( $def['key'] ?? null ) || '' === $def['key'] || ( array_key_exists( 'show', $def ) && ! $def['show'] ) ) {
				continue; // A hidden field is not read at all, whatever was posted for it.
			}
			$key    = $def['key'];
			$system = ! empty( $def['system'] );
			if ( $system && 'region' === $key ) {
				self::region( ! empty( $def['required'] ), $raw, $cfg, $data, $errors );
				continue;
			}
			$flat = $system && in_array( $key, self::FLAT, true );
			$type = (string) ( $def['type'] ?? 'text' );
			$text = self::text( $raw[ $key ] ?? '' );

			switch ( $type ) {
				case 'textarea':
					list( $value, $invalid ) = self::read_textarea( $text );
					break;
				case 'tel':
					list( $value, $invalid ) = self::read_tel( $text );
					break;
				case 'email':
					list( $value, $invalid ) = self::read_email( $text );
					break;
				case 'number':
					list( $value, $invalid ) = self::read_number( $text );
					break;
				case 'select':
					list( $value, $invalid ) = self::read_select( $text, (array) ( $def['options'] ?? array() ) );
					break;
				case 'checkbox':
					list( $value, $invalid ) = self::read_checkbox( $text );
					break;
				default:
					list( $value, $invalid ) = self::read_line( $text, $flat ? ( self::LIMITS[ $key ] ?? self::LIMIT_LINE ) : self::LIMIT_LINE );
			}

			// The contact rule, not the definition, decides whether phone and email are required.
			if ( $flat && 'phone' === $key ) {
				$required = in_array( $rule, array( 'phone', 'both' ), true );
			} elseif ( $flat && 'email' === $key ) {
				$required = in_array( $rule, array( 'email', 'both' ), true );
			} else {
				$required = ! empty( $def['required'] );
			}
			$shown[ $key ] = true;

			if ( $flat ) {
				$data[ $key ] = $value;
			} elseif ( '' !== $value ) {
				$data['custom'][] = array(
					'key'   => $key,
					'label' => (string) ( $def['label'] ?? $key ),
					'type'  => $type,
					'value' => $value,
				);
			}

			if ( 'message' === $key && $flat ) {
				$message_required = $required; // Judged below, together with the quote list.
			} elseif ( '' !== $invalid ) {
				$errors[ $key ] = $invalid;
			} elseif ( '' === $value && $required ) {
				$errors[ $key ] = self::required_message( $key, $type, $flat );
			}
		}

		if ( 'either' === $rule && '' === $data['phone'] && '' === $data['email'] && ( isset( $shown['phone'] ) || isset( $shown['email'] ) ) ) {
			$errors[ isset( $shown['phone'] ) ? 'phone' : 'email' ] = __( 'Please enter a phone number or an email address.', 'quote-requests' );
		}
		if ( '' === $data['message'] ) {
			if ( empty( $cfg['has_items'] ) ) {
				$errors['message'] = __( 'Your quote list is empty. Please describe what you need.', 'quote-requests' );
			} elseif ( $message_required ) {
				$errors['message'] = __( 'Please fill in this field.', 'quote-requests' );
			}
		}

		// Without a consent box nothing was shown to agree to, so a posted tick is not a consent.
		$data['consent'] = ! empty( $cfg['require_consent'] ) && in_array( $raw['consent'] ?? '', array( '1', 'yes', 'on', 'true', true, 1 ), true );
		if ( ! empty( $cfg['require_consent'] ) && ! $data['consent'] ) {
			$errors['consent'] = __( 'Please tick the box so we can reply to your request.', 'quote-requests' );
		}

		/**
		 * Filters the errors of a quote request, after the built-in checks. Any error left in the list rejects the request.
		 *
		 * @param array $errors Field key => message. Use the key of a field to show the message beside it.
		 * @param array $data   The cleaned values: name, phone, email, company, message, region_country, region, region_name, consent, custom.
		 * @param array $cfg    The configuration the checks ran with: fields, contact_rule, require_consent, has_items, region.
		 */
		$filtered = apply_filters( 'quote_requests_validate', $errors, $data, $cfg );
		if ( is_array( $filtered ) ) {
			$errors = array();
			foreach ( $filtered as $key => $message ) {
				// PHP turns a key made of digits into an integer, and a field key may be made of digits.
				if ( ( is_string( $key ) || is_int( $key ) ) && '' !== (string) $key && is_string( $message ) && '' !== $message ) {
					$errors[ $key ] = $message; // An entry that is not a field key with a message cannot be shown, so it is dropped.
				}
			}
		}
		return array(
			'data'   => $data,
			'errors' => $errors,
		);
	}

	/** Each reader returns array( cleaned value, error message or an empty string ). */
	private static function read_line( string $text, int $max ): array {
		return array( self::cut( sanitize_text_field( $text ), $max ), '' );
	}

	private static function read_textarea( string $text ): array {
		return array( self::cut( sanitize_textarea_field( $text ), self::LIMITS['message'] ), '' );
	}

	private static function read_tel( string $text ): array {
		// Cut far above the 20 digit limit, so a longer number still fails but a huge one is not kept whole.
		$phone = self::cut( self::clean_phone( $text ), self::LIMITS['phone'] * 2 );
		if ( '' !== $phone && ! preg_match( '/^\+?[0-9]{7,20}$/', $phone ) ) {
			return array( $phone, __( 'Please enter a phone number of 7 to 20 digits.', 'quote-requests' ) );
		}
		return array( $phone, '' );
	}

	private static function read_email( string $text ): array {
		$email = self::cut( sanitize_text_field( $text ), self::LIMITS['email'] );
		if ( '' !== $email && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return array( $email, __( 'Please enter a valid email address.', 'quote-requests' ) );
		}
		return array( $email, '' );
	}

	private static function read_number( string $text ): array {
		// A number is never cut: a longer one would be stored as a different number.
		$number = self::ascii_digits( sanitize_text_field( $text ) );
		if ( '' !== $number && ( mb_strlen( $number ) > self::LIMIT_NUMBER || ! is_numeric( $number ) ) ) {
			return array( self::cut( $number, self::LIMIT_NUMBER ), __( 'Please enter a number.', 'quote-requests' ) );
		}
		return array( $number, '' );
	}

	private static function read_select( string $text, array $options ): array {
		$value = sanitize_text_field( $text );
		if ( '' === $value ) {
			return array( '', '' );
		}
		foreach ( $options as $option ) {
			if ( is_array( $option ) && isset( $option['value'] ) && is_scalar( $option['value'] ) && sanitize_text_field( (string) $option['value'] ) === $value ) {
				return array( (string) $option['value'], '' );
			}
		}
		return array( '', __( 'Please choose an option from the list.', 'quote-requests' ) );
	}

	private static function read_checkbox( string $text ): array {
		return array( in_array( strtolower( trim( $text ) ), array( '1', 'yes', 'on', 'true' ), true ) ? '1' : '', '' );
	}

	/** Resolves the posted region through $cfg['region'] and fills the three region values. */
	private static function region( bool $required, array $raw, array $cfg, array &$data, array &$errors ): void {
		$value   = self::cut( sanitize_text_field( self::text( $raw['region'] ?? '' ) ), self::LIMIT_REGION );
		$country = strtoupper( preg_replace( '/[^A-Za-z]/', '', self::text( $raw['region_country'] ?? '' ) ) );
		$country = 2 === strlen( $country ) ? $country : '';
		$found   = is_callable( $cfg['region'] ?? null ) ? call_user_func( $cfg['region'], $country, $value ) : null;

		if ( ! is_array( $found ) && '' !== $value ) {
			$errors['region'] = __( 'Please choose a region from the list.', 'quote-requests' );
			return;
		}
		$name = is_array( $found ) ? self::text( $found['name'] ?? '' ) : '';
		if ( '' === $name ) {
			if ( $required ) {
				$errors['region'] = __( 'Please choose your region.', 'quote-requests' );
			}
			return;
		}
		$data['region_country'] = self::text( $found['country'] ?? '' );
		$data['region']         = self::text( $found['code'] ?? '' );
		$data['region_name']    = $name;
	}

	private static function required_message( string $key, string $type, bool $flat ): string {
		if ( $flat ) {
			switch ( $key ) {
				case 'name':
					return __( 'Please enter your name.', 'quote-requests' );
				case 'phone':
					return __( 'Please enter your phone or WhatsApp number.', 'quote-requests' );
				case 'email':
					return __( 'Please enter your email address.', 'quote-requests' );
			}
		}
		switch ( $type ) {
			case 'checkbox':
				return __( 'Please tick this box.', 'quote-requests' );
			case 'select':
				return __( 'Please choose an option.', 'quote-requests' );
		}
		return __( 'Please fill in this field.', 'quote-requests' );
	}

	/** A posted value as a string; arrays and objects count as empty. */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	public static function items( array $raw_items, callable $lookup ): array {
		$qty       = array();
		$dropped   = max( 0, count( $raw_items ) - self::MAX_ROWS_READ );
		$raw_items = array_slice( $raw_items, 0, self::MAX_ROWS_READ ); // Bound the work an oversized request can cause.
		foreach ( $raw_items as $row ) {
			if ( ! is_array( $row ) ) {
				++$dropped;
				continue;
			}
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id <= 0 ) {
				++$dropped;
				continue;
			}
			$qty[ $id ] = ( $qty[ $id ] ?? 0 ) + max( 1, (int) ( $row['qty'] ?? 1 ) );
		}
		$items = array();
		foreach ( $qty as $id => $q ) {
			$snap = $lookup( (int) $id );
			if ( null === $snap ) {
				++$dropped;
				continue;
			}
			if ( count( $items ) >= self::MAX_LINES ) {
				++$dropped;
				continue;
			}
			$snap['qty'] = min( self::MAX_QTY, $q );
			$items[]     = $snap;
		}
		return array(
			'items'   => $items,
			'dropped' => $dropped,
		);
	}

	public static function clean_phone( string $phone ): string {
		// Numbers copied from WhatsApp or a contacts app carry invisible direction marks.
		// preg_replace() with the u flag returns null for text that is not valid UTF-8 (a form post can carry any bytes).
		// Such text is passed on as it came: it is not a phone number, and the check of the digits refuses it.
		$marks = preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u', '', $phone );
		$phone = trim( self::ascii_digits( is_string( $marks ) ? $marks : $phone ) );
		$clean = preg_replace( '/[\s\-\.\(\)\x{00A0}]+/u', '', $phone );
		return is_string( $clean ) ? $clean : $phone;
	}

	/** Arabic-Indic and Eastern Arabic-Indic digits as the digits 0 to 9; everything else stays. */
	public static function ascii_digits( string $text ): string {
		return strtr( $text, array_combine( preg_split( '//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) );
	}

	private static function cut( string $text, int $max ): string {
		return mb_substr( $text, 0, $max );
	}
}
