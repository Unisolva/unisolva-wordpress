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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quote_Requests\Fields;
use Quote_Requests\Validator;

final class ValidatorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['qr_test_filters'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['qr_test_filters'] = array();
	}

	/** Calls the region callable received, as [ country, value ] pairs. */
	private array $region_calls = array();

	/**
	 * Definitions: the six defaults with changes applied by key, then custom definitions.
	 *
	 * @param array<string,array> $change Partial changes by key, for example 'region' => [ 'required' => true ].
	 * @param array<int,array>    $custom Complete custom definitions.
	 */
	private function defs( array $change = array(), array $custom = array() ): array {
		$out = array();
		foreach ( Fields::defaults() as $def ) {
			$out[] = array_merge( $def, $change[ $def['key'] ] ?? array() );
		}
		return array_merge( $out, $custom );
	}

	private function custom( string $key, string $type, array $more = array() ): array {
		return array_merge(
			array(
				'key'      => $key,
				'type'     => $type,
				'label'    => ucfirst( $key ),
				'help'     => '',
				'required' => false,
				'show'     => true,
				'options'  => array(),
				'system'   => false,
			),
			$more
		);
	}

	private function sizes(): array {
		return array(
			array( 'value' => 'small', 'label' => 'Small size' ),
			array( 'value' => 'large', 'label' => 'Large size' ),
		);
	}

	private function cfg( array $over = array() ): array {
		$this->region_calls = array();
		return array_merge(
			array(
				'fields'          => $this->defs(),
				'contact_rule'    => 'either',
				'require_consent' => true,
				'has_items'       => true,
				'region'          => function ( string $country, string $value ): ?array {
					$this->region_calls[] = array( $country, $value );
					$codes                = array( 'Cairo' => 'C', 'Giza' => 'G' );
					if ( '' === $value ) {
						return array( 'country' => $country, 'code' => '', 'name' => '' );
					}
					return isset( $codes[ $value ] ) ? array( 'country' => 'EG', 'code' => $codes[ $value ], 'name' => $value ) : null;
				},
			),
			$over
		);
	}

	private function valid(): array {
		return array( 'name' => 'Ahmed Ali', 'phone' => '+1 555 010-0199', 'consent' => '1' );
	}

	public function test_valid_minimum_passes_and_normalises_phone(): void {
		$r = Validator::contact( $this->valid(), $this->cfg() );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( '+15550100199', $r['data']['phone'] );
		$this->assertTrue( $r['data']['consent'] );
		$this->assertSame( array(), $r['data']['custom'] );
	}

	public function test_data_has_the_documented_keys(): void {
		$r = Validator::contact( $this->valid(), $this->cfg() );
		$this->assertSame(
			array( 'name', 'phone', 'email', 'company', 'message', 'region_country', 'region', 'region_name', 'consent', 'custom' ),
			array_keys( $r['data'] )
		);
	}

	public function test_arabic_indic_digits_in_phone_are_accepted(): void {
		$raw          = $this->valid();
		$raw['phone'] = '٥٥٥٠١٠٠١٩٩';
		$r            = Validator::contact( $raw, $this->cfg() );
		$this->assertSame( '5550100199', $r['data']['phone'] );
		$this->assertArrayNotHasKey( 'phone', $r['errors'] );
	}

	public function test_required_name_contact_and_consent(): void {
		$r = Validator::contact( array(), $this->cfg() );
		$this->assertSame( 'Please enter your name.', $r['errors']['name'] );
		$this->assertSame( 'Please enter a phone number or an email address.', $r['errors']['phone'] );
		$this->assertSame( 'Please tick the box so we can reply to your request.', $r['errors']['consent'] );
		$this->assertArrayNotHasKey( 'region', $r['errors'] );
	}

	public function test_phone_bounds(): void {
		foreach ( array( '123456', '+123456789012345678901', '12ab3456789', '++15550100' ) as $bad ) {
			$raw          = $this->valid();
			$raw['phone'] = $bad;
			$this->assertArrayHasKey( 'phone', Validator::contact( $raw, $this->cfg() )['errors'], $bad );
		}
		foreach ( array( '1234567', '(02) 1234-5678', '+12345678901234567890' ) as $good ) {
			$raw          = $this->valid();
			$raw['phone'] = $good;
			$this->assertArrayNotHasKey( 'phone', Validator::contact( $raw, $this->cfg() )['errors'], $good );
		}
	}

	public function test_bad_phone_message_keeps_its_text(): void {
		$raw          = $this->valid();
		$raw['phone'] = '123';
		$this->assertSame( 'Please enter a phone number of 7 to 20 digits.', Validator::contact( $raw, $this->cfg() )['errors']['phone'] );
	}

	public function test_email_optional_but_checked_when_given(): void {
		$raw          = $this->valid();
		$raw['email'] = 'not-an-email';
		$this->assertSame( 'Please enter a valid email address.', Validator::contact( $raw, $this->cfg() )['errors']['email'] );
		$raw['email'] = 'buyer@example.com';
		$r            = Validator::contact( $raw, $this->cfg() );
		$this->assertSame( 'buyer@example.com', $r['data']['email'] );
		$this->assertArrayNotHasKey( 'email', $r['errors'] );
	}

	public function test_email_required_when_the_rule_says_so(): void {
		foreach ( array( 'email', 'both' ) as $rule ) {
			$errors = Validator::contact( $this->valid(), $this->cfg( array( 'contact_rule' => $rule ) ) )['errors'];
			$this->assertSame( 'Please enter your email address.', $errors['email'], $rule );
		}
	}

	public function test_definition_required_flag_is_ignored_for_phone_and_email(): void {
		$fields = $this->defs( array( 'email' => array( 'required' => true ), 'phone' => array( 'required' => true ) ) );
		$raw    = array( 'name' => 'Ahmed Ali', 'consent' => '1' );
		$r      = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields, 'contact_rule' => 'email' ) ) );
		$this->assertSame( array( 'email' ), array_keys( $r['errors'] ) );
		$raw['email'] = 'buyer@example.com';
		$r            = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields, 'contact_rule' => 'email' ) ) );
		$this->assertSame( array(), $r['errors'] );
	}

	/** @param string[] $error_keys */
	#[DataProvider( 'rules' )]
	public function test_contact_rule( string $rule, string $phone, string $email, array $error_keys ): void {
		$raw = array( 'name' => 'Ahmed Ali', 'consent' => '1' );
		if ( '' !== $phone ) {
			$raw['phone'] = $phone;
		}
		if ( '' !== $email ) {
			$raw['email'] = $email;
		}
		$r = Validator::contact( $raw, $this->cfg( array( 'contact_rule' => $rule ) ) );
		$this->assertSame( $error_keys, array_keys( $r['errors'] ) );
	}

	public static function rules(): array {
		return array(
			'either with none'       => array( 'either', '', '', array( 'phone' ) ),
			'either with email only' => array( 'either', '', 'buyer@example.com', array() ),
			'phone with email only'  => array( 'phone', '', 'buyer@example.com', array( 'phone' ) ),
			'email with phone only'  => array( 'email', '+15550100199', '', array( 'email' ) ),
			'both with phone only'   => array( 'both', '+15550100199', '', array( 'email' ) ),
			'both with both'         => array( 'both', '+15550100199', 'buyer@example.com', array() ),
		);
	}

	public function test_either_rule_puts_the_error_on_email_when_phone_is_hidden(): void {
		$fields = $this->defs( array( 'phone' => array( 'show' => false ) ) );
		$r      = Validator::contact( array( 'name' => 'Ahmed Ali', 'consent' => '1' ), $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( array( 'email' ), array_keys( $r['errors'] ) );
		$this->assertSame( 'Please enter a phone number or an email address.', $r['errors']['email'] );
	}

	public function test_either_rule_still_checks_a_phone_that_is_given(): void {
		$raw          = $this->valid();
		$raw['phone'] = '123';
		$raw['email'] = 'buyer@example.com';
		$r            = Validator::contact( $raw, $this->cfg() );
		$this->assertSame( array( 'phone' ), array_keys( $r['errors'] ) );
	}

	public function test_hidden_system_field_is_ignored_even_if_sent(): void {
		$raw            = $this->valid();
		$raw['company'] = 'Should not be kept';
		$raw['email']   = 'buyer@example.com';
		$fields         = $this->defs( array( 'company' => array( 'show' => false ), 'email' => array( 'show' => false ) ) );
		$r              = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( '', $r['data']['company'] );
		$this->assertSame( '', $r['data']['email'] );
	}

	public function test_hidden_field_value_is_ignored(): void {
		$raw            = $this->valid();
		$raw['company'] = 'Should not be kept';
		$raw['secret']  = 'nor this';
		$raw['plan']    = 'large';
		// A field flagged hidden inside the list, and a field that is not in the list at all.
		$fields = $this->defs(
			array( 'company' => array( 'required' => true, 'show' => false ) ),
			array( $this->custom( 'secret', 'text', array( 'required' => true, 'show' => false ) ) )
		);
		$r = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( '', $r['data']['company'] );
		$this->assertSame( array(), $r['data']['custom'] );
	}

	public function test_company_required_from_its_definition(): void {
		$fields = $this->defs( array( 'company' => array( 'required' => true ) ) );
		$r      = Validator::contact( $this->valid(), $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( 'Please fill in this field.', $r['errors']['company'] );
		$raw            = $this->valid();
		$raw['company'] = 'Acme';
		$this->assertSame( 'Acme', Validator::contact( $raw, $this->cfg( array( 'fields' => $fields ) ) )['data']['company'] );
	}

	public function test_region_is_resolved_through_the_callable(): void {
		$raw                   = $this->valid();
		$raw['region']         = 'Cairo';
		$raw['region_country'] = 'eg';
		$cfg                   = $this->cfg();
		$r                     = Validator::contact( $raw, $cfg );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( array( array( 'EG', 'Cairo' ) ), $this->region_calls );
		$this->assertSame( 'EG', $r['data']['region_country'] );
		$this->assertSame( 'C', $r['data']['region'] );
		$this->assertSame( 'Cairo', $r['data']['region_name'] );
	}

	public function test_region_country_is_empty_when_not_posted(): void {
		$raw           = $this->valid();
		$raw['region'] = 'Giza';
		$cfg = $this->cfg();
		Validator::contact( $raw, $cfg );
		$this->assertSame( array( array( '', 'Giza' ) ), $this->region_calls );
	}

	public function test_region_the_callable_rejects_is_an_error_and_clears_the_values(): void {
		$raw           = $this->valid();
		$raw['region'] = 'Atlantis';
		$r             = Validator::contact( $raw, $this->cfg() );
		$this->assertSame( 'Please choose a region from the list.', $r['errors']['region'] );
		$this->assertSame( '', $r['data']['region'] );
		$this->assertSame( '', $r['data']['region_name'] );
		$this->assertSame( '', $r['data']['region_country'] );
	}

	public function test_region_required_and_empty(): void {
		$fields = $this->defs( array( 'region' => array( 'required' => true ) ) );
		$r      = Validator::contact( $this->valid(), $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( 'Please choose your region.', $r['errors']['region'] );
	}

	public function test_region_optional_when_not_required(): void {
		$r = Validator::contact( $this->valid(), $this->cfg() );
		$this->assertArrayNotHasKey( 'region', $r['errors'] );
		$this->assertSame( '', $r['data']['region_name'] );
	}

	public function test_region_callable_is_not_called_when_the_field_is_not_shown(): void {
		$raw                   = $this->valid();
		$raw['region']         = 'Cairo';
		$raw['region_country'] = 'EG';
		$fields                = array_values( array_filter( $this->defs(), static fn( array $d ): bool => 'region' !== $d['key'] ) );
		$cfg                   = $this->cfg( array( 'fields' => $fields ) );
		$r                     = Validator::contact( $raw, $cfg );
		$this->assertSame( array(), $this->region_calls );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( '', $r['data']['region_country'] );
		$this->assertSame( '', $r['data']['region'] );
		$this->assertSame( '', $r['data']['region_name'] );
	}

	public function test_message_required_only_without_items(): void {
		$this->assertArrayNotHasKey( 'message', Validator::contact( $this->valid(), $this->cfg() )['errors'] );
		$r = Validator::contact( $this->valid(), $this->cfg( array( 'has_items' => false ) ) );
		$this->assertSame( 'Your quote list is empty. Please describe what you need.', $r['errors']['message'] );
		$raw            = $this->valid();
		$raw['message'] = 'Need 200 units of a sample item';
		$this->assertArrayNotHasKey( 'message', Validator::contact( $raw, $this->cfg( array( 'has_items' => false ) ) )['errors'] );
	}

	public function test_long_and_arabic_text_is_trimmed_not_broken(): void {
		$raw            = $this->valid();
		$raw['name']    = str_repeat( 'محمد ', 40 );
		$raw['message'] = str_repeat( 'نص ', 900 ) . '<script>x</script>';
		$d              = Validator::contact( $raw, $this->cfg() )['data'];
		$this->assertLessThanOrEqual( 100, mb_strlen( $d['name'] ) );
		$this->assertLessThanOrEqual( 2000, mb_strlen( $d['message'] ) );
		$this->assertStringNotContainsString( '<script>', $d['message'] );
		$this->assertTrue( mb_check_encoding( $d['message'], 'UTF-8' ) );
	}

	public function test_consent_values(): void {
		foreach ( array( '1', 'yes', 'on', 'true', true ) as $ok ) {
			$raw            = $this->valid();
			$raw['consent'] = $ok;
			$this->assertArrayNotHasKey( 'consent', Validator::contact( $raw, $this->cfg() )['errors'] );
		}
		$raw            = $this->valid();
		$raw['consent'] = '0';
		$this->assertArrayHasKey( 'consent', Validator::contact( $raw, $this->cfg() )['errors'] );
	}

	public function test_consent_not_required_when_setting_is_off(): void {
		$raw = $this->valid();
		unset( $raw['consent'] );
		$r = Validator::contact( $raw, $this->cfg( array( 'require_consent' => false ) ) );
		$this->assertSame( array(), $r['errors'] );
		$this->assertFalse( $r['data']['consent'] );
	}

	public function test_consent_posted_to_a_form_without_a_consent_box_is_not_recorded(): void {
		foreach ( array( '1', 'yes', 'on', 'true', true, 1 ) as $tick ) {
			$raw            = $this->valid();
			$raw['consent'] = $tick;
			$r              = Validator::contact( $raw, $this->cfg( array( 'require_consent' => false ) ) );
			$this->assertSame( array(), $r['errors'] );
			$this->assertFalse( $r['data']['consent'], 'no box was shown, so no consent was given: ' . var_export( $tick, true ) );
		}
		$raw            = $this->valid();
		$raw['consent'] = '1';
		$this->assertFalse( Validator::contact( $raw, $this->cfg( array( 'require_consent' => null ) ) )['data']['consent'], 'a missing switch counts as off' );
		$this->assertTrue( Validator::contact( $raw, $this->cfg() )['data']['consent'], 'with the box on, the tick is recorded' );
	}

	public function test_the_limits_hold_no_leftover_of_the_old_details_field(): void {
		$this->assertSame( array( 'name', 'phone', 'email', 'company', 'message' ), array_keys( Validator::LIMITS ) );
	}

	public function test_custom_select_rejects_a_value_not_in_options(): void {
		$fields = $this->defs( array(), array( $this->custom( 'size', 'select', array( 'options' => $this->sizes() ) ) ) );
		$cfg    = $this->cfg( array( 'fields' => $fields ) );

		$raw         = $this->valid();
		$raw['size'] = 'Large size'; // The label, not the value.
		$r           = Validator::contact( $raw, $cfg );
		$this->assertSame( 'Please choose an option from the list.', $r['errors']['size'] );
		$this->assertSame( array(), $r['data']['custom'] );

		$raw['size'] = 'large';
		$r           = Validator::contact( $raw, $cfg );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( 'large', $r['data']['custom'][0]['value'] );

		unset( $raw['size'] );
		$this->assertSame( array(), Validator::contact( $raw, $cfg )['errors'], 'An optional select may stay empty.' );
	}

	public function test_custom_required_select_must_be_chosen(): void {
		$fields = $this->defs( array(), array( $this->custom( 'size', 'select', array( 'options' => $this->sizes(), 'required' => true ) ) ) );
		$r      = Validator::contact( $this->valid(), $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( 'Please choose an option.', $r['errors']['size'] );
	}

	public function test_custom_required_checkbox_must_be_ticked(): void {
		$fields = $this->defs( array(), array( $this->custom( 'terms', 'checkbox', array( 'required' => true ) ) ) );
		$cfg    = $this->cfg( array( 'fields' => $fields ) );
		foreach ( array( null, '', '0', 'no' ) as $posted ) {
			$raw = $this->valid();
			if ( null !== $posted ) {
				$raw['terms'] = $posted;
			}
			$this->assertSame( 'Please tick this box.', Validator::contact( $raw, $cfg )['errors']['terms'] ?? '', var_export( $posted, true ) );
		}
		foreach ( array( '1', 'on' ) as $posted ) {
			$raw          = $this->valid();
			$raw['terms'] = $posted;
			$r            = Validator::contact( $raw, $cfg );
			$this->assertSame( array(), $r['errors'] );
			$this->assertSame( '1', $r['data']['custom'][0]['value'] );
		}
	}

	public function test_custom_optional_checkbox_unticked_is_not_stored(): void {
		$fields = $this->defs( array(), array( $this->custom( 'terms', 'checkbox' ) ) );
		$r      = Validator::contact( $this->valid(), $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( array(), $r['data']['custom'] );
	}

	public function test_custom_number_rejects_text(): void {
		$fields = $this->defs( array(), array( $this->custom( 'qty', 'number' ) ) );
		$cfg    = $this->cfg( array( 'fields' => $fields ) );
		foreach ( array( 'abc', '12abc', '1,5', '--3', str_repeat( '9', 30 ) . 'abc' ) as $bad ) {
			$raw        = $this->valid();
			$raw['qty'] = $bad;
			$this->assertSame( 'Please enter a number.', Validator::contact( $raw, $cfg )['errors']['qty'] ?? '', $bad );
		}
		foreach ( array( '12' => '12', ' 7 ' => '7', '-3.5' => '-3.5', '0.25' => '0.25' ) as $posted => $kept ) {
			$raw        = $this->valid();
			$raw['qty'] = (string) $posted;
			$r          = Validator::contact( $raw, $cfg );
			$this->assertSame( array(), $r['errors'], (string) $posted );
			$this->assertSame( $kept, $r['data']['custom'][0]['value'] );
		}
	}

	public function test_custom_number_accepts_arabic_indic_and_eastern_arabic_indic_digits(): void {
		$fields = $this->defs( array(), array( $this->custom( 'qty', 'number' ) ) );
		$cfg    = $this->cfg( array( 'fields' => $fields ) );
		foreach ( array( '١٢٣' => '123', '۴۵۶' => '456', '-٣.٥' => '-3.5', '١2۳' => '123', ' ٠ ' => '0' ) as $posted => $kept ) {
			$raw        = $this->valid();
			$raw['qty'] = (string) $posted;
			$r          = Validator::contact( $raw, $cfg );
			$this->assertSame( array(), $r['errors'], (string) $posted );
			$this->assertSame( $kept, $r['data']['custom'][0]['value'], 'stored with ASCII digits' );
		}
		foreach ( array( 'ثلاثة', '١٢abc', str_repeat( '٩', 31 ) ) as $bad ) {
			$raw        = $this->valid();
			$raw['qty'] = $bad;
			$this->assertSame( 'Please enter a number.', Validator::contact( $raw, $cfg )['errors']['qty'] ?? '', $bad );
		}
	}

	public function test_the_digit_mapping_is_the_one_the_phone_cleaner_uses(): void {
		$this->assertSame( '01234567890123456789', Validator::ascii_digits( '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹' ) );
		$this->assertSame( 'Area 51', Validator::ascii_digits( 'Area ٥١' ) );
		$this->assertSame( '+201001234567', Validator::clean_phone( '+٢٠ ١٠٠ ١٢٣ ٤٥٦٧' ) );
	}

	/** A form-encoded post can carry any bytes. Text that is not valid UTF-8 must be refused, never crash the request. */
	public function test_a_phone_that_is_not_valid_utf8_is_refused_without_an_error_in_php(): void {
		$bad = "\xFF0100555";
		$this->assertIsString( Validator::clean_phone( $bad ) );
		$this->assertIsString( Validator::clean_phone( "+1 555 \xC3\x28 0100" ) );
		$this->assertSame( '+15550100199', Validator::clean_phone( '+1 555 010-0199' ), 'valid text is cleaned as before' );

		$raw          = $this->valid();
		$raw['phone'] = $bad;
		$r            = Validator::contact( $raw, $this->cfg() );
		$this->assertSame( 'Please enter a phone number of 7 to 20 digits.', $r['errors']['phone'] ?? '' );

		$raw['email'] = 'visitor@example.com';
		$r            = Validator::contact( $raw, $this->cfg() );
		$this->assertArrayHasKey( 'phone', $r['errors'], 'not dropped in silence when another contact is given' );

		$fields     = $this->defs( array(), array( $this->custom( 'mobile', 'tel' ) ) );
		$raw        = $this->valid();
		$raw['mobile'] = $bad;
		$r          = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( 'Please enter a phone number of 7 to 20 digits.', $r['errors']['mobile'] ?? '', 'a custom phone field is checked the same way' );
	}

	public function test_custom_text_required_and_optional(): void {
		$fields = $this->defs( array(), array( $this->custom( 'ref', 'text', array( 'required' => true ) ), $this->custom( 'note', 'text' ) ) );
		$cfg    = $this->cfg( array( 'fields' => $fields ) );
		$r      = Validator::contact( $this->valid(), $cfg );
		$this->assertSame( array( 'ref' ), array_keys( $r['errors'] ) );
		$this->assertSame( 'Please fill in this field.', $r['errors']['ref'] );
		$this->assertSame( array(), $r['data']['custom'] );
	}

	public function test_custom_email_and_tel_use_the_same_rules_as_the_system_fields(): void {
		$fields = $this->defs( array(), array( $this->custom( 'billing_email', 'email' ), $this->custom( 'fax', 'tel' ) ) );
		$cfg    = $this->cfg( array( 'fields' => $fields ) );
		$raw    = array_merge( $this->valid(), array( 'billing_email' => 'nope', 'fax' => '12' ) );
		$r      = Validator::contact( $raw, $cfg );
		$this->assertSame( array( 'billing_email', 'fax' ), array_keys( $r['errors'] ) );
		$raw = array_merge( $this->valid(), array( 'billing_email' => 'ap@example.com', 'fax' => '+1 555 012 3456' ) );
		$r   = Validator::contact( $raw, $cfg );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( array( 'ap@example.com', '+15550123456' ), array_column( $r['data']['custom'], 'value' ) );
	}

	public function test_custom_values_carry_label_and_type(): void {
		$fields = $this->defs( array(), array( $this->custom( 'details', 'text', array( 'label' => 'Item and size' ) ) ) );
		$raw    = array_merge( $this->valid(), array( 'details' => 'Blue, 5 units' ) );
		$r      = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame(
			array( array( 'key' => 'details', 'label' => 'Item and size', 'type' => 'text', 'value' => 'Blue, 5 units' ) ),
			$r['data']['custom']
		);
	}

	public function test_custom_values_keep_definition_order_and_skip_empty_ones(): void {
		$fields = $this->defs(
			array(),
			array(
				$this->custom( 'zeta', 'text' ),
				$this->custom( 'empty', 'text' ),
				$this->custom( 'alpha', 'textarea' ),
			)
		);
		$raw    = array_merge( $this->valid(), array( 'alpha' => "Line one\nLine two", 'zeta' => 'Z' ) );
		$r      = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( array( 'zeta', 'alpha' ), array_column( $r['data']['custom'], 'key' ) );
		$this->assertSame( array( 'text', 'textarea' ), array_column( $r['data']['custom'], 'type' ) );
		$this->assertSame( "Line one\nLine two", $r['data']['custom'][1]['value'] );
	}

	public function test_posted_keys_without_a_definition_are_ignored(): void {
		$fields = $this->defs( array(), array( $this->custom( 'extra', 'text' ) ) );
		$raw    = array_merge( $this->valid(), array( 'extra' => 'x', 'custom' => 'y' ) );
		$r      = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( array( 'extra' ), array_column( $r['data']['custom'], 'key' ) );
	}

	public function test_overlong_values_are_cut_not_rejected(): void {
		$fields = $this->defs(
			array(),
			array(
				$this->custom( 'line', 'text' ),
				$this->custom( 'long', 'textarea' ),
			)
		);
		$raw    = array_merge(
			$this->valid(),
			array(
				'name'    => str_repeat( 'n', 300 ),
				'company' => str_repeat( 'c', 300 ),
				'message' => str_repeat( 'm', 3000 ),
				'line'    => str_repeat( 'l', 300 ),
				'long'    => str_repeat( 't', 3000 ),
			)
		);
		$r      = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( 100, mb_strlen( $r['data']['name'] ) );
		$this->assertSame( 150, mb_strlen( $r['data']['company'] ) );
		$this->assertSame( 2000, mb_strlen( $r['data']['message'] ) );
		$this->assertSame( array( 200, 2000 ), array_map( 'strlen', array_column( $r['data']['custom'], 'value' ) ) );
	}

	public function test_overlong_number_is_rejected_never_cut(): void {
		$fields = $this->defs( array(), array( $this->custom( 'amount', 'number' ) ) );
		$cfg    = $this->cfg( array( 'fields' => $fields ) );

		$raw           = $this->valid();
		$raw['amount'] = str_repeat( '9', 30 );
		$r             = Validator::contact( $raw, $cfg );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( str_repeat( '9', 30 ), $r['data']['custom'][0]['value'] );

		$raw['amount'] = str_repeat( '9', 31 );
		$this->assertSame( 'Please enter a number.', Validator::contact( $raw, $cfg )['errors']['amount'] );

		$raw['amount'] = str_repeat( '9', 5000 );
		$r             = Validator::contact( $raw, $cfg );
		$this->assertSame( 'Please enter a number.', $r['errors']['amount'] );
		$this->assertLessThanOrEqual( 30, strlen( $r['data']['custom'][0]['value'] ), 'A rejected value is bounded in the data.' );
	}

	public function test_overlong_phone_is_rejected_and_bounded_in_the_data(): void {
		$raw          = $this->valid();
		$raw['phone'] = str_repeat( '1', 5000 );
		$r            = Validator::contact( $raw, $this->cfg() );
		$this->assertSame( 'Please enter a phone number of 7 to 20 digits.', $r['errors']['phone'] );
		$this->assertLessThanOrEqual( 40, strlen( $r['data']['phone'] ) );

		$fields       = $this->defs( array(), array( $this->custom( 'fax', 'tel' ) ) );
		$raw          = array_merge( $this->valid(), array( 'fax' => '+' . str_repeat( '2', 5000 ) ) );
		$r            = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertArrayHasKey( 'fax', $r['errors'] );
		$this->assertLessThanOrEqual( 40, strlen( $r['data']['custom'][0]['value'] ) );
	}

	public function test_select_option_value_that_the_sanitiser_changes_can_still_be_chosen(): void {
		$options = array( array( 'value' => 'Two  spaces', 'label' => 'Two spaces' ), array( 'value' => 'other', 'label' => 'Other' ) );
		$fields  = $this->defs( array(), array( $this->custom( 'pick', 'select', array( 'options' => $options ) ) ) );
		$cfg     = $this->cfg( array( 'fields' => $fields ) );
		$raw     = array_merge( $this->valid(), array( 'pick' => 'Two  spaces' ) );
		$r       = Validator::contact( $raw, $cfg );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( 'Two  spaces', $r['data']['custom'][0]['value'], 'The option value itself is stored.' );

		$raw['pick'] = 'Two   spaces'; // Spaces collapse the same way on both sides.
		$this->assertSame( array(), Validator::contact( $raw, $cfg )['errors'] );
		$raw['pick'] = 'Two';
		$this->assertArrayHasKey( 'pick', Validator::contact( $raw, $cfg )['errors'] );
	}

	public function test_zero_is_a_real_value(): void {
		$options = array( array( 'value' => '0', 'label' => 'None' ), array( 'value' => '1', 'label' => 'One' ) );
		$fields  = $this->defs(
			array(),
			array(
				$this->custom( 'word', 'text', array( 'required' => true ) ),
				$this->custom( 'count', 'number', array( 'required' => true ) ),
				$this->custom( 'pick', 'select', array( 'options' => $options, 'required' => true ) ),
			)
		);
		$raw     = array_merge( $this->valid(), array( 'word' => '0', 'count' => '0', 'pick' => '0' ) );
		$r       = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( array( 'word', 'count', 'pick' ), array_column( $r['data']['custom'], 'key' ) );
		$this->assertSame( array( '0', '0', '0' ), array_column( $r['data']['custom'], 'value' ) );
	}

	public function test_whitespace_only_counts_as_empty_in_a_required_field(): void {
		$fields = $this->defs(
			array( 'company' => array( 'required' => true ) ),
			array(
				$this->custom( 'word', 'text', array( 'required' => true ) ),
				$this->custom( 'notes', 'textarea', array( 'required' => true ) ),
				$this->custom( 'count', 'number', array( 'required' => true ) ),
			)
		);
		$raw    = array_merge( $this->valid(), array( 'name' => '   ', 'company' => " \t ", 'word' => '   ', 'notes' => " \n  \n ", 'count' => '  ' ) );
		$r      = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( array( 'name', 'company', 'word', 'notes', 'count' ), array_keys( $r['errors'] ) );
		$this->assertSame( array(), $r['data']['custom'] );
	}

	public function test_multibyte_custom_text_is_cut_by_characters(): void {
		$fields = $this->defs( array(), array( $this->custom( 'line', 'text' ) ) );
		$raw    = array_merge( $this->valid(), array( 'line' => str_repeat( 'م', 300 ), 'company' => str_repeat( 'é', 300 ) ) );
		$r      = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields ) ) );
		$value  = $r['data']['custom'][0]['value'];
		$this->assertSame( 200, mb_strlen( $value ) );
		$this->assertSame( 400, strlen( $value ) );
		$this->assertTrue( mb_check_encoding( $value, 'UTF-8' ) );
		$this->assertSame( 150, mb_strlen( $r['data']['company'] ) );
	}

	public function test_array_posted_for_any_field_is_treated_as_empty(): void {
		$fields = $this->defs(
			array( 'region' => array( 'required' => true ) ),
			array(
				$this->custom( 'pick', 'select', array( 'options' => $this->sizes() ) ),
				$this->custom( 'terms', 'checkbox' ),
			)
		);
		$raw    = array_merge(
			$this->valid(),
			array(
				'region'         => array( 'Cairo' ),
				'region_country' => array( 'EG' ),
				'consent'        => array( '1' ),
				'pick'           => array( 'small' ),
				'terms'          => array( '1' ),
			)
		);
		$r      = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( array( array( '', '' ) ), $this->region_calls );
		$this->assertSame( 'Please choose your region.', $r['errors']['region'] );
		$this->assertSame( '', $r['data']['region_country'] );
		$this->assertFalse( $r['data']['consent'] );
		$this->assertArrayHasKey( 'consent', $r['errors'] );
		$this->assertArrayNotHasKey( 'pick', $r['errors'] );
		$this->assertArrayNotHasKey( 'terms', $r['errors'] );
		$this->assertSame( array(), $r['data']['custom'] );
	}

	public function test_non_text_input_is_treated_as_empty(): void {
		$fields = $this->defs( array(), array( $this->custom( 'line', 'text' ) ) );
		$raw    = array_merge( $this->valid(), array( 'name' => array( 'x' ), 'line' => array( 'y' ), 'message' => new stdClass() ) );
		$r      = Validator::contact( $raw, $this->cfg( array( 'fields' => $fields ) ) );
		$this->assertSame( 'Please enter your name.', $r['errors']['name'] );
		$this->assertSame( array(), $r['data']['custom'] );
	}

	public function test_items_merge_clamp_drop_and_cap(): void {
		$known  = static fn( int $id ) => in_array( $id, array( 10, 11, 12 ), true )
			? array( 'product_id' => $id, 'sku' => 'S' . $id, 'name' => 'P' . $id, 'family' => 'F' )
			: null;
		$result = Validator::items(
			array(
				array( 'id' => 10, 'qty' => 2 ),
				array( 'id' => '10', 'qty' => 3 ),
				array( 'id' => 11, 'qty' => 0 ),
				array( 'id' => 12, 'qty' => 99999 ),
				array( 'id' => 999, 'qty' => 1 ),
				array( 'id' => -4, 'qty' => 1 ),
				'garbage',
			),
			$known
		);
		$this->assertSame( array( 10, 11, 12 ), array_column( $result['items'], 'product_id' ) );
		$this->assertSame( array( 5, 1, 9999 ), array_column( $result['items'], 'qty' ) );
		$this->assertSame( 3, $result['dropped'] );
	}

	public function test_items_capped_at_fifty_lines(): void {
		$raw = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$raw[] = array( 'id' => $i, 'qty' => 1 );
		}
		$r = Validator::items( $raw, static fn( int $id ) => array( 'product_id' => $id, 'sku' => '', 'name' => 'P', 'family' => '' ) );
		$this->assertCount( 50, $r['items'] );
		$this->assertSame( 10, $r['dropped'] );
	}

	public function test_phone_pasted_with_invisible_direction_marks_is_accepted(): void {
		foreach ( array( "\u{202A}+1 555 010 0199\u{202C}", "\u{200E}+15550100199", "\u{2066}5550100199\u{2069}" ) as $pasted ) {
			$raw          = $this->valid();
			$raw['phone'] = $pasted;
			$r            = Validator::contact( $raw, $this->cfg() );
			$this->assertArrayNotHasKey( 'phone', $r['errors'], bin2hex( $pasted ) );
			$this->assertMatchesRegularExpression( '/^\+?[0-9]+$/', $r['data']['phone'] );
		}
	}

	public function test_items_stop_looking_up_after_200_rows(): void {
		$raw = array();
		for ( $i = 1; $i <= 5000; $i++ ) {
			$raw[] = array( 'id' => $i, 'qty' => 1 );
		}
		$lookups = 0;
		$r       = Validator::items(
			$raw,
			static function ( int $id ) use ( &$lookups ) {
				++$lookups;
				return null;
			}
		);
		$this->assertLessThanOrEqual( 200, $lookups );
		$this->assertSame( array(), $r['items'] );
		$this->assertSame( 5000, $r['dropped'] );
	}

	public function test_validate_filter_can_add_an_error_that_rejects_the_request(): void {
		$GLOBALS['qr_test_filters']['quote_requests_validate'][] = static function ( array $errors, array $data ): array {
			if ( 'blocked@example.com' === $data['email'] ) {
				$errors['email'] = 'This address cannot be used.';
			}
			return $errors;
		};

		$raw          = $this->valid();
		$raw['email'] = 'blocked@example.com';
		$r            = Validator::contact( $raw, $this->cfg() );
		$this->assertSame( array( 'email' => 'This address cannot be used.' ), $r['errors'] );

		$raw['email'] = 'other@example.com';
		$this->assertSame( array(), Validator::contact( $raw, $this->cfg() )['errors'] );
	}

	public function test_validate_filter_receives_the_errors_the_cleaned_data_and_the_config(): void {
		$seen                                                    = array();
		$GLOBALS['qr_test_filters']['quote_requests_validate'][] = static function ( array $errors, array $data, array $cfg ) use ( &$seen ): array {
			$seen = array( $errors, $data, $cfg );
			return $errors;
		};

		$raw          = $this->valid();
		$raw['phone'] = 'abc';
		$cfg          = $this->cfg( array( 'contact_rule' => 'phone' ) );
		$r            = Validator::contact( $raw, $cfg );

		$this->assertSame( $r['errors'], $seen[0] );
		$this->assertArrayHasKey( 'phone', $seen[0] );
		$this->assertSame( $r['data'], $seen[1] );
		$this->assertSame( 'phone', $seen[2]['contact_rule'] );
		$this->assertSame( $cfg['fields'], $seen[2]['fields'] );
	}

	public function test_validate_filter_returning_garbage_changes_nothing(): void {
		$raw   = $this->valid();
		$plain = Validator::contact( $raw, $this->cfg() );
		$bad   = Validator::contact( array(), $this->cfg() );
		foreach ( array( 'rejected', null, 42, false, new stdClass() ) as $garbage ) {
			$GLOBALS['qr_test_filters']['quote_requests_validate'] = array(
				static function () use ( $garbage ) {
					return $garbage;
				},
			);
			$this->assertSame( $plain, Validator::contact( $raw, $this->cfg() ) );
			$this->assertSame( $bad, Validator::contact( array(), $this->cfg() ) );
		}
	}

	public function test_validate_filter_entries_that_are_not_a_key_and_a_message_are_dropped(): void {
		$GLOBALS['qr_test_filters']['quote_requests_validate'][] = static function ( array $errors ): array {
			$errors['good']  = 'A real message.';
			$errors['array'] = array( 'not', 'text' );
			$errors['empty'] = '';
			$errors['null']  = null;
			return $errors;
		};

		$r = Validator::contact( $this->valid(), $this->cfg() );
		$this->assertSame( array( 'good' => 'A real message.' ), $r['errors'] );
	}

	public function test_validate_filter_keeps_the_error_of_a_field_whose_key_is_made_of_digits(): void {
		$GLOBALS['qr_test_filters']['quote_requests_validate'][] = static function ( array $errors ): array {
			return $errors;
		};

		$cfg = $this->cfg( array( 'fields' => $this->defs( array(), array( $this->custom( '2024', 'text', array( 'required' => true ) ) ) ) ) );
		$r   = Validator::contact( $this->valid(), $cfg );
		$this->assertSame( array( 'Please fill in this field.' ), array_values( $r['errors'] ) );
	}

	/** The example in docs/hooks.md for the quote_requests_validate filter, word for word. */
	public function test_the_documented_validate_example_works(): void {
		add_filter(
			'quote_requests_validate',
			static function ( array $errors, array $data ): array {
				if ( ! isset( $errors['message'] ) && preg_match( '#https?://#i', $data['message'] ) ) {
					$errors['message'] = 'Please describe your request without links.';
				}
				return $errors;
			},
			10,
			2
		);

		$raw            = $this->valid();
		$raw['message'] = 'See https://example.com/plans for the drawings.';
		$r              = Validator::contact( $raw, $this->cfg() );
		$this->assertSame( array( 'message' => 'Please describe your request without links.' ), $r['errors'] );

		$raw['message'] = 'Three doors and two windows.';
		$this->assertSame( array(), Validator::contact( $raw, $this->cfg() )['errors'] );
	}
}
