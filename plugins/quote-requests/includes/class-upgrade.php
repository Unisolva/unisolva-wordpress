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
 * Moves a site from 0.1 to 0.2: the settings and the stored records.
 *
 * convert_settings(), is_legacy(), details_label() and details_entry() are pure: they take values and return values.
 * maybe_run(), run(), pending() and migrate_records() read and write the database.
 */
final class Upgrade {

	/** The data version this code writes once the upgrade is complete. */
	public const VERSION = 2;

	/** Option that holds the data version of the site. */
	public const VERSION_OPTION = 'quote_requests_db_version';

	/** Record meta where 0.1 kept the value of its one extra field. */
	public const LEGACY_META = '_qr_crop_area';

	/** The 0.1 show and required switches, as 0.1 treated a switch that was never saved. */
	private const OLD_SWITCHES = array(
		'email'   => array(
			'show'     => true,
			'required' => false,
		),
		'company' => array(
			'show'     => true,
			'required' => false,
		),
		'region'  => array(
			'show'     => true,
			'required' => true,
		),
		'details' => array(
			'show'     => true,
			'required' => false,
		),
	);

	/** Keys of the 0.1 shape that no longer exist. */
	private const REMOVED = array( 'label_company', 'label_details', 'region_label', 'fields' );

	/** True when the stored settings are in the 0.1 shape. */
	public static function is_legacy( array $stored ): bool {
		if ( ! $stored ) {
			return false;
		}
		if ( isset( $stored['fields'] ) && is_array( $stored['fields'] ) && array_key_exists( 'email', $stored['fields'] ) ) {
			return true;
		}
		return ! array_key_exists( 'contact_rule', $stored );
	}

	/** Turns 0.1 settings into 0.2 settings with the same form. Settings that are not legacy come back unchanged. */
	public static function convert_settings( array $old ): array {
		if ( ! self::is_legacy( $old ) ) {
			return $old;
		}

		$switch = static function ( string $field, string $name ) use ( $old ): bool {
			$saved = $old['fields'][ $field ] ?? null;
			if ( is_array( $saved ) && array_key_exists( $name, $saved ) ) {
				return (bool) $saved[ $name ];
			}
			return self::OLD_SWITCHES[ $field ][ $name ];
		};
		$label  = static function ( string $key, string $fallback ) use ( $old ): string {
			$text = is_scalar( $old[ $key ] ?? null ) ? trim( (string) $old[ $key ] ) : '';
			return '' !== $text ? $text : $fallback;
		};

		// The phone and email "required" flags stay off: the contact rule decides what is mandatory.
		$fields = Fields::normalize(
			array(
				array( 'key' => 'name' ),
				array(
					'key'   => 'phone',
					'label' => __( 'Phone / WhatsApp', 'quote-requests' ),
				),
				array(
					'key'      => 'region',
					'label'    => $label( 'region_label', __( 'Region', 'quote-requests' ) ),
					'show'     => $switch( 'region', 'show' ),
					'required' => $switch( 'region', 'required' ),
				),
				array(
					'key'      => 'email',
					'show'     => $switch( 'email', 'show' ),
					'required' => false,
				),
				array(
					'key'      => 'company',
					'label'    => $label( 'label_company', __( 'Company', 'quote-requests' ) ),
					'show'     => $switch( 'company', 'show' ),
					'required' => $switch( 'company', 'required' ),
				),
				array(
					'key'      => 'details',
					'type'     => 'text',
					'label'    => $label( 'label_details', __( 'Details', 'quote-requests' ) ),
					'show'     => $switch( 'details', 'show' ),
					'required' => $switch( 'details', 'required' ),
				),
				array( 'key' => 'message' ),
			)
		);

		$new                    = array_diff_key( $old, array_flip( self::REMOVED ) );
		$new['fields']          = $fields;
		$new['contact_rule']    = $switch( 'email', 'show' ) && $switch( 'email', 'required' ) ? 'both' : 'phone';
		$new['require_consent'] = true;
		$new['region_mode']     = 'single';
		return $new;
	}

	public static function register(): void {
		add_action( 'init', array( self::class, 'maybe_run' ), 5 );
	}

	/**
	 * Runs the upgrade when the site is behind. Does nothing while QUOTE_REQUESTS_HOLD_UPGRADE is defined with any
	 * truthy value (true, 1, "yes", even the string "false"): it is a switch for postponing the data upgrade, and
	 * only a falsy value or no constant lets the upgrade run.
	 */
	public static function maybe_run(): void {
		if ( self::held() ) {
			return;
		}
		self::run_if_needed();
	}

	/** True while QUOTE_REQUESTS_HOLD_UPGRADE postpones every write of the data upgrade. */
	public static function held(): bool {
		return defined( 'QUOTE_REQUESTS_HOLD_UPGRADE' ) && QUOTE_REQUESTS_HOLD_UPGRADE;
	}

	/** True when a site with this stored data version still has to be upgraded. */
	public static function needed( $stored_version ): bool {
		return (int) $stored_version < self::VERSION;
	}

	/** The part of maybe_run() after the hold: runs the upgrade when the stored version is behind. Returns whether it ran. */
	public static function run_if_needed(): bool {
		if ( ! self::needed( get_option( self::VERSION_OPTION, 0 ) ) ) {
			return false;
		}
		self::run();
		return true;
	}

	/**
	 * Converts 0.1 settings, moves the old details values into the custom fields of each record and, once no record
	 * is left to move, stores the data version. Safe to run again: a finished upgrade changes nothing. One batch of
	 * records is moved per call; the next call (the next request, on a large site) continues.
	 *
	 * It also looks up, once, whether the site has orders with the old quote statuses (Legacy_Statuses).
	 *
	 * The settings that are converted are the stored ones (stored_settings()): a filter on the option
	 * (option_quote_requests_settings) changes what a request sees, and must never be written back as the setting.
	 */
	public static function run(): void {
		if ( ! Legacy_Statuses::detected() ) {
			Legacy_Statuses::detect();
		}
		$stored = self::stored_settings();
		if ( is_string( $stored ) ) {
			$stored = json_decode( $stored, true );
		}
		$stored = is_array( $stored ) ? $stored : array();
		if ( self::is_legacy( $stored ) ) {
			$stored = self::convert_settings( $stored );
			update_option( Settings::OPTION, $stored );
		}
		if ( 0 === self::migrate_records( self::details_label( $stored ) ) ) {
			update_option( self::VERSION_OPTION, self::VERSION, true );
		}
	}

	/**
	 * The settings as they are stored, an empty array when nothing is stored: get_option() with the filters on its
	 * result (option_ and default_option_ of the settings) set aside for the one call. A filter that stands in for
	 * the stored row itself (pre_option_) still applies.
	 *
	 * @return mixed
	 */
	private static function stored_settings() {
		global $wp_filter;
		$aside = array();
		foreach ( array( 'option_' . Settings::OPTION, 'default_option_' . Settings::OPTION ) as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) ) {
				$aside[ $hook ] = $wp_filter[ $hook ];
				unset( $wp_filter[ $hook ] );
			}
		}
		try {
			return get_option( Settings::OPTION, array() );
		} finally {
			foreach ( $aside as $hook => $callbacks ) {
				$wp_filter[ $hook ] = $callbacks; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Puts back what was set aside above.
			}
		}
	}

	/**
	 * Ids of records that still hold an old details value, oldest first. A limit of -1 means all of them.
	 *
	 * @return int[]
	 */
	public static function pending( int $limit ): array {
		$ids = get_posts(
			array(
				'post_type'              => Store::POST_TYPE,
				'post_status'            => array_values( get_post_stati() ),
				'posts_per_page'         => $limit,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'meta_key'               => self::LEGACY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A one-off upgrade query.
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Moves up to $limit old details values into _qr_fields (key details, type text, the given label) and removes the
	 * old meta; an empty old value is just removed. A record whose _qr_fields holds something that is not a JSON list
	 * is never overwritten: its old value is copied to the meta _qr_fields_legacy instead, and then counts as moved.
	 * Returns how many records still have the old meta.
	 */
	public static function migrate_records( string $label, int $limit = 500 ): int {
		foreach ( self::pending( $limit ) as $id ) {
			$value = (string) get_post_meta( $id, self::LEGACY_META, true );
			if ( '' !== trim( $value ) ) {
				$raw    = (string) get_post_meta( $id, '_qr_fields', true );
				$fields = '' === trim( $raw ) ? array() : json_decode( $raw, true );
				if ( is_array( $fields ) ) {
					if ( ! in_array( 'details', array_column( array_filter( $fields, 'is_array' ), 'key' ), true ) ) {
						$fields[] = self::details_entry( $label, $value );
					}
					$meta  = '_qr_fields';
					$write = (string) wp_json_encode( array_values( $fields ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				} else {
					$meta  = '_qr_fields_legacy'; // _qr_fields is not a JSON list: leave it alone and keep the old value beside it.
					$write = $value;
				}
				update_post_meta( $id, $meta, wp_slash( $write ) );
				if ( get_post_meta( $id, $meta, true ) !== $write ) {
					continue; // Not saved: keep the old value and count the record as left.
				}
			}
			delete_post_meta( $id, self::LEGACY_META );
		}
		return count( self::pending( -1 ) );
	}

	/** The label of the details field in 0.2 settings (or 0.1 settings, which are converted first). */
	public static function details_label( array $settings ): string {
		$settings = self::convert_settings( $settings );
		foreach ( is_array( $settings['fields'] ?? null ) ? $settings['fields'] : array() as $def ) {
			if ( is_array( $def ) && 'details' === ( $def['key'] ?? '' ) && is_string( $def['label'] ?? null ) && '' !== trim( $def['label'] ) ) {
				return $def['label'];
			}
		}
		return __( 'Details', 'quote-requests' );
	}

	/** One stored custom field value for the old details value. */
	public static function details_entry( string $label, string $value ): array {
		return array(
			'key'   => 'details',
			'label' => $label,
			'type'  => 'text',
			'value' => $value,
		);
	}
}
