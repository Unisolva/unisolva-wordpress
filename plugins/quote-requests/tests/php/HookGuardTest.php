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

use PHPUnit\Framework\TestCase;
use Quote_Requests\Hook_Guard;

/**
 * What the plugin does when a callback on one of its hooks throws after a request is stored: the failure is
 * written to the error log when WP_DEBUG is on, and WordPress's list of running hooks is put back.
 */
final class HookGuardTest extends TestCase {

	/** The lines handed to the replaced log. */
	private array $lines = array();

	protected function setUp(): void {
		$GLOBALS['wp_current_filter'] = array();
		$this->lines                  = array();
		Hook_Guard::$debug            = true;
		Hook_Guard::$log              = function ( string $line ): void {
			$this->lines[] = $line;
		};
	}

	protected function tearDown(): void {
		$GLOBALS['wp_current_filter'] = array();
		Hook_Guard::$debug            = null;
		Hook_Guard::$log              = null;
	}

	public function test_the_line_names_the_hook_the_quote_the_class_and_the_message(): void {
		$line = Hook_Guard::line( 'quote_requests_created', 77, new RuntimeException( 'The CRM said no' ) );
		$this->assertStringStartsWith( 'Quote Requests: a callback on quote_requests_created failed for quote 77. RuntimeException: The CRM said no (', $line );
		$this->assertStringContainsString( 'HookGuardTest.php:', $line, 'where it was thrown' );
		$this->assertStringContainsString( 'TypeError: wrong type', Hook_Guard::line( 'quote_requests_thanks_links', 5, new TypeError( 'wrong type' ) ) );
	}

	public function test_the_line_is_one_line_of_bounded_length(): void {
		$line = Hook_Guard::line( 'quote_requests_created', 1, new RuntimeException( "first\r\nsecond\tthird\0" . str_repeat( 'x', 5000 ) ) );
		$this->assertSame( 0, preg_match( '/[\x00-\x1F\x7F]/', $line ), 'no line break or other control character' );
		$this->assertStringContainsString( 'first second third', $line );
		$this->assertLessThan( 1200, strlen( $line ) );
	}

	public function test_depth_is_the_number_of_running_hooks(): void {
		$this->assertSame( 0, Hook_Guard::depth() );
		$GLOBALS['wp_current_filter'] = array( 'init', 'my_hook' );
		$this->assertSame( 2, Hook_Guard::depth() );
		$GLOBALS['wp_current_filter'] = null; // Never a notice, whatever the global holds.
		$this->assertSame( 0, Hook_Guard::depth() );
	}

	public function test_a_failure_puts_the_list_of_running_hooks_back(): void {
		$GLOBALS['wp_current_filter'] = array( 'init', 'my_hook' );
		$depth                        = Hook_Guard::depth();
		// What WordPress leaves behind when a callback throws: the hook, and any hook that was running inside it.
		$GLOBALS['wp_current_filter'][] = 'quote_requests_created';
		$GLOBALS['wp_current_filter'][] = 'inner_hook';
		Hook_Guard::failed( 'quote_requests_created', 9, new RuntimeException( 'boom' ), $depth );
		$this->assertSame( array( 'init', 'my_hook' ), $GLOBALS['wp_current_filter'] );
	}

	public function test_a_list_that_is_not_longer_than_before_is_left_alone(): void {
		$GLOBALS['wp_current_filter'] = array( 'init' );
		Hook_Guard::failed( 'quote_requests_created', 9, new RuntimeException( 'boom' ), 3 );
		$this->assertSame( array( 'init' ), $GLOBALS['wp_current_filter'] );
		$GLOBALS['wp_current_filter'] = null;
		Hook_Guard::failed( 'quote_requests_created', 9, new RuntimeException( 'boom' ), 0 );
		$this->assertNull( $GLOBALS['wp_current_filter'] );
	}

	public function test_with_debugging_on_a_failure_is_one_line_in_the_log(): void {
		Hook_Guard::failed( 'quote_requests_created', 9, new LogicException( 'boom' ), 0 );
		$this->assertCount( 1, $this->lines );
		$this->assertStringContainsString( 'quote_requests_created failed for quote 9. LogicException: boom', $this->lines[0] );
	}

	public function test_with_debugging_off_nothing_is_logged_and_the_list_is_still_put_back(): void {
		Hook_Guard::$debug            = false;
		$GLOBALS['wp_current_filter'] = array( 'quote_requests_created' );
		Hook_Guard::failed( 'quote_requests_created', 9, new RuntimeException( 'boom' ), 0 );
		$this->assertSame( array(), $this->lines );
		$this->assertSame( array(), $GLOBALS['wp_current_filter'] );
	}

	public function test_without_a_replacement_debugging_follows_wp_debug(): void {
		Hook_Guard::$debug = null;
		$this->assertSame( defined( 'WP_DEBUG' ) && WP_DEBUG, Hook_Guard::debugging() );
		Hook_Guard::$debug = true;
		$this->assertTrue( Hook_Guard::debugging() );
	}

	public function test_without_a_replacement_the_line_goes_to_the_php_error_log(): void {
		Hook_Guard::$log = null;
		$file            = (string) tempnam( sys_get_temp_dir(), 'qr-unit-' );
		$before          = ini_get( 'error_log' );
		try {
			ini_set( 'error_log', $file );
			Hook_Guard::failed( 'quote_requests_created', 12, new RuntimeException( 'boom' ), 0 );
			$written = (string) file_get_contents( $file );
		} finally {
			ini_set( 'error_log', (string) $before );
			unlink( $file );
		}
		$this->assertSame( 1, substr_count( $written, "\n" ), 'one line' );
		$this->assertStringContainsString( 'Quote Requests: a callback on quote_requests_created failed for quote 12. RuntimeException: boom', $written );
	}
}
