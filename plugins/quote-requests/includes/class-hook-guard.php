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
 * What the plugin does when a callback on one of its hooks throws after a request is stored and mailed. The
 * request is still answered as sent, so the failure must not vanish: it is written to the PHP error log when
 * WP_DEBUG is on, and WordPress's list of running hooks is put back to what it was before the hook.
 *
 * Used as:
 *     $depth = Hook_Guard::depth();
 *     try {
 *         do_action( 'the_hook', $id );
 *     } catch ( \Throwable $e ) {
 *         Hook_Guard::failed( 'the_hook', $id, $e, $depth );
 *     }
 */
final class Hook_Guard {

	/** The longest exception message that is written to the log, in characters. */
	public const MAX_MESSAGE = 500;

	/**
	 * For tests: true or false in place of the WP_DEBUG constant. Null means the constant decides.
	 *
	 * @var bool|null
	 */
	public static $debug = null;

	/**
	 * For tests: a callable( string $line ) that takes the line in place of the PHP error log.
	 *
	 * @var callable|null
	 */
	public static $log = null;

	/** How many hooks are running now: the length of WordPress's list, taken before a guarded hook. */
	public static function depth(): int {
		return is_array( $GLOBALS['wp_current_filter'] ?? null ) ? count( $GLOBALS['wp_current_filter'] ) : 0;
	}

	/** True when failures are written to the log: WP_DEBUG is on. */
	public static function debugging(): bool {
		return is_bool( self::$debug ) ? self::$debug : ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	/**
	 * Call it from the catch block around a hook of the plugin.
	 *
	 * WordPress takes a hook off its list of running hooks after the callbacks return. A callback that throws skips
	 * that step, and current_filter() and doing_action() would be wrong for the rest of the request, so the list is
	 * cut back to the length it had before the hook.
	 *
	 * @param string     $hook     The name of the action or filter.
	 * @param int        $quote_id Post ID of the request the hook ran for.
	 * @param \Throwable $e        What the callback threw.
	 * @param int        $depth    The value of depth() before the hook.
	 */
	public static function failed( string $hook, int $quote_id, \Throwable $e, int $depth ): void {
		if ( is_array( $GLOBALS['wp_current_filter'] ?? null ) && count( $GLOBALS['wp_current_filter'] ) > $depth ) {
			array_splice( $GLOBALS['wp_current_filter'], max( 0, $depth ) );
		}
		if ( ! self::debugging() ) {
			return;
		}
		$line = self::line( $hook, $quote_id, $e );
		if ( is_callable( self::$log ) ) {
			call_user_func( self::$log, $line );
			return;
		}
		// Never trigger_error(): with errors displayed it would print into the JSON answer of the request.
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only with WP_DEBUG on: the one place a developer learns that a callback failed.
	}

	/**
	 * The line for the log: the hook, the request, the class and the message of what was thrown, and where.
	 *
	 * @param string     $hook     The name of the action or filter.
	 * @param int        $quote_id Post ID of the request.
	 * @param \Throwable $e        What the callback threw.
	 */
	public static function line( string $hook, int $quote_id, \Throwable $e ): string {
		$one_line = static fn( string $text ): string => trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) );
		return sprintf(
			'Quote Requests: a callback on %1$s failed for quote %2$d. %3$s: %4$s (%5$s:%6$d)',
			$one_line( $hook ),
			$quote_id,
			get_class( $e ),
			substr( $one_line( $e->getMessage() ), 0, self::MAX_MESSAGE ),
			$one_line( $e->getFile() ),
			$e->getLine()
		);
	}
}
