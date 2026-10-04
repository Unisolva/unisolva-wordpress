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

final class Fallback {

	public const COOKIE = 'quote_requests_list';

	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'on_quote_page' ), 5 );
		add_action( 'admin_post_quote_requests_submit', array( self::class, 'handle_post' ) );
		add_action( 'admin_post_nopriv_quote_requests_submit', array( self::class, 'handle_post' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
	}

	public static function is_quote_page(): bool {
		$id = (int) Settings::get( 'quote_page' );
		return $id > 0 && is_page( $id );
	}

	public static function quote_url( array $args = array() ): string {
		$id = (int) Settings::get( 'quote_page' );
		if ( $id <= 0 ) {
			return '';
		}
		return add_query_arg( $args, get_permalink( $id ) );
	}

	public static function cookie_items(): array {
		$raw  = isset( $_COOKIE[ self::COOKIE ] ) ? wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$data = json_decode( (string) $raw, true );
		$out  = array();
		foreach ( (array) ( $data['items'] ?? array() ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id > 0 && count( $out ) < Validator::MAX_LINES ) {
				$out[] = array(
					'id'  => $id,
					'qty' => max( 1, min( Validator::MAX_QTY, (int) ( $row['qty'] ?? 1 ) ) ),
				);
			}
		}
		return $out;
	}

	public static function set_cookie_items( array $items ): void {
		$value = $items ? wp_json_encode(
			array(
				'v'     => 1,
				'items' => array_values( $items ),
			)
		) : '';
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $items ? time() + 30 * DAY_IN_SECONDS : time() - HOUR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => false, // The script reads it once to merge into localStorage, then deletes it.
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $value;
	}

	public static function on_quote_page(): void {
		if ( ! self::is_quote_page() ) {
			return;
		}
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'quote-requests page' );
		$add    = isset( $_GET['add'] ) ? absint( $_GET['add'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$remove = isset( $_GET['remove'] ) ? absint( $_GET['remove'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $add && ! $remove ) {
			return;
		}
		$items = self::cookie_items();
		if ( $add && Products::snapshot( $add ) ) {
			$found = false;
			foreach ( $items as &$it ) {
				if ( $it['id'] === $add ) {
					$it['qty'] = min( Validator::MAX_QTY, $it['qty'] + 1 );
					$found     = true;
				}
			}
			unset( $it );
			if ( ! $found && count( $items ) < Validator::MAX_LINES ) {
				$items[] = array(
					'id'  => $add,
					'qty' => 1,
				);
			}
		}
		if ( $remove ) {
			$items = array_values( array_filter( $items, static fn( $it ) => $it['id'] !== $remove ) );
		}
		self::set_cookie_items( $items );
		wp_safe_redirect( self::quote_url(), 303 );
		exit;
	}

	public static function robots( array $robots ): array {
		if ( self::is_quote_page() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	/** Error codes shown from the URL alone, with nothing stored (bots produce these in volume). */
	public const STATIC_CODES = array( 'token_invalid', 'token_expired', 'token_too_fast', 'rejected', 'rate_limited' );

	public static function static_message( string $code ): string {
		if ( 'rate_limited' === $code ) {
			return __( 'Too many requests from this connection. Please try again in an hour.', 'quote-requests' );
		}
		if ( 'rejected' === $code ) {
			return __( 'Your request could not be sent.', 'quote-requests' );
		}
		return __( 'The form had been open too long or was sent too quickly. Please fill it in and send it again.', 'quote-requests' );
	}

	/**
	 * Names of the transients that carry the outcome of a no-script post back to the page, by kind:
	 * the success panel, the errors with the typed values, and the values kept over "Update regions".
	 * The key of state_key() follows the name. The page reads a row only through the key in the redirect address.
	 */
	public const STATE = array(
		'done' => 'quote_requests_done_',
		'err'  => 'quote_requests_err_',
		'keep' => 'quote_requests_keep_',
	);

	/**
	 * The key of the rows one form and one visitor own, the same for every kind: a keyed hash of the form's token
	 * and the requester's address (found the way the rate limiter finds it). So a post that is sent again
	 * overwrites its one row per kind instead of adding rows, and nobody else can name the row.
	 * 20 lowercase hex characters: the key is read back through sanitize_key().
	 *
	 * @param array $posted The unslashed post.
	 * @param array $server The $_SERVER values.
	 */
	public static function state_key( array $posted, array $server ): string {
		$token = is_scalar( $posted['token'] ?? null ) ? (string) $posted['token'] : '';
		$ip    = Client_Info::ip( $server, (bool) Settings::get( 'trust_proxy' ) );
		return substr( hash_hmac( 'sha256', $token . '|' . $ip, Token::secret() ), 0, 20 );
	}

	/**
	 * Where a no-script post ends: back on the page, with the outcome stored under state_key() for 10 minutes.
	 * A post that was refused before the form was read (STATIC_CODES) stores nothing.
	 *
	 * @param array $result What Submission::process() returned.
	 * @param array $posted The unslashed post.
	 * @param array $server The $_SERVER values.
	 */
	public static function result_redirect( array $result, array $posted, array $server ): string {
		$code = (string) ( $result['body']['code'] ?? '' );
		if ( empty( $result['body']['ok'] ) && in_array( $code, self::STATIC_CODES, true ) ) {
			return self::quote_url( array( 'qr_err' => $code ) ) . '#quote-requests-form';
		}
		$key = self::state_key( $posted, $server );
		if ( ! empty( $result['body']['ok'] ) ) {
			set_transient( self::STATE['done'] . $key, $result['body'], 10 * MINUTE_IN_SECONDS );
			return self::quote_url( array( 'qr_done' => $key ) ) . '#quote-requests';
		}
		set_transient(
			self::STATE['err'] . $key,
			array(
				'body'   => $result['body'],
				'values' => self::kept_values( $posted ),
			),
			10 * MINUTE_IN_SECONDS
		);
		return self::quote_url( array( 'qr_err' => $key ) ) . '#quote-requests-form';
	}

	/**
	 * The longest value a visitor can keep for a field, the same as the limit the form puts on the control.
	 *
	 * @param array $def Field definition.
	 */
	public static function value_limit( array $def ): int {
		if ( ! empty( $def['system'] ) ) {
			return 'region' === $def['key'] ? Validator::LIMIT_REGION : ( Validator::LIMITS[ $def['key'] ] ?? Validator::LIMIT_LINE );
		}
		switch ( $def['type'] ) {
			case 'textarea':
				return Validator::LIMITS['message'];
			case 'email':
				return Validator::LIMITS['email'];
			case 'tel':
				return Validator::LIMITS['phone'];
			case 'number':
				return Validator::LIMIT_NUMBER;
			case 'checkbox':
				return 1;
		}
		return Validator::LIMIT_LINE;
	}

	/**
	 * What a re-displayed form keeps of a post: every defined field (also those not shown) and the
	 * chosen country, each cut to its limit, and the consent tick as a boolean. Arrays and everything else are dropped.
	 *
	 * @param array $posted The unslashed post.
	 */
	public static function kept_values( array $posted ): array {
		$values = array();
		foreach ( Fields::all() as $def ) {
			$key = $def['key'];
			if ( isset( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ) {
				$values[ $key ] = mb_substr( (string) $posted[ $key ], 0, self::value_limit( $def ) );
			}
		}
		if ( isset( $posted['consent'] ) && is_scalar( $posted['consent'] ) ) {
			$values['consent'] = in_array( $posted['consent'], array( '1', 'yes', 'on', 'true', true, 1 ), true ); // The tick, as the validator reads it.
		}
		if ( isset( $posted['region_country'] ) && is_scalar( $posted['region_country'] ) ) {
			$values['region_country'] = substr( strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) $posted['region_country'] ) ), 0, 2 );
		}
		return $values;
	}

	/**
	 * Where the "Update regions" button of the no-script form sends the visitor: back to the page with
	 * what was typed, so the regions of the chosen country show. Nothing is submitted or counted.
	 *
	 * Only a token the page issued, inside the window the submit path allows, is accepted. The stored
	 * values are keyed by state_key(), so a replay overwrites one row instead of adding rows.
	 *
	 * @param array $posted The unslashed post.
	 * @param array $server The $_SERVER values.
	 */
	public static function refresh_redirect( array $posted, array $server ): string {
		if ( '' === self::quote_url() ) {
			return ''; // Nowhere to go back to: store nothing.
		}
		$token  = is_scalar( $posted['token'] ?? null ) ? (string) $posted['token'] : '';
		$status = Token::check( $token, time(), Token::secret(), 0 ); // No minimum age; the normal maximum.
		if ( 'ok' !== $status ) {
			return self::quote_url( array( 'qr_err' => 'token_' . $status ) ) . '#quote-requests-form';
		}
		$values = self::kept_values( $posted );
		unset( $values['region'] ); // The country just changed: a region picked for the old one does not apply.
		$key = self::state_key( $posted, $server );
		set_transient( self::STATE['keep'] . $key, array( 'values' => $values ), 10 * MINUTE_IN_SECONDS );
		return self::quote_url( array( 'qr_keep' => $key ) ) . '#qr-region_country';
	}

	/**
	 * The page a post to the form ends on.
	 *
	 * @param array $posted The unslashed post.
	 * @param array $server The $_SERVER values.
	 */
	public static function post_redirect( array $posted, array $server ): string {
		if ( ! empty( $posted['qr_refresh'] ) ) {
			return self::refresh_redirect( $posted, $server );
		}
		$result = Submission::process( $posted, $server );
		if ( ! empty( $result['body']['ok'] ) ) {
			self::set_cookie_items( array() );
		}
		return self::result_redirect( $result, $posted, $server );
	}

	public static function handle_post(): void {
		$posted = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- Verified by the signed token in Submission.
		$posted = is_array( $posted ) ? $posted : array();
		$url    = self::post_redirect( $posted, $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		wp_safe_redirect( $url ? $url : home_url( '/' ), 303 );
		exit;
	}
}
