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

	/** The source label of the built-in form, and what a record without a label reads as. */
	public const SOURCE_FORM = 'form';

	/** The source label of a quote_requests_submit() call that names none. */
	public const SOURCE_OTHER = 'other';

	/** The longest source label, in characters. */
	public const MAX_SOURCE = 40;

	/** The longest consent text a caller of quote_requests_submit() can hand over, in characters. */
	public const MAX_CONSENT_TEXT = 1000;

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

	private static function rate_limited(): array {
		return self::fail( 429, 'rate_limited', __( 'Too many requests from this connection. Please try again in an hour.', 'quote-requests' ) . self::fallback_sentence() );
	}

	/**
	 * A post of the built-in form, from the REST route or from the page without the script: the checks that belong
	 * to that form, then create().
	 *
	 * @param array $params The unslashed post.
	 * @param array $server The $_SERVER values.
	 */
	public static function process( array $params, array $server ): array {
		return self::guard( $params ) ?? self::create( $params, $server );
	}

	/**
	 * The spam checks of the built-in form: its signed token and its honeypot. Null when the post passes them.
	 * Another form that calls quote_requests_submit() has neither and brings its own protection.
	 *
	 * @param array $params The unslashed post.
	 */
	private static function guard( array $params ): ?array {
		$token = Token::check( (string) ( $params['token'] ?? '' ), time(), Token::secret() );
		if ( 'ok' !== $token ) {
			return self::fail( 400, 'token_' . $token, __( 'The form needs a moment to get ready. Please try again.', 'quote-requests' ) );
		}
		if ( '' !== trim( (string) ( $params['website'] ?? '' ) ) ) {
			return self::fail( 400, 'rejected', __( 'Your request could not be sent.', 'quote-requests' ) );
		}
		return null;
	}

	/**
	 * Everything that is decided before a request is stored: the address, the early look at the rate limit, the
	 * product checks and the validation. Nothing is stored or counted here.
	 *
	 * @param array $params Values by field key, plus consent, region_country and items.
	 * @param array $server The $_SERVER values.
	 * @param array $args   ip: the address to use instead of the one of the request.
	 * @return array refused (the answer of a refused request, or null), settings, secret, ip, items (items and dropped), data.
	 */
	private static function prepare( array $params, array $server, array $args ): array {
		$settings = Settings::all();
		$secret   = Token::secret();
		$given    = $args['ip'] ?? null;
		$ip       = is_string( $given ) && false !== filter_var( $given, FILTER_VALIDATE_IP ) ? $given : Client_Info::ip( $server, $settings['trust_proxy'] );
		$ready    = array(
			'refused'  => null,
			'settings' => $settings,
			'secret'   => $secret,
			'ip'       => $ip,
			'items'    => array(),
			'data'     => array(),
		);
		if ( ! Rate_Limit::allow( $ip, $settings['rate_limit'], $secret ) ) {
			$ready['refused'] = self::rate_limited();
			return $ready;
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
			$ready['refused'] = self::fail(
				400,
				'invalid',
				__( 'Please check the highlighted fields.', 'quote-requests' ),
				array(
					'errors'  => $contact['errors'],
					'dropped' => $items['dropped'],
				)
			);
			return $ready;
		}
		$ready['items'] = $items;
		$ready['data']  = $contact['data'];
		return $ready;
	}

	/**
	 * The checks of create() without storing, mailing or counting anything.
	 *
	 * @param array $params Values by field key, plus consent, region_country and items.
	 * @param array $server The $_SERVER values.
	 * @param array $args   As for create().
	 * @return array|null Null when the request would be stored now, otherwise the answer of the refusal.
	 */
	public static function check( array $params, array $server, array $args = array() ): ?array {
		return self::prepare( $params, $server, $args )['refused'];
	}

	/**
	 * The pipeline every request goes through, whatever form sent it: rate limit, validation, product checks,
	 * the record, the emails, the quote_requests_created action and the answer.
	 *
	 * @param array $params Values by field key, plus consent, region_country, items and the visit details of the built-in form.
	 * @param array $server The $_SERVER values.
	 * @param array $args   source: the label stored on the record (default "form"). ip: the address the rate limit and
	 *                      the stored visitor details use instead of the one of the request. page, consent_text and
	 *                      privacy_url: as args() returns them. The built-in form passes none of these.
	 * @return array status, body and, for a stored request, id (the post ID, which the built-in form does not publish).
	 */
	public static function create( array $params, array $server, array $args = array() ): array {
		$ready = self::prepare( $params, $server, $args );
		if ( null !== $ready['refused'] ) {
			return $ready['refused'];
		}
		$settings = $ready['settings'];
		$secret   = $ready['secret'];
		$ip       = $ready['ip'];
		$items    = $ready['items'];

		$data    = $ready['data'];
		$consent = self::consent( $settings['require_consent'] && $data['consent'], $args ); // Never given on a form that shows no consent box.
		$client  = array();
		if ( $settings['store_client'] ) {
			$client       = Client_Info::collect( $server, $params, $settings['trust_proxy'], home_url() );
			$client['ip'] = $ip; // The address the rate limit counted.
			if ( is_string( $args['page'] ?? null ) ) {
				$client['page'] = $args['page']; // Another form: the page its code names (see args()). The built-in form posts its own.
			}
		}

		// Reserve the slot in one atomic step, so parallel posts cannot all pass the early check above.
		if ( ! Rate_Limit::take( $ip, $settings['rate_limit'], $secret ) ) {
			return self::rate_limited();
		}

		$id = Store::create( $data, $items['items'], $consent, $client, self::source_label( $args['source'] ?? '' ) );
		if ( ! $id ) {
			return self::fail( 500, 'store_failed', __( 'Your request could not be saved.', 'quote-requests' ) . self::fallback_sentence() );
		}
		Mailer::send( $id );
		self::created( $id );

		// The page without the script stores this answer and draws the same panel from it (Quote_Page::done_panel()).
		$quote = Store::get( $id ) ?? array();
		return array(
			'status' => 201,
			'id'     => $id,
			'body'   => array(
				'ok'        => true,
				'ref'       => $quote['ref'] ?? '',
				'name'      => $data['name'],
				'thanks'    => str_replace( '%name%', $data['name'], $settings['thanks_text'] ),
				'items'     => array_map(
					static fn( $i ) => array(
						'name' => $i['name'],
						'qty'  => $i['qty'],
					),
					$items['items']
				),
				'dropped'   => $items['dropped'],
				'links'     => Thanks::links( $quote ),
				// Handed to the mailer, which is not the same as delivered. Skipped (no address, or confirmation off) and failed are both false.
				'copy_sent' => 'handed_over' === ( $quote['mail']['customer']['state'] ?? '' ),
			),
		);
	}

	/**
	 * The consent a record stores. A record never claims a consent that was not given, and it names the text the
	 * visitor agreed to: the text and the privacy address the calling form hands over, otherwise those of the settings,
	 * which is what the built-in form shows.
	 *
	 * @param bool  $given True when the settings ask for the consent and the visitor ticked it.
	 * @param array $args  consent_text and privacy_url, as args() returns them (an empty string: not given).
	 * @return array given, time, text and privacy_url.
	 */
	public static function consent( bool $given, array $args = array() ): array {
		$consent = array(
			'given'       => $given,
			'time'        => '',
			'text'        => '',
			'privacy_url' => '',
		);
		if ( ! $given ) {
			return $consent;
		}
		$text                   = is_string( $args['consent_text'] ?? null ) ? $args['consent_text'] : '';
		$url                    = is_string( $args['privacy_url'] ?? null ) ? $args['privacy_url'] : '';
		$page                   = (int) Settings::get( 'privacy_page' );
		$consent['time']        = gmdate( 'c' );
		$consent['text']        = '' !== $text ? $text : Settings::consent_text();
		$consent['privacy_url'] = '' !== $url ? $url : ( $page ? (string) get_permalink( $page ) : '' );
		return $consent;
	}

	/**
	 * The arguments of a quote_requests_submit() call, cleaned. What is not given, or not usable, is an empty string
	 * (null for the address), and create() then does what it does for the built-in form.
	 *
	 * @param array $args As given to quote_requests_submit().
	 * @return array source (a label), ip (as given), page (the path of an address on this site), consent_text (plain
	 *               text, at most MAX_CONSENT_TEXT characters) and privacy_url (an http or https address).
	 */
	public static function args( array $args ): array {
		$text = $args['consent_text'] ?? '';
		$text = is_string( $text ) ? trim( mb_substr( trim( sanitize_textarea_field( $text ) ), 0, self::MAX_CONSENT_TEXT ) ) : '';
		$url  = $args['privacy_url'] ?? '';
		$url  = is_string( $url ) && 1 === preg_match( '#^https?://[^/\\\\\s]#i', trim( $url ) ) ? (string) esc_url_raw( trim( $url ), array( 'http', 'https' ) ) : '';
		return array(
			'source'       => self::source_label( $args['source'] ?? '', self::SOURCE_OTHER ),
			'ip'           => $args['ip'] ?? null,
			'page'         => Client_Info::page( $args['page'] ?? '', home_url() ),
			'consent_text' => $text,
			'privacy_url'  => $url,
		);
	}

	/**
	 * Fires quote_requests_created for a request that is stored and mailed. A callback that throws must not turn
	 * the answer into a failure: the visitor would send the same request again. The failure goes to the error log
	 * when WP_DEBUG is on (see Hook_Guard).
	 *
	 * @param int $id Post ID of the request.
	 */
	public static function created( int $id ): void {
		$depth = Hook_Guard::depth();
		try {
			/**
			 * Fires after a quote request is stored and its emails are handed over.
			 *
			 * @param int $id Post ID of the request. Read it with Quote_Requests\Store::get().
			 */
			do_action( 'quote_requests_created', $id );
		} catch ( \Throwable $e ) {
			Hook_Guard::failed( 'quote_requests_created', $id, $e, $depth ); // The record exists whatever the callback did.
		}
	}

	/**
	 * A source label as it is stored: lowercase letters, digits, dash and underscore, at most MAX_SOURCE characters.
	 *
	 * @param mixed  $value    The label as given.
	 * @param string $fallback What an empty or unusable label becomes.
	 */
	public static function source_label( $value, string $fallback = self::SOURCE_FORM ): string {
		$label = is_scalar( $value ) ? substr( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ), 0, self::MAX_SOURCE ) : '';
		return '' !== $label ? $label : $fallback;
	}

	/** True when requests can be stored: the record type is registered, which happens on the init action with WooCommerce active. */
	public static function ready(): bool {
		return function_exists( 'post_type_exists' ) && post_type_exists( Store::POST_TYPE );
	}

	/**
	 * What create() reads from a call of quote_requests_submit(): the values of the defined fields, the consent
	 * tick, the chosen country and the product lines. Anything else in $fields is left out.
	 *
	 * @param array $fields Values by field key.
	 * @param array $items  List of array( 'id' => int, 'qty' => int ).
	 */
	public static function params( array $fields, array $items ): array {
		$params = array();
		foreach ( array_merge( array_column( Fields::all(), 'key' ), array( 'consent', 'region_country' ) ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$params[ $key ] = $fields[ $key ];
			}
		}
		$params['items'] = array_values( $items );
		return $params;
	}

	/**
	 * The answer of quote_requests_submit() for a result of create() or check().
	 *
	 * @param array $result status and body, and id for a stored request.
	 * @return array ok, id, ref and dropped (the number of product lines left out) for a stored request; ok, code,
	 *               message and errors (by field key) otherwise.
	 */
	public static function answer( array $result ): array {
		$body = is_array( $result['body'] ?? null ) ? $result['body'] : array();
		if ( ! empty( $body['ok'] ) ) {
			return array(
				'ok'      => true,
				'id'      => (int) ( $result['id'] ?? 0 ),
				'ref'     => (string) ( $body['ref'] ?? '' ),
				'dropped' => is_int( $body['dropped'] ?? null ) ? $body['dropped'] : 0,
			);
		}
		return array(
			'ok'      => false,
			'code'    => is_string( $body['code'] ?? null ) ? $body['code'] : 'failed',
			'message' => is_string( $body['message'] ?? null ) ? $body['message'] : '',
			'errors'  => is_array( $body['errors'] ?? null ) ? $body['errors'] : array(),
		);
	}

	/**
	 * What quote_requests_submit() does (see includes/functions.php for the arguments and the answer).
	 *
	 * @param array $fields  Values by field key, plus consent and region_country.
	 * @param array $items   List of array( 'id' => int, 'qty' => int ).
	 * @param array $args    source, ip, page, consent_text and privacy_url.
	 * @param bool  $dry_run True to run the checks only: nothing is stored, mailed or counted, and the answer of a
	 *                       request that would be stored is array( 'ok' => true ).
	 */
	public static function submit( array $fields, array $items = array(), array $args = array(), bool $dry_run = false ): array {
		if ( ! self::ready() ) {
			// Before the init action no text is translated: WordPress loads translations from that action on.
			$message = 'Quote requests are not ready. Call quote_requests_submit() after the init action, with WooCommerce active.';
			return self::answer( self::fail( 503, 'not_ready', did_action( 'init' ) ? __( 'Quote requests are not ready. Call quote_requests_submit() after the init action, with WooCommerce active.', 'quote-requests' ) : $message ) );
		}
		$params = self::params( $fields, $items );
		$server = $_SERVER; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Read by Client_Info, which validates and cuts each value.
		$args   = self::args( $args );
		if ( $dry_run ) {
			$refused = self::check( $params, $server, $args );
			return null === $refused ? array( 'ok' => true ) : self::answer( $refused );
		}
		return self::answer( self::create( $params, $server, $args ) );
	}
}
