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

use ElementorPro\Modules\Forms\Classes\Action_Base;

defined( 'ABSPATH' ) || exit;

// This file is read only when one of the two hooks Plugin::boot() listens to fires while Elementor Pro's form
// classes exist. Should anything else ask for the class on a site without them, nothing is declared.
if ( ! class_exists( Action_Base::class ) ) {
	return;
}

/**
 * The "Quote request" action of the Elementor Pro Form widget, and the worked example of an adapter for
 * quote_requests_submit(). The site owner adds it to a form under "Actions After Submit".
 *
 * Form fields are matched by their ID: a field whose ID is a field key of this plugin (name, phone, email,
 * company, region, message, or the key of a custom field) is passed on. "region" takes a region code or name,
 * "region_country" a two-letter country code when the visitor may choose the country. A ticked Acceptance field
 * with the ID "consent" is the consent, and its own text is what the record names as agreed to. A Hidden field
 * with the ID "quote_requests_items" carries the quote list, which the front script writes into it. The page the
 * form was on is stored as the page the request was sent from.
 *
 * The request is checked on elementor_pro/forms/validation, so the visitor reads the errors beside the fields
 * and Elementor runs none of the form's actions for a request this plugin refuses. It is stored once, in run().
 */
final class Elementor_Action extends Action_Base {

	/** The name of the action in Elementor, and the source label of the requests it stores. */
	public const NAME = 'quote_requests';

	/** The source label on the record. */
	public const SOURCE = 'elementor';

	/** The ID of the hidden form field that carries the quote list. */
	public const ITEMS = 'quote_requests_items';

	/** The ID of the Acceptance field that is the consent. */
	public const CONSENT = 'consent';

	/**
	 * Adds the action to Elementor Pro's list of form actions (hook elementor_pro/forms/actions/register).
	 *
	 * @param mixed $registrar Elementor Pro's registrar of form actions.
	 */
	public static function add( $registrar ): void {
		if ( is_object( $registrar ) && method_exists( $registrar, 'register' ) ) {
			$registrar->register( new self() );
		}
	}

	/** The name Elementor stores in the form's list of actions. */
	public function get_name() {
		return self::NAME;
	}

	/** The label in the "Add Action" list of the form. */
	public function get_label() {
		return esc_html__( 'Quote request', 'quote-requests' );
	}

	/**
	 * The section the Form widget shows when the action is chosen: a note with the field IDs. The action has no settings.
	 *
	 * @param \ElementorPro\Modules\Forms\Widgets\Form $widget The Form widget.
	 */
	public function register_settings_section( $widget ) {
		$widget->start_controls_section(
			'section_quote_requests',
			array(
				'label'     => $this->get_label(),
				'condition' => array( 'submit_actions' => $this->get_name() ),
			)
		);
		$code  = static fn( string $id ): string => '<code>' . esc_html( $id ) . '</code>';
		$lines = array(
			esc_html__( 'Each submission of this form is stored as a quote request and emailed to your team.', 'quote-requests' ),
			sprintf(
				/* translators: %s: list of field IDs */
				esc_html__( 'Give the form fields these IDs (Advanced tab of each field): %s, and the key of each field you added in the Quote Requests settings.', 'quote-requests' ),
				implode( ', ', array_map( $code, Fields::SYSTEM ) )
			),
			sprintf(
				/* translators: 1: field ID quote_requests_items, 2: field ID consent, 3: field ID region_country */
				esc_html__( 'Add a Hidden field with the ID %1$s to send the visitor\'s quote list, an Acceptance field with the ID %2$s when the consent box is required, and a field with the ID %3$s (a two-letter country code) when visitors choose their country.', 'quote-requests' ),
				$code( self::ITEMS ),
				$code( 'consent' ),
				$code( 'region_country' )
			),
			esc_html__( 'Elementor keeps its own copy of each submission, and its Email action sends its own email. Remove the Email action to avoid two emails.', 'quote-requests' ),
		);
		$widget->add_control(
			'quote_requests_note',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => implode( '<br><br>', $lines ),
				'content_classes' => 'elementor-descriptor',
			)
		);
		$widget->end_controls_section();
	}

	/**
	 * Called when a form is exported. The action stores nothing in the form, so nothing is taken out.
	 *
	 * @param array $element The form element.
	 */
	public function on_export( $element ) {
		return $element;
	}

	/**
	 * Checks a submission of a form that has the action, before Elementor runs any action of the form
	 * (hook elementor_pro/forms/validation). Nothing is stored or counted here.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       The submitted form.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Collects the errors of the form.
	 */
	public static function validate( $record, $ajax_handler ): void {
		if ( ! self::chosen( $record ) || ! is_object( $ajax_handler ) ) {
			return;
		}
		$fields = self::fields( $record );
		$answer = Submission::submit( $fields, quote_requests_parse_items( $fields[ self::ITEMS ] ?? '' ), array( 'source' => self::SOURCE ), true );
		if ( empty( $answer['ok'] ) ) {
			self::report( $answer, $fields, $ajax_handler );
		}
	}

	/**
	 * Stores the submission as a quote request. Elementor calls it after the validation passed.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       The submitted form.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Collects the errors and the answer of the form.
	 */
	public function run( $record, $ajax_handler ) {
		$fields = self::fields( $record );
		$answer = quote_requests_submit(
			$fields,
			quote_requests_parse_items( $fields[ self::ITEMS ] ?? '' ),
			array(
				'source'       => self::SOURCE,
				'consent_text' => self::consent_text( $record ),
				'page'         => self::page( $record ),
			)
		);
		if ( empty( $answer['ok'] ) ) {
			// Rare after a passed check: the rate limit was reached meanwhile, or the record could not be saved.
			self::report( $answer, $fields, $ajax_handler );
			return;
		}
		$ajax_handler->add_response_data( self::NAME, array( 'ref' => $answer['ref'] ) );
	}

	/**
	 * True when the form has this action among its actions after submit.
	 *
	 * @param mixed $record The submitted form.
	 */
	private static function chosen( $record ): bool {
		return is_object( $record ) && method_exists( $record, 'get_form_settings' ) && in_array( self::NAME, (array) $record->get_form_settings( 'submit_actions' ), true );
	}

	/**
	 * The submitted values by field ID, as the visitor typed them. This plugin's validator cleans them, as it
	 * cleans the values of the built-in form.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record The submitted form.
	 */
	private static function fields( $record ): array {
		$out = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$raw   = $field['raw_value'] ?? '';
			$value = $field['value'] ?? '';
			if ( ! is_string( $raw ) ) {
				$raw = is_scalar( $value ) ? (string) $value : ''; // Several ticked boxes come as a list; Elementor joins them in "value".
			}
			$out[ (string) $id ] = $raw;
		}
		return $out;
	}

	/**
	 * The text of the form's Acceptance field with the ID "consent", as plain text: what the visitor read and
	 * ticked. Elementor prints that text as HTML, so its tags are taken out. An empty string when the form has no
	 * such field or the field has no text; the record then names the consent text of the settings.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record The submitted form.
	 */
	private static function consent_text( $record ): string {
		foreach ( (array) $record->get_form_settings( 'form_fields' ) as $field ) {
			if ( is_array( $field ) && self::CONSENT === ( $field['custom_id'] ?? '' ) && 'acceptance' === ( $field['field_type'] ?? '' ) ) {
				$text = $field['acceptance_text'] ?? '';
				return is_string( $text ) ? trim( wp_specialchars_decode( wp_strip_all_tags( $text ), ENT_QUOTES ) ) : '';
			}
		}
		return '';
	}

	/**
	 * The address of the page the form was on: what Elementor recorded for the submission (the "Page URL" of the
	 * form's Meta Data), else the address Elementor's script posts with every form, else the referrer of the
	 * request. quote_requests_submit() keeps it only when it is an address of this site.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record The submitted form.
	 */
	private static function page( $record ): string {
		$meta = $record->get( 'meta' );
		$url  = is_array( $meta ) && is_string( $meta['page_url']['value'] ?? null ) ? $meta['page_url']['value'] : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Elementor has accepted the submission by now. The value is only stored when it is an address of this site.
		if ( '' === $url && isset( $_POST['referrer'] ) && is_string( $_POST['referrer'] ) ) {
			$url = (string) esc_url_raw( wp_unslash( $_POST['referrer'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		}
		if ( '' === $url ) {
			$url = (string) wp_get_raw_referer();
		}
		return $url;
	}

	/**
	 * Hands a refusal to Elementor. An error of a field the form has goes beside that field, under the ID of the
	 * field. Any other error, and a general failure (rate limit, not saved), becomes the form's error message and
	 * one entry under the name of the action: Elementor's script builds a selector from every key of a field
	 * error, so a key that is not a field of the form (a quote_requests_validate callback can return any key) is
	 * never handed over as one. Every refusal leaves an entry in the handler's errors, which is what makes
	 * Elementor end the request without running the form's actions. Elementor prints the texts as HTML, so they
	 * are escaped here.
	 *
	 * @param array                                            $answer       The refusal, as quote_requests_submit() returns it.
	 * @param array                                            $fields       The submitted values by field ID.
	 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Collects the errors of the form.
	 */
	private static function report( array $answer, array $fields, $ajax_handler ): void {
		$general = array();
		foreach ( $answer['errors'] as $key => $message ) {
			if ( array_key_exists( $key, $fields ) ) {
				$ajax_handler->add_error( (string) $key, esc_html( $message ) );
			} else {
				$general[] = $message; // No field of the form to show it beside.
			}
		}
		if ( ! $answer['errors'] ) {
			$general[] = $answer['message'];
		}
		if ( $general ) {
			$text = esc_html( implode( ' ', $general ) );
			$ajax_handler->add_error( self::NAME, $text );
			$ajax_handler->add_error_message( $text );
		}
	}
}
