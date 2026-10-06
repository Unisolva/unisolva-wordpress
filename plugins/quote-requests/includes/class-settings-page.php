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

/**
 * The settings screen: notifications, pages, the form fields table, the contact rule, consent, regions, texts,
 * catalog mode and privacy. Saved through the Settings API; Settings::sanitize() cleans the post.
 */
final class Settings_Page {

	public const SLUG = 'quote-requests-settings';

	/** The option group of the settings form. */
	public const GROUP = 'quote_requests';

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
		add_action( 'admin_menu', array( self::class, 'menu' ), 60 );
		add_filter( 'option_page_capability_' . self::GROUP, array( self::class, 'capability' ) );
	}

	/** Who may save the settings form: whoever may open the screen. Without this, options.php asks for manage_options. */
	public static function capability(): string {
		return 'manage_woocommerce';
	}

	public static function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
			)
		);
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Quote request settings', 'quote-requests' ), __( 'Quote request settings', 'quote-requests' ), 'manage_woocommerce', self::SLUG, array( self::class, 'render' ) );
	}

	private static function name( string $key ): string {
		return Settings::OPTION . '[' . $key . ']';
	}

	private static function text( string $key, string $label, $value, string $help = '' ): void {
		echo '<tr><th scope="row"><label for="qr-s-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" type="text" id="qr-s-' . esc_attr( $key ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="' . esc_attr( (string) $value ) . '">';
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** A tick box. The hidden input in front of it posts 0, so a box that is not ticked is still posted. */
	private static function check( string $key, string $label, bool $value, string $help = '', string $row = '' ): void {
		echo '<tr' . ( '' !== $row ? ' data-qr-mode="' . esc_attr( $row ) . '"' : '' ) . '><th scope="row"><label for="qr-s-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="hidden" name="' . esc_attr( self::name( $key ) ) . '" value="0"><input type="checkbox" id="qr-s-' . esc_attr( $key ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="1"' . checked( $value, true, false ) . '>';
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function number( string $key, string $label, int $value, int $min, int $max ): void {
		echo '<tr><th scope="row"><label for="qr-s-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" min="' . (int) $min . '" max="' . (int) $max . '" id="qr-s-' . esc_attr( $key ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="' . (int) $value . '"></td></tr>';
	}

	private static function page( string $key, string $label, int $value ): void {
		echo '<tr><th scope="row"><label for="qr-s-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		wp_dropdown_pages(
			array(
				'name'              => esc_attr( self::name( $key ) ),
				'id'                => esc_attr( 'qr-s-' . $key ),
				'selected'          => (int) $value,
				'show_option_none'  => esc_html__( 'Not set', 'quote-requests' ),
				'option_none_value' => '0',
			)
		); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</td></tr>';
	}

	/** A group of radio buttons in a fieldset. $choices is value => label. */
	private static function radios( string $key, string $label, array $choices, string $value, string $help = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><fieldset><legend class="screen-reader-text"><span>' . esc_html( $label ) . '</span></legend>';
		foreach ( $choices as $choice => $text ) {
			echo '<label><input type="radio" name="' . esc_attr( self::name( $key ) ) . '" value="' . esc_attr( $choice ) . '"' . checked( $value, $choice, false ) . '> ' . esc_html( $text ) . '</label><br>';
		}
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</fieldset></td></tr>';
	}

	/** The names of the field types as the site owner reads them. */
	private static function type_names(): array {
		return array(
			'text'     => __( 'One line of text', 'quote-requests' ),
			'textarea' => __( 'Several lines of text', 'quote-requests' ),
			'email'    => __( 'Email address', 'quote-requests' ),
			'tel'      => __( 'Phone number', 'quote-requests' ),
			'number'   => __( 'Number', 'quote-requests' ),
			'select'   => __( 'Choice from a list', 'quote-requests' ),
			'checkbox' => __( 'Tick box', 'quote-requests' ),
			'region'   => __( 'Region list', 'quote-requests' ),
		);
	}

	/**
	 * One row of the form fields table.
	 *
	 * @param string     $i     Row number in the posted list, or the placeholder of the row template.
	 * @param array|null $def   Field definition, or null for a new field.
	 * @param int        $order Position shown in the order box.
	 */
	private static function row( string $i, ?array $def, int $order ): void {
		$is_new = null === $def;
		$key    = $is_new ? '' : (string) $def['key'];
		$system = ! $is_new && ! empty( $def['system'] );
		$label  = $is_new ? '' : (string) $def['label'];
		$type   = $is_new ? 'text' : (string) $def['type'];
		$base   = Settings::OPTION . '[fields][' . $i . ']';
		$id     = 'qr-f-' . $i;
		$types  = self::type_names();
		$who    = $is_new ? __( 'new field', 'quote-requests' ) : $key; // Tells the rows apart in the labels of their controls.
		$named  = '' !== $label ? $label : $who;                         // The field as the buttons name it.

		echo '<tr data-qr-row data-qr-key="' . esc_attr( $key ) . '"' . ( $system ? ' data-qr-system' : '' ) . '>';

		// Label and key.
		echo '<td class="qr-col-label" data-qr-col="' . esc_attr__( 'Label and key', 'quote-requests' ) . '">';
		/* translators: %s: field key, or "new field" */
		echo '<label class="screen-reader-text" for="' . esc_attr( $id . '-label' ) . '">' . esc_html( sprintf( __( 'Label (%s)', 'quote-requests' ), $who ) ) . '</label>';
		echo '<input type="text" id="' . esc_attr( $id . '-label' ) . '" name="' . esc_attr( $base . '[label]' ) . '" value="' . esc_attr( $label ) . '" maxlength="100" data-qr-label>';
		if ( $is_new ) {
			echo '<p class="qr-key"><label for="' . esc_attr( $id . '-key' ) . '">' . esc_html__( 'Key', 'quote-requests' ) . '</label> <input type="text" class="code" id="' . esc_attr( $id . '-key' ) . '" name="' . esc_attr( $base . '[key]' ) . '" value="" maxlength="40" pattern="[a-z0-9_]*" title="' . esc_attr__( 'Small letters, digits and underscores only.', 'quote-requests' ) . '" autocapitalize="none" autocomplete="off" spellcheck="false" aria-describedby="qr-key-help"></p>';
		} else {
			echo '<p class="qr-key">' . esc_html__( 'Key', 'quote-requests' ) . ' <code>' . esc_html( $key ) . '</code></p>';
			echo '<input type="hidden" name="' . esc_attr( $base . '[key]' ) . '" value="' . esc_attr( $key ) . '"><input type="hidden" name="' . esc_attr( $base . '[id]' ) . '" value="' . esc_attr( $key ) . '">';
		}
		echo '</td>';

		// Type, and the choices of a list.
		echo '<td class="qr-col-type" data-qr-col="' . esc_attr__( 'Type', 'quote-requests' ) . '">';
		if ( $system ) {
			echo '<span class="qr-fixed">' . esc_html( $types[ $type ] ?? $type ) . '</span>';
		} else {
			/* translators: %s: field key, or "new field" */
			echo '<label class="screen-reader-text" for="' . esc_attr( $id . '-type' ) . '">' . esc_html( sprintf( __( 'Type (%s)', 'quote-requests' ), $who ) ) . '</label>';
			echo '<select id="' . esc_attr( $id . '-type' ) . '" name="' . esc_attr( $base . '[type]' ) . '" data-qr-type>';
			foreach ( Fields::TYPES as $t ) {
				echo '<option value="' . esc_attr( $t ) . '"' . selected( $type, $t, false ) . '>' . esc_html( $types[ $t ] ?? $t ) . '</option>';
			}
			echo '</select>';
			echo '<div class="qr-options" data-qr-options>';
			/* translators: %s: field key, or "new field" */
			echo '<label for="' . esc_attr( $id . '-options' ) . '">' . esc_html__( 'Choices', 'quote-requests' ) . '<span class="screen-reader-text"> ' . esc_html( sprintf( __( '(%s)', 'quote-requests' ), $who ) ) . '</span></label>';
			echo '<textarea id="' . esc_attr( $id . '-options' ) . '" name="' . esc_attr( $base . '[options]' ) . '" rows="4" aria-describedby="qr-options-help">' . esc_textarea( Fields::options_to_text( $is_new ? array() : (array) $def['options'] ) ) . '</textarea>';
			echo '</div>';
		}
		echo '</td>';

		// Help text.
		echo '<td class="qr-col-help" data-qr-col="' . esc_attr__( 'Help text', 'quote-requests' ) . '">';
		/* translators: %s: field key, or "new field" */
		echo '<label class="screen-reader-text" for="' . esc_attr( $id . '-help' ) . '">' . esc_html( sprintf( __( 'Help text (%s)', 'quote-requests' ), $who ) ) . '</label>';
		echo '<input type="text" id="' . esc_attr( $id . '-help' ) . '" name="' . esc_attr( $base . '[help]' ) . '" value="' . esc_attr( $is_new ? '' : (string) $def['help'] ) . '" maxlength="200">';
		echo '</td>';

		// Required.
		echo '<td class="qr-col-required" data-qr-col="' . esc_attr__( 'Required', 'quote-requests' ) . '">';
		if ( 'name' === $key ) {
			echo '<span class="qr-fixed">' . esc_html__( 'Always', 'quote-requests' ) . '</span>';
		} elseif ( 'message' === $key ) {
			echo '<span class="qr-fixed">' . esc_html__( 'When the quote list is empty', 'quote-requests' ) . '</span>';
		} elseif ( 'phone' === $key || 'email' === $key ) {
			echo '<span class="qr-fixed">' . esc_html__( 'Set by the contact rule', 'quote-requests' ) . '</span>';
		} else {
			/* translators: %s: field key, or "new field" */
			self::tick( $id . '-required', $base . '[required]', ! $is_new && ! empty( $def['required'] ), sprintf( __( 'Required (%s)', 'quote-requests' ), $who ) );
		}
		echo '</td>';

		// Show.
		echo '<td class="qr-col-show" data-qr-col="' . esc_attr__( 'Show', 'quote-requests' ) . '">';
		if ( 'name' === $key || 'message' === $key ) {
			echo '<span class="qr-fixed">' . esc_html__( 'Always', 'quote-requests' ) . '</span>';
		} else {
			/* translators: %s: field key, or "new field" */
			self::tick( $id . '-show', $base . '[show]', $is_new || ! empty( $def['show'] ), sprintf( __( 'Show (%s)', 'quote-requests' ), $who ) );
		}
		echo '</td>';

		// Order. The number box works without the script; the script hides it and shows the buttons.
		echo '<td class="qr-col-order" data-qr-col="' . esc_attr__( 'Order', 'quote-requests' ) . '">';
		/* translators: %s: field key, or "new field" */
		echo '<span class="qr-nojs"><label class="screen-reader-text" for="' . esc_attr( $id . '-order' ) . '">' . esc_html( sprintf( __( 'Position (%s)', 'quote-requests' ), $who ) ) . '</label>';
		echo '<input type="number" class="small-text" id="' . esc_attr( $id . '-order' ) . '" name="' . esc_attr( $base . '[order]' ) . '" value="' . (int) $order . '" min="0" step="1" data-qr-order></span>';
		/* translators: %s: field label */
		echo '<button type="button" class="button" data-qr-move="up" aria-label="' . esc_attr( sprintf( __( 'Move up: %s', 'quote-requests' ), $named ) ) . '" hidden>' . esc_html__( 'Move up', 'quote-requests' ) . '</button> ';
		/* translators: %s: field label */
		echo '<button type="button" class="button" data-qr-move="down" aria-label="' . esc_attr( sprintf( __( 'Move down: %s', 'quote-requests' ), $named ) ) . '" hidden>' . esc_html__( 'Move down', 'quote-requests' ) . '</button> ';
		if ( ! $system ) {
			/* translators: %s: field label */
			echo '<button type="button" class="button-link button-link-delete" data-qr-remove aria-label="' . esc_attr( sprintf( __( 'Remove: %s', 'quote-requests' ), $named ) ) . '" hidden>' . esc_html__( 'Remove', 'quote-requests' ) . '</button>';
			if ( ! $is_new ) {
				/* translators: %s: field key */
				echo '<label class="qr-nojs qr-remove"><input type="checkbox" name="' . esc_attr( $base . '[remove]' ) . '" value="1"> ' . esc_html__( 'Remove', 'quote-requests' ) . '<span class="screen-reader-text"> ' . esc_html( sprintf( __( '(%s)', 'quote-requests' ), $who ) ) . '</span></label>';
			}
		}
		echo '</td></tr>';
	}

	/** A tick box of the fields table, with a hidden 0 in front so "not ticked" is posted. */
	private static function tick( string $id, string $name, bool $on, string $label ): void {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( $on, true, false ) . '>';
		echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
	}

	/** The form fields table: one row per field, a blank row for a new one, and what the script needs. */
	private static function fields_table( array $fields ): void {
		$custom = count( Fields::custom( $fields ) );
		$words  = array(
			/* translators: %s: field label */
			'up'        => __( 'Move up: %s', 'quote-requests' ),
			/* translators: %s: field label */
			'down'      => __( 'Move down: %s', 'quote-requests' ),
			/* translators: %s: field label */
			'remove'    => __( 'Remove: %s', 'quote-requests' ),
			'unnamed'   => __( 'new field', 'quote-requests' ),
			/* translators: 1: field label, 2: new position, 3: number of fields */
			'movedUp'   => __( '%1$s moved up, now %2$s of %3$s.', 'quote-requests' ),
			/* translators: 1: field label, 2: new position, 3: number of fields */
			'movedDown' => __( '%1$s moved down, now %2$s of %3$s.', 'quote-requests' ),
			/* translators: %s: field label */
			'first'     => __( '%s is already the first field.', 'quote-requests' ),
			/* translators: %s: field label */
			'last'      => __( '%s is already the last field.', 'quote-requests' ),
			/* translators: 1: position of the new field, 2: number of fields */
			'added'     => __( 'Field added, number %1$s of %2$s. Type its label.', 'quote-requests' ),
			/* translators: %s: field label */
			'removed'   => __( '%s removed.', 'quote-requests' ),
			/* translators: 1: number of custom fields, 2: the most a form can have */
			'count'     => __( '%1$s of %2$s custom fields', 'quote-requests' ),
			'choices'   => __( 'Add at least one choice, or choose another type.', 'quote-requests' ),
			/* translators: %s: field key */
			'reserved'  => __( 'The key "%s" is reserved. Type another key.', 'quote-requests' ),
			/* translators: %s: field key */
			'used'      => __( 'The key "%s" is already used by another field. Type another key.', 'quote-requests' ),
		);

		echo '<h2>' . esc_html__( 'Form fields', 'quote-requests' ) . '</h2>';
		echo '<p>' . esc_html__( 'The fields of the quote form, in the order visitors see them. Name, phone, email, company, region and message are built in: they can be renamed and moved, and some can be hidden. Changes apply when you save.', 'quote-requests' ) . '</p>';
		echo '<table class="widefat striped qr-fields" id="qr-fields" data-qr-max="' . (int) Fields::MAX_CUSTOM . '" data-qr-reserved="' . esc_attr( (string) wp_json_encode( Fields::RESERVED ) ) . '" data-qr-text="' . esc_attr( (string) wp_json_encode( $words ) ) . '"><thead><tr>';
		foreach ( array( __( 'Label and key', 'quote-requests' ), __( 'Type', 'quote-requests' ), __( 'Help text', 'quote-requests' ), __( 'Required', 'quote-requests' ), __( 'Show', 'quote-requests' ), __( 'Order', 'quote-requests' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$i = 0;
		foreach ( $fields as $def ) {
			self::row( (string) $i, $def, $i + 1 );
			++$i;
		}
		if ( $custom < Fields::MAX_CUSTOM ) {
			self::row( (string) $i, null, $i + 1 ); // Without the script this row is how a field is added.
		}
		echo '</tbody></table>';
		echo '<template id="qr-field-template">';
		self::row( '__i__', null, 0 );
		echo '</template>';
		echo '<p class="qr-fields-foot"><button type="button" class="button" id="qr-add-field" hidden>' . esc_html__( 'Add field', 'quote-requests' ) . '</button> <span id="qr-fields-count">' . esc_html( sprintf( $words['count'], $custom, Fields::MAX_CUSTOM ) ) . '</span></p>';
		echo '<p class="description" id="qr-key-help">' . esc_html__( 'Key: small letters, digits and underscores, at most 40. It names the field in stored requests and cannot be changed later. Leave it empty to have it made from the label.', 'quote-requests' ) . '</p>';
		echo '<p class="description" id="qr-options-help">' . esc_html__( 'Choices are for the type "Choice from a list": one per line, as "value | Label", or one text that is used for both.', 'quote-requests' ) . '</p>';
		echo '<p class="description qr-nojs">' . esc_html__( 'To add a field, fill in the last row. To change the order, change the numbers in the Order column.', 'quote-requests' ) . '</p>';
		echo '<div id="qr-fields-live" class="screen-reader-text" role="status" aria-live="polite"></div>';
	}

	/** The countries a visitor may choose from: a multiple select, or a text box when the store has no country list. */
	private static function countries( array $chosen ): void {
		$names = Regions::all_country_names();
		$help  = __( 'Used when the visitor chooses the country. Select none to offer every country.', 'quote-requests' );
		echo '<tr data-qr-mode="choose"><th scope="row"><label for="qr-s-region_countries">' . esc_html__( 'Allowed countries', 'quote-requests' ) . '</label></th><td>';
		if ( $names ) {
			echo '<input type="hidden" name="' . esc_attr( self::name( 'region_countries' ) . '[]' ) . '" value="">';
			echo '<select multiple size="8" id="qr-s-region_countries" name="' . esc_attr( self::name( 'region_countries' ) . '[]' ) . '" aria-describedby="qr-s-region_countries-help">';
			foreach ( $names as $code => $country ) {
				echo '<option value="' . esc_attr( $code ) . '"' . selected( in_array( (string) $code, $chosen, true ), true, false ) . '>' . esc_html( $country ) . '</option>';
			}
			echo '</select>';
			$help .= ' ' . __( 'Hold Ctrl, or Cmd on a Mac, to select more than one.', 'quote-requests' );
		} else {
			echo '<input class="regular-text" type="text" id="qr-s-region_countries" name="' . esc_attr( self::name( 'region_countries' ) ) . '" value="' . esc_attr( implode( ', ', $chosen ) ) . '" aria-describedby="qr-s-region_countries-help">';
			$help .= ' ' . __( 'Two-letter codes, separated by commas.', 'quote-requests' );
		}
		echo '<p class="description" id="qr-s-region_countries-help">' . esc_html( $help ) . '</p></td></tr>';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s = Settings::all();
		echo '<div class="wrap qr-settings"><h1>' . esc_html__( 'Quote request settings', 'quote-requests' ) . '</h1>';
		settings_errors();
		echo '<form method="post" action="options.php" id="qr-settings-form">';
		settings_fields( self::GROUP );
		echo '<input type="hidden" name="' . esc_attr( self::name( '_form' ) ) . '" value="' . esc_attr( Settings::FORM ) . '">';

		echo '<h2>' . esc_html__( 'Notifications', 'quote-requests' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="qr-s-recipients">' . esc_html__( 'Recipients', 'quote-requests' ) . '</label></th><td><textarea class="large-text" rows="3" id="qr-s-recipients" name="' . esc_attr( self::name( 'recipients' ) ) . '">' . esc_textarea( implode( "\n", $s['recipients'] ) ) . '</textarea><p class="description">' . esc_html__( 'One email address per line.', 'quote-requests' ) . '</p></td></tr>';
		self::check( 'customer_confirmation', __( 'Send customer confirmation', 'quote-requests' ), $s['customer_confirmation'] );
		self::text( 'subject_prefix', __( 'Subject prefix of the team email', 'quote-requests' ), $s['subject_prefix'], __( 'Empty means the site name. The customer\'s copy always carries the site name.', 'quote-requests' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Pages', 'quote-requests' ) . '</h2><table class="form-table" role="presentation">';
		self::page( 'quote_page', __( 'Quote page', 'quote-requests' ), $s['quote_page'] );
		self::page( 'privacy_page', __( 'Privacy page', 'quote-requests' ), $s['privacy_page'] );
		echo '</table>';

		self::fields_table( $s['fields'] );

		echo '<h2>' . esc_html__( 'Form rules', 'quote-requests' ) . '</h2><table class="form-table" role="presentation">';
		self::radios(
			'contact_rule',
			__( 'Contact rule', 'quote-requests' ),
			array(
				'either' => __( 'At least one of phone and email', 'quote-requests' ),
				'phone'  => __( 'Phone mandatory, email optional', 'quote-requests' ),
				'email'  => __( 'Email mandatory, phone optional', 'quote-requests' ),
				'both'   => __( 'Phone and email mandatory', 'quote-requests' ),
			),
			$s['contact_rule'],
			__( 'A contact field that the rule makes mandatory is always shown. A request with no way to reply is always rejected.', 'quote-requests' )
		);
		self::check( 'require_consent', __( 'Require a consent checkbox', 'quote-requests' ), $s['require_consent'] );
		self::text( 'consent_text', __( 'Consent text', 'quote-requests' ), $s['consent_text'], Settings::consent_text() );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Regions', 'quote-requests' ) . '</h2><table class="form-table" role="presentation" id="qr-regions">';
		self::radios(
			'region_mode',
			__( 'Region mode', 'quote-requests' ),
			array(
				'single' => __( 'Single country', 'quote-requests' ),
				'choose' => __( 'Visitor chooses the country', 'quote-requests' ),
			),
			$s['region_mode'],
			__( 'The region is a list where the store knows the regions of the country, and a line of text where it does not.', 'quote-requests' )
		);
		self::text( 'region_country', __( 'Country (2-letter code)', 'quote-requests' ), $s['region_country'], __( 'Empty means the store country. When the visitor chooses the country, this one is preselected.', 'quote-requests' ) );
		self::check( 'region_outside', __( 'Offer "Outside [country]"', 'quote-requests' ), $s['region_outside'], __( 'Used with a single country.', 'quote-requests' ), 'single' );
		self::countries( $s['region_countries'] );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Texts', 'quote-requests' ) . '</h2><table class="form-table" role="presentation">';
		self::text( 'button_label', __( 'Button label', 'quote-requests' ), $s['button_label'] );
		/* translators: %d is shown literally: it is the placeholder the site owner may use. */
		self::text( 'button_added_label', __( 'Button label when added (%d = quantity)', 'quote-requests' ), $s['button_added_label'] );
		self::text( 'empty_text', __( 'Empty list text', 'quote-requests' ), $s['empty_text'] );
		self::text( 'thanks_text', __( 'Thank-you text (%name% = customer name)', 'quote-requests' ), $s['thanks_text'] );
		self::check( 'thanks_links', __( 'Show links after a request is sent', 'quote-requests' ), $s['thanks_links'], __( 'Under the thank-you text: a link to the shop page, when the store has one, and a link to the home page.', 'quote-requests' ) );
		self::text( 'thanks_continue_label', __( 'Label of the shop page link', 'quote-requests' ), $s['thanks_continue_label'] );
		self::text( 'thanks_home_label', __( 'Label of the home page link', 'quote-requests' ), $s['thanks_home_label'] );
		self::text( 'fallback_contact', __( 'Failure fallback contact', 'quote-requests' ), $s['fallback_contact'], __( 'Shown when a request cannot be sent, for example an email address and phone number.', 'quote-requests' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Catalog mode and buttons', 'quote-requests' ) . '</h2><table class="form-table" role="presentation">';
		self::check( 'hide_prices', __( 'Hide prices', 'quote-requests' ), $s['hide_prices'] );
		self::check( 'remove_add_to_cart', __( 'Remove add to cart', 'quote-requests' ), $s['remove_add_to_cart'] );
		self::check( 'redirect_cart', __( 'Redirect cart and checkout to the quote page', 'quote-requests' ), $s['redirect_cart'] );
		self::check( 'auto_button_single', __( 'Add the button to product pages', 'quote-requests' ), $s['auto_button_single'] );
		self::check( 'auto_button_loop', __( 'Add the button to product lists', 'quote-requests' ), $s['auto_button_loop'] );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Privacy and abuse', 'quote-requests' ) . '</h2><table class="form-table" role="presentation">';
		self::number( 'retention_months', __( 'Keep requests for (months)', 'quote-requests' ), $s['retention_months'], 1, 120 );
		self::check( 'store_client', __( 'Store visitor details (IP, browser, device, language, pages)', 'quote-requests' ), $s['store_client'] );
		self::check( 'trust_proxy', __( 'Trust proxy header for the IP (CF-Connecting-IP, X-Forwarded-For)', 'quote-requests' ), $s['trust_proxy'] );
		self::number( 'rate_limit', __( 'Max requests per IP per hour', 'quote-requests' ), $s['rate_limit'], 1, 100 );
		echo '</table>';

		submit_button();
		echo '</form></div>';
	}
}
