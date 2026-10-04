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

final class Rest {

	public const NS = 'quote-requests/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/quotes',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( \WP_REST_Request $req ) {
					$params = $req->get_json_params();
					$params = $params ? $params : $req->get_body_params();
					$result = Submission::process( is_array( $params ) ? $params : array(), $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					return new \WP_REST_Response( $result['body'], $result['status'] );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/token',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					do_action( 'litespeed_control_set_nocache', 'quote-requests token' );
					$res = new \WP_REST_Response( array( 'token' => Token::issue( time(), Token::secret() ) ), 200 );
					$res->header( 'Cache-Control', 'no-store, max-age=0' );
					return $res;
				},
			)
		);
		register_rest_route(
			self::NS,
			'/products',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'ids' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => static function ( \WP_REST_Request $req ) {
					$ids = array_slice( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $req->get_param( 'ids' ) ) ) ) ), 0, Validator::MAX_LINES );
					$out = array();
					foreach ( $ids as $id ) {
						$card = Products::card( $id );
						if ( $card ) {
							$out[] = $card;
						}
					}
					return new \WP_REST_Response( $out, 200 );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/regions',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'country' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => static function ( $value ): string {
							return strtoupper( preg_replace( '/[^A-Za-z]/', '', is_scalar( $value ) ? (string) $value : '' ) );
						},
					),
				),
				'callback'            => static function ( \WP_REST_Request $req ) {
					$country = (string) $req->get_param( 'country' );
					if ( '' === $country ) {
						$country = Regions::default_country();
					}
					if ( '' === $country || ! array_key_exists( $country, Regions::countries() ) ) {
						return new \WP_Error( 'quote_requests_country', __( 'This country is not available.', 'quote-requests' ), array( 'status' => 400 ) );
					}
					$states = array();
					foreach ( Regions::states( $country ) as $code => $name ) {
						$states[] = array(
							'code' => (string) $code,
							'name' => $name,
						);
					}
					$res = new \WP_REST_Response(
						array(
							'country' => $country,
							'states'  => $states,
						),
						200
					);
					$res->header( 'Cache-Control', 'public, max-age=86400' );
					return $res;
				},
			)
		);
	}
}
