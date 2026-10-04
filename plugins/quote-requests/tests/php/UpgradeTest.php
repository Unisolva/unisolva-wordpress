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
use Quote_Requests\Upgrade;

final class UpgradeTest extends TestCase {
	protected function setUp(): void {
		qr_test_reset_options( array( 'admin_email' => 'admin@example.test' ) );
	}

	private function legacy(): array {
		return require __DIR__ . '/fixtures/settings-0.1-sample.php';
	}

	private function by_key( array $new ): array {
		return array_column( $new['fields'], null, 'key' );
	}

	public function test_legacy_settings_convert_without_changing_the_form(): void {
		$new = Upgrade::convert_settings( $this->legacy() );
		$by  = $this->by_key( $new );
		$this->assertSame( array( 'name', 'phone', 'region', 'email', 'company', 'details', 'message' ), array_column( $new['fields'], 'key' ) );
		$this->assertSame( 'State', $by['region']['label'] );
		$this->assertTrue( $by['region']['required'] );
		$this->assertSame( 'Company or organisation', $by['company']['label'] );
		$this->assertSame( 'Project details', $by['details']['label'] );
		$this->assertFalse( $by['details']['system'] );
		$this->assertSame( 'phone', $new['contact_rule'] );
		$this->assertSame( 'single', $new['region_mode'] );
		$this->assertSame( 'US', $new['region_country'] );
		$this->assertArrayNotHasKey( 'label_details', $new );
	}

	public function test_converted_definitions_have_the_0_1_labels_types_and_switches(): void {
		$by = $this->by_key( Upgrade::convert_settings( $this->legacy() ) );
		$this->assertSame( 'Name', $by['name']['label'] );
		$this->assertTrue( $by['name']['required'] );
		$this->assertSame( 'Phone / WhatsApp', $by['phone']['label'] );
		$this->assertSame( 'tel', $by['phone']['type'] );
		$this->assertTrue( $by['phone']['show'] );
		$this->assertSame( 'region', $by['region']['type'] );
		$this->assertTrue( $by['region']['show'] );
		$this->assertSame( 'Email', $by['email']['label'] );
		$this->assertTrue( $by['email']['show'] );
		$this->assertFalse( $by['email']['required'] );
		$this->assertTrue( $by['company']['show'] );
		$this->assertFalse( $by['company']['required'] );
		$this->assertSame( 'Message', $by['message']['label'] );
		$this->assertTrue( $by['message']['show'] );
		$this->assertSame(
			array(
				'key'      => 'details',
				'type'     => 'text',
				'label'    => 'Project details',
				'help'     => '',
				'required' => false,
				'show'     => true,
				'options'  => array(),
				'system'   => false,
			),
			$by['details']
		);
	}

	public function test_conversion_adds_the_new_keys_and_keeps_every_other_key(): void {
		$old = $this->legacy();
		$new = Upgrade::convert_settings( $old );
		$this->assertTrue( $new['require_consent'] );
		foreach ( array( 'label_company', 'label_details', 'region_label' ) as $gone ) {
			$this->assertArrayNotHasKey( $gone, $new );
		}
		$kept = array_diff_key( $old, array_flip( array( 'label_company', 'label_details', 'region_label', 'fields' ) ) );
		$this->assertSame( $kept, array_intersect_key( $new, $kept ) );
		$this->assertSame( array( 'sales@example.com', 'info@example.com' ), $new['recipients'] );
		$this->assertTrue( $new['hide_prices'] );
		$this->assertSame( 11, $new['quote_page'] );
	}

	public function test_converting_twice_changes_nothing(): void {
		$once  = Upgrade::convert_settings( $this->legacy() );
		$twice = Upgrade::convert_settings( $once );
		$this->assertSame( $once, $twice );
		$this->assertFalse( Upgrade::is_legacy( $once ) );
	}

	public function test_is_legacy(): void {
		$this->assertFalse( Upgrade::is_legacy( array() ), 'nothing stored' );
		$this->assertTrue( Upgrade::is_legacy( $this->legacy() ), 'the 0.1 fixture' );
		$this->assertTrue( Upgrade::is_legacy( array( 'hide_prices' => true ) ), 'other keys but no contact rule' );
		$this->assertTrue(
			Upgrade::is_legacy(
				array(
					'contact_rule' => 'either',
					'fields'       => array( 'email' => array( 'show' => true ) ),
				)
			),
			'associative fields with an email key'
		);
		$this->assertFalse(
			Upgrade::is_legacy(
				array(
					'contact_rule' => 'either',
					'fields'       => array( array( 'key' => 'name' ) ),
				)
			),
			'a field list with a contact rule'
		);
		$this->assertFalse( Upgrade::is_legacy( Upgrade::convert_settings( $this->legacy() ) ), 'converted settings' );
	}

	public function test_a_settings_array_that_is_not_legacy_is_returned_unchanged(): void {
		$new = array(
			'contact_rule'  => 'both',
			'fields'        => array( array( 'key' => 'name' ) ),
			'label_company' => 'Kept because nothing is converted',
		);
		$this->assertSame( $new, Upgrade::convert_settings( $new ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'email_switches' )]
	public function test_contact_rule_follows_the_old_email_switches( bool $show, bool $required, string $rule ): void {
		$old                    = $this->legacy();
		$old['fields']['email'] = array(
			'show'     => $show,
			'required' => $required,
		);
		$new = Upgrade::convert_settings( $old );
		$this->assertSame( $rule, $new['contact_rule'] );
		$this->assertSame( $show, $this->by_key( $new )['email']['show'] );
	}

	public static function email_switches(): array {
		return array(
			'shown and required'  => array( true, true, 'both' ),
			'shown, not required' => array( true, false, 'phone' ),
			'hidden'              => array( false, false, 'phone' ),
			'hidden but required' => array( false, true, 'phone' ),
		);
	}

	public function test_old_switches_and_labels_carry_over(): void {
		$old           = $this->legacy();
		$old['fields'] = array(
			'email'   => array(
				'show'     => false,
				'required' => false,
			),
			'company' => array(
				'show'     => true,
				'required' => true,
			),
			'region'  => array(
				'show'     => false,
				'required' => true,
			),
			'details' => array(
				'show'     => true,
				'required' => true,
			),
		);
		$by = $this->by_key( Upgrade::convert_settings( $old ) );
		$this->assertFalse( $by['email']['show'] );
		$this->assertTrue( $by['company']['show'] );
		$this->assertTrue( $by['company']['required'] );
		$this->assertFalse( $by['region']['show'] );
		$this->assertFalse( $by['region']['required'], 'a hidden field is never required' );
		$this->assertTrue( $by['details']['show'] );
		$this->assertTrue( $by['details']['required'] );
	}

	public function test_missing_switches_and_empty_labels_take_the_0_1_defaults(): void {
		$new = Upgrade::convert_settings(
			array(
				'hide_prices'   => true,
				'label_company' => '   ',
				'label_details' => '',
			)
		);
		$by  = $this->by_key( $new );
		$this->assertTrue( $new['hide_prices'] );
		$this->assertSame( 'phone', $new['contact_rule'] );
		$this->assertSame( 'Region', $by['region']['label'] );
		$this->assertTrue( $by['region']['show'] );
		$this->assertTrue( $by['region']['required'], 'region was required by default in 0.1' );
		$this->assertSame( 'Company', $by['company']['label'] );
		$this->assertTrue( $by['company']['show'] );
		$this->assertFalse( $by['company']['required'] );
		$this->assertSame( 'Details', $by['details']['label'] );
		$this->assertTrue( $by['details']['show'] );
		$this->assertFalse( $by['details']['required'] );
		$this->assertTrue( $by['email']['show'] );
		$this->assertFalse( $by['email']['required'] );
	}

	public function test_details_label_of_0_1_settings_is_the_old_label(): void {
		$this->assertSame( 'Project details', Upgrade::details_label( $this->legacy() ) );
	}

	public function test_details_label_of_0_2_settings_is_the_label_of_the_details_field(): void {
		$new = Upgrade::convert_settings( $this->legacy() );
		$this->assertSame( 'Project details', Upgrade::details_label( $new ) );
		foreach ( $new['fields'] as $i => $def ) {
			if ( 'details' === $def['key'] ) {
				$new['fields'][ $i ]['label'] = 'Renamed since';
			}
		}
		$this->assertSame( 'Renamed since', Upgrade::details_label( $new ) );
	}

	public function test_details_label_falls_back_when_there_is_no_details_field(): void {
		$this->assertSame( 'Details', Upgrade::details_label( array() ), 'nothing stored' );
		$this->assertSame(
			'Details',
			Upgrade::details_label(
				array(
					'contact_rule' => 'either',
					'fields'       => array( array( 'key' => 'name' ) ),
				)
			),
			'a 0.2 list without the field'
		);
	}

	public function test_details_entry_is_a_text_field_with_the_given_label_and_value(): void {
		$this->assertSame(
			array(
				'key'   => 'details',
				'label' => 'Project details',
				'type'  => 'text',
				'value' => 'Two units, spring',
			),
			Upgrade::details_entry( 'Project details', 'Two units, spring' )
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'stored_versions' )]
	public function test_needed_when_the_stored_version_is_behind( $stored, bool $needed ): void {
		$this->assertSame( $needed, Upgrade::needed( $stored ) );
	}

	public static function stored_versions(): array {
		return array(
			'never set (option default)' => array( 0, true ),
			'option missing (false)'     => array( false, true ),
			'version 1'                  => array( 1, true ),
			'current version'            => array( 2, false ),
			'stored as a string'         => array( '2', false ),
			'a later version'            => array( 3, false ),
			'junk'                       => array( 'abc', true ),
		);
	}

	public function test_the_data_version(): void {
		$this->assertSame( 2, Upgrade::VERSION );
		$this->assertSame( 'quote_requests_db_version', Upgrade::VERSION_OPTION );
	}
}
