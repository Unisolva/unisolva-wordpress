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

final class Mailer {

	/** @var callable|null Replaced in tests; null means wp_mail. */
	public static $transport = null;

	private static function clean( string $s ): string {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( $s ) ) );
	}

	/**
	 * Reply-To header for the customer, or an empty string.
	 *
	 * Only the address is used: a display name containing a comma splits into two
	 * addresses and mail services then reject the whole message.
	 *
	 * @param string $name  Customer name (unused in the header, kept for callers).
	 * @param string $email Customer email.
	 */
	public static function reply_to( string $name, string $email ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Kept so callers need not change.
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return '';
		}
		return 'Reply-To: ' . $email;
	}

	private static function rows( array $q, bool $admin ): array {
		// The form fields come from the field definitions (system fields) and from the record (custom fields, with the labels they were stored under).
		$rows        = array();
		$device_text = '';
		$device      = __( 'Device', 'quote-requests' );
		if ( $admin && ! empty( $q['client']['device'] ) ) {
			$c           = $q['client'];
			$device_text = trim( $c['device'] . ', ' . trim( ( $c['browser'] ?? '' ) . ' ' . ( $c['browser_version'] ?? '' ) ) . ', ' . ( $c['os'] ?? '' ), ', ' );
		}
		Fields::add_row( $rows, __( 'Reference', 'quote-requests' ), $q['ref'] );
		Fields::add_row( $rows, $device, $device_text ); // Holds the label, so a field with the same label gets a number; moved to the end below.
		$shown = array_merge(
			$q,
			array(
				'name'        => self::clean( $q['name'] ),
				'region_name' => Regions::location( $q ),
			)
		);
		foreach ( Fields::rows( $shown ) as $label => $value ) {
			Fields::add_row( $rows, (string) $label, $value );
		}
		if ( '' !== $device_text ) {
			unset( $rows[ $device ] );
			$rows[ $device ] = $device_text;
		}
		return $rows;
	}

	private static function render( string $intro, array $rows, array $items, string $outro_html, string $outro_text ): array {
		$html  = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.5;color:#1d2327">';
		$html .= '<p>' . esc_html( $intro ) . '</p><table cellpadding="6" style="border-collapse:collapse">';
		$text  = $intro . "\n\n";
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th align="left" style="vertical-align:top">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( (string) $value ) ) . '</td></tr>';
			$text .= $label . ': ' . $value . "\n";
		}
		$html .= '</table><h3>' . esc_html__( 'Products', 'quote-requests' ) . '</h3>';
		$text .= "\n" . __( 'Products', 'quote-requests' ) . ":\n";
		if ( $items ) {
			$html .= '<table cellpadding="6" style="border-collapse:collapse"><tr><th align="left">' . esc_html__( 'Product', 'quote-requests' ) . '</th><th align="left">' . esc_html__( 'Family', 'quote-requests' ) . '</th><th align="right">' . esc_html__( 'Quantity', 'quote-requests' ) . '</th></tr>';
			foreach ( $items as $it ) {
				$html .= '<tr><td>' . esc_html( $it['name'] ) . ( '' !== $it['sku'] ? ' (' . esc_html( $it['sku'] ) . ')' : '' ) . '</td><td>' . esc_html( $it['family'] ) . '</td><td align="right">' . (int) $it['qty'] . '</td></tr>';
				$text .= '- ' . $it['name'] . ' x ' . (int) $it['qty'] . "\n";
			}
			$html .= '</table>';
		} else {
			$html .= '<p>' . esc_html__( 'No products listed; see the message.', 'quote-requests' ) . '</p>';
			$text .= __( 'No products listed; see the message.', 'quote-requests' ) . "\n";
		}
		$html .= $outro_html . '</div>';
		$text .= "\n" . $outro_text . "\n";
		return array( $html, $text );
	}

	public static function admin_message( array $q, array $ctx ): array {
		$name    = self::clean( $q['name'] );
		$subject = sprintf( '[%s] ', $ctx['prefix'] ) . sprintf(
			/* translators: 1: reference, 2: customer name */
			__( 'Quote request %1$s from %2$s', 'quote-requests' ),
			$q['ref'],
			$name
		) . ( '' !== Regions::location( $q ) ? ' (' . Regions::location( $q ) . ')' : '' );
		list( $html, $text ) = self::render(
			__( 'A new quote request was sent from the website.', 'quote-requests' ),
			self::rows( $q, true ),
			$q['items'],
			'<p><a href="' . esc_url( $ctx['admin_url'] ) . '">' . esc_html__( 'Open the request in WordPress', 'quote-requests' ) . '</a></p>',
			__( 'Open the request in WordPress:', 'quote-requests' ) . ' ' . $ctx['admin_url']
		);
		$headers             = array( 'Content-Type: text/html; charset=UTF-8' );
		$reply               = self::reply_to( $name, (string) $q['email'] );
		if ( '' !== $reply ) {
			$headers[] = $reply;
		}
		return array(
			'to'      => array_values( $ctx['recipients'] ),
			'subject' => $subject,
			'html'    => $html,
			'text'    => $text,
			'headers' => $headers,
		);
	}

	public static function customer_message( array $q, array $ctx ): ?array {
		if ( ! filter_var( (string) $q['email'], FILTER_VALIDATE_EMAIL ) ) {
			return null;
		}
		$subject = sprintf( '[%s] ', $ctx['prefix'] ) . sprintf(
			/* translators: %s: reference */
			__( 'We received your quote request %s', 'quote-requests' ),
			$q['ref']
		);
		// Only the reference: nothing the sender typed is mailed to an address they chose (no relay for free text).
		$rows                = array( __( 'Reference', 'quote-requests' ) => $q['ref'] );
		list( $html, $text ) = self::render(
			$ctx['thanks'],
			$rows,
			$q['items'],
			'<p><a href="' . esc_url( $ctx['site_url'] ) . '">' . esc_html( $ctx['prefix'] ) . '</a></p>',
			$ctx['prefix'] . ' ' . $ctx['site_url']
		);
		return array(
			'to'      => array( $q['email'] ),
			'subject' => $subject,
			'html'    => $html,
			'text'    => $text,
			'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
		);
	}

	/**
	 * What the team email is built with. Its subject starts with the subject prefix of the settings; an empty prefix means the site name.
	 *
	 * @param string $admin_url Address of the request in the admin.
	 */
	public static function admin_context( string $admin_url ): array {
		return array(
			'recipients' => (array) Settings::get( 'recipients' ),
			'prefix'     => Settings::subject_prefix(),
			'admin_url'  => $admin_url,
		);
	}

	/**
	 * What the customer's copy is built with. Its subject starts with the site name and its last link shows the site name:
	 * the subject prefix of the settings belongs to the team email and is not used here.
	 *
	 * @param array  $q        The request as Store::get() returns it.
	 * @param string $site_url Address of the home page.
	 */
	public static function customer_context( array $q, string $site_url ): array {
		return array(
			'prefix'   => Settings::site_name(),
			'site_url' => $site_url,
			'thanks'   => str_replace( '%name%', self::clean( $q['name'] ), (string) Settings::get( 'thanks_text' ) ),
		);
	}

	private static function deliver( array $m ): bool {
		$alt = static function ( $phpmailer ) use ( $m ) {
			$phpmailer->AltBody = $m['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		};
		add_action( 'phpmailer_init', $alt );
		try {
			$send = self::$transport ?? 'wp_mail';
			$ok   = (bool) $send( $m['to'], $m['subject'], $m['html'], $m['headers'] );
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		remove_action( 'phpmailer_init', $alt );
		return $ok;
	}

	public static function send( int $quote_id ): void {
		$q = Store::get( $quote_id );
		if ( ! $q ) {
			return;
		}
		$admin = self::admin_message( $q, self::admin_context( admin_url( 'post.php?post=' . $quote_id . '&action=edit' ) ) );
		Store::set_mail( $quote_id, 'admin', $admin['to'] && self::deliver( $admin ) ? 'handed_over' : 'failed' );

		$customer = Settings::get( 'customer_confirmation' ) ? self::customer_message( $q, self::customer_context( $q, home_url( '/' ) ) ) : null;
		Store::set_mail( $quote_id, 'customer', null === $customer ? 'skipped' : ( self::deliver( $customer ) ? 'handed_over' : 'failed' ) );
	}
}
