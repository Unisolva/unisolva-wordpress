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

final class Submission {

	private static function fail( int $status, string $code, string $message, array $extra = array() ): array {
		return array(
			'status' => $status,
			'body'   => array_merge(
				array(
					'ok'      => false,
					'code'    => $code,
					'message' => $message,
				),
				$extra
			),
		);
	}

	private static function fallback_sentence(): string {
		$c = trim( (string) Settings::get( 'fallback_contact' ) );
		return '' !== $c ? ' ' . $c : '';
	}

	public static function process( array $params, array $server ): array {
		$secret = Token::secret();
		$token  = Token::check( (string) ( $params['token'] ?? '' ), time(), $secret );
		if ( 'ok' !== $token ) {
			return self::fail( 400, 'token_' . $token, __( 'The form needs a moment to get ready. Please try again.', 'quote-requests' ) );
		}
		if ( '' !== trim( (string) ( $params['website'] ?? '' ) ) ) {
			return self::fail( 400, 'rejected', __( 'Your request could not be sent.', 'quote-requests' ) );
		}
		$settings = Settings::all();
		$ip       = Client_Info::ip( $server, $settings['trust_proxy'] );
		if ( ! Rate_Limit::allow( $ip, $settings['rate_limit'], $secret ) ) {
			return self::fail( 429, 'rate_limited', __( 'Too many requests from this connection. Please try again in an hour.', 'quote-requests' ) . self::fallback_sentence() );
		}

		$raw_items = is_array( $params['items'] ?? null ) ? $params['items'] : array();
		$items     = Validator::items( $raw_items, array( Products::class, 'snapshot' ) );
		$contact   = Validator::contact(
			$params,
			array(
				'fields'          => Fields::visible(),
				'contact_rule'    => $settings['contact_rule'],
				'require_consent' => $settings['require_consent'],
				'has_items'       => (bool) $items['items'],
				'region'          => static function ( string $country, string $value ): ?array {
					// The posted country counts only when the visitor may choose one; otherwise it is always the site's country.
					return Regions::resolve( 'choose' === Regions::mode() ? $country : Regions::default_country(), $value );
				},
			)
		);
		if ( $contact['errors'] ) {
			return self::fail(
				400,
				'invalid',
				__( 'Please check the highlighted fields.', 'quote-requests' ),
				array(
					'errors'  => $contact['errors'],
					'dropped' => $items['dropped'],
				)
			);
		}

		$data    = $contact['data'];
		$consent = array(
			'given'       => $settings['require_consent'] && $data['consent'], // Never given on a form that shows no consent box.
			'time'        => '',
			'text'        => '',
			'privacy_url' => '',
		);
		if ( $consent['given'] ) { // A record never claims a consent that was not given.
			$consent['time']        = gmdate( 'c' );
			$consent['text']        = Settings::consent_text();
			$consent['privacy_url'] = $settings['privacy_page'] ? (string) get_permalink( $settings['privacy_page'] ) : '';
		}
		$client = $settings['store_client'] ? Client_Info::collect( $server, $params, $settings['trust_proxy'], home_url() ) : array();

		// Reserve the slot in one atomic step, so parallel posts cannot all pass the early check above.
		if ( ! Rate_Limit::take( $ip, $settings['rate_limit'], $secret ) ) {
			return self::fail( 429, 'rate_limited', __( 'Too many requests from this connection. Please try again in an hour.', 'quote-requests' ) . self::fallback_sentence() );
		}

		$id = Store::create( $data, $items['items'], $consent, $client );
		if ( ! $id ) {
			return self::fail( 500, 'store_failed', __( 'Your request could not be saved.', 'quote-requests' ) . self::fallback_sentence() );
		}
		Mailer::send( $id );
		do_action( 'quote_requests_created', $id );

		return array(
			'status' => 201,
			'body'   => array(
				'ok'      => true,
				'ref'     => Store::get( $id )['ref'],
				'name'    => $data['name'],
				'thanks'  => str_replace( '%name%', $data['name'], $settings['thanks_text'] ),
				'items'   => array_map(
					static fn( $i ) => array(
						'name' => $i['name'],
						'qty'  => $i['qty'],
					),
					$items['items']
				),
				'dropped' => $items['dropped'],
			),
		);
	}
}
