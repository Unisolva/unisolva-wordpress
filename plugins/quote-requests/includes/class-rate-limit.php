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
 * Per-address hourly limit on stored quote requests.
 *
 * Counts live in one options row per address and hour, incremented in a single
 * atomic statement so parallel requests cannot all slip under the limit.
 */
final class Rate_Limit {

	public const PREFIX = 'quote_requests_rl_';

	/**
	 * Counter store with incr( string ): int and get( string ): int. Replaced in tests.
	 *
	 * @var object|null
	 */
	public static $store = null;

	private static function store(): object {
		if ( null === self::$store ) {
			self::$store = new class() {
				public function incr( string $key ): int {
					global $wpdb;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One atomic statement; the options API cannot do this.
					$affected = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(option_value + 1)", $key ) );
					if ( 1 === (int) $affected ) {
						return 1; // Row inserted.
					}
					return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
				public function get( string $key ): int {
					global $wpdb;
					return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			};
		}
		return self::$store;
	}

	private static function key( string $ip, string $secret, int $now ): string {
		return self::PREFIX . substr( hash_hmac( 'sha256', $ip, $secret ), 0, 20 ) . '_' . intdiv( $now, HOUR_IN_SECONDS );
	}

	/**
	 * Cheap early check: is this address still under the limit for the current hour?
	 */
	public static function allow( string $ip, int $limit, string $secret, ?int $now = null ): bool {
		return self::store()->get( self::key( $ip, $secret, $now ?? time() ) ) < $limit;
	}

	/**
	 * Reserve one request for this address. False when the limit is already used up.
	 */
	public static function take( string $ip, int $limit, string $secret, ?int $now = null ): bool {
		return self::store()->incr( self::key( $ip, $secret, $now ?? time() ) ) <= $limit;
	}

	/**
	 * Remove counters of past hours (called by the daily purge).
	 */
	public static function purge_old( ?int $now = null ): int {
		global $wpdb;
		$current = '%' . $wpdb->esc_like( '_' . intdiv( $now ?? time(), HOUR_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%', $current ) );
	}
}
