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
use Quote_Requests\Mailer;
use Quote_Requests\Regions;
use Quote_Requests\Settings;

final class MailerTest extends TestCase {

	protected function setUp(): void {
		qr_test_reset_options( array( 'admin_email' => 'admin@example.test' ) );
		$GLOBALS['qr_test_filters'] = array();
	}

	protected function tearDown(): void {
		qr_test_reset_options( array() );
		$GLOBALS['qr_test_filters'] = array();
		unset( $GLOBALS['qr_test_blogname'] );
	}

	/** Stores field definitions: the six system fields plus the given custom ones. */
	private function define_fields( array $custom ): void {
		qr_test_set_option( Settings::OPTION, array(
			'contact_rule' => 'either',
			'fields'       => array_merge( Fields::defaults(), $custom ),
		) );
	}

	private function stored( string $key, string $label, string $type, string $value ): array {
		return array(
			'key'   => $key,
			'label' => $label,
			'type'  => $type,
			'value' => $value,
		);
	}

	private function quote( array $over = array() ): array {
		return array_merge(
			array(
				'id' => 5, 'ref' => 'Q-2026-0007', 'name' => 'Ahmed <b>Ali</b>', 'phone' => '+15550100000', 'email' => 'buyer@example.com',
				'company' => 'Acme', 'region_code' => 'EGC', 'region_name' => 'Cairo', 'message' => "Line one\nعينة",
				'items' => array( array( 'product_id' => 1, 'sku' => 'T-1', 'name' => 'Sample Product', 'family' => 'Samples', 'qty' => 3 ) ),
				'client' => array( 'ip' => '212.30.36.41', 'browser' => 'Chrome', 'browser_version' => '141', 'os' => 'Android', 'device' => 'mobile' ),
			),
			$over
		);
	}

	private function admin_ctx(): array {
		return array( 'recipients' => array( 'sales@example.test', 'info@example.test' ), 'prefix' => 'Test Site', 'admin_url' => 'https://example.test/wp-admin/post.php?post=5&action=edit' );
	}

	public function test_admin_message_content(): void {
		$m = Mailer::admin_message( $this->quote(), $this->admin_ctx() );
		$this->assertSame( array( 'sales@example.test', 'info@example.test' ), $m['to'] );
		$this->assertSame( '[Test Site] Quote request Q-2026-0007 from Ahmed Ali (Cairo)', $m['subject'] );
		$this->assertStringContainsString( 'Sample Product', $m['html'] );
		$this->assertStringContainsString( 'عينة', $m['html'] );
		$this->assertStringContainsString( 'mobile, Chrome 141, Android', $m['html'] );
		$this->assertStringNotContainsString( '212.30.36.41', $m['html'] . $m['text'] );
		$this->assertStringNotContainsString( '<b>Ali</b>', $m['html'] );
		$this->assertContains( 'Reply-To: buyer@example.com', $m['headers'] );
	}

	public function test_no_price_words_anywhere(): void {
		$m = Mailer::admin_message( $this->quote(), $this->admin_ctx() );
		$c = Mailer::customer_message( $this->quote(), array( 'prefix' => 'Test Site', 'site_url' => 'https://example.test', 'thanks' => 'Thank you, Ahmed.' ) );
		foreach ( array( $m['html'], $m['text'], $c['html'], $c['text'] ) as $body ) {
			$this->assertDoesNotMatchRegularExpression( '/price|subtotal|total|EGP|\$|€/i', $body );
		}
	}

	public function test_no_reply_to_without_valid_email(): void {
		$m = Mailer::admin_message( $this->quote( array( 'email' => '' ) ), $this->admin_ctx() );
		foreach ( $m['headers'] as $h ) {
			$this->assertStringStartsNotWith( 'Reply-To', $h );
		}
		$this->assertSame( '', Mailer::reply_to( 'X', 'not-an-email' ) );
	}

	public function test_reply_to_is_the_bare_address_whatever_the_name(): void {
		// A display name with a comma ("Acme, Ltd") splits into two addresses and mail services reject the message.
		$this->assertSame( 'Reply-To: buyer@example.com', Mailer::reply_to( 'Unisolva test, please ignore', 'buyer@example.com' ) );
		$this->assertSame( 'Reply-To: buyer@example.com', Mailer::reply_to( "Evil\r\nBcc: x@y.z", 'buyer@example.com' ) );
	}

	public function test_customer_message_only_with_email(): void {
		$this->assertNull( Mailer::customer_message( $this->quote( array( 'email' => '' ) ), array( 'prefix' => 'S', 'site_url' => 'https://example.test', 'thanks' => 'Thanks' ) ) );
		$c = Mailer::customer_message( $this->quote(), array( 'prefix' => 'Test Site', 'site_url' => 'https://example.test', 'thanks' => 'Thank you, Ahmed.' ) );
		$this->assertSame( array( 'buyer@example.com' ), $c['to'] );
		$this->assertSame( '[Test Site] We received your quote request Q-2026-0007', $c['subject'] );
		$this->assertStringContainsString( 'Thank you, Ahmed.', $c['html'] );
		$this->assertStringNotContainsString( '212.30.36.41', $c['html'] );
	}

	// ---- The subject prefix belongs to the team email; the customer's copy carries the site name. ----

	private function team_subject(): string {
		return Mailer::admin_message( $this->quote(), Mailer::admin_context( 'https://example.test/wp-admin/post.php?post=5&action=edit' ) )['subject'];
	}

	private function customer_copy(): array {
		return Mailer::customer_message( $this->quote(), Mailer::customer_context( $this->quote(), 'https://example.test/' ) );
	}

	public function test_team_subject_with_a_custom_prefix(): void {
		qr_test_set_option( Settings::OPTION, array( 'contact_rule' => 'either', 'subject_prefix' => 'New Quote Request', 'recipients' => array( 'sales@example.test' ) ) );
		$this->assertSame( '[New Quote Request] Quote request Q-2026-0007 from Ahmed Ali (Cairo)', $this->team_subject() );
		$ctx = Mailer::admin_context( 'https://example.test/wp-admin/post.php?post=5&action=edit' );
		$this->assertSame( array( 'recipients', 'prefix', 'admin_url' ), array_keys( $ctx ) );
		$this->assertSame( array( 'sales@example.test' ), $ctx['recipients'] );
		$this->assertSame( 'https://example.test/wp-admin/post.php?post=5&action=edit', $ctx['admin_url'] );
	}

	public function test_team_subject_with_an_empty_prefix_carries_the_site_name(): void {
		foreach ( array( '', '   ' ) as $empty ) {
			qr_test_set_option( Settings::OPTION, array( 'contact_rule' => 'either', 'subject_prefix' => $empty ) );
			$this->assertSame( '[Test Site] Quote request Q-2026-0007 from Ahmed Ali (Cairo)', $this->team_subject() );
		}
	}

	public function test_the_customer_copy_ignores_the_custom_prefix(): void {
		qr_test_set_option( Settings::OPTION, array( 'contact_rule' => 'either', 'subject_prefix' => 'New Quote Request', 'thanks_text' => 'Thank you, %name%.' ) );
		$c = $this->customer_copy();
		$this->assertSame( '[Test Site] We received your quote request Q-2026-0007', $c['subject'] );
		$this->assertStringContainsString( '<p><a href="https://example.test/">Test Site</a></p>', $c['html'], 'the link at the end shows the site name' );
		$this->assertStringContainsString( "\nTest Site https://example.test/\n", $c['text'] );
		$this->assertStringNotContainsString( 'New Quote Request', $c['subject'] . $c['html'] . $c['text'] );
		$this->assertStringContainsString( 'Thank you, Ahmed Ali.', $c['text'], 'the greeting is the thank-you text with the cleaned name' );
		$this->assertSame( array( 'prefix', 'site_url', 'thanks' ), array_keys( Mailer::customer_context( $this->quote(), 'https://example.test/' ) ) );
	}

	public function test_the_customer_copy_carries_the_site_name_with_an_empty_prefix_too(): void {
		$this->assertSame( '[Test Site] We received your quote request Q-2026-0007', $this->customer_copy()['subject'] );
	}

	public function test_a_site_with_a_title_keeps_it_as_the_site_name(): void {
		$GLOBALS['qr_test_blogname'] = 'Example Shop';
		$this->assertSame( 'Example Shop', Settings::site_name() );
		$this->assertSame( '[Example Shop] We received your quote request Q-2026-0007', $this->customer_copy()['subject'] );
	}

	public function test_a_site_without_a_title_is_named_by_its_host(): void {
		foreach ( array( '', '   ', "\t\n" ) as $empty ) {
			$GLOBALS['qr_test_blogname'] = $empty;
			$this->assertSame( 'example.test', Settings::site_name(), 'the host of home_url()' );
			$this->assertSame( 'example.test', Settings::subject_prefix(), 'the team email with an empty prefix' );
			$c = $this->customer_copy();
			$this->assertSame( '[example.test] We received your quote request Q-2026-0007', $c['subject'], 'never "[] We received"' );
			$this->assertStringContainsString( '<p><a href="https://example.test/">example.test</a></p>', $c['html'], 'the link at the end has a text' );
		}
	}

	public function test_a_site_title_stored_with_entities_reaches_the_subjects_as_plain_text(): void {
		$GLOBALS['qr_test_blogname'] = 'Fish &amp; Chips&#039; &quot;Shop&quot;'; // What WordPress stores for: Fish & Chips' "Shop".
		$this->assertSame( 'Fish & Chips\' "Shop"', Settings::site_name() );
		$this->assertSame( 'Fish & Chips\' "Shop"', Settings::subject_prefix() );
		$this->assertStringStartsWith( '[Fish & Chips\' "Shop"] Quote request ', $this->team_subject() );
		$c = $this->customer_copy();
		$this->assertSame( '[Fish & Chips\' "Shop"] We received your quote request Q-2026-0007', $c['subject'] );
		$this->assertStringContainsString( '>Fish &amp; Chips&#039; &quot;Shop&quot;</a>', $c['html'], 'escaped once in the HTML part' );
		$this->assertStringContainsString( "\nFish & Chips' \"Shop\" https://example.test/\n", $c['text'] );
	}

	public function test_a_prefix_the_owner_typed_is_used_as_typed(): void {
		qr_test_set_option( Settings::OPTION, array( 'contact_rule' => 'either', 'subject_prefix' => 'Sales &amp; more' ) );
		$this->assertSame( 'Sales &amp; more', Settings::subject_prefix() );
	}

	public function test_message_without_items(): void {
		$m = Mailer::admin_message( $this->quote( array( 'items' => array() ) ), $this->admin_ctx() );
		$this->assertStringContainsString( 'No products listed', $m['text'] );
	}

	public function test_customer_copy_carries_no_text_typed_by_the_sender(): void {
		$q = $this->quote( array( 'message' => 'BUY CHEAP PILLS http://spam.example', 'company' => 'SpamCo', 'fields' => array( $this->stored( 'project', 'Project details', 'text', 'spam-details' ), $this->stored( 'agree', 'Newsletter', 'checkbox', '1' ) ) ) );
		$c = Mailer::customer_message( $q, array( 'prefix' => 'Test Site', 'site_url' => 'https://example.test', 'thanks' => 'Thank you.' ) );
		foreach ( array( 'BUY CHEAP PILLS', 'spam.example', 'SpamCo', 'spam-details' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $c['html'] . $c['text'] );
		}
		$this->assertStringContainsString( 'Q-2026-0007', $c['html'] );
		$this->assertStringContainsString( 'Sample Product', $c['html'] );
	}

	public function test_team_message_lists_custom_fields_under_their_stored_labels(): void {
		$this->define_fields( array( array( 'key' => 'project', 'type' => 'text', 'label' => 'Project details', 'show' => true ) ) );
		$q = $this->quote( array( 'fields' => array( $this->stored( 'project', 'Project details', 'text', "Blue, 20 units\nSecond line" ) ) ) );
		$m = Mailer::admin_message( $q, $this->admin_ctx() );
		$this->assertStringContainsString( 'Project details', $m['html'] );
		$this->assertStringContainsString( 'Blue, 20 units<br />', $m['html'] );
		$this->assertStringContainsString( "Project details: Blue, 20 units\nSecond line", $m['text'] );
		$this->assertStringNotContainsString( 'Details:', $m['text'], 'there is no fixed Details row any more' );
	}

	public function test_team_message_row_order_is_reference_then_fields_then_device(): void {
		$q = $this->quote( array( 'fields' => array( $this->stored( 'project', 'Project details', 'text', 'Blue' ) ) ) );
		$m = Mailer::admin_message( $q, $this->admin_ctx() );
		$labels = array( 'Reference: ', 'Name: ', 'Phone: ', 'Email: ', 'Company: ', 'Region: ', 'Message: ', 'Project details: ', 'Device: ' );
		$last   = -1;
		foreach ( $labels as $label ) {
			$at = strpos( $m['text'], $label );
			$this->assertNotFalse( $at, $label );
			$this->assertGreaterThan( $last, $at, $label );
			$last = $at;
		}
	}

	public function test_choose_mode_puts_the_country_in_the_subject_and_the_region_row(): void {
		qr_test_set_option( Settings::OPTION, array( 'region_mode' => 'choose', 'contact_rule' => 'either' ) );
		Regions::$country_names = array( 'US' => 'United States', 'GE' => 'Georgia' );
		try {
			$m = Mailer::admin_message( $this->quote( array( 'region_country' => 'GE', 'region_code' => '', 'region_name' => 'Georgia' ) ), $this->admin_ctx() );
			$this->assertSame( '[Test Site] Quote request Q-2026-0007 from Ahmed Ali (Georgia, Georgia)', $m['subject'] );
			$this->assertStringContainsString( 'Region: Georgia, Georgia', $m['text'] );
			$m = Mailer::admin_message( $this->quote( array( 'region_country' => 'US', 'region_code' => 'GA', 'region_name' => 'Georgia' ) ), $this->admin_ctx() );
			$this->assertStringEndsWith( '(Georgia, United States)', $m['subject'] );
			$this->assertStringContainsString( 'Region: Georgia, United States', $m['text'] );
			$m = Mailer::admin_message( $this->quote( array( 'region_country' => 'US', 'region_code' => '', 'region_name' => '' ) ), $this->admin_ctx() );
			$this->assertStringEndsWith( '(United States)', $m['subject'] );
			$this->assertStringContainsString( 'Region: United States', $m['text'] );
			$m = Mailer::admin_message( $this->quote( array( 'region_country' => '', 'region_code' => '', 'region_name' => '' ) ), $this->admin_ctx() );
			$this->assertSame( '[Test Site] Quote request Q-2026-0007 from Ahmed Ali', $m['subject'] );
			$this->assertStringNotContainsString( 'Region:', $m['text'] );
		} finally {
			Regions::$country_names = null;
		}
	}

	public function test_single_mode_with_a_foreign_country_shows_it_and_the_default_country_does_not(): void {
		qr_test_set_option( Settings::OPTION, array( 'region_mode' => 'single', 'region_country' => 'US', 'contact_rule' => 'either' ) );
		Regions::$country_names = array( 'US' => 'United States', 'CA' => 'Canada' );
		try {
			$m = Mailer::admin_message( $this->quote( array( 'region_country' => 'US', 'region_name' => 'Texas' ) ), $this->admin_ctx() );
			$this->assertStringEndsWith( '(Texas)', $m['subject'] );
			$m = Mailer::admin_message( $this->quote( array( 'region_country' => 'CA', 'region_name' => 'Ontario' ) ), $this->admin_ctx() );
			$this->assertStringEndsWith( '(Ontario, Canada)', $m['subject'] );
		} finally {
			Regions::$country_names = null;
		}
	}

	public function test_a_stored_label_with_markup_is_escaped_in_the_html_and_never_raw(): void {
		$q = $this->quote( array( 'fields' => array( $this->stored( 'x', '<img src=x onerror=alert(1)>Fish & "chips"', 'text', 'v' ) ) ) );
		$m = Mailer::admin_message( $q, $this->admin_ctx() );
		$this->assertStringNotContainsString( '<img', $m['html'] );
		$this->assertStringContainsString( 'Fish &amp; &quot;chips&quot;', $m['html'] );
	}

	public function test_a_field_labelled_like_a_fixed_row_does_not_replace_it(): void {
		$q = $this->quote(
			array(
				'fields' => array(
					$this->stored( 'a', 'Reference', 'text', 'my own ref' ),
					$this->stored( 'b', 'Device', 'text', 'my own device' ),
				),
			)
		);
		$m = Mailer::admin_message( $q, $this->admin_ctx() );
		$this->assertStringContainsString( 'Reference: Q-2026-0007', $m['text'] );
		$this->assertStringContainsString( 'Reference (2): my own ref', $m['text'] );
		$this->assertStringContainsString( 'Device: mobile, Chrome 141, Android', $m['text'] );
		$this->assertStringContainsString( 'Device (2): my own device', $m['text'] );
	}

	public function test_a_field_labelled_device_is_kept_when_there_is_no_device_row(): void {
		$q = $this->quote( array( 'client' => array(), 'fields' => array( $this->stored( 'b', 'Device', 'text', 'my own device' ) ) ) );
		$m = Mailer::admin_message( $q, $this->admin_ctx() );
		$this->assertStringContainsString( 'Device: my own device', $m['text'] );
		$this->assertStringNotContainsString( 'Device (2)', $m['text'] );
	}

	public function test_team_message_uses_current_labels_for_system_rows(): void {
		$GLOBALS['qr_test_filters']['quote_requests_fields'][] = static function ( array $defs ): array {
			foreach ( $defs as $i => $def ) {
				if ( 'phone' === $def['key'] ) {
					$defs[ $i ]['label'] = 'Telephone';
				}
			}
			return $defs;
		};
		$m = Mailer::admin_message( $this->quote(), $this->admin_ctx() );
		$this->assertStringContainsString( 'Telephone: +15550100000', $m['text'] );
		$this->assertStringNotContainsString( 'Phone:', $m['text'] );
	}

	public function test_team_message_cleans_the_name_row_and_escapes_custom_values(): void {
		$q = $this->quote( array( 'fields' => array( $this->stored( 'project', 'Project details', 'text', '<script>alert(1)</script> & more' ) ) ) );
		$m = Mailer::admin_message( $q, $this->admin_ctx() );
		$this->assertStringContainsString( 'Name: Ahmed Ali', $m['text'] );
		$this->assertStringNotContainsString( '<script>', $m['html'] );
		$this->assertStringContainsString( '&lt;script&gt;', $m['html'] );
		$this->assertStringContainsString( '&amp; more', $m['html'] );
	}

	public function test_team_message_shows_a_checked_checkbox_as_yes_and_skips_empty_values(): void {
		$q = $this->quote(
			array(
				'fields' => array(
					$this->stored( 'agree', 'Newsletter', 'checkbox', '1' ),
					$this->stored( 'empty', 'Left blank', 'text', '' ),
				),
			)
		);
		$m = Mailer::admin_message( $q, $this->admin_ctx() );
		$this->assertStringContainsString( 'Newsletter: Yes', $m['text'] );
		$this->assertStringNotContainsString( 'Left blank', $m['text'] . $m['html'] );
	}

	/** Review Focus 4: a field renamed or deleted in settings after a quote was stored with it. */
	public function test_a_renamed_field_keeps_its_stored_label_in_old_quotes(): void {
		$this->define_fields( array( array( 'key' => 'project', 'type' => 'text', 'label' => 'Brief', 'show' => true ) ) );
		$q = $this->quote( array( 'fields' => array( $this->stored( 'project', 'Project details', 'text', 'Blue, 20 units' ) ) ) );
		$m = Mailer::admin_message( $q, $this->admin_ctx() );
		$this->assertStringContainsString( 'Project details: Blue, 20 units', $m['text'] );
		$this->assertStringNotContainsString( 'Brief', $m['text'] . $m['html'] );
	}

	public function test_a_deleted_field_and_a_deleted_option_still_show_in_old_quotes(): void {
		$this->define_fields(
			array(
				array(
					'key'     => 'size',
					'type'    => 'select',
					'label'   => 'Size',
					'show'    => true,
					'options' => array( array( 'value' => 'large', 'label' => 'Large' ) ),
				),
			)
		);
		$q = $this->quote(
			array(
				'fields' => array(
					$this->stored( 'project', 'Project details', 'text', 'Blue, 20 units' ), // Field no longer defined.
					$this->stored( 'size', 'Size', 'select', 'small' ), // Option "small" no longer offered.
				),
			)
		);
		$m = Mailer::admin_message( $q, $this->admin_ctx() );
		$this->assertStringContainsString( 'Project details: Blue, 20 units', $m['text'] );
		$this->assertStringContainsString( 'Size: small', $m['text'] );
	}

	public function test_a_hidden_system_field_still_shows_what_an_old_quote_holds(): void {
		$this->define_fields( array() );
		$GLOBALS['qr_test_filters']['quote_requests_fields'][] = static function ( array $defs ): array {
			foreach ( $defs as $i => $def ) {
				if ( 'company' === $def['key'] ) {
					$defs[ $i ]['show'] = false;
				}
			}
			return $defs;
		};
		$m = Mailer::admin_message( $this->quote(), $this->admin_ctx() );
		$this->assertStringContainsString( 'Company: Acme', $m['text'] );
	}

	public function test_customer_message_carries_no_custom_field_value_or_label(): void {
		$q = $this->quote( array( 'fields' => array( $this->stored( 'project', 'Project details', 'text', 'private-note' ) ) ) );
		$c = Mailer::customer_message( $q, array( 'prefix' => 'Test Site', 'site_url' => 'https://example.test', 'thanks' => 'Thank you.' ) );
		foreach ( array( 'private-note', 'Project details', 'Acme', 'Cairo' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $c['html'] . $c['text'] );
		}
		$this->assertStringContainsString( 'Reference: Q-2026-0007', $c['text'] );
		$this->assertStringContainsString( 'Sample Product', $c['text'] );
	}
}
