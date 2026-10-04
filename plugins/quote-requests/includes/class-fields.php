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
 * Definitions of the quote form fields: six built-in (system) fields plus up to 20 custom ones.
 *
 * A definition is array{ key, type, label, help, required, show, options, system }.
 */
final class Fields {

	public const TYPES      = array( 'text', 'textarea', 'email', 'tel', 'number', 'select', 'checkbox' );
	public const SYSTEM     = array( 'name', 'phone', 'email', 'company', 'region', 'message' );
	public const RESERVED   = array( 'token', 'website', 'items', 'consent', 'action', 'region_country', 'landing', 'referrer', 'page', 'tz', 'qr_refresh' );
	public const MAX_CUSTOM = 20;

	private const MAX_KEY  = 40;
	private const MAX_TEXT = 100;
	private const MAX_HELP = 200;

	private const SYSTEM_TYPES = array(
		'name'    => 'text',
		'phone'   => 'tel',
		'email'   => 'email',
		'company' => 'text',
		'region'  => 'region',
		'message' => 'textarea',
	);

	/** The six system fields, all shown, only the name required. */
	public static function defaults(): array {
		$labels = array(
			'name'    => __( 'Name', 'quote-requests' ),
			'phone'   => __( 'Phone', 'quote-requests' ),
			'email'   => __( 'Email', 'quote-requests' ),
			'company' => __( 'Company', 'quote-requests' ),
			'region'  => __( 'Region', 'quote-requests' ),
			'message' => __( 'Message', 'quote-requests' ),
		);

		$out = array();
		foreach ( self::SYSTEM as $key ) {
			$out[] = array(
				'key'      => $key,
				'type'     => self::SYSTEM_TYPES[ $key ],
				'label'    => $labels[ $key ],
				'help'     => '',
				'required' => 'name' === $key,
				'show'     => true,
				'options'  => array(),
				'system'   => true,
			);
		}
		return $out;
	}

	/** Turns anything into a clean, ordered list of definitions that always holds the six system fields. */
	public static function normalize( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return self::defaults();
		}
		$system = array();
		foreach ( self::defaults() as $def ) {
			$system[ $def['key'] ] = $def;
		}
		$out    = array();
		$seen   = array();
		$custom = 0;

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$key = self::clean_key( $entry['key'] ?? '' );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$base      = $system[ $key ] ?? null;
			$is_system = null !== $base;
			if ( ! $is_system && ( in_array( $key, self::RESERVED, true ) || $custom >= self::MAX_CUSTOM ) ) {
				continue;
			}

			if ( $is_system ) {
				$type = $base['type'];
			} else {
				$type = is_string( $entry['type'] ?? null ) ? strtolower( trim( $entry['type'] ) ) : '';
				$type = in_array( $type, self::TYPES, true ) ? $type : 'text';
			}
			$options = array();
			if ( 'select' === $type ) {
				$options = self::clean_options( $entry['options'] ?? array() );
				if ( ! $options ) {
					continue; // A select nobody can choose from is useless.
				}
			}

			$label = self::clean_text( $entry['label'] ?? '' );
			if ( '' === $label ) {
				$label = $is_system ? $base['label'] : $key;
			}
			$show     = self::flag( $entry, 'show', true );
			$required = self::flag( $entry, 'required', $is_system && $base['required'] );
			if ( 'name' === $key || 'message' === $key ) {
				$show = true;
			}
			$required = 'name' === $key || ( 'message' !== $key && $show && $required );

			$out[] = array(
				'key'      => $key,
				'type'     => $type,
				'label'    => $label,
				'help'     => mb_substr( sanitize_text_field( is_string( $entry['help'] ?? null ) ? $entry['help'] : '' ), 0, self::MAX_HELP ),
				'required' => $required,
				'show'     => $show,
				'options'  => $options,
				'system'   => $is_system,
			);

			$seen[ $key ] = true;
			if ( ! $is_system ) {
				++$custom;
			}
		}

		foreach ( $system as $key => $def ) {
			if ( ! isset( $seen[ $key ] ) ) {
				$out[] = $def;
			}
		}
		return $out;
	}

	/**
	 * Turns the rows the settings form posts into a list for normalize().
	 *
	 * A row whose "id" is the key of an existing field keeps that key whatever "key" says, so a key cannot be
	 * changed once the field exists; it keeps its "id", which marks it as an existing field for accept(). A row
	 * without such an id is a new field: its "id" is taken away and an empty key is made from the label. A ticked
	 * "remove" drops a custom row. "options" may be text, one "value | Label" per line. Rows are sorted by
	 * "order"; rows without a number keep their place after the numbered ones.
	 *
	 * @param array    $rows     Posted rows.
	 * @param string[] $existing Keys of the fields that exist now.
	 */
	public static function from_form( array $rows, array $existing ): array {
		$rows = array_values( array_filter( $rows, 'is_array' ) );
		foreach ( $rows as $i => $row ) {
			$id = is_string( $row['id'] ?? null ) ? $row['id'] : '';
			if ( '' !== $id && in_array( $id, $existing, true ) ) {
				$rows[ $i ]['key'] = $id;
			} else {
				unset( $rows[ $i ]['id'] );
			}
		}
		$taken = array_fill_keys( array_merge( self::SYSTEM, self::RESERVED, $existing ), true );
		foreach ( $rows as $row ) {
			$taken[ self::clean_key( $row['key'] ?? '' ) ] = true;
		}

		$sorted = array();
		foreach ( $rows as $i => $row ) {
			$key     = self::clean_key( $row['key'] ?? '' );
			$is_new  = ! isset( $row['id'] );
			$removed = ! empty( $row['remove'] ) && filter_var( $row['remove'], FILTER_VALIDATE_BOOLEAN );
			if ( $removed && ! in_array( $key, self::SYSTEM, true ) ) {
				continue;
			}
			if ( $is_new && '' === $key && array_key_exists( 'key', $row ) && '' !== self::clean_text( $row['label'] ?? '' ) ) {
				$row['key']           = self::key_from_label( self::clean_text( $row['label'] ), $taken );
				$taken[ $row['key'] ] = true;
			}
			if ( is_string( $row['options'] ?? null ) ) {
				$row['options'] = self::options_from_text( $row['options'] );
			}
			$order = $row['order'] ?? null;
			unset( $row['remove'], $row['order'] );
			$sorted[] = array( is_numeric( $order ) ? (int) $order : PHP_INT_MAX, $i, $row );
		}
		usort(
			$sorted,
			static fn( array $a, array $b ): int => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] )
		);
		return array_column( $sorted, 2 );
	}

	/**
	 * What a post of the settings form saves: array( 'fields' => normalized list, 'problems' => what was not accepted ).
	 *
	 * A field is only ever taken away by its Remove control. A row of an existing field (see from_form()) that
	 * cannot be saved, a choice field without choices, keeps the stored definition unchanged. A new row that
	 * cannot be saved is left out. Each problem is array( code, name, key ): code "choices_kept" (existing field
	 * kept as it was), or for a new row "choices", "reserved", "duplicate" or "limit"; name is the label, or the
	 * key when there is none.
	 *
	 * @param array $rows   Posted rows.
	 * @param array $stored The definitions that exist now.
	 */
	public static function accept( array $rows, array $stored ): array {
		$old = array();
		foreach ( $stored as $def ) {
			if ( is_array( $def ) && isset( $def['key'] ) ) {
				$old[ (string) $def['key'] ] = $def;
			}
		}
		$rows    = self::from_form( $rows, array_map( 'strval', array_keys( $old ) ) );
		$claimed = array_fill_keys( array_filter( array_column( $rows, 'id' ), 'is_string' ), true );
		$seen    = array();
		$keep    = array();
		$issues  = array();
		// The existing custom fields have their places first, wherever they sort: the limit can only refuse a new row.
		$custom = count( array_diff( array_keys( $claimed ), self::SYSTEM ) );

		foreach ( $rows as $row ) {
			$key = self::clean_key( $row['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$exists  = isset( $row['id'] );
			$label   = self::clean_text( $row['label'] ?? '' );
			$problem = '';
			if ( isset( $seen[ $key ] ) || ( ! $exists && isset( $claimed[ $key ] ) ) ) {
				$problem = $exists ? 'repeat' : 'duplicate';
			} elseif ( ! in_array( $key, self::SYSTEM, true ) ) {
				if ( in_array( $key, self::RESERVED, true ) ) {
					$problem = 'reserved';
				} elseif ( ! $exists && $custom >= self::MAX_CUSTOM ) {
					$problem = 'limit';
				} elseif ( ! in_array( $key, array_column( self::normalize( array( $row ) ), 'key' ), true ) ) {
					$problem = 'choices'; // What is left: normalize() takes a choice field without choices out.
				}
			}
			if ( 'choices' === $problem && $exists ) {
				$row     = $old[ $key ];
				$label   = (string) $old[ $key ]['label'];
				$problem = 'choices_kept';
			}
			if ( '' !== $problem && 'repeat' !== $problem ) {
				$issues[] = array(
					'code' => $problem,
					'name' => '' !== $label ? $label : $key,
					'key'  => $key,
				);
			}
			if ( '' !== $problem && 'choices_kept' !== $problem ) {
				continue;
			}
			$seen[ $key ] = true;
			$keep[]       = $row;
			if ( ! $exists && ! in_array( $key, self::SYSTEM, true ) ) {
				++$custom;
			}
		}
		return array(
			'fields'   => self::normalize( $keep ),
			'problems' => $issues,
		);
	}

	/** The lines of an options box as a list of value and label: "value | Label", or one text for both. */
	public static function options_from_text( string $text ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$parts = explode( '|', $line, 2 );
			$value = trim( $parts[0] );
			$label = trim( $parts[1] ?? '' );
			if ( '' === $value ) {
				$value = $label;
			}
			if ( '' !== $value ) {
				$out[] = array(
					'value' => $value,
					'label' => '' !== $label ? $label : $value,
				);
			}
		}
		return $out;
	}

	/** Options as the text of an options box: the reverse of options_from_text(). */
	public static function options_to_text( array $options ): string {
		$lines = array();
		foreach ( $options as $option ) {
			$value = (string) ( $option['value'] ?? '' );
			$label = (string) ( $option['label'] ?? '' );
			if ( '' !== $value ) {
				$lines[] = '' === $label || $label === $value ? $value : $value . ' | ' . $label;
			}
		}
		return implode( "\n", $lines );
	}

	/** A free key made from a label: small letters, digits and underscores; "field" when the label has none. */
	private static function key_from_label( string $label, array $taken ): string {
		$base = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( $label ) ), '_' );
		$base = '' !== $base ? substr( $base, 0, self::MAX_KEY - 4 ) : 'field';
		$key  = $base;
		for ( $n = 2; isset( $taken[ $key ] ); $n++ ) {
			$key = $base . '_' . $n;
		}
		return $key;
	}

	/**
	 * Shows the contact fields the contact rule makes mandatory; with "either", phone when both are hidden.
	 * The rule is enforced on the saved list and again on what the quote_requests_fields filter returns, so a
	 * request cannot arrive with no way to reply. An unknown rule counts as "either".
	 *
	 * @param array  $fields Normalized definitions.
	 * @param string $rule   One of either, phone, email, both.
	 */
	public static function apply_contact_rule( array $fields, string $rule ): array {
		$shown = array();
		foreach ( $fields as $def ) {
			$shown[ $def['key'] ] = $def['show'];
		}
		$force = array();
		if ( 'phone' === $rule || 'both' === $rule ) {
			$force[] = 'phone';
		}
		if ( 'email' === $rule || 'both' === $rule ) {
			$force[] = 'email';
		}
		if ( ! in_array( $rule, array( 'phone', 'email', 'both' ), true ) && empty( $shown['phone'] ) && empty( $shown['email'] ) ) {
			$force[] = 'phone';
		}
		return array_map(
			static function ( array $def ) use ( $force ): array {
				if ( in_array( $def['key'], $force, true ) ) {
					$def['show'] = true;
				}
				return $def;
			},
			$fields
		);
	}

	/**
	 * The list all() built, kept for the rest of the request. Null until the first call and after flush().
	 *
	 * @var array|null
	 */
	private static ?array $memo = null;

	/** Forgets the list kept for this request. Settings::flush() calls it, so a settings change reaches the list too. */
	public static function flush(): void {
		self::$memo = null;
	}

	/**
	 * The saved list, passed through the quote_requests_fields filter, normalized again and held to the contact rule.
	 * Built once per request, so the filter runs once per request.
	 */
	public static function all(): array {
		if ( null === self::$memo ) {
			$list = self::normalize( Settings::get( 'fields' ) );
			/**
			 * Filters the quote form field definitions.
			 *
			 * @param array $list Normalized definitions. The result is normalized again, so system fields cannot be removed.
			 *                    A result that is not an array is ignored and the list stays as it was passed in.
			 */
			$filtered   = apply_filters( 'quote_requests_fields', $list );
			self::$memo = self::apply_contact_rule( is_array( $filtered ) ? self::normalize( $filtered ) : $list, (string) Settings::get( 'contact_rule' ) );
		}
		return self::$memo;
	}

	/** Fields the visitor sees on the form. */
	public static function visible(): array {
		return array_values(
			array_filter(
				self::all(),
				static fn( array $def ): bool => $def['show']
			)
		);
	}

	/** The custom (non-system) fields of a list of definitions. */
	public static function custom( array $defs ): array {
		return array_values(
			array_filter(
				$defs,
				static fn( array $def ): bool => empty( $def['system'] )
			)
		);
	}

	/**
	 * Label => value rows for a stored quote: system fields with their current labels in the
	 * current order, then the stored custom fields with the labels they were saved with.
	 * Empty values are left out. Two equal labels get a number so no value is lost.
	 */
	public static function rows( array $quote ): array {
		$rows = array();
		foreach ( self::all() as $def ) {
			if ( ! $def['system'] ) {
				continue;
			}
			$source = 'region' === $def['key'] ? 'region_name' : $def['key'];
			self::add_row( $rows, $def['label'], $quote[ $source ] ?? '' );
		}
		$stored = $quote['fields'] ?? array();
		foreach ( is_array( $stored ) ? $stored : array() as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$label = self::clean_text( $field['label'] ?? '' );
			if ( '' === $label ) {
				$label = self::clean_key( $field['key'] ?? '' );
			}
			self::add_row( $rows, $label, self::display_value( $field ) );
		}
		return $rows;
	}

	/** The value of a stored custom field as people read it: a checked checkbox is "Yes", anything else as stored. */
	public static function display_value( array $field ) {
		$value = $field['value'] ?? '';
		if ( 'checkbox' === ( $field['type'] ?? '' ) && is_scalar( $value ) && '1' === (string) $value ) {
			return __( 'Yes', 'quote-requests' );
		}
		return $value;
	}

	/** Adds a row unless its value is empty; a label that is already in use gets a number. */
	public static function add_row( array &$rows, string $label, $value ): void {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return;
		}
		$unique = $label;
		for ( $n = 2; isset( $rows[ $unique ] ); $n++ ) {
			$unique = $label . ' (' . $n . ')';
		}
		$rows[ $unique ] = (string) $value;
	}

	private static function clean_key( $key ): string {
		if ( ! is_string( $key ) && ! is_int( $key ) ) {
			return '';
		}
		return substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $key ) ), 0, self::MAX_KEY );
	}

	private static function clean_text( $text ): string {
		if ( ! is_string( $text ) && ! is_int( $text ) && ! is_float( $text ) ) {
			return '';
		}
		return mb_substr( sanitize_text_field( (string) $text ), 0, self::MAX_TEXT );
	}

	private static function flag( array $entry, string $name, bool $fallback ): bool {
		if ( ! array_key_exists( $name, $entry ) ) {
			return $fallback;
		}
		return filter_var( $entry[ $name ], FILTER_VALIDATE_BOOLEAN );
	}

	private static function clean_options( $options ): array {
		if ( ! is_array( $options ) ) {
			return array();
		}
		$out = array();
		foreach ( $options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$value = self::clean_text( $option['value'] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			$label = self::clean_text( $option['label'] ?? '' );
			$out[] = array(
				'value' => $value,
				'label' => '' !== $label ? $label : $value,
			);
		}
		return $out;
	}
}
