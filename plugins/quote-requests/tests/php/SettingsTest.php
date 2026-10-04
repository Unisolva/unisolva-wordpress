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
use Quote_Requests\Fields;
use Quote_Requests\Settings;

final class SettingsTest extends TestCase {
	protected function setUp(): void {
		qr_test_reset_options( array( 'admin_email' => 'admin@example.test', 'wp_page_for_privacy_policy' => 7 ) );
		$GLOBALS['qr_test_filters']         = array();
		$GLOBALS['qr_test_settings_errors'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['qr_test_filters'] = array();
	}

	public function test_all_reads_the_option_once_per_request(): void {
		qr_test_set_option( Settings::OPTION, require __DIR__ . '/fixtures/settings-0.1-sample.php' );
		$GLOBALS['qr_test_option_reads'] = array();
		$first                           = Settings::all();
		$second                          = Settings::all();
		$this->assertSame( $first, $second );
		$this->assertTrue( Settings::get( 'hide_prices' ) );
		Settings::region_country();
		Fields::all();
		Fields::visible();
		$this->assertSame( 1, $GLOBALS['qr_test_option_reads'][ Settings::OPTION ], 'one read of the option, however often the settings are asked for' );
	}

	public function test_the_kept_settings_are_used_until_flush(): void {
		$this->assertFalse( Settings::get( 'hide_prices' ) );
		$GLOBALS['qr_test_options'][ Settings::OPTION ] = array( 'contact_rule' => 'either', 'hide_prices' => true ); // Behind the back of the class.
		$this->assertFalse( Settings::get( 'hide_prices' ), 'kept for the request' );
		Settings::flush();
		$this->assertTrue( Settings::get( 'hide_prices' ) );
	}

	public static function hooks_that_drop_the_kept_settings(): array {
		return array(
			array( 'update_option_' . Settings::OPTION ),
			array( 'add_option_' . Settings::OPTION ),
			array( 'delete_option_' . Settings::OPTION ),
			array( 'update_option_admin_email' ),
			array( 'update_option_wp_page_for_privacy_policy' ),
			array( 'switch_locale' ),
			array( 'restore_previous_locale' ),
			array( 'switch_blog' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'hooks_that_drop_the_kept_settings' )]
	public function test_an_option_change_or_a_locale_switch_drops_the_kept_settings( string $hook ): void {
		Settings::register();
		$this->assertFalse( Settings::get( 'hide_prices' ) );
		$this->assertSame( array(), Fields::custom( Fields::all() ) );
		$GLOBALS['qr_test_options'][ Settings::OPTION ] = array(
			'contact_rule' => 'either',
			'hide_prices'  => true,
			'fields'       => array(
				array(
					'key'   => 'extra',
					'label' => 'Extra',
				),
			),
		);
		do_action( $hook, 'old', 'new', Settings::OPTION );
		$this->assertTrue( Settings::get( 'hide_prices' ), $hook );
		$this->assertSame( array( 'extra' ), array_column( Fields::custom( Fields::all() ), 'key' ), 'the field list is dropped together with the settings' );
	}

	public function test_a_field_label_is_escaped_in_the_message_for_the_settings_screen(): void {
		Settings::sanitize(
			array(
				'recipients' => 'sales@example.com',
				'fields'     => array(
					array(
						'key'     => '',
						'label'   => 'Size & "fit" > all',
						'type'    => 'select',
						'options' => '',
					),
				),
			)
		);
		$messages = array_column( $GLOBALS['qr_test_settings_errors'], 'message' );
		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'Size &amp; &quot;fit&quot; &gt; all: add at least one choice.', $messages[0] );
		$this->assertStringNotContainsString( '"fit"', $messages[0] );
	}

	private function by_key( array $fields ): array {
		return array_column( $fields, null, 'key' );
	}

	public function test_defaults_when_nothing_stored(): void {
		$this->assertSame( array( 'admin@example.test' ), Settings::get( 'recipients' ) );
		$this->assertSame( 7, Settings::get( 'privacy_page' ) );
		$this->assertFalse( Settings::get( 'hide_prices' ) );
		$this->assertTrue( Settings::get( 'auto_button_single' ) );
		$this->assertSame( 24, Settings::get( 'retention_months' ) );
	}

	public function test_fresh_install_defaults(): void {
		$all = Settings::all();
		$this->assertSame( 'either', $all['contact_rule'] );
		$this->assertTrue( $all['require_consent'] );
		$this->assertSame( 'single', $all['region_mode'] );
		$this->assertSame( array(), $all['region_countries'] );
		$this->assertSame( Fields::defaults(), $all['fields'] );
		$this->assertSame( $all, Settings::defaults() );
		foreach ( array( 'label_company', 'label_details', 'region_label' ) as $gone ) {
			$this->assertArrayNotHasKey( $gone, $all );
		}
	}

	public function test_the_removed_field_keys_constant_is_gone(): void {
		$this->assertFalse( defined( Settings::class . '::FIELD_KEYS' ) );
	}

	public function test_stored_values_override_and_the_field_list_is_normalized(): void {
		qr_test_set_option( Settings::OPTION, array(
			'hide_prices'  => true,
			'contact_rule' => 'phone',
			'fields'       => array(
				array(
					'key'   => 'company',
					'label' => 'Organisation',
					'show'  => false,
				),
				array(
					'key'   => 'Extra',
					'type'  => 'text',
					'label' => 'Extra',
				),
			),
		) );
		$this->assertTrue( Settings::get( 'hide_prices' ) );
		$this->assertSame( 'phone', Settings::get( 'contact_rule' ) );
		$fields = Settings::get( 'fields' );
		$this->assertSame( array( 'company', 'extra', 'name', 'phone', 'email', 'region', 'message' ), array_column( $fields, 'key' ) );
		$by = $this->by_key( $fields );
		$this->assertSame( 'Organisation', $by['company']['label'] );
		$this->assertFalse( $by['company']['show'] );
		$this->assertFalse( $by['extra']['system'] );
		$this->assertTrue( $by['name']['required'] );
	}

	public function test_all_converts_a_legacy_option_on_read(): void {
		$stored = require __DIR__ . '/fixtures/settings-0.1-sample.php';
		qr_test_set_option( Settings::OPTION, $stored );

		$all = Settings::all();
		$by  = $this->by_key( $all['fields'] );

		$this->assertSame( 'phone', $all['contact_rule'] );
		$this->assertSame( 'single', $all['region_mode'] );
		$this->assertTrue( $all['require_consent'] );
		$this->assertSame( array( 'name', 'phone', 'region', 'email', 'company', 'details', 'message' ), array_column( $all['fields'], 'key' ) );
		$this->assertSame( 'State', $by['region']['label'] );
		$this->assertTrue( $by['region']['required'] );
		$this->assertSame( 'Company or organisation', $by['company']['label'] );
		$this->assertSame( 'Project details', $by['details']['label'] );
		$this->assertSame( 'US', $all['region_country'] );
		$this->assertTrue( $all['hide_prices'] );
		$this->assertSame( array( 'sales@example.com', 'info@example.com' ), $all['recipients'] );
		$this->assertSame( $stored, $GLOBALS['qr_test_options'][ Settings::OPTION ], 'reading must not write the option' );
		$this->assertArrayHasKey( 'email', $GLOBALS['qr_test_options'][ Settings::OPTION ]['fields'], 'the stored option is still the 0.1 shape' );
	}

	public function test_a_stored_json_string_is_read_too(): void {
		qr_test_set_option( Settings::OPTION, wp_json_encode( array( 'contact_rule' => 'both', 'hide_prices' => true ) ) );
		$this->assertSame( 'both', Settings::get( 'contact_rule' ) );
		$this->assertTrue( Settings::get( 'hide_prices' ) );
	}

	public function test_unknown_rule_and_mode_fall_back_to_defaults(): void {
		$out = Settings::sanitize(
			array(
				'contact_rule' => 'sometimes',
				'region_mode'  => 'everywhere',
			)
		);
		$this->assertSame( 'either', $out['contact_rule'] );
		$this->assertSame( 'single', $out['region_mode'] );

		qr_test_set_option( Settings::OPTION, array(
			'contact_rule' => array( 'phone' ),
			'region_mode'  => 'everywhere',
			'fields'       => array( array( 'key' => 'name' ) ),
		) );
		$this->assertSame( 'either', Settings::get( 'contact_rule' ) );
		$this->assertSame( 'single', Settings::get( 'region_mode' ) );
	}

	public function test_known_rules_and_modes_are_kept(): void {
		foreach ( array( 'either', 'phone', 'email', 'both' ) as $rule ) {
			$this->assertSame( $rule, Settings::sanitize( array( 'contact_rule' => $rule ) )['contact_rule'] );
		}
		foreach ( array( 'single', 'choose' ) as $mode ) {
			$this->assertSame( $mode, Settings::sanitize( array( 'region_mode' => $mode ) )['region_mode'] );
		}
	}

	public function test_consent_switch_is_a_boolean_that_defaults_on(): void {
		$this->assertTrue( Settings::get( 'require_consent' ) );
		$this->assertTrue( Settings::sanitize( array( 'require_consent' => '1' ) )['require_consent'] );
		$this->assertFalse( Settings::sanitize( array( '_form' => Settings::FORM ) )['require_consent'], 'the current form: an absent box is unticked' );
		$this->assertTrue( Settings::sanitize( array() )['require_consent'], 'a form without the box keeps the current value' );
		qr_test_set_option( Settings::OPTION, array(
			'contact_rule'    => 'either',
			'require_consent' => false,
		) );
		$this->assertFalse( Settings::get( 'require_consent' ) );
	}

	public function test_region_countries_are_clean_two_letter_codes(): void {
		$this->assertSame( array( 'EG', 'SA' ), Settings::sanitize( array( 'region_countries' => 'eg, SA ,egypt, sa, x1' ) )['region_countries'] );
		$this->assertSame( array( 'AE', 'OM' ), Settings::sanitize( array( 'region_countries' => array( 'ae', 'AE', 'bad', 7, 'om' ) ) )['region_countries'] );
		$this->assertSame( array(), Settings::sanitize( array() )['region_countries'] );
		$this->assertSame( array(), Settings::sanitize( array( 'region_countries' => 5 ) )['region_countries'] );
		qr_test_set_option( Settings::OPTION, array(
			'contact_rule'     => 'either',
			'region_countries' => array( 'eg', 'EGY', 'sa' ),
		) );
		$this->assertSame( array( 'EG', 'SA' ), Settings::get( 'region_countries' ) );
		qr_test_set_option( Settings::OPTION, array(
			'contact_rule'     => 'either',
			'region_countries' => 'EG',
		) );
		$this->assertSame( array( 'EG' ), Settings::get( 'region_countries' ) );
	}

	public function test_sanitize_cleans_and_bounds_values(): void {
		$out = Settings::sanitize(
			array(
				'recipients'       => "sales@example.test, bad-address\ninfo@example.test",
				'retention_months' => '0',
				'rate_limit'       => '500',
				'region_country'   => 'eg',
				'hide_prices'      => '1',
				'thanks_text'      => '<b>Thanks</b> %name%',
				'contact_rule'     => 'email',
				'fields'           => array(
					array(
						'key'      => 'email',
						'show'     => '1',
						'required' => '1',
					),
					array(
						'key'   => 'extra',
						'type'  => 'text',
						'label' => '<i>Extra</i>',
						'show'  => '1',
					),
				),
			)
		);
		$this->assertSame( array( 'sales@example.test', 'info@example.test' ), $out['recipients'] );
		$this->assertSame( 1, $out['retention_months'] );
		$this->assertSame( 100, $out['rate_limit'] );
		$this->assertSame( 'EG', $out['region_country'] );
		$this->assertTrue( $out['hide_prices'] );
		$this->assertFalse( $out['redirect_cart'] );
		$this->assertSame( 'Thanks %name%', $out['thanks_text'] );
		$this->assertSame( 'email', $out['contact_rule'] );
		$by = $this->by_key( $out['fields'] );
		$this->assertTrue( $by['email']['required'] );
		$this->assertSame( 'Extra', $by['extra']['label'] );
		$this->assertFalse( $by['extra']['system'] );
		$this->assertSame( array( 'email', 'extra', 'name', 'phone', 'company', 'region', 'message' ), array_column( $out['fields'], 'key' ), 'missing system fields come back at the end' );
	}

	public function test_sanitize_passes_the_posted_field_list_through_normalize(): void {
		$posted = array(
			array( 'key' => 'name' ),
			array( 'key' => 'token', 'label' => 'Reserved' ),
			array( 'key' => 'pick', 'type' => 'select', 'label' => 'Pick' ),
			array( 'key' => 'ok', 'type' => 'banana', 'label' => 'Fine', 'help' => str_repeat( 'h', 300 ) ),
			'not a definition',
		);
		$out    = Settings::sanitize( array( 'fields' => $posted ) );
		$this->assertSame( Fields::normalize( $posted ), $out['fields'] );
		$this->assertSame( array( 'name', 'ok', 'phone', 'email', 'company', 'region', 'message' ), array_column( $out['fields'], 'key' ) );
		$this->assertSame( 'text', $this->by_key( $out['fields'] )['ok']['type'] );
		$this->assertSame( 200, strlen( $this->by_key( $out['fields'] )['ok']['help'] ) );
	}

	public function test_sanitize_without_fields_and_nothing_stored_gives_the_default_list(): void {
		$this->assertSame( Fields::defaults(), Settings::sanitize( array() )['fields'] );
		$this->assertSame( Fields::defaults(), Settings::sanitize( array( 'fields' => 'junk' ) )['fields'] );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'contact_cases' )]
	public function test_contact_fields_are_forced_shown_by_the_rule( string $rule, bool $phone_in, bool $email_in, bool $phone_out, bool $email_out ): void {
		$out = Settings::sanitize(
			array(
				'contact_rule' => $rule,
				'fields'       => array(
					array(
						'key'  => 'phone',
						'show' => $phone_in ? '1' : '0',
					),
					array(
						'key'  => 'email',
						'show' => $email_in ? '1' : '0',
					),
				),
			)
		);
		$by = $this->by_key( $out['fields'] );
		$this->assertSame( $phone_out, $by['phone']['show'], 'phone shown' );
		$this->assertSame( $email_out, $by['email']['show'], 'email shown' );
	}

	public static function contact_cases(): array {
		return array(
			'either, both hidden shows phone' => array( 'either', false, false, true, false ),
			'either, phone only'              => array( 'either', true, false, true, false ),
			'either, email only'              => array( 'either', false, true, false, true ),
			'either, both shown'              => array( 'either', true, true, true, true ),
			'phone rule shows phone'          => array( 'phone', false, false, true, false ),
			'phone rule keeps a shown email'  => array( 'phone', false, true, true, true ),
			'phone rule keeps email hidden'   => array( 'phone', true, false, true, false ),
			'email rule shows email'          => array( 'email', true, false, true, true ),
			'email rule keeps phone hidden'   => array( 'email', false, false, false, true ),
			'both rule shows both'            => array( 'both', false, false, true, true ),
		);
	}

	public function test_email_rule_forces_email_shown(): void {
		$out = Settings::sanitize(
			array(
				'contact_rule' => 'email',
				'fields'       => array(
					array(
						'key'  => 'email',
						'show' => '0',
					),
				),
			)
		);
		$this->assertTrue( $this->by_key( $out['fields'] )['email']['show'] );
	}

	public function test_either_with_both_contacts_hidden_shows_phone(): void {
		$out = Settings::sanitize(
			array(
				'contact_rule' => 'either',
				'fields'       => array(
					array(
						'key'  => 'phone',
						'show' => '0',
					),
					array(
						'key'  => 'email',
						'show' => '0',
					),
				),
			)
		);
		$by = $this->by_key( $out['fields'] );
		$this->assertTrue( $by['phone']['show'] );
		$this->assertFalse( $by['email']['show'] );
	}

	public function test_a_stored_option_that_hides_a_mandatory_contact_is_shown_on_read(): void {
		qr_test_set_option( Settings::OPTION, array(
			'contact_rule' => 'both',
			'fields'       => array(
				array(
					'key'  => 'email',
					'show' => false,
				),
			),
		) );
		$this->assertTrue( $this->by_key( Settings::get( 'fields' ) )['email']['show'] );
	}

	public function test_sanitize_accepts_recipients_as_array(): void {
		$out = Settings::sanitize( array( 'recipients' => array( 'a@example.test', 'nope' ) ) );
		$this->assertSame( array( 'a@example.test' ), $out['recipients'] );
	}

	public function test_consent_text_default_uses_site_name(): void {
		$this->assertStringContainsString( 'Test Site may use these details', Settings::consent_text() );
	}

	public function test_recipients_fall_back_to_admin_email_when_none_is_valid(): void {
		$out = Settings::sanitize( array( 'recipients' => 'sales@@example, not-an-address' ) );
		$this->assertSame( array( 'admin@example.test' ), $out['recipients'] );
		qr_test_set_option( Settings::OPTION, array( 'recipients' => array() ) );
		$this->assertSame( array( 'admin@example.test' ), Settings::get( 'recipients' ) );
	}

	/** What the 0.1 settings form posts: no field list, switches keyed by field name, only ticked boxes. */
	private function old_form_post(): array {
		return array(
			'recipients'            => "sales@example.com\nlab@example.com",
			'customer_confirmation' => '1',
			'subject_prefix'        => 'Quotes',
			'quote_page'            => '11',
			'privacy_page'          => '12',
			'region_label'          => 'State',
			'region_country'        => 'US',
			'region_outside'        => '1',
			'fields'                => array(
				'email'   => array( 'show' => '1' ),
				'company' => array( 'show' => '1' ),
				'region'  => array(
					'show'     => '1',
					'required' => '1',
				),
			),
			'label_company'         => 'Company or organisation',
			'label_details'         => 'Project details',
			'consent_text'          => '',
			'button_label'          => 'Add to quote',
			'retention_months'      => '24',
			'store_client'          => '1',
			'rate_limit'            => '5',
		);
	}

	public function test_a_post_from_the_old_form_keeps_the_field_list_and_the_new_settings(): void {
		qr_test_set_option( Settings::OPTION, array(
			'contact_rule'     => 'phone',
			'require_consent'  => false,
			'region_mode'      => 'choose',
			'region_countries' => array( 'EG', 'SA' ),
			'fields'           => array(
				array( 'key' => 'name' ),
				array( 'key' => 'material', 'label' => 'Material', 'type' => 'text' ),
				array( 'key' => 'company', 'label' => 'Organisation', 'show' => false ),
			),
		) );
		$before = Settings::all();
		$out    = Settings::sanitize( $this->old_form_post() );
		$this->assertSame( $before['fields'], $out['fields'] );
		$this->assertSame( array( 'name', 'material', 'company', 'phone', 'email', 'region', 'message' ), array_column( $out['fields'], 'key' ) );
		$this->assertSame( 'phone', $out['contact_rule'] );
		$this->assertFalse( $out['require_consent'] );
		$this->assertSame( 'choose', $out['region_mode'] );
		$this->assertSame( array( 'EG', 'SA' ), $out['region_countries'] );
		$this->assertSame( array( 'sales@example.com', 'lab@example.com' ), $out['recipients'], 'what the old form does post is saved' );
		$this->assertFalse( $out['hide_prices'], 'a box of the old form that is not ticked is off' );
	}

	public function test_a_post_from_the_old_form_over_stored_0_1_settings_keeps_the_converted_form(): void {
		qr_test_set_option( Settings::OPTION, require __DIR__ . '/fixtures/settings-0.1-sample.php' );
		$before = Settings::all();
		$this->assertContains( 'details', array_column( $before['fields'], 'key' ) );
		$out = Settings::sanitize( $this->old_form_post() );
		$this->assertSame( $before['fields'], $out['fields'] );
		$this->assertSame( $before['contact_rule'], $out['contact_rule'] );
		$this->assertTrue( $out['require_consent'] );
		$this->assertSame( 'single', $out['region_mode'] );
	}

	public function test_a_post_without_a_field_list_keeps_the_stored_one(): void {
		qr_test_set_option( Settings::OPTION, array(
			'contact_rule' => 'either',
			'fields'       => array( array( 'key' => 'material', 'label' => 'Material' ) ),
		) );
		$before = Settings::get( 'fields' );
		foreach ( array( array(), array( 'fields' => 'junk' ), array( 'fields' => array( 'email' => array( 'show' => '1' ) ) ), array( '_form' => Settings::FORM ) ) as $post ) {
			$this->assertSame( $before, Settings::sanitize( $post )['fields'] );
		}
		$this->assertSame( array( 'name', 'phone', 'email', 'company', 'region', 'message' ), array_column( Settings::sanitize( array( 'fields' => array() ) )['fields'], 'key' ), 'an empty list is a list: no custom fields' );
	}

	public function test_the_current_form_means_unticked_and_none_selected_when_a_key_is_absent(): void {
		qr_test_set_option( Settings::OPTION, array(
			'contact_rule'     => 'both',
			'require_consent'  => true,
			'region_mode'      => 'choose',
			'region_countries' => array( 'EG' ),
		) );
		$out = Settings::sanitize( array( '_form' => Settings::FORM ) );
		$this->assertFalse( $out['require_consent'] );
		$this->assertSame( array(), $out['region_countries'] );
		$this->assertSame( 'both', $out['contact_rule'], 'a radio group that is not posted keeps its value' );
		$this->assertSame( 'choose', $out['region_mode'] );
		$this->assertArrayNotHasKey( '_form', $out );

		$out = Settings::sanitize( array( '_form' => Settings::FORM, 'require_consent' => '0', 'region_countries' => array( '', 'sa' ) ) );
		$this->assertFalse( $out['require_consent'] );
		$this->assertSame( array( 'SA' ), $out['region_countries'] );
	}

	public function test_form_rows_are_sorted_and_cleaned_before_they_are_normalized(): void {
		qr_test_set_option( Settings::OPTION, array(
			'contact_rule' => 'either',
			'fields'       => array( array( 'key' => 'material', 'label' => 'Material' ) ),
		) );
		$out = Settings::sanitize(
			array(
				'_form'  => Settings::FORM,
				'fields' => array(
					array( 'id' => 'name', 'key' => 'name', 'label' => 'Your name', 'order' => '2' ),
					array( 'id' => 'material', 'key' => 'harvest', 'label' => 'Material', 'type' => 'text', 'required' => '0', 'show' => '1', 'order' => '1' ),
					array( 'key' => 'size', 'label' => 'Size', 'type' => 'select', 'options' => "s | Small\nl | Large", 'required' => '1', 'show' => '1', 'order' => '3' ),
					array( 'key' => '', 'label' => '', 'type' => 'text', 'order' => '4' ),
				),
			)
		);
		$this->assertSame( array( 'material', 'name', 'size', 'phone', 'email', 'company', 'region', 'message' ), array_column( $out['fields'], 'key' ) );
		$by = $this->by_key( $out['fields'] );
		$this->assertSame( 'Your name', $by['name']['label'] );
		$this->assertTrue( $by['size']['required'] );
		$this->assertSame( array( 's', 'l' ), array_column( $by['size']['options'], 'value' ) );
		$this->assertSame( $out, Settings::sanitize( $out ), 'saving what was saved changes nothing' );
	}
}
