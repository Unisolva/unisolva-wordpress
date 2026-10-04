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

final class Privacy {

	/** Meta keys that hold what the visitor typed into the custom fields (the old Details meta and the upgrade's leftover included). */
	private const FREE_TEXT_META = array( '_qr_fields', '_qr_fields_legacy', Upgrade::LEGACY_META );

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'policy_text' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
	}

	public static function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$months = (int) Settings::get( 'retention_months' );
		$text   = '<p>' . esc_html__( 'When you send a quote request we store your name, phone number, the optional details you give (email, company, region, message and any other fields of the form), the products and quantities you chose and the time you agreed to the privacy policy.', 'quote-requests' ) . '</p>';
		if ( Settings::get( 'store_client' ) ) {
			$text .= '<p>' . esc_html__( 'We also store your IP address, browser, operating system, device type, preferred language, the page you sent the request from, the page where your visit started and the website that sent you here, to prevent abuse and to understand how customers find us.', 'quote-requests' ) . '</p>';
		}
		/* translators: %d: number of months */
		$text .= '<p>' . esc_html( sprintf( __( 'Quote requests are deleted automatically after %d months.', 'quote-requests' ), $months ) ) . '</p>';
		$text .= '<p>' . esc_html__( 'The products in your quote list are kept in your browser (local storage "quote_requests_list", or the cookie of the same name when local storage is not available) for 30 days and are not sent to us until you send the request.', 'quote-requests' ) . '</p>';
		wp_add_privacy_policy_content( 'Quote Requests', wp_kses_post( $text ) );
	}

	private static function ids_for( string $email ): array {
		return get_posts(
			array(
				'post_type'   => Store::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => '_qr_email',
				'meta_value'  => $email,
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	public static function exporters( array $exporters ): array {
		$exporters['quote-requests'] = array(
			'exporter_friendly_name' => __( 'Quote requests', 'quote-requests' ),
			'callback'               => static function ( string $email, int $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature set by WordPress; all records are handled in one page.
				$data = array();
				foreach ( self::ids_for( $email ) as $id ) {
					$q      = Store::get( (int) $id );
					$fields = array();
					$record = array_merge( $q, array( 'region_name' => Regions::location( $q, true ) ) ); // The same location text as the admin view.
					foreach ( array( 'ref', 'date', 'name', 'phone', 'email', 'company', 'region_name', 'message' ) as $k ) {
						$fields[] = array(
							'name'  => $k,
							'value' => (string) ( $record[ $k ] ?? '' ),
						);
					}
					foreach ( $q['fields'] as $field ) { // Labels as stored at submission time, whatever the definitions say now.
						$fields[] = array(
							'name'  => '' !== $field['label'] ? $field['label'] : $field['key'],
							'value' => (string) Fields::display_value( $field ),
						);
					}
					$legacy = (string) get_post_meta( (int) $id, '_qr_fields_legacy', true ); // Left by the upgrade when _qr_fields was unreadable; exported as it is.
					if ( '' !== $legacy ) {
						$fields[] = array(
							'name'  => 'fields_legacy',
							'value' => $legacy,
						);
					}
					$fields[] = array(
						'name'  => 'items',
						'value' => wp_json_encode( $q['items'] ),
					);
					$fields[] = array(
						'name'  => 'visitor',
						'value' => wp_json_encode( $q['client'] ),
					);
					$fields[] = array(
						'name'  => 'consent',
						'value' => wp_json_encode( $q['consent'] ),
					);
					$data[]   = array(
						'group_id'    => 'quote-requests',
						'group_label' => __( 'Quote requests', 'quote-requests' ),
						'item_id'     => 'quote-request-' . $id,
						'data'        => $fields,
					);
				}
				return array(
					'data' => $data,
					'done' => true,
				);
			},
		);
		return $exporters;
	}

	public static function erasers( array $erasers ): array {
		$erasers['quote-requests'] = array(
			'eraser_friendly_name' => __( 'Quote requests', 'quote-requests' ),
			'callback'             => static function ( string $email, int $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature set by WordPress; all records are handled in one page.
				$removed  = false;
				$retained = false;
				foreach ( self::ids_for( $email ) as $id ) {
					foreach ( self::FREE_TEXT_META as $meta ) { // Empty what the visitor typed first, so it is gone even if the record cannot be deleted.
						delete_post_meta( (int) $id, $meta );
					}
					if ( wp_delete_post( (int) $id, true ) ) {
						$removed = true;
					} else {
						$retained = true;
					}
				}
				return array(
					'items_removed'  => $removed,
					'items_retained' => $retained,
					'messages'       => $retained ? array( __( 'A quote request could not be deleted. The text typed into its custom fields was emptied; the rest was kept.', 'quote-requests' ) ) : array(),
					'done'           => true,
				);
			},
		);
		return $erasers;
	}
}
