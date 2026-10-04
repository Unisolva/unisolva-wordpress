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
 * Three order statuses of an earlier quote workflow that kept its quotes as WooCommerce orders. This plugin has
 * no such workflow: it registers the statuses only so that orders a site already has stay visible and readable.
 *
 * Whether the site has such orders is looked up once (detect(), from the data upgrade and on activation) and the
 * answer is stored. A request only reads the stored answer.
 */
final class Legacy_Statuses {

	public const STATUSES = array( 'wc-quote-requested', 'wc-quote-approved', 'wc-quote-rejected' );

	/** Option that holds the answer of detect(): "yes" or "no". Absent while the site has not been checked. */
	public const OPTION = 'quote_requests_legacy_statuses';

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_statuses' ), 20 );
		add_filter( 'wc_order_statuses', array( self::class, 'labels' ), 20 );
	}

	/**
	 * Whether the statuses are registered. Without a stored answer they are: a site that was not checked yet
	 * (the data upgrade is held, or has not run) may have such orders, and they must not disappear from its lists.
	 */
	public static function enabled(): bool {
		/**
		 * Filters whether the three old quote order statuses are registered.
		 *
		 * @param bool $register True when the site has orders with one of the statuses, or was not checked yet.
		 */
		return (bool) apply_filters( 'quote_requests_legacy_order_statuses', 'no' !== get_option( self::OPTION, '' ) );
	}

	/** Status key => name as people read it. */
	private static function names(): array {
		return array(
			'wc-quote-requested' => _x( 'Quote Requested', 'Order status', 'quote-requests' ),
			'wc-quote-approved'  => _x( 'Quote Approved', 'Order status', 'quote-requests' ),
			'wc-quote-rejected'  => _x( 'Quote Rejected', 'Order status', 'quote-requests' ),
		);
	}

	/** Status key => the name with the number of orders, for the status links above the order list. */
	private static function counts(): array {
		return array(
			/* translators: %s: number of orders */
			'wc-quote-requested' => _n_noop( 'Quote Requested <span class="count">(%s)</span>', 'Quote Requested <span class="count">(%s)</span>', 'quote-requests' ),
			/* translators: %s: number of orders */
			'wc-quote-approved'  => _n_noop( 'Quote Approved <span class="count">(%s)</span>', 'Quote Approved <span class="count">(%s)</span>', 'quote-requests' ),
			/* translators: %s: number of orders */
			'wc-quote-rejected'  => _n_noop( 'Quote Rejected <span class="count">(%s)</span>', 'Quote Rejected <span class="count">(%s)</span>', 'quote-requests' ),
		);
	}

	public static function register_statuses(): void {
		if ( self::enabled() ) {
			self::add_statuses();
		}
	}

	/**
	 * Registers each status that nothing else has registered.
	 *
	 * @return string[] The keys this call registered.
	 */
	private static function add_statuses(): array {
		$names  = self::names();
		$counts = self::counts();
		$added  = array();
		foreach ( self::STATUSES as $key ) {
			if ( get_post_status_object( $key ) ) {
				continue; // Something else on the site registers it.
			}
			register_post_status(
				$key,
				array(
					'label'                     => $names[ $key ],
					'public'                    => false,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => $counts[ $key ],
				)
			);
			$added[] = $key;
		}
		return $added;
	}

	public static function labels( array $statuses ): array {
		if ( ! self::enabled() ) {
			return $statuses;
		}
		foreach ( self::names() as $key => $label ) {
			if ( ! isset( $statuses[ $key ] ) ) {
				$statuses[ $key ] = $label;
			}
		}
		return $statuses;
	}

	/**
	 * Whether the site has an order with one of the given statuses, whichever way WooCommerce stores its orders.
	 * Null when that cannot be told now: WooCommerce is not loaded, its order type is not registered yet, or the
	 * query threw (in WooCommerce or in a query filter of another plugin). No answer means nothing is stored and
	 * the statuses stay registered; the lookup must never take a request down.
	 *
	 * With orders stored as posts the query drops a status that is not registered, and a query left without any
	 * status matches every order. So each status is registered for the time of the query and taken away again
	 * unless it was registered before.
	 *
	 * @param string[] $statuses Status keys with the "wc-" prefix.
	 */
	public static function has_orders( array $statuses = self::STATUSES ): ?bool {
		global $wp_post_statuses;
		if ( ! $statuses || ! function_exists( 'wc_get_orders' ) || ! post_type_exists( 'shop_order' ) ) {
			return null;
		}
		$added = array();
		foreach ( $statuses as $key ) {
			if ( ! get_post_status_object( $key ) ) {
				register_post_status( $key, array( 'public' => false ) );
				$added[] = $key;
			}
		}
		try {
			$ids = wc_get_orders(
				array(
					'type'   => 'shop_order',
					'status' => array_values( $statuses ),
					'limit'  => 1,
					'return' => 'ids',
				)
			);
		} catch ( \Throwable $e ) {
			$ids = null;
		} finally {
			foreach ( $added as $key ) {
				unset( $wp_post_statuses[ $key ] ); // WordPress has no function that takes a post status away.
			}
		}
		return is_array( $ids ) ? (bool) $ids : null;
	}

	/**
	 * Looks the orders up once and stores the answer, so that no request has to ask again.
	 * Stores nothing when the question cannot be answered now. Returns the answer.
	 */
	public static function detect(): ?bool {
		$found = self::has_orders();
		if ( null !== $found ) {
			update_option( self::OPTION, $found ? 'yes' : 'no', true );
		}
		return $found;
	}

	/** True once detect() has stored an answer. */
	public static function detected(): bool {
		return in_array( get_option( self::OPTION, '' ), array( 'yes', 'no' ), true );
	}
}
