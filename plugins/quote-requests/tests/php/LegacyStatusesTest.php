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
use Quote_Requests\Legacy_Statuses;

final class LegacyStatusesTest extends TestCase {
	protected function setUp(): void {
		qr_test_reset_options( array() );
		$GLOBALS['qr_test_filters'] = array();
	}

	protected function tearDown(): void {
		qr_test_reset_options( array() );
		$GLOBALS['qr_test_filters'] = array();
	}

	public function test_the_three_statuses(): void {
		$this->assertSame( array( 'wc-quote-requested', 'wc-quote-approved', 'wc-quote-rejected' ), Legacy_Statuses::STATUSES );
	}

	public function test_the_stored_answer_decides(): void {
		qr_test_set_option( Legacy_Statuses::OPTION, 'no' );
		$this->assertFalse( Legacy_Statuses::enabled(), 'the site has no order with one of the statuses' );
		qr_test_set_option( Legacy_Statuses::OPTION, 'yes' );
		$this->assertTrue( Legacy_Statuses::enabled(), 'the site has such orders' );
	}

	public function test_a_site_that_was_not_checked_yet_keeps_the_statuses(): void {
		$this->assertTrue( Legacy_Statuses::enabled(), 'no stored answer: the orders of an existing site stay visible' );
		qr_test_set_option( Legacy_Statuses::OPTION, '' );
		$this->assertTrue( Legacy_Statuses::enabled(), 'an empty answer is no answer' );
	}

	public function test_the_filter_has_the_last_word_and_gets_the_stored_answer(): void {
		$seen = array();
		qr_test_set_option( Legacy_Statuses::OPTION, 'no' );
		add_filter(
			'quote_requests_legacy_order_statuses',
			static function ( $register ) use ( &$seen ) {
				$seen[] = $register;
				return true;
			}
		);
		$this->assertTrue( Legacy_Statuses::enabled() );
		$GLOBALS['qr_test_filters'] = array();
		qr_test_set_option( Legacy_Statuses::OPTION, 'yes' );
		add_filter(
			'quote_requests_legacy_order_statuses',
			static function ( $register ) use ( &$seen ) {
				$seen[] = $register;
				return false;
			}
		);
		$this->assertFalse( Legacy_Statuses::enabled() );
		$this->assertSame( array( false, true ), $seen );
	}
}
