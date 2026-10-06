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

final class Admin {

	public static function register(): void {
		$pt = Store::POST_TYPE;
		add_filter( "manage_{$pt}_posts_columns", array( self::class, 'columns' ) );
		add_action( "manage_{$pt}_posts_custom_column", array( self::class, 'print_column' ), 10, 2 );
		add_filter( 'posts_join', array( self::class, 'search_join' ), 10, 2 );
		add_filter( 'posts_search', array( self::class, 'search_where' ), 10, 2 );
		add_filter( 'posts_distinct', array( self::class, 'search_distinct' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( self::class, 'crm_filter' ) );
		add_action( 'pre_get_posts', array( self::class, 'apply_crm_filter' ) );
		add_action( "add_meta_boxes_{$pt}", array( self::class, 'meta_boxes' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
	}

	/**
	 * Whether the CRM state of a record is shown: the list column, the list filter and the row on the record.
	 * Off by default: the state is kept on every record for a connector, and this plugin has none of its own.
	 */
	public static function show_crm(): bool {
		/**
		 * Filters whether the CRM state of quote records is shown in the admin.
		 *
		 * @param bool $show Default false.
		 */
		return (bool) apply_filters( 'quote_requests_show_crm_state', false );
	}

	public static function columns(): array {
		$columns = array(
			'cb'       => '<input type="checkbox" />',
			'ref'      => __( 'Reference', 'quote-requests' ),
			'name'     => __( 'Name', 'quote-requests' ),
			'phone'    => __( 'Contact', 'quote-requests' ),
			'region'   => __( 'Region', 'quote-requests' ),
			'products' => __( 'Products', 'quote-requests' ),
			'mail'     => __( 'Team email', 'quote-requests' ),
			'crm'      => __( 'CRM', 'quote-requests' ),
			'date'     => __( 'Date', 'quote-requests' ),
		);
		if ( ! self::show_crm() ) {
			unset( $columns['crm'] );
		}
		return $columns;
	}

	public static function column_value( string $column, int $id ): string {
		$q = Store::get( $id );
		if ( ! $q ) {
			return '';
		}
		switch ( $column ) {
			case 'ref':
				return '<a href="' . esc_url( get_edit_post_link( $id ) ) . '"><strong>' . esc_html( $q['ref'] ) . '</strong></a>';
			case 'name':
				return esc_html( $q['name'] ) . ( $q['company'] ? '<br><span class="description">' . esc_html( $q['company'] ) . '</span>' : '' );
			case 'phone':
				// The phone when there is one, otherwise the email: the contact rule may ask for either.
				if ( '' !== $q['phone'] ) {
					return '<a href="' . esc_url( 'tel:' . $q['phone'] ) . '">' . esc_html( $q['phone'] ) . '</a>';
				}
				return '' !== $q['email'] ? '<a href="' . esc_url( 'mailto:' . $q['email'] ) . '">' . esc_html( $q['email'] ) . '</a>' : '';
			case 'region':
				return esc_html( Regions::location( $q ) );
			case 'products':
				return esc_html( (string) count( $q['items'] ) );
			case 'mail':
				$state  = $q['mail']['admin']['state'] ?? '';
				$labels = array(
					'handed_over' => __( 'Handed over', 'quote-requests' ),
					'failed'      => __( 'Failed', 'quote-requests' ),
				);
				$label  = $labels[ $state ] ?? __( 'Not sent', 'quote-requests' );
				return 'handed_over' === $state ? esc_html( $label ) : '<strong style="color:#b32d2e">' . esc_html( $label ) . '</strong>';
			case 'crm':
				return esc_html( ucfirst( $q['crm_state'] ) );
		}
		return '';
	}

	public static function print_column( string $column, int $id ): void {
		echo self::column_value( $column, $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped in column_value().
	}

	private static function is_list_search( \WP_Query $q ): bool {
		return is_admin() && Store::POST_TYPE === $q->get( 'post_type' ) && '' !== (string) $q->get( 's' );
	}

	public static function search_join( string $join, \WP_Query $q ): string {
		global $wpdb;
		if ( self::is_list_search( $q ) ) {
			$join .= " LEFT JOIN {$wpdb->postmeta} qrm ON ( qrm.post_id = {$wpdb->posts}.ID AND qrm.meta_key IN ( '_qr_ref', '_qr_name', '_qr_phone', '_qr_email', '_qr_company' ) ) ";
		}
		return $join;
	}

	public static function search_where( string $search, \WP_Query $q ): string {
		global $wpdb;
		if ( ! self::is_list_search( $q ) ) {
			return $search;
		}
		$like = '%' . $wpdb->esc_like( (string) $q->get( 's' ) ) . '%';
		return $wpdb->prepare( " AND ( {$wpdb->posts}.post_title LIKE %s OR qrm.meta_value LIKE %s ) ", $like, $like );
	}

	public static function search_distinct( string $distinct, \WP_Query $q ): string {
		return self::is_list_search( $q ) ? 'DISTINCT' : $distinct;
	}

	public static function crm_filter( string $post_type ): void {
		if ( Store::POST_TYPE !== $post_type || ! self::show_crm() ) {
			return;
		}
		$current = isset( $_GET['qr_crm'] ) ? sanitize_key( wp_unslash( $_GET['qr_crm'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<label class="screen-reader-text" for="qr-crm-filter">' . esc_html__( 'Filter by CRM state', 'quote-requests' ) . '</label>';
		echo '<select id="qr-crm-filter" name="qr_crm"><option value="">' . esc_html__( 'All CRM states', 'quote-requests' ) . '</option>';
		foreach ( array(
			'pending' => __( 'Pending', 'quote-requests' ),
			'synced'  => __( 'Synced', 'quote-requests' ),
			'failed'  => __( 'Failed', 'quote-requests' ),
		) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $current, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function apply_crm_filter( \WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() || Store::POST_TYPE !== $q->get( 'post_type' ) || empty( $_GET['qr_crm'] ) || ! self::show_crm() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$q->set(
			'meta_query',
			array(
				array(
					'key'   => '_qr_crm_state',
					'value' => sanitize_key( wp_unslash( $_GET['qr_crm'] ) ), // phpcs:ignore WordPress.Security.NonceVerification -- Read-only list filter.
				),
			)
		); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function row_actions( array $actions, \WP_Post $post ): array {
		if ( Store::POST_TYPE === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}

	public static function meta_boxes(): void {
		remove_post_type_support( Store::POST_TYPE, 'title' );
		remove_meta_box( 'submitdiv', Store::POST_TYPE, 'side' );
		remove_meta_box( 'slugdiv', Store::POST_TYPE, 'normal' );
		add_meta_box( 'qr-detail', __( 'Quote request', 'quote-requests' ), array( self::class, 'render_detail' ), Store::POST_TYPE, 'normal', 'high' );
	}

	private static function table( array $rows ): void {
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			echo '<tr><th scope="row" style="width:220px">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( (string) $value ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function render_detail( \WP_Post $post ): void {
		$q = Store::get( (int) $post->ID );
		if ( ! $q ) {
			return;
		}
		$c = $q['client'];
		echo '<h3>' . esc_html( $q['ref'] ) . '</h3>';
		// The form fields: system fields under their current labels, then the custom fields under the labels they were stored with.
		$rows = array();
		Fields::add_row( $rows, __( 'Received', 'quote-requests' ), get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $q['date'] ) ), 'Y-m-d H:i' ) );
		foreach ( Fields::rows( array_merge( $q, array( 'region_name' => Regions::location( $q, true ) ) ) ) as $label => $value ) {
			Fields::add_row( $rows, (string) $label, $value );
		}
		self::table( $rows );
		echo '<h3>' . esc_html__( 'Products', 'quote-requests' ) . '</h3>';
		if ( $q['items'] ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'quote-requests' ) . '</th><th>SKU</th><th>' . esc_html__( 'Family', 'quote-requests' ) . '</th><th>' . esc_html__( 'Quantity', 'quote-requests' ) . '</th></tr></thead><tbody>';
			foreach ( $q['items'] as $it ) {
				echo '<tr><td>' . esc_html( $it['name'] ) . '</td><td>' . esc_html( $it['sku'] ) . '</td><td>' . esc_html( $it['family'] ) . '</td><td>' . (int) $it['qty'] . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'No products listed; see the message.', 'quote-requests' ) . '</p>';
		}
		echo '<h3>' . esc_html__( 'Visitor', 'quote-requests' ) . '</h3>';
		self::table(
			array(
				__( 'IP address', 'quote-requests' )     => $c['ip'] ?? '',
				__( 'Device', 'quote-requests' )         => trim( ( $c['device'] ?? '' ) . ', ' . trim( ( $c['browser'] ?? '' ) . ' ' . ( $c['browser_version'] ?? '' ) ) . ', ' . ( $c['os'] ?? '' ), ', ' ),
				__( 'Language', 'quote-requests' )       => $c['language'] ?? '',
				__( 'Timezone offset (min)', 'quote-requests' ) => isset( $c['tz_offset'] ) ? (string) $c['tz_offset'] : '',
				__( 'Sent from page', 'quote-requests' ) => $c['page'] ?? '',
				__( 'Landing page', 'quote-requests' )   => $c['landing'] ?? '',
				__( 'Referrer', 'quote-requests' )       => $c['referrer'] ?? '',
				__( 'User agent', 'quote-requests' )     => $c['user_agent'] ?? '',
				// What sent the request: "form" for the built-in form, otherwise the label the calling code gave.
				__( 'Source', 'quote-requests' )         => $q['source'],
			)
		);
		echo '<h3>' . esc_html( self::show_crm() ? __( 'Consent, email and CRM', 'quote-requests' ) : __( 'Consent and email', 'quote-requests' ) ) . '</h3>';
		$mail = static fn( string $k ) => isset( $q['mail'][ $k ] ) ? $q['mail'][ $k ]['state'] . ' ' . $q['mail'][ $k ]['time'] : '';
		// Records from 0.1 have no "given" key and count as given; a form without a consent box stores given = false.
		$consent = array_key_exists( 'given', $q['consent'] ) && ! $q['consent']['given']
			? array( __( 'Consent', 'quote-requests' ) => __( 'No consent box was shown on the form when this request was sent.', 'quote-requests' ) )
			: array(
				__( 'Consent given', 'quote-requests' ) => ( $q['consent']['time'] ?? '' ),
				__( 'Consent text', 'quote-requests' )  => ( $q['consent']['text'] ?? '' ),
				__( 'Privacy page', 'quote-requests' )  => ( $q['consent']['privacy_url'] ?? '' ),
			);
		Fields::add_row( $consent, __( 'Team email', 'quote-requests' ), $mail( 'admin' ) );
		Fields::add_row( $consent, __( 'Customer email', 'quote-requests' ), $mail( 'customer' ) );
		if ( self::show_crm() ) {
			Fields::add_row( $consent, __( 'CRM state', 'quote-requests' ), $q['crm_state'] );
		}
		self::table( $consent );
		echo '<p class="description">' . esc_html__( '"handed_over" means WordPress passed the email to the mail service; check the mail service log to confirm delivery.', 'quote-requests' ) . '</p>';
		echo '<p><a class="button-link-delete" href="' . esc_url( get_delete_post_link( $post->ID ) ) . '">' . esc_html__( 'Move to Trash', 'quote-requests' ) . '</a></p>';
	}
}
