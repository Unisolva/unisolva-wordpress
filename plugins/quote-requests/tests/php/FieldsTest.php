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

final class FieldsTest extends TestCase {
	protected function setUp(): void {
		qr_test_reset_options( array( 'admin_email' => 'admin@example.test' ) );
		$GLOBALS['qr_test_filters'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['qr_test_filters'] = array();
	}

	private function find( array $defs, string $key ): ?array {
		foreach ( $defs as $def ) {
			if ( $def['key'] === $key ) {
				return $def;
			}
		}
		return null;
	}

	private function custom_entry( string $key, string $type = 'text', array $extra = array() ): array {
		return array_merge(
			array(
				'key'   => $key,
				'type'  => $type,
				'label' => 'Label ' . $key,
				'show'  => true,
			),
			$extra
		);
	}

	public function test_defaults_are_neutral_and_ordered(): void {
		$keys = array_column( Fields::defaults(), 'key' );
		$this->assertSame( array( 'name', 'phone', 'email', 'company', 'region', 'message' ), $keys );
		$this->assertStringNotContainsStringIgnoringCase( 'details', wp_json_encode( Fields::defaults() ) );
	}

	public function test_defaults_have_the_agreed_labels_types_and_flags(): void {
		$defs = Fields::defaults();
		$this->assertSame( array( 'Name', 'Phone', 'Email', 'Company', 'Region', 'Message' ), array_column( $defs, 'label' ) );
		$this->assertSame( array( 'text', 'tel', 'email', 'text', 'region', 'textarea' ), array_column( $defs, 'type' ) );
		$this->assertSame( array( true, true, true, true, true, true ), array_column( $defs, 'show' ) );
		$this->assertSame( array( true, false, false, false, false, false ), array_column( $defs, 'required' ) );
		$this->assertSame( array( true, true, true, true, true, true ), array_column( $defs, 'system' ) );
		foreach ( $defs as $def ) {
			$this->assertSame( array( 'key', 'type', 'label', 'help', 'required', 'show', 'options', 'system' ), array_keys( $def ) );
			$this->assertSame( '', $def['help'] );
			$this->assertSame( array(), $def['options'] );
		}
	}

	public function test_garbage_input_restores_system_fields(): void {
		foreach ( array( null, 'x', array( 'x', 5 ), array( array( 'key' => 'company' ) ) ) as $raw ) {
			$keys = array_column( Fields::normalize( $raw ), 'key' );
			foreach ( Fields::SYSTEM as $k ) {
				$this->assertContains( $k, $keys );
			}
		}
	}

	public function test_non_array_input_gives_the_defaults(): void {
		$this->assertSame( Fields::defaults(), Fields::normalize( null ) );
		$this->assertSame( Fields::defaults(), Fields::normalize( 'x' ) );
		$this->assertSame( Fields::defaults(), Fields::normalize( array() ) );
	}

	public function test_missing_system_fields_are_added_at_the_end(): void {
		$raw  = array( $this->custom_entry( 'first' ), array( 'key' => 'email', 'label' => 'Mail' ) );
		$defs = Fields::normalize( $raw );
		$this->assertSame( array( 'first', 'email', 'name', 'phone', 'company', 'region', 'message' ), array_column( $defs, 'key' ) );
		$this->assertSame( 'Mail', $this->find( $defs, 'email' )['label'] );
		$this->assertSame( 'Phone', $this->find( $defs, 'phone' )['label'] );
	}

	public function test_reserved_and_duplicate_keys_are_dropped(): void {
		$raw = Fields::defaults();
		foreach ( array( 'token', 'website', 'items', 'consent', 'action', 'region_country', 'name', 'Site Area', 'site_area' ) as $k ) {
			$raw[] = array(
				'key'   => $k,
				'type'  => 'text',
				'label' => 'X',
				'show'  => true,
			);
		}
		$custom = array_column( Fields::custom( Fields::normalize( $raw ) ), 'key' );
		$this->assertSame( array( 'sitearea', 'site_area' ), $custom );
	}

	public function test_every_reserved_name_is_dropped(): void {
		$raw = array();
		foreach ( Fields::RESERVED as $k ) {
			$raw[] = $this->custom_entry( $k );
		}
		$this->assertSame( array(), Fields::custom( Fields::normalize( $raw ) ) );
	}

	public function test_an_earlier_custom_key_wins_over_a_later_duplicate(): void {
		$raw    = array( $this->custom_entry( 'size', 'text', array( 'label' => 'First' ) ), $this->custom_entry( 'SIZE', 'number', array( 'label' => 'Second' ) ) );
		$custom = Fields::custom( Fields::normalize( $raw ) );
		$this->assertCount( 1, $custom );
		$this->assertSame( 'First', $custom[0]['label'] );
	}

	public function test_keys_are_lowercased_stripped_and_cut_to_40(): void {
		$long = str_repeat( 'a', 60 );
		$raw  = array( $this->custom_entry( ' Site-Area! ' ), $this->custom_entry( $long ), $this->custom_entry( '!!!' ) );
		$keys = array_column( Fields::custom( Fields::normalize( $raw ) ), 'key' );
		$this->assertSame( array( 'sitearea', str_repeat( 'a', 40 ) ), $keys );
	}

	public function test_unknown_type_becomes_text_and_region_type_is_not_available_to_custom_fields(): void {
		$raw    = array( $this->custom_entry( 'one', 'colour' ), $this->custom_entry( 'two', 'region' ), $this->custom_entry( 'three', 'number' ) );
		$custom = Fields::custom( Fields::normalize( $raw ) );
		$this->assertSame( array( 'text', 'text', 'number' ), array_column( $custom, 'type' ) );
	}

	public function test_system_fields_keep_their_fixed_type(): void {
		$raw = array(
			array( 'key' => 'region', 'type' => 'text' ),
			array( 'key' => 'phone', 'type' => 'number' ),
			array( 'key' => 'message', 'type' => 'select' ),
		);
		$defs = Fields::normalize( $raw );
		$this->assertSame( 'region', $this->find( $defs, 'region' )['type'] );
		$this->assertSame( 'tel', $this->find( $defs, 'phone' )['type'] );
		$this->assertSame( 'textarea', $this->find( $defs, 'message' )['type'] );
		$this->assertSame( array(), $this->find( $defs, 'message' )['options'] );
	}

	public function test_system_flag_is_set_by_key_not_by_input(): void {
		$raw  = array( $this->custom_entry( 'extra', 'text', array( 'system' => true ) ), array( 'key' => 'company', 'system' => false ) );
		$defs = Fields::normalize( $raw );
		$this->assertFalse( $this->find( $defs, 'extra' )['system'] );
		$this->assertTrue( $this->find( $defs, 'company' )['system'] );
	}

	public function test_name_cannot_be_hidden_or_optional(): void {
		$raw = array(
			array(
				'key'      => 'name',
				'label'    => 'Full name',
				'show'     => false,
				'required' => false,
			),
		);
		$name = $this->find( Fields::normalize( $raw ), 'name' );
		$this->assertTrue( $name['show'] );
		$this->assertTrue( $name['required'] );
		$this->assertSame( 'Full name', $name['label'] );
	}

	public function test_message_is_always_shown_and_never_required(): void {
		$raw     = array(
			array(
				'key'      => 'message',
				'show'     => false,
				'required' => true,
			),
		);
		$message = $this->find( Fields::normalize( $raw ), 'message' );
		$this->assertTrue( $message['show'] );
		$this->assertFalse( $message['required'] );
	}

	public function test_other_system_fields_can_be_hidden_and_a_hidden_field_is_not_required(): void {
		$raw  = array(
			array(
				'key'      => 'company',
				'show'     => false,
				'required' => true,
			),
			array(
				'key'      => 'phone',
				'show'     => true,
				'required' => true,
			),
		);
		$defs = Fields::normalize( $raw );
		$this->assertFalse( $this->find( $defs, 'company' )['show'] );
		$this->assertFalse( $this->find( $defs, 'company' )['required'] );
		$this->assertTrue( $this->find( $defs, 'phone' )['required'] );
	}

	public function test_flags_accept_stored_string_values(): void {
		$raw    = array( $this->custom_entry( 'a', 'text', array( 'show' => '0', 'required' => '1' ) ), $this->custom_entry( 'b', 'text', array( 'show' => '1', 'required' => '1' ) ) );
		$custom = Fields::custom( Fields::normalize( $raw ) );
		$this->assertFalse( $custom[0]['show'] );
		$this->assertFalse( $custom[0]['required'] );
		$this->assertTrue( $custom[1]['show'] );
		$this->assertTrue( $custom[1]['required'] );
	}

	public function test_select_without_options_is_dropped(): void {
		$raw = array(
			$this->custom_entry( 'a', 'select' ),
			$this->custom_entry( 'b', 'select', array( 'options' => array() ) ),
			$this->custom_entry( 'c', 'select', array( 'options' => 'not a list' ) ),
			$this->custom_entry( 'd', 'select', array( 'options' => array( 'x', array( 'value' => '', 'label' => 'Empty value' ) ) ) ),
			$this->custom_entry( 'e', 'select', array( 'options' => array( array( 'value' => 'v', 'label' => 'Ok' ) ) ) ),
		);
		$this->assertSame( array( 'e' ), array_column( Fields::custom( Fields::normalize( $raw ) ), 'key' ) );
	}

	public function test_a_dropped_select_does_not_block_a_later_field_with_the_same_key(): void {
		$raw    = array( $this->custom_entry( 'size', 'select' ), $this->custom_entry( 'size', 'text' ) );
		$custom = Fields::custom( Fields::normalize( $raw ) );
		$this->assertCount( 1, $custom );
		$this->assertSame( 'text', $custom[0]['type'] );
	}

	public function test_select_options_are_cut_and_other_types_lose_their_options(): void {
		$long = str_repeat( 'b', 150 );
		$raw  = array(
			$this->custom_entry( 'pick', 'select', array( 'options' => array( array( 'value' => $long, 'label' => $long ), array( 'value' => 'plain', 'label' => '' ) ) ) ),
			$this->custom_entry( 'free', 'text', array( 'options' => array( array( 'value' => 'v', 'label' => 'L' ) ) ) ),
		);
		$custom = Fields::custom( Fields::normalize( $raw ) );
		$this->assertSame( str_repeat( 'b', 100 ), $custom[0]['options'][0]['value'] );
		$this->assertSame( str_repeat( 'b', 100 ), $custom[0]['options'][0]['label'] );
		$this->assertSame( array( 'value' => 'plain', 'label' => 'plain' ), $custom[0]['options'][1] );
		$this->assertSame( array(), $custom[1]['options'] );
	}

	public function test_labels_are_cut_to_100_and_an_empty_label_falls_back(): void {
		$raw  = array(
			$this->custom_entry( 'long', 'text', array( 'label' => str_repeat( 'c', 150 ) ) ),
			$this->custom_entry( 'bare', 'text', array( 'label' => '   ' ) ),
			array( 'key' => 'company', 'label' => '' ),
		);
		$defs = Fields::normalize( $raw );
		$this->assertSame( str_repeat( 'c', 100 ), $this->find( $defs, 'long' )['label'] );
		$this->assertSame( 'bare', $this->find( $defs, 'bare' )['label'] );
		$this->assertSame( 'Company', $this->find( $defs, 'company' )['label'] );
	}

	public function test_help_text_is_sanitised(): void {
		$defs = Fields::normalize( array( $this->custom_entry( 'a', 'text', array( 'help' => '<b>Hint</b>' ) ) ) );
		$this->assertSame( 'Hint', $this->find( $defs, 'a' )['help'] );
	}

	public function test_help_text_is_cut_to_200_characters(): void {
		$defs = Fields::normalize(
			array(
				$this->custom_entry( 'long', 'text', array( 'help' => str_repeat( 'a', 250 ) ) ),
				$this->custom_entry( 'exact', 'text', array( 'help' => str_repeat( 'b', 200 ) ) ),
				$this->custom_entry( 'wide', 'text', array( 'help' => str_repeat( "\u{0639}", 250 ) ) ),
				$this->custom_entry( 'short', 'text', array( 'help' => 'Short hint' ) ),
			)
		);
		$this->assertSame( str_repeat( 'a', 200 ), $this->find( $defs, 'long' )['help'] );
		$this->assertSame( str_repeat( 'b', 200 ), $this->find( $defs, 'exact' )['help'] );
		$this->assertSame( 200, mb_strlen( $this->find( $defs, 'wide' )['help'] ) );
		$this->assertSame( str_repeat( "\u{0639}", 200 ), $this->find( $defs, 'wide' )['help'] );
		$this->assertSame( 'Short hint', $this->find( $defs, 'short' )['help'] );
	}

	public function test_more_than_20_custom_fields_are_cut(): void {
		$raw = Fields::defaults();
		for ( $i = 1; $i <= 25; $i++ ) {
			$raw[] = $this->custom_entry( 'f' . $i );
		}
		$defs   = Fields::normalize( $raw );
		$custom = Fields::custom( $defs );
		$this->assertCount( Fields::MAX_CUSTOM, $custom );
		$this->assertSame( 'f1', $custom[0]['key'] );
		$this->assertSame( 'f20', $custom[19]['key'] );
		$this->assertCount( 26, $defs );
	}

	public function test_normalize_is_idempotent(): void {
		$raw = array(
			$this->custom_entry( 'size', 'select', array( 'options' => array( array( 'value' => 'a', 'label' => 'A' ) ) ) ),
			array( 'key' => 'name', 'show' => false ),
			$this->custom_entry( 'notes', 'textarea', array( 'required' => true ) ),
		);
		$once = Fields::normalize( $raw );
		$this->assertSame( $once, Fields::normalize( $once ) );
	}

	public function test_custom_returns_only_non_system_fields(): void {
		$raw = array_merge( Fields::defaults(), array( $this->custom_entry( 'extra' ) ) );
		$this->assertSame( array( 'extra' ), array_column( Fields::custom( Fields::normalize( $raw ) ), 'key' ) );
		$this->assertSame( array(), Fields::custom( Fields::defaults() ) );
	}

	public function test_all_turns_the_old_settings_structure_into_the_defaults(): void {
		$this->assertSame( Fields::defaults(), Fields::all() );
	}

	public function test_visible_leaves_out_hidden_fields(): void {
		$GLOBALS['qr_test_filters']['quote_requests_fields'][] = static function ( array $defs ): array {
			foreach ( $defs as $i => $def ) {
				if ( in_array( $def['key'], array( 'company', 'region' ), true ) ) {
					$defs[ $i ]['show'] = false;
				}
			}
			return $defs;
		};
		$this->assertSame( array( 'name', 'phone', 'email', 'message' ), array_column( Fields::visible(), 'key' ) );
		$this->assertCount( 6, Fields::all() );
	}

	public function test_filter_result_is_normalized_again(): void {
		$GLOBALS['qr_test_filters']['quote_requests_fields'][] = function ( array $defs ): array {
			$defs[] = $this->custom_entry( 'site_area', 'number' );
			$defs[] = $this->custom_entry( 'token' );
			return $defs;
		};
		$this->assertSame( array( 'site_area' ), array_column( Fields::custom( Fields::all() ), 'key' ) );
	}

	public function test_filter_returning_something_that_is_not_a_list_keeps_the_saved_form(): void {
		qr_test_set_option(
			'quote_requests_settings',
			array(
				'contact_rule' => 'either',
				'fields'       => array(
					array(
						'key'   => 'company',
						'label' => 'Organisation',
					),
					$this->custom_entry( 'site_area', 'number' ),
				),
			)
		);
		$saved = Fields::all();
		$this->assertSame( 'Organisation', $this->find( $saved, 'company' )['label'] );
		$this->assertSame( array( 'site_area' ), array_column( Fields::custom( $saved ), 'key' ) );
		foreach ( array( 'not an array', null, 42, false ) as $garbage ) {
			$GLOBALS['qr_test_filters']['quote_requests_fields'] = array(
				static function () use ( $garbage ) {
					return $garbage;
				},
			);
			Fields::flush();
			$this->assertSame( $saved, Fields::all(), 'the form as saved, not the neutral defaults: ' . var_export( $garbage, true ) );
		}
	}

	public function test_all_runs_the_filter_once_per_request_until_flushed(): void {
		$calls = 0;
		add_filter(
			'quote_requests_fields',
			static function ( array $defs ) use ( &$calls ): array {
				++$calls;
				return $defs;
			}
		);
		$first = Fields::all();
		$this->assertSame( $first, Fields::all() );
		Fields::visible();
		Fields::rows( array( 'name' => 'A' ) );
		$this->assertSame( 1, $calls, 'the list is built once' );
		Fields::flush();
		Fields::all();
		$this->assertSame( 2, $calls, 'and again after a flush' );
	}

	public function test_a_settings_change_drops_the_kept_field_list(): void {
		$this->assertSame( array(), Fields::custom( Fields::all() ) );
		qr_test_set_option( 'quote_requests_settings', array( 'contact_rule' => 'either', 'fields' => array( $this->custom_entry( 'site_area', 'number' ) ) ) );
		$this->assertSame( array( 'site_area' ), array_column( Fields::custom( Fields::all() ), 'key' ) );
	}

	public function test_filter_returning_a_list_without_name_restores_name(): void {
		$GLOBALS['qr_test_filters']['quote_requests_fields'][] = static function (): array {
			return array(
				array(
					'key'   => 'phone',
					'label' => 'Phone',
				),
			);
		};
		$all  = Fields::all();
		$name = $this->find( $all, 'name' );
		$this->assertNotNull( $name );
		$this->assertTrue( $name['show'] );
		$this->assertTrue( $name['required'] );
		foreach ( Fields::SYSTEM as $k ) {
			$this->assertNotNull( $this->find( $all, $k ) );
		}
	}

	public function test_rows_use_stored_label_for_custom_and_current_label_for_system(): void {
		$GLOBALS['qr_test_filters']['quote_requests_fields'][] = static function ( array $defs ): array {
			foreach ( $defs as $i => $def ) {
				if ( 'phone' === $def['key'] ) {
					$defs[ $i ]['label'] = 'Telephone';
				}
			}
			return $defs;
		};
		$quote = array(
			'name'           => 'Sample Person',
			'phone'          => '555 0100',
			'email'          => 'person@example.com',
			'company'        => 'Example Ltd',
			'region_name'    => 'North Region',
			'region_country' => 'XX',
			'message'        => 'Need a price list.',
			'fields'         => array(
				array(
					'key'   => 'area',
					'label' => 'Area (old label)',
					'type'  => 'number',
					'value' => '12',
				),
				array(
					'key'   => 'tier',
					'label' => 'Tier',
					'type'  => 'select',
					'value' => 'gold',
				),
			),
		);
		$this->assertSame(
			array(
				'Name'             => 'Sample Person',
				'Telephone'        => '555 0100',
				'Email'            => 'person@example.com',
				'Company'          => 'Example Ltd',
				'Region'           => 'North Region',
				'Message'          => 'Need a price list.',
				'Area (old label)' => '12',
				'Tier'             => 'gold',
			),
			Fields::rows( $quote )
		);
	}

	public function test_rows_follow_the_current_system_order_and_skip_region_country(): void {
		$GLOBALS['qr_test_filters']['quote_requests_fields'][] = static function ( array $defs ): array {
			return array_reverse( $defs );
		};
		$quote = array(
			'name'           => 'Sample Person',
			'region_name'    => 'North Region',
			'region_country' => 'XX',
			'message'        => 'Hello',
		);
		$this->assertSame( array( 'Message', 'Region', 'Name' ), array_keys( Fields::rows( $quote ) ) );
		$this->assertNotContains( 'XX', Fields::rows( $quote ) );
	}

	public function test_rows_omit_empty_values_but_keep_zero(): void {
		$quote = array(
			'name'    => 'Sample Person',
			'phone'   => '',
			'email'   => '   ',
			'company' => '0',
			'fields'  => array(
				array(
					'key'   => 'a',
					'label' => 'Empty',
					'type'  => 'text',
					'value' => '',
				),
				array(
					'key'   => 'b',
					'label' => 'Quantity',
					'type'  => 'number',
					'value' => '0',
				),
				'not a field',
			),
		);
		$this->assertSame(
			array(
				'Name'     => 'Sample Person',
				'Company'  => '0',
				'Quantity' => '0',
			),
			Fields::rows( $quote )
		);
	}

	public function test_rows_show_a_checked_checkbox_as_yes(): void {
		$quote = array(
			'name'   => 'Sample Person',
			'fields' => array(
				array(
					'key'   => 'newsletter',
					'label' => 'Newsletter',
					'type'  => 'checkbox',
					'value' => '1',
				),
				array(
					'key'   => 'note',
					'label' => 'Note',
					'type'  => 'text',
					'value' => '1',
				),
			),
		);
		$rows = Fields::rows( $quote );
		$this->assertSame( 'Yes', $rows['Newsletter'] );
		$this->assertSame( '1', $rows['Note'] );
	}

	public function test_rows_handle_a_quote_without_fields_and_a_stored_label_that_is_empty(): void {
		$this->assertSame( array( 'Name' => 'Sample Person' ), Fields::rows( array( 'name' => 'Sample Person' ) ) );
		$quote = array(
			'fields' => array(
				array(
					'key'   => 'area',
					'label' => '',
					'type'  => 'text',
					'value' => '5',
				),
			),
		);
		$this->assertSame( array( 'area' => '5' ), Fields::rows( $quote ) );
	}

	public function test_rows_keep_both_values_when_two_labels_collide(): void {
		$quote = array(
			'email'  => 'person@example.com',
			'fields' => array(
				array(
					'key'   => 'second_email',
					'label' => 'Email',
					'type'  => 'email',
					'value' => 'other@example.com',
				),
			),
		);
		$this->assertSame(
			array(
				'Email'     => 'person@example.com',
				'Email (2)' => 'other@example.com',
			),
			Fields::rows( $quote )
		);
	}

	public function test_rows_still_show_a_value_stored_for_a_field_that_is_now_hidden(): void {
		$GLOBALS['qr_test_filters']['quote_requests_fields'][] = static function ( array $defs ): array {
			foreach ( $defs as $i => $def ) {
				if ( 'company' === $def['key'] ) {
					$defs[ $i ]['show'] = false;
				}
			}
			return $defs;
		};
		$this->assertSame( array( 'Company' => 'Example Ltd' ), Fields::rows( array( 'company' => 'Example Ltd' ) ) );
	}

	public function test_from_form_sorts_rows_by_order_and_keeps_unnumbered_rows_in_place(): void {
		$rows = array(
			array( 'key' => 'name', 'order' => '3' ),
			array( 'key' => 'phone', 'order' => '1' ),
			array( 'key' => 'alpha', 'label' => 'Alpha', 'order' => '2' ),
			array( 'key' => 'beta', 'label' => 'Beta' ),
			array( 'key' => 'gamma', 'label' => 'Gamma' ),
		);
		$this->assertSame( array( 'phone', 'alpha', 'name', 'beta', 'gamma' ), array_column( Fields::from_form( $rows, array() ), 'key' ) );
		$plain = array( array( 'key' => 'b' ), array( 'key' => 'a' ), array( 'key' => 'c' ) );
		$this->assertSame( array( 'b', 'a', 'c' ), array_column( Fields::from_form( $plain, array() ), 'key' ), 'a list without order keeps its order' );
		$same = array( array( 'key' => 'b', 'order' => 1 ), array( 'key' => 'a', 'order' => 1 ) );
		$this->assertSame( array( 'b', 'a' ), array_column( Fields::from_form( $same, array() ), 'key' ), 'equal order keeps the posted sequence' );
	}

	public function test_from_form_reads_options_from_text_one_per_line(): void {
		$rows = Fields::from_form(
			array(
				array(
					'key'     => 'size',
					'type'    => 'select',
					'label'   => 'Size',
					'options' => "small | Up to 10\r\n\r\n  Medium  \nlarge|More | than 100\n | Only a label\n|\n",
				),
			),
			array()
		);
		$this->assertSame(
			array(
				array(
					'value' => 'small',
					'label' => 'Up to 10',
				),
				array(
					'value' => 'Medium',
					'label' => 'Medium',
				),
				array(
					'value' => 'large',
					'label' => 'More | than 100',
				),
				array(
					'value' => 'Only a label',
					'label' => 'Only a label',
				),
			),
			$this->find( Fields::normalize( $rows ), 'size' )['options']
		);
		$list = array(
			array(
				'value' => 'a',
				'label' => 'A',
			),
		);
		$kept = Fields::from_form( array( array( 'key' => 'pick', 'type' => 'select', 'options' => $list ) ), array() );
		$this->assertSame( $list, $kept[0]['options'], 'a list of options passes unchanged' );
	}

	public function test_from_form_takes_the_key_of_an_existing_row_from_its_id(): void {
		$rows = Fields::from_form(
			array(
				array( 'id' => 'extra', 'key' => 'renamed', 'label' => 'Extra' ),
				array( 'id' => 'company', 'key' => 'firm', 'label' => 'Firm' ),
				array( 'id' => 'gone', 'key' => 'fresh', 'label' => 'Fresh' ),
			),
			array( 'name', 'company', 'extra' )
		);
		$this->assertSame( array( 'extra', 'company', 'fresh' ), array_column( $rows, 'key' ), 'a known id fixes the key; an unknown id is a new row' );
	}

	private function stored_fields(): array {
		return Fields::normalize(
			array(
				array( 'key' => 'name' ),
				array( 'key' => 'material', 'type' => 'text', 'label' => 'Material', 'help' => 'What it is made of.' ),
				array(
					'key'     => 'size',
					'type'    => 'select',
					'label'   => 'Package size',
					'options' => array(
						array(
							'value' => 's',
							'label' => 'Small',
						),
					),
				),
			)
		);
	}

	public function test_from_form_keeps_the_id_of_an_existing_row_only(): void {
		$rows = Fields::from_form(
			array(
				array( 'id' => 'material', 'key' => 'material' ),
				array( 'id' => 'nope', 'key' => 'fresh' ),
			),
			array( 'material' )
		);
		$this->assertSame( 'material', $rows[0]['id'] );
		$this->assertArrayNotHasKey( 'id', $rows[1] );
	}

	public function test_accept_keeps_an_existing_choice_field_whose_choices_were_emptied(): void {
		$stored = $this->stored_fields();
		$out    = Fields::accept(
			array(
				array( 'id' => 'name', 'key' => 'name', 'order' => 1 ),
				array( 'id' => 'size', 'key' => 'size', 'type' => 'select', 'label' => 'Renamed', 'options' => "  \n", 'order' => 2 ),
				array( 'id' => 'material', 'key' => 'material', 'type' => 'text', 'label' => 'Material', 'order' => 3 ),
			),
			$stored
		);
		$by     = array_column( $out['fields'], null, 'key' );
		$this->assertSame( array_column( $stored, null, 'key' )['size'], $by['size'], 'the stored definition is kept unchanged' );
		$this->assertSame( array( 'name', 'size', 'material' ), array_slice( array_column( $out['fields'], 'key' ), 0, 3 ), 'in the posted place' );
		$this->assertSame(
			array(
				array(
					'code' => 'choices_kept',
					'name' => 'Package size',
					'key'  => 'size',
				),
			),
			$out['problems']
		);
	}

	public function test_accept_keeps_an_existing_field_switched_to_a_choice_without_choices(): void {
		$stored = $this->stored_fields();
		$out    = Fields::accept(
			array(
				array( 'id' => 'material', 'key' => 'material', 'type' => 'select', 'label' => 'Material kind', 'options' => '' ),
				array( 'id' => 'size', 'key' => 'size', 'type' => 'select', 'label' => 'Package size', 'options' => 's | Small' ),
			),
			$stored
		);
		$by     = array_column( $out['fields'], null, 'key' );
		$this->assertSame( array_column( $stored, null, 'key' )['material'], $by['material'] );
		$this->assertSame( 'text', $by['material']['type'] );
		$this->assertSame( array( 'choices_kept' ), array_column( $out['problems'], 'code' ) );
		$this->assertSame( array( 'Material' ), array_column( $out['problems'], 'name' ), 'named by the label the field has, not the one typed' );
	}

	public function test_accept_drops_new_rows_that_cannot_be_saved_and_says_why(): void {
		$stored = $this->stored_fields();
		$out    = Fields::accept(
			array(
				array( 'id' => 'name', 'key' => 'name', 'label' => 'Name', 'order' => 0 ),
				array( 'key' => 'material', 'label' => 'Impostor', 'order' => 1 ),
				array( 'id' => 'material', 'key' => 'material', 'label' => 'Material', 'type' => 'text', 'order' => 2 ),
				array( 'id' => 'size', 'key' => 'size', 'label' => 'Package size', 'type' => 'select', 'options' => 's | Small', 'order' => 3 ),
				array( 'key' => 'token', 'label' => 'Reserved one', 'order' => 4 ),
				array( 'key' => 'pick', 'label' => 'Pick', 'type' => 'select', 'options' => '', 'order' => 5 ),
				array( 'key' => 'twice', 'label' => 'First', 'order' => 6 ),
				array( 'key' => 'twice', 'label' => 'Second', 'order' => 7 ),
				array( 'key' => 'name', 'label' => 'Second name', 'order' => 8 ),
				array( 'key' => '', 'label' => '', 'order' => 9 ),
			),
			$stored
		);
		$this->assertSame( array( 'name', 'material', 'size', 'twice', 'phone', 'email', 'company', 'region', 'message' ), array_column( $out['fields'], 'key' ) );
		$by = array_column( $out['fields'], null, 'key' );
		$this->assertSame( 'Material', $by['material']['label'] );
		$this->assertSame( 'First', $by['twice']['label'] );
		$this->assertSame( 'Name', $by['name']['label'], 'a new row cannot take over a system field' );
		$this->assertSame(
			array(
				array( 'duplicate', 'Impostor', 'material' ),
				array( 'reserved', 'Reserved one', 'token' ),
				array( 'choices', 'Pick', 'pick' ),
				array( 'duplicate', 'Second', 'twice' ),
				array( 'duplicate', 'Second name', 'name' ),
			),
			array_map( static fn( array $p ): array => array( $p['code'], $p['name'], $p['key'] ), $out['problems'] )
		);
	}

	public function test_accept_reports_the_row_over_the_limit(): void {
		$rows = array();
		for ( $n = 1; $n <= Fields::MAX_CUSTOM + 1; $n++ ) {
			$rows[] = array( 'key' => 'extra_' . $n, 'label' => 'Extra ' . $n );
		}
		$out = Fields::accept( $rows, Fields::defaults() );
		$this->assertCount( Fields::MAX_CUSTOM, Fields::custom( $out['fields'] ) );
		$this->assertSame(
			array(
				array(
					'code' => 'limit',
					'name' => 'Extra 21',
					'key'  => 'extra_21',
				),
			),
			$out['problems']
		);
	}

	/** The stored form with the most custom fields a form can have, and the rows the settings screen posts for them. */
	private function full_form(): array {
		$stored = Fields::defaults();
		for ( $n = 1; $n <= Fields::MAX_CUSTOM; $n++ ) {
			$stored[] = $this->custom_entry( 'extra_' . $n );
		}
		$stored = Fields::normalize( $stored );
		$rows   = array();
		foreach ( Fields::custom( $stored ) as $i => $def ) {
			$rows[] = array(
				'id'    => $def['key'],
				'key'   => $def['key'],
				'label' => $def['label'],
				'order' => (string) ( $i + 1 ),
			);
		}
		return array( $stored, $rows );
	}

	public function test_accept_at_the_limit_drops_the_new_row_never_an_existing_field(): void {
		list( $stored, $rows ) = $this->full_form();
		// A crafted post: a new row that sorts in front of every existing field.
		array_unshift(
			$rows,
			array(
				'key'   => 'squeezed_in',
				'label' => 'Squeezed in',
				'order' => '0',
			)
		);
		$out = Fields::accept( $rows, $stored );
		$this->assertSame( array_column( Fields::custom( $stored ), 'key' ), array_column( Fields::custom( $out['fields'] ), 'key' ), 'all 20 existing fields are kept' );
		$this->assertSame(
			array(
				array(
					'code' => 'limit',
					'name' => 'Squeezed in',
					'key'  => 'squeezed_in',
				),
			),
			$out['problems']
		);
	}

	public function test_accept_takes_a_new_row_into_the_place_of_a_removed_field(): void {
		list( $stored, $rows ) = $this->full_form();
		$rows[4]['remove']     = '1';
		array_unshift(
			$rows,
			array(
				'key'   => 'squeezed_in',
				'label' => 'Squeezed in',
				'order' => '0',
			),
			array(
				'key'   => 'one_too_many',
				'label' => 'One too many',
				'order' => '0',
			)
		);
		$out  = Fields::accept( $rows, $stored );
		$keys = array_column( Fields::custom( $out['fields'] ), 'key' );
		$this->assertCount( Fields::MAX_CUSTOM, $keys );
		$this->assertContains( 'squeezed_in', $keys );
		$this->assertNotContains( 'extra_5', $keys, 'the removed field is gone' );
		$this->assertNotContains( 'one_too_many', $keys );
		$this->assertSame( array( array( 'limit', 'one_too_many' ) ), array_map( static fn( array $p ): array => array( $p['code'], $p['key'] ), $out['problems'] ) );
	}

	public function test_accept_of_a_clean_list_reports_nothing_and_equals_normalize(): void {
		$stored = $this->stored_fields();
		$out    = Fields::accept( $stored, $stored );
		$this->assertSame( $stored, $out['fields'] );
		$this->assertSame( array(), $out['problems'] );
		$mixed = array( array( 'key' => 'name' ), array( 'key' => 'ok', 'type' => 'banana', 'label' => 'Fine' ), 'not a definition' );
		$this->assertSame( Fields::normalize( $mixed ), Fields::accept( $mixed, array() )['fields'] );
	}

	public function test_from_form_removes_ticked_custom_rows_but_never_a_system_row(): void {
		$rows = Fields::from_form(
			array(
				array( 'id' => 'extra', 'key' => 'extra', 'remove' => '1' ),
				array( 'id' => 'company', 'key' => 'company', 'label' => 'Firm', 'remove' => '1' ),
				array( 'id' => 'other', 'key' => 'other', 'remove' => '0' ),
			),
			array( 'company', 'extra', 'other' )
		);
		$this->assertSame( array( 'company', 'other' ), array_column( $rows, 'key' ) );
	}

	public function test_from_form_makes_a_key_from_the_label_of_a_new_row_without_one(): void {
		$rows = Fields::from_form(
			array(
				array( 'key' => '', 'label' => 'Delivery date!' ),
				array( 'key' => ' ', 'label' => 'Delivery date' ),
				array( 'key' => '', 'label' => 'Company' ),
				array( 'key' => '', 'label' => 'Token' ),
				array( 'key' => '', 'label' => "\u{0627}\u{0644}\u{0645}\u{062D}\u{0635}\u{0648}\u{0644}" ),
				array( 'key' => '', 'label' => '' ),
				array( 'label' => 'No key posted at all' ),
				array( 'key' => 'Mixed Case-Key', 'label' => 'Typed' ),
			),
			array( 'company' )
		);
		$this->assertSame( array( 'delivery_date', 'delivery_date_2', 'company_2', 'token_2', 'field', '', null, 'Mixed Case-Key' ), array_map( static fn( array $row ) => $row['key'] ?? null, $rows ) );
		$this->assertSame( array( 'delivery_date', 'delivery_date_2', 'company_2', 'token_2', 'field', 'mixedcasekey' ), array_column( Fields::custom( Fields::normalize( $rows ) ), 'key' ) );
	}

	public function test_from_form_ignores_entries_that_are_not_rows(): void {
		$this->assertSame( array( array( 'key' => 'a' ) ), Fields::from_form( array( 'junk', 5, array( 'key' => 'a' ), null ), array() ) );
	}

	/** The example in docs/hooks.md for the quote_requests_fields filter, word for word. */
	public function test_the_documented_fields_example_works(): void {
		add_filter(
			'quote_requests_fields',
			static function ( array $fields ): array {
				// Hide a built-in field.
				foreach ( $fields as $i => $field ) {
					if ( 'company' === $field['key'] ) {
						$fields[ $i ]['show'] = false;
					}
				}

				// Add two fields of your own, in front of the message.
				$new_fields = array(
					array(
						'key'      => 'delivery_date',
						'type'     => 'text',
						'label'    => 'Delivery date',
						'help'     => 'When do you need the products?',
						'required' => false,
					),
					array(
						'key'      => 'project_type',
						'type'     => 'select',
						'label'    => 'Project type',
						'required' => true,
						'options'  => array(
							array(
								'value' => 'new',
								'label' => 'New build',
							),
							array(
								'value' => 'renovation',
								'label' => 'Renovation',
							),
						),
					),
				);
				$position   = array_search( 'message', array_column( $fields, 'key' ), true );
				array_splice( $fields, (int) $position, 0, $new_fields );

				return $fields;
			}
		);

		$all = Fields::all();
		$this->assertSame( array( 'name', 'phone', 'email', 'company', 'region', 'delivery_date', 'project_type', 'message' ), array_column( $all, 'key' ) );
		$this->assertSame( array( 'name', 'phone', 'email', 'region', 'delivery_date', 'project_type', 'message' ), array_column( Fields::visible(), 'key' ) );

		$date = $this->find( $all, 'delivery_date' );
		$this->assertSame( 'text', $date['type'] );
		$this->assertSame( 'Delivery date', $date['label'] );
		$this->assertSame( 'When do you need the products?', $date['help'] );
		$this->assertFalse( $date['required'] );
		$this->assertFalse( $date['system'] );

		$type = $this->find( $all, 'project_type' );
		$this->assertSame( 'select', $type['type'] );
		$this->assertTrue( $type['required'] );
		$this->assertSame(
			array(
				array(
					'value' => 'new',
					'label' => 'New build',
				),
				array(
					'value' => 'renovation',
					'label' => 'Renovation',
				),
			),
			$type['options']
		);
	}

	/** @return array<string,array{string,string[]}> The contact rule and the contact fields it needs to be visible when a filter hides them both. */
	public static function contact_rules(): array {
		return array(
			'either' => array( 'either', array( 'phone' ) ),
			'phone'  => array( 'phone', array( 'phone' ) ),
			'email'  => array( 'email', array( 'email' ) ),
			'both'   => array( 'both', array( 'phone', 'email' ) ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'contact_rules' )]
	public function test_a_filter_cannot_hide_the_contact_fields_the_rule_needs( string $rule, array $needed ): void {
		qr_test_set_option( 'quote_requests_settings', array( 'contact_rule' => $rule ) );
		add_filter(
			'quote_requests_fields',
			static function ( array $defs ): array {
				foreach ( $defs as $i => $def ) {
					if ( in_array( $def['key'], array( 'phone', 'email' ), true ) ) {
						$defs[ $i ]['show'] = false;
					}
				}
				return $defs;
			}
		);
		$visible = array_column( Fields::visible(), 'key' );
		foreach ( $needed as $key ) {
			$this->assertContains( $key, $visible, $rule );
		}
		$this->assertCount( count( $needed ), array_intersect( array( 'phone', 'email' ), $visible ), 'only what the rule needs comes back' );
	}

	public function test_apply_contact_rule_is_pure_and_keeps_order(): void {
		$defs = Fields::defaults();
		foreach ( $defs as $i => $def ) {
			if ( in_array( $def['key'], array( 'phone', 'email' ), true ) ) {
				$defs[ $i ]['show'] = false;
			}
		}
		$out = Fields::apply_contact_rule( $defs, 'both' );
		$this->assertSame( array_column( $defs, 'key' ), array_column( $out, 'key' ) );
		$this->assertSame( array( true, true, true, true, true, true ), array_column( $out, 'show' ) );
		$this->assertFalse( $defs[1]['show'], 'the input is not changed' );
		$either = Fields::apply_contact_rule( $defs, 'not a rule' ); // An unknown rule counts as "either".
		$this->assertTrue( $either[1]['show'] );
		$this->assertFalse( $either[2]['show'] );
	}
}
