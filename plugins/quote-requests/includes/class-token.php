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

final class Token {

	public static function issue( int $now, string $secret ): string {
		return $now . '.' . hash_hmac( 'sha256', (string) $now, $secret );
	}

	public static function check( string $token, int $now, string $secret, int $min_age = 3, int $max_age = 7200 ): string {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return 'invalid';
		}
		$issued = (int) $parts[0];
		if ( ! hash_equals( hash_hmac( 'sha256', (string) $issued, $secret ), $parts[1] ) || $issued > $now ) {
			return 'invalid';
		}
		$age = $now - $issued;
		if ( $age < $min_age ) {
			return 'too_fast';
		}
		return $age > $max_age ? 'expired' : 'ok';
	}

	public static function secret(): string {
		return wp_salt( 'nonce' ) . '|quote-requests';
	}
}
