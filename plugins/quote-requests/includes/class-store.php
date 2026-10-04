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

final class Store {

	public const POST_TYPE = 'quote_request';

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
	}

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Quote requests', 'quote-requests' ),
					'singular_name' => __( 'Quote request', 'quote-requests' ),
					'menu_name'     => __( 'Quote requests', 'quote-requests' ),
					'all_items'     => __( 'Quote requests', 'quote-requests' ),
					'edit_item'     => __( 'Quote request', 'quote-requests' ),
					'search_items'  => __( 'Search quote requests', 'quote-requests' ),
					'not_found'     => __( 'No quote requests yet.', 'quote-requests' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'woocommerce',
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'map_meta_cap'        => false,
				'capabilities'        => array(
					'edit_post'          => 'manage_woocommerce',
					'read_post'          => 'manage_woocommerce',
					'delete_post'        => 'manage_woocommerce',
					'edit_posts'         => 'manage_woocommerce',
					'edit_others_posts'  => 'manage_woocommerce',
					'delete_posts'       => 'manage_woocommerce',
					'publish_posts'      => 'manage_woocommerce',
					'read_private_posts' => 'manage_woocommerce',
					'create_posts'       => 'do_not_allow',
				),
			)
		);
	}

	public static function next_number( int $year ): int {
		global $wpdb;
		$name = 'quote_requests_counter_' . $year;
		for ( $i = 0; $i < 3; $i++ ) {
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $updated ) {
				wp_cache_delete( $name, 'options' );
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( add_option( $name, 1, '', false ) ) {
				return 1;
			}
		}
		return (int) ( microtime( true ) * 1000 ) % 100000; // Never reached in practice; keeps a unique-looking number instead of failing the request.
	}

	public static function create( array $contact, array $items, array $consent, array $client ): int {
		$year = (int) gmdate( 'Y' );
		$ref  = Reference::format( $year, self::next_number( $year ) );
		$id   = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $ref . ' ' . $contact['name'],
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		$json = static fn( $v ) => wp_slash( wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$meta = array(
			'_qr_ref'            => $ref,
			'_qr_name'           => $contact['name'],
			'_qr_phone'          => $contact['phone'],
			'_qr_email'          => $contact['email'],
			'_qr_company'        => $contact['company'],
			'_qr_region_country' => $contact['region_country'] ?? '',
			'_qr_region_code'    => $contact['region'],
			'_qr_region_name'    => $contact['region_name'] ?? '',
			'_qr_message'        => $contact['message'],
			'_qr_crm_state'      => 'pending',
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, wp_slash( (string) $v ) );
		}
		update_post_meta( $id, '_qr_items', $json( array_values( $items ) ) );
		update_post_meta( $id, '_qr_fields', $json( self::clean_fields( $contact['custom'] ?? array() ) ) );
		update_post_meta( $id, '_qr_consent', $json( $consent ) );
		$source = array_intersect_key( $client, array_flip( array( 'page', 'landing', 'referrer' ) ) );
		update_post_meta( $id, '_qr_source', $json( $source ) );
		update_post_meta( $id, '_qr_client', $json( array_diff_key( $client, $source ) ) );
		update_post_meta( $id, '_qr_mail', $json( new \stdClass() ) );
		return (int) $id;
	}

	public static function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$m   = static fn( string $k ) => (string) get_post_meta( $id, $k, true );
		$j   = static function ( string $k ) use ( $m ): array {
			$v = json_decode( $m( $k ), true );
			return is_array( $v ) ? $v : array();
		};
		$src = $j( '_qr_source' );
		return array(
			'id'             => $id,
			'ref'            => $m( '_qr_ref' ),
			'date'           => get_post_time( 'c', true, $post ),
			'name'           => $m( '_qr_name' ),
			'phone'          => $m( '_qr_phone' ),
			'email'          => $m( '_qr_email' ),
			'company'        => $m( '_qr_company' ),
			'region_country' => $m( '_qr_region_country' ),
			'region_code'    => $m( '_qr_region_code' ),
			'region_name'    => $m( '_qr_region_name' ),
			'message'        => $m( '_qr_message' ),
			'items'          => $j( '_qr_items' ),
			'fields'         => self::fields( $id, $j( '_qr_fields' ) ),
			'consent'        => $j( '_qr_consent' ),
			'client'         => array_merge( $j( '_qr_client' ), $src ),
			'mail'           => $j( '_qr_mail' ),
			'crm_state'      => $m( '_qr_crm_state' ),
		);
	}

	/**
	 * The custom field values of a record as a list of array( key, label, type, value ).
	 * A record from 0.1 that the data upgrade has not reached yet still has its old details value; it is shown as one field.
	 */
	private static function fields( int $id, array $stored ): array {
		if ( metadata_exists( 'post', $id, '_qr_fields' ) ) {
			return self::clean_fields( $stored );
		}
		$old = trim( (string) get_post_meta( $id, Upgrade::LEGACY_META, true ) );
		return '' === $old ? array() : array( Upgrade::details_entry( Upgrade::details_label( Settings::all() ), $old ) );
	}

	/** Keeps only well-formed entries, each reduced to key, label, type and value as strings. */
	private static function clean_fields( $fields ): array {
		$text = static fn( $v ): string => is_scalar( $v ) ? (string) $v : '';
		$out  = array();
		foreach ( is_array( $fields ) ? $fields : array() as $field ) {
			if ( ! is_array( $field ) || ! is_string( $field['key'] ?? null ) || '' === $field['key'] ) {
				continue;
			}
			$out[] = array(
				'key'   => $field['key'],
				'label' => $text( $field['label'] ?? '' ),
				'type'  => $text( $field['type'] ?? 'text' ),
				'value' => $text( $field['value'] ?? '' ),
			);
		}
		return $out;
	}

	public static function set_mail( int $id, string $which, string $state ): void {
		$mail           = json_decode( (string) get_post_meta( $id, '_qr_mail', true ), true );
		$mail           = is_array( $mail ) ? $mail : array();
		$mail[ $which ] = array(
			'state' => $state,
			'time'  => gmdate( 'c' ),
		);
		update_post_meta( $id, '_qr_mail', wp_slash( wp_json_encode( $mail ) ) );
	}
}
