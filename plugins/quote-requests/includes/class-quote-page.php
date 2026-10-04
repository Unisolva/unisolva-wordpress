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

final class Quote_Page {

	public static function register(): void {
		add_shortcode( 'quote_requests', array( self::class, 'render' ) );
	}

	private static function transient( string $param, string $prefix ): ?array {
		$key = isset( $_GET[ $param ] ) ? sanitize_key( wp_unslash( $_GET[ $param ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $key ) {
			return null;
		}
		$v = get_transient( $prefix . $key );
		return is_array( $v ) ? $v : null;
	}

	/** The error state to show: a stored one, or a fixed message for a code that stores nothing. */
	private static function error_state(): ?array {
		$err  = self::transient( 'qr_err', Fallback::STATE['err'] );
		$code = isset( $_GET['qr_err'] ) ? sanitize_key( wp_unslash( $_GET['qr_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Display only.
		if ( null === $err && in_array( $code, Fallback::STATIC_CODES, true ) ) {
			$err = array(
				'body'   => array( 'message' => Fallback::static_message( $code ) ),
				'values' => array(),
			);
		}
		return $err;
	}

	public static function render(): string {
		$s     = Settings::all();
		$done  = self::transient( 'qr_done', Fallback::STATE['done'] );
		$err   = self::error_state();
		$keep  = self::transient( 'qr_keep', Fallback::STATE['keep'] ); // The no-script "Update regions" round trip: values, no errors.
		$defs  = Fields::visible();
		$shown = array_column( $defs, 'key' );
		$ctx   = array(
			'errors' => $err['body']['errors'] ?? array(),
			'values' => $err['values'] ?? $keep['values'] ?? array(),
			'rule'   => $s['contact_rule'],
			'shown'  => $shown,
			'hint'   => 'either' === $s['contact_rule'] && in_array( 'phone', $shown, true ) && in_array( 'email', $shown, true ),
		);
		$items = array();
		foreach ( Fallback::cookie_items() as $row ) {
			$card = Products::card( $row['id'] );
			if ( $card ) {
				$items[] = array_merge( $card, array( 'qty' => $row['qty'] ) );
			}
		}

		ob_start();
		echo '<div id="quote-requests" class="qr" data-qr-page>';
		self::done_panel( $done );
		echo '<div class="qr-body"' . ( $done ? ' hidden' : '' ) . '>';
		self::product_list( $items, $s['empty_text'] );
		self::form_start( $items, $s['contact_rule'] );
		self::error_summary( $err );
		$hint = $ctx['hint'];
		foreach ( $defs as $def ) {
			if ( $hint && self::is_contact( $def ) ) {
				echo '<p id="qr-contact-hint" class="qr-contact-hint">' . esc_html__( 'Give a phone number or an email address, or both.', 'quote-requests' ) . '</p>';
				$hint = false; // Once, before the first of the two.
			}
			self::field( $def, $ctx );
		}
		if ( $s['require_consent'] ) {
			self::consent( (int) $s['privacy_page'], $ctx );
		}
		echo '<p class="qr-hp" aria-hidden="true"><label for="qr-website">' . esc_html__( 'Leave this field empty', 'quote-requests' ) . '</label><input type="text" id="qr-website" name="website" value="" tabindex="-1" autocomplete="off"></p>';
		echo '<p class="qr-actions"><button type="submit" class="qr-submit">' . esc_html__( 'Send quote request', 'quote-requests' ) . '</button></p>';
		echo '<p class="qr-failure" data-qr-failure role="alert" hidden></p>';
		echo '</form></div></div>';
		return (string) ob_get_clean();
	}

	/** Success panel (no-script result, or filled by the script). */
	private static function done_panel( ?array $done ): void {
		echo '<div class="qr-done" data-qr-done tabindex="-1"' . ( $done ? '' : ' hidden' ) . '>';
		if ( $done ) {
			echo '<h2 class="qr-done__title">' . esc_html( $done['thanks'] ) . '</h2>';
			/* translators: %s: reference such as Q-2026-0001 */
			echo '<p>' . esc_html( sprintf( __( 'Your reference: %s', 'quote-requests' ), $done['ref'] ) ) . '</p>';
			if ( $done['items'] ) {
				echo '<ul class="qr-done__items">';
				foreach ( $done['items'] as $it ) {
					echo '<li>' . esc_html( $it['name'] ) . ' &times; ' . (int) $it['qty'] . '</li>';
				}
				echo '</ul>';
			}
		}
		echo '</div>';
	}

	private static function product_list( array $items, string $empty_text ): void {
		echo '<section class="qr-list" aria-labelledby="qr-list-title"><h2 id="qr-list-title" class="qr-heading">' . esc_html__( 'Your products', 'quote-requests' ) . '</h2>';
		echo '<ul class="qr-items" data-qr-list>';
		foreach ( $items as $i => $it ) {
			self::item_row( $i, $it );
		}
		echo '</ul>';
		echo '<p class="qr-empty" data-qr-empty' . ( $items ? ' hidden' : '' ) . '>' . esc_html( $empty_text ) . '</p>';
		echo '<p class="qr-note" data-qr-note role="status" hidden></p>';
		echo '</section>';
	}

	/**
	 * The address the script asks for region lists. It carries the plugin version and a hash of the region
	 * settings, so neither an update nor a settings change is answered from a cached copy of the old list.
	 */
	private static function regions_url(): string {
		return add_query_arg(
			array(
				'ver' => QUOTE_REQUESTS_VERSION,
				'rs'  => Regions::settings_hash(),
			),
			rest_url( Rest::NS . '/regions' )
		);
	}

	/** The form tag and its hidden inputs. */
	private static function form_start( array $items, string $rule ): void {
		$mode = Regions::mode();
		echo '<form id="quote-requests-form" class="qr-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-qr-contact="' . esc_attr( $rule ) . '" data-qr-region-mode="' . esc_attr( $mode ) . '" data-qr-regions-url="' . esc_url( self::regions_url() ) . '">';
		if ( 'choose' === $mode ) {
			// Enter in a field presses the first submit button: it must send the form, not "Update regions".
			echo '<button type="submit" class="qr-default" tabindex="-1" aria-hidden="true" data-qr-nojs>' . esc_html__( 'Send quote request', 'quote-requests' ) . '</button>';
		}
		echo '<input type="hidden" name="action" value="quote_requests_submit">';
		echo '<input type="hidden" name="token" value="' . esc_attr( Token::issue( time(), Token::secret() ) ) . '">';
		foreach ( array( 'landing', 'referrer', 'page', 'tz' ) as $h ) {
			echo '<input type="hidden" name="' . esc_attr( $h ) . '" value="">';
		}
		foreach ( $items as $i => $it ) {
			echo '<input type="hidden" name="items[' . (int) $i . '][id]" value="' . (int) $it['id'] . '" data-qr-nojs>';
		}
	}

	private static function error_summary( ?array $err ): void {
		echo '<div class="qr-errors" data-qr-errors role="alert" tabindex="-1"' . ( $err ? '' : ' hidden' ) . '>';
		if ( $err ) {
			echo '<p>' . esc_html( $err['body']['message'] ?? '' ) . '</p>';
			if ( ! empty( $err['body']['errors'] ) ) {
				echo '<ul>';
				foreach ( $err['body']['errors'] as $f => $msg ) {
					echo '<li><a href="#qr-' . esc_attr( $f ) . '">' . esc_html( $msg ) . '</a></li>';
				}
				echo '</ul>';
			}
		}
		echo '</div>';
	}

	private static function item_row( int $i, array $it ): void {
		echo '<li class="qr-item" data-qr-item="' . (int) $it['id'] . '">';
		if ( $it['image'] ) {
			echo '<img class="qr-item__img" src="' . esc_url( $it['image'] ) . '" alt="" width="64" height="64" loading="lazy">';
		}
		echo '<span class="qr-item__text"><a class="qr-item__name" href="' . esc_url( $it['url'] ) . '">' . esc_html( $it['name'] ) . '</a>';
		if ( $it['family'] ) {
			echo '<span class="qr-item__family">' . esc_html( $it['family'] ) . '</span>';
		}
		echo '</span>';
		echo '<label class="qr-item__qty"><span class="screen-reader-text">' . esc_html( sprintf( /* translators: %s: product name */ __( 'Quantity of %s', 'quote-requests' ), $it['name'] ) ) . '</span>';
		echo '<input type="number" form="quote-requests-form" name="items[' . (int) $i . '][qty]" value="' . (int) $it['qty'] . '" min="1" max="' . (int) Validator::MAX_QTY . '" inputmode="numeric"></label>';
		echo '<a class="qr-item__remove" href="' . esc_url( Fallback::quote_url( array( 'remove' => $it['id'] ) ) ) . '" data-qr-remove="' . (int) $it['id'] . '">' . esc_html__( 'Remove', 'quote-requests' ) . '<span class="screen-reader-text"> ' . esc_html( $it['name'] ) . '</span></a>';
		echo '</li>';
	}

	private static function is_contact( array $def ): bool {
		return ! empty( $def['system'] ) && in_array( $def['key'], array( 'phone', 'email' ), true );
	}

	/**
	 * Whether the control carries "required". The contact rule decides phone and email. With "either"
	 * neither does while both are shown (the hint, the script and the server enforce "at least one");
	 * when only one is shown, that one is required.
	 */
	private static function is_required( array $def, array $ctx ): bool {
		if ( empty( $def['system'] ) ) {
			return ! empty( $def['required'] );
		}
		$rule = $ctx['rule'];
		switch ( $def['key'] ) {
			case 'name':
				return true;
			case 'phone':
				return in_array( $rule, array( 'phone', 'both' ), true ) || ( 'either' === $rule && ! in_array( 'email', $ctx['shown'], true ) );
			case 'email':
				return in_array( $rule, array( 'email', 'both' ), true ) || ( 'either' === $rule && ! in_array( 'phone', $ctx['shown'], true ) );
			case 'message':
				return false; // Required only while the list is empty: the script and the server judge that.
		}
		return ! empty( $def['required'] );
	}

	/** The text the script shows for an empty required field (the same words the server uses). */
	private static function empty_message( array $def ): string {
		if ( ! empty( $def['system'] ) ) {
			switch ( $def['key'] ) {
				case 'name':
					return __( 'Please enter your name.', 'quote-requests' );
				case 'phone':
					return __( 'Please enter your phone or WhatsApp number.', 'quote-requests' );
				case 'email':
					return __( 'Please enter your email address.', 'quote-requests' );
				case 'region':
					return __( 'Please choose your region.', 'quote-requests' );
				case 'message':
					return __( 'Your quote list is empty. Please describe what you need.', 'quote-requests' );
			}
		}
		switch ( $def['type'] ) { // The words the server uses for the same case.
			case 'checkbox':
				return __( 'Please tick this box.', 'quote-requests' );
			case 'select':
				return __( 'Please choose an option.', 'quote-requests' );
		}
		return __( 'Please fill in this field.', 'quote-requests' );
	}

	private static function autocomplete( array $def ): string {
		if ( empty( $def['system'] ) ) {
			return 'off';
		}
		$tokens = array(
			'name'    => 'name',
			'phone'   => 'tel',
			'email'   => 'email',
			'company' => 'organization',
		);
		return $tokens[ $def['key'] ] ?? '';
	}

	private static function label( array $def, bool $required ): string {
		$html = '<label for="qr-' . esc_attr( $def['key'] ) . '">' . esc_html( $def['label'] );
		if ( ! empty( $def['system'] ) && 'message' === $def['key'] ) {
			$html .= ' <span class="qr-hint" data-qr-message-hint>' . esc_html__( '(required when your list is empty)', 'quote-requests' ) . '</span>';
		}
		return $html . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</label>';
	}

	/** One form field: label, control, help text and error span, in one paragraph. Everything is escaped where it is built. */
	private static function field( array $def, array $ctx ): void {
		$key      = $def['key'];
		$type     = $def['type'];
		$id       = 'qr-' . $key;
		$required = self::is_required( $def, $ctx );
		$error    = (string) ( $ctx['errors'][ $key ] ?? '' );
		$value    = (string) ( $ctx['values'][ $key ] ?? '' );
		$help     = '' !== $def['help'] ? '<span id="' . esc_attr( $id ) . '-help" class="qr-help">' . esc_html( $def['help'] ) . '</span>' : '';
		$attrs    = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '"'
			. ( $required ? ' required aria-required="true"' : '' )
			. ' aria-describedby="' . esc_attr( ( '' !== $help ? $id . '-help ' : '' ) . ( $ctx['hint'] && self::is_contact( $def ) ? 'qr-contact-hint ' : '' ) . $id . '-error' ) . '"'
			. ( '' !== $error ? ' aria-invalid="true"' : '' );
		$auto     = self::autocomplete( $def );
		$attrs   .= '' !== $auto ? ' autocomplete="' . esc_attr( $auto ) . '"' : '';

		switch ( $type ) {
			case 'region':
				$control = self::region_control( $def, $required, $ctx, $attrs );
				break;
			case 'textarea':
				$control = self::label( $def, $required ) . self::textarea_control( $def, $attrs, $value );
				break;
			case 'select':
				$control = self::label( $def, $required ) . self::select_control( $def, $attrs, $value );
				break;
			case 'checkbox':
				$control = self::checkbox_control( $attrs, $value ) . self::label( $def, $required );
				break;
			default:
				$control = self::label( $def, $required ) . self::input_control( $def, $attrs, $value );
		}

		echo '<p class="qr-field' . ( 'checkbox' === $type ? ' qr-field--check' : '' ) . '" data-qr-field="' . esc_attr( $key ) . '" data-qr-type="' . esc_attr( $type ) . '">' . $control . $help // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped where built.
			. '<span id="' . esc_attr( $id ) . '-error" class="qr-error" data-msg="' . esc_attr( self::empty_message( $def ) ) . '"' . ( '' !== $error ? '' : ' hidden' ) . '>' . esc_html( $error ) . '</span></p>';
	}

	/** Text, email, tel and number. */
	private static function input_control( array $def, string $attrs, string $value ): string {
		$limit = 'number' === $def['type'] ? ' step="any"' : ' maxlength="' . (int) Fallback::value_limit( $def ) . '"';
		return '<input type="' . esc_attr( $def['type'] ) . '"' . $attrs . ' value="' . esc_attr( $value ) . '"' . $limit . '>';
	}

	private static function textarea_control( array $def, string $attrs, string $value ): string {
		return '<textarea' . $attrs . ' rows="5" maxlength="' . (int) Fallback::value_limit( $def ) . '">' . esc_textarea( $value ) . '</textarea>';
	}

	private static function select_control( array $def, string $attrs, string $value ): string {
		$html = '<select' . $attrs . '><option value="">' . esc_html__( 'Choose…', 'quote-requests' ) . '</option>';
		foreach ( $def['options'] as $option ) {
			$html .= '<option value="' . esc_attr( $option['value'] ) . '"' . selected( $value, $option['value'], false ) . '>' . esc_html( $option['label'] ) . '</option>';
		}
		return $html . '</select>';
	}

	private static function checkbox_control( string $attrs, string $value ): string {
		return '<input type="checkbox"' . $attrs . ' value="1"' . checked( '1', $value, false ) . '>';
	}

	/**
	 * The region block. "single" mode: the regions of the site's country. "choose" mode: a country
	 * select first, then the regions of the chosen country, and the button that reloads the page for
	 * visitors without the script. A country without a region list takes one line of text.
	 */
	private static function region_control( array $def, bool $required, array $ctx, string $attrs ): string {
		$choose  = 'choose' === Regions::mode();
		$country = Regions::default_country();
		$value   = (string) ( $ctx['values']['region'] ?? '' );
		$html    = '';
		if ( $choose ) {
			$countries = Regions::countries();
			$posted    = strtoupper( (string) ( $ctx['values']['region_country'] ?? '' ) );
			$country   = isset( $countries[ $posted ] ) ? $posted : $country;
			$html     .= '<label for="qr-region_country">' . esc_html__( 'Country', 'quote-requests' ) . '</label>';
			// The script compares this with the select's value: a browser that restores the form on reload can leave them apart.
			$html .= '<select id="qr-region_country" name="region_country" data-qr-country="' . esc_attr( $country ) . '">';
			foreach ( $countries as $code => $name ) {
				$html .= '<option value="' . esc_attr( $code ) . '"' . selected( $country, $code, false ) . '>' . esc_html( $name ) . '</option>';
			}
			$html .= '</select>';
		}
		$html  .= self::label( $def, $required );
		$states = Regions::states( $country );
		if ( $states ) {
			$html .= '<select' . $attrs . '><option value="">' . esc_html__( 'Choose…', 'quote-requests' ) . '</option>';
			foreach ( $states as $code => $name ) {
				$html .= '<option value="' . esc_attr( $code ) . '"' . selected( $value, (string) $code, false ) . '>' . esc_html( $name ) . '</option>';
			}
			$html .= '</select>';
		} else {
			$html .= '<input type="text"' . $attrs . ' value="' . esc_attr( $value ) . '" maxlength="' . (int) Validator::LIMIT_REGION . '">';
		}
		if ( $choose ) {
			$html .= '<button type="submit" class="qr-refresh" name="qr_refresh" value="1" formnovalidate data-qr-nojs>' . esc_html__( 'Update regions', 'quote-requests' ) . '</button>';
		}
		return $html;
	}

	/** The consent box, drawn only when the settings ask for consent. */
	private static function consent( int $privacy_page, array $ctx ): void {
		$has     = isset( $ctx['errors']['consent'] );
		$privacy = $privacy_page ? get_permalink( $privacy_page ) : '';
		echo '<p class="qr-field qr-field--check"><input type="checkbox" id="qr-consent" name="consent" value="1"' . checked( true, ! empty( $ctx['values']['consent'] ), false ) . ' required aria-required="true" aria-describedby="qr-consent-error"' . ( $has ? ' aria-invalid="true"' : '' ) . '>';
		echo '<label for="qr-consent">' . esc_html( Settings::consent_text() ) . ' <span aria-hidden="true">*</span></label>';
		if ( $privacy ) {
			echo ' <a class="qr-privacy" href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Read the privacy policy (opens in a new tab)', 'quote-requests' ) . '</a>';
		}
		echo '<span id="qr-consent-error" class="qr-error" data-msg="' . esc_attr__( 'Please tick the box so we can reply to your request.', 'quote-requests' ) . '"' . ( $has ? '' : ' hidden' ) . '>' . esc_html( $ctx['errors']['consent'] ?? '' ) . '</span></p>';
	}
}
