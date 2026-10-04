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
use Quote_Requests\Rate_Limit;

final class RateLimitTest extends TestCase {

	/** In-memory counter store with the same contract as the database one. */
	private function memory(): object {
		return new class() {
			public array $rows = array();
			public function incr( string $key ): int {
				$this->rows[ $key ] = ( $this->rows[ $key ] ?? 0 ) + 1;
				return $this->rows[ $key ];
			}
			public function get( string $key ): int {
				return $this->rows[ $key ] ?? 0;
			}
		};
	}

	public function test_take_grants_up_to_the_limit_then_refuses(): void {
		Rate_Limit::$store = $this->memory();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( Rate_Limit::take( '1.2.3.4', 5, 'k', 1000 ) );
		}
		$this->assertFalse( Rate_Limit::take( '1.2.3.4', 5, 'k', 1000 ) );
		$this->assertTrue( Rate_Limit::take( '5.6.7.8', 5, 'k', 1000 ), 'other addresses are not affected' );
		$this->assertFalse( Rate_Limit::allow( '1.2.3.4', 5, 'k', 1000 ) );
		$this->assertTrue( Rate_Limit::allow( '5.6.7.8', 5, 'k', 1000 ) );
	}

	public function test_a_new_hour_starts_a_new_count(): void {
		Rate_Limit::$store = $this->memory();
		for ( $i = 0; $i < 6; $i++ ) {
			Rate_Limit::take( '1.2.3.4', 5, 'k', 1000 );
		}
		$this->assertTrue( Rate_Limit::take( '1.2.3.4', 5, 'k', 1000 + 3600 ) );
	}

	public function test_key_does_not_contain_the_ip(): void {
		$store             = $this->memory();
		Rate_Limit::$store = $store;
		Rate_Limit::take( '1.2.3.4', 5, 'k', 1000 );
		$key = array_key_first( $store->rows );
		$this->assertStringStartsWith( 'quote_requests_rl_', $key );
		$this->assertStringNotContainsString( '1.2.3.4', $key );
	}
}
