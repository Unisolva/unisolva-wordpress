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

final class Retention {

	public static function register(): void {
		add_action( 'quote_requests_purge', array( self::class, 'purge' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( 'quote_requests_deactivate', array( self::class, 'unschedule' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( 'quote_requests_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'quote_requests_purge' );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( 'quote_requests_purge' );
	}

	public static function purge( ?int $now = null ): int {
		$now    = $now ?? time();
		$months = max( 1, (int) Settings::get( 'retention_months' ) );
		$before = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months', $now ) );
		$ids    = get_posts(
			array(
				'post_type'        => Store::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => 200, // phpcs:ignore WordPress.WP.PostsPerPage -- Bounded batch for a daily purge.
				'fields'           => 'ids',
				'suppress_filters' => true,
				'date_query'       => array(
					array(
						'column' => 'post_date_gmt',
						'before' => $before,
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		Rate_Limit::purge_old( $now );
		return count( $ids );
	}
}
