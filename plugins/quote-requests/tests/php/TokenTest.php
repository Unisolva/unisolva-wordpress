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
use Quote_Requests\Token;

final class TokenTest extends TestCase {
	public function test_round_trip_and_ages(): void {
		$t = Token::issue( 1000, 's3cret' );
		$this->assertSame( 'too_fast', Token::check( $t, 1001, 's3cret' ) );
		$this->assertSame( 'ok', Token::check( $t, 1003, 's3cret' ) );
		$this->assertSame( 'ok', Token::check( $t, 1000 + 7200, 's3cret' ) );
		$this->assertSame( 'expired', Token::check( $t, 1000 + 7201, 's3cret' ) );
	}

	public function test_tampering_is_invalid(): void {
		$t = Token::issue( 1000, 's3cret' );
		$this->assertSame( 'invalid', Token::check( $t, 1010, 'other' ) );
		$this->assertSame( 'invalid', Token::check( '999.' . explode( '.', $t )[1], 1010, 's3cret' ) );
		$this->assertSame( 'invalid', Token::check( '', 1010, 's3cret' ) );
		$this->assertSame( 'invalid', Token::check( 'abc', 1010, 's3cret' ) );
	}

	public function test_future_timestamp_is_invalid(): void {
		$this->assertSame( 'invalid', Token::check( Token::issue( 5000, 's' ), 1000, 's' ) );
	}
}
