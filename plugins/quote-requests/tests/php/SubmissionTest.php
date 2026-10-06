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
use Quote_Requests\Rate_Limit;
use Quote_Requests\Settings;
use Quote_Requests\Submission;
use Quote_Requests\Token;

/**
 * The public entry point, quote_requests_submit(), through the parts that need no database:
 * the shape of its answers, the refusals (not ready, rate limit, validation) and the source label.
 * A stored request is covered by tests/integration/entry-point.php.
 */
final class SubmissionTest extends TestCase {

	private const IP = '198.51.100.7';

	/** What $_SERVER held before the test. */
	private array $server = array();

	protected function setUp(): void {
		qr_test_reset_options( array( 'admin_email' => 'admin@example.test' ) );
		$GLOBALS['qr_test_filters']     = array();
		$GLOBALS['qr_test_post_types']  = array( 'quote_request' );
		$GLOBALS['qr_test_did_actions'] = array( 'init' => 1 );
		$this->server                   = $_SERVER;
		$_SERVER['REMOTE_ADDR']         = self::IP;
		// In-memory counter store with the same contract as the database one.
		Rate_Limit::$store = new class() {
			public array $rows = array();
			public function incr( string $key ): int {
				$this->rows[ $key ] = ( $this->rows[ $key ] ?? 0 ) + 1;
				return $this->rows[ $key ];
			}
			public function get( string $key ): int {
				return $this->rows[ $key ] ?? 0;
			}
		};
	}

	protected function tearDown(): void {
		qr_test_reset_options( array() );
		$GLOBALS['qr_test_filters']     = array();
		$GLOBALS['qr_test_post_types']  = array();
		$GLOBALS['qr_test_did_actions'] = array();
		$_SERVER                        = $this->server;
		Rate_Limit::$store              = null;
	}

	/** Uses up the hourly limit of one address. */
	private function exhaust( string $ip ): void {
		$limit = (int) Settings::get( 'rate_limit' );
		for ( $i = 0; $i < $limit; $i++ ) {
			Rate_Limit::take( $ip, $limit, Token::secret() );
		}
	}

	private function valid(): array {
		return array(
			'name'    => 'Sample Visitor',
			'phone'   => '+1 555 010 0199',
			'message' => 'Three doors and two windows.',
			'consent' => '1',
		);
	}

	// ---- The shape of the answers. ----

	public function test_the_answer_of_a_stored_request_is_ok_with_the_id_the_reference_and_the_dropped_lines(): void {
		$stored = array(
			'status' => 201,
			'id'     => 321,
			'body'   => array(
				'ok'     => true,
				'ref'    => 'Q-2026-0042',
				'name'   => 'Sample Visitor',
				'thanks'  => 'Thank you.',
				'dropped' => 2,
				'links'   => array(),
			),
		);
		$this->assertSame(
			array(
				'ok'      => true,
				'id'      => 321,
				'ref'     => 'Q-2026-0042',
				'dropped' => 2,
			),
			Submission::answer( $stored )
		);
	}

	public function test_the_answer_of_a_stored_request_without_a_count_of_dropped_lines_says_none(): void {
		$answer = Submission::answer(
			array(
				'status' => 201,
				'id'     => 5,
				'body'   => array(
					'ok'      => true,
					'ref'     => 'Q-2026-0001',
					'dropped' => 'junk',
				),
			)
		);
		$this->assertSame( array( 'ok', 'id', 'ref', 'dropped' ), array_keys( $answer ) );
		$this->assertSame( 0, $answer['dropped'] );
	}

	public function test_the_answer_of_a_refused_request_carries_code_message_and_the_errors_by_field(): void {
		$refused = array(
			'status' => 400,
			'body'   => array(
				'ok'      => false,
				'code'    => 'invalid',
				'message' => 'Please check the highlighted fields.',
				'errors'  => array( 'phone' => 'Please enter your phone or WhatsApp number.' ),
				'dropped' => 0,
			),
		);
		$this->assertSame(
			array(
				'ok'      => false,
				'code'    => 'invalid',
				'message' => 'Please check the highlighted fields.',
				'errors'  => array( 'phone' => 'Please enter your phone or WhatsApp number.' ),
			),
			Submission::answer( $refused )
		);
	}

	public function test_a_refusal_without_field_errors_has_an_empty_errors_list(): void {
		$answer = Submission::answer(
			array(
				'status' => 429,
				'body'   => array(
					'ok'      => false,
					'code'    => 'rate_limited',
					'message' => 'Too many requests.',
				),
			)
		);
		$this->assertSame( array( 'ok', 'code', 'message', 'errors' ), array_keys( $answer ) );
		$this->assertSame( array(), $answer['errors'] );
	}

	public function test_a_result_that_is_not_understood_is_a_failure_never_a_notice(): void {
		$this->assertSame(
			array(
				'ok'      => false,
				'code'    => 'failed',
				'message' => '',
				'errors'  => array(),
			),
			Submission::answer( array() )
		);
	}

	// ---- quote_requests_submit(): the refusals. ----

	public function test_before_the_plugin_is_ready_the_answer_is_not_ready(): void {
		$GLOBALS['qr_test_post_types']  = array();
		$GLOBALS['qr_test_did_actions'] = array();
		$answer                         = quote_requests_submit( $this->valid() );
		$this->assertFalse( $answer['ok'] );
		$this->assertSame( 'not_ready', $answer['code'] );
		$this->assertNotSame( '', $answer['message'] );
		$this->assertSame( array(), $answer['errors'] );
	}

	public function test_validation_errors_come_back_keyed_by_field(): void {
		$answer = quote_requests_submit( array( 'email' => 'not-an-address' ) );
		$this->assertFalse( $answer['ok'] );
		$this->assertSame( 'invalid', $answer['code'] );
		$this->assertSame( 'Please check the highlighted fields.', $answer['message'] );
		$this->assertSame( array( 'name', 'email', 'message', 'consent' ), array_keys( $answer['errors'] ) );
		$this->assertSame( 'Please enter your name.', $answer['errors']['name'] );
		$this->assertSame( 'Your quote list is empty. Please describe what you need.', $answer['errors']['message'] );
	}

	public function test_consent_is_the_consent_key_of_the_fields_as_the_built_in_form_posts_it(): void {
		$fields = $this->valid();
		unset( $fields['name'] ); // One error is left, so nothing is stored.
		foreach ( array( '1', 'on', 'yes', 'true', true, 1 ) as $tick ) {
			$fields['consent'] = $tick;
			$this->assertSame( array( 'name' ), array_keys( quote_requests_submit( $fields )['errors'] ), 'consent given as ' . var_export( $tick, true ) );
		}
		foreach ( array( '', '0', 'no', false, null, array( '1' ) ) as $none ) {
			$fields['consent'] = $none;
			$this->assertSame( array( 'name', 'consent' ), array_keys( quote_requests_submit( $fields )['errors'] ), 'no consent: ' . var_export( $none, true ) );
		}
	}

	public function test_an_address_over_the_limit_is_refused_as_rate_limited(): void {
		$this->exhaust( self::IP );
		$answer = quote_requests_submit( $this->valid() );
		$this->assertFalse( $answer['ok'] );
		$this->assertSame( 'rate_limited', $answer['code'] );
		$this->assertStringContainsString( 'Too many requests', $answer['message'] );
		$this->assertSame( array(), $answer['errors'] );
	}

	public function test_the_ip_argument_is_the_address_the_rate_limit_counts(): void {
		$this->exhaust( '203.0.113.9' );
		$fields = array( 'email' => 'not-an-address' ); // Refused by the validator when the rate limit lets it through.
		$this->assertSame( 'invalid', quote_requests_submit( $fields )['code'], 'the address of the request is under the limit' );
		$this->assertSame( 'rate_limited', quote_requests_submit( $fields, array(), array( 'ip' => '203.0.113.9' ) )['code'] );
		$this->exhaust( self::IP );
		$this->assertSame( 'rate_limited', quote_requests_submit( $fields )['code'] );
		$this->assertSame( 'invalid', quote_requests_submit( $fields, array(), array( 'ip' => '203.0.113.10' ) )['code'], 'another address is not affected' );
		$this->assertSame( 'rate_limited', quote_requests_submit( $fields, array(), array( 'ip' => 'not an address' ) )['code'], 'an argument that is no address means the address of the request' );
	}

	public function test_a_dry_run_gives_the_same_refusals_and_ok_for_a_request_that_would_be_stored(): void {
		$this->assertSame( array( 'ok' => true ), Submission::submit( $this->valid(), array(), array(), true ) );
		$refused = Submission::submit( array(), array(), array(), true );
		$this->assertSame( 'invalid', $refused['code'] );
		$this->assertArrayHasKey( 'name', $refused['errors'] );
		$this->exhaust( self::IP );
		$this->assertSame( 'rate_limited', Submission::submit( $this->valid(), array(), array(), true )['code'] );
	}

	public function test_a_dry_run_counts_nothing(): void {
		for ( $i = 0; $i < 20; $i++ ) {
			Submission::submit( $this->valid(), array(), array(), true );
		}
		$this->assertSame( array(), Rate_Limit::$store->rows );
	}

	// ---- What is read from the fields. ----

	public function test_only_field_keys_consent_and_the_country_are_read_from_the_fields(): void {
		$params = Submission::params(
			array(
				'name'           => 'Sample Visitor',
				'consent'        => 'on',
				'region_country' => 'FR',
				'token'          => 'x',
				'website'        => 'https://example.com/',
				'page'           => 'https://example.com/contact/',
				'landing'        => '/',
				'referrer'       => 'https://example.org/',
				'tz'             => 60,
				'items'          => array( array( 'id' => 9 ) ),
				'unknown'        => 'x',
			),
			array(
				5 => array(
					'id'  => 12,
					'qty' => 3,
				),
			)
		);
		$this->assertSame(
			array(
				'name'           => 'Sample Visitor',
				'consent'        => 'on',
				'region_country' => 'FR',
				'items'          => array(
					array(
						'id'  => 12,
						'qty' => 3,
					),
				),
			),
			$params
		);
	}

	public function test_a_custom_field_is_read_by_its_key(): void {
		$fields   = Quote_Requests\Fields::defaults();
		$fields[] = array(
			'key'   => 'project',
			'type'  => 'text',
			'label' => 'Project',
		);
		qr_test_set_option(
			Settings::OPTION,
			array(
				'contact_rule' => 'either',
				'fields'       => $fields,
			)
		);
		$params = Submission::params( array( 'project' => 'Warehouse' ), array() );
		$this->assertSame( 'Warehouse', $params['project'] );
	}

	// ---- The source label. ----

	public function test_a_source_label_keeps_lowercase_letters_digits_dash_and_underscore(): void {
		$this->assertSame( 'elementor', Submission::source_label( 'elementor' ) );
		$this->assertSame( 'my_form-2', Submission::source_label( ' My_Form-2! ' ) );
		$this->assertSame( 'scriptalert1script', Submission::source_label( '<script>alert(1)</script>' ) );
		$this->assertSame( str_repeat( 'a', 40 ), Submission::source_label( str_repeat( 'a', 60 ) ) );
		$this->assertSame( 40, strlen( Submission::source_label( str_repeat( '!', 30 ) . str_repeat( 'b', 60 ) ) ), 'cut after cleaning' );
	}

	public function test_an_empty_or_unusable_source_label_is_the_fallback(): void {
		$this->assertSame( 'form', Submission::source_label( '' ) );
		$this->assertSame( 'form', Submission::source_label( null ) );
		$this->assertSame( 'form', Submission::source_label( array( 'x' ) ) );
		$this->assertSame( 'form', Submission::source_label( '!!!' ) );
		$this->assertSame( 'other', Submission::source_label( '', Submission::SOURCE_OTHER ) );
	}

	// ---- The arguments: page, consent_text and privacy_url. ----

	public function test_the_arguments_are_cleaned_and_the_ones_not_given_are_empty(): void {
		$args = Submission::args( array() );
		$this->assertSame( array( 'source', 'ip', 'page', 'consent_text', 'privacy_url' ), array_keys( $args ) );
		$this->assertSame( 'other', $args['source'] );
		$this->assertNull( $args['ip'] );
		$this->assertSame( '', $args['page'] );
		$this->assertSame( '', $args['consent_text'] );
		$this->assertSame( '', $args['privacy_url'] );
		$this->assertSame( 'my-form', Submission::args( array( 'source' => 'My-Form' ) )['source'] );
		$this->assertSame( '203.0.113.9', Submission::args( array( 'ip' => '203.0.113.9' ) )['ip'] );
	}

	public function test_the_page_argument_is_kept_as_a_path_only_for_an_address_of_this_site(): void {
		$page = static fn( $value ): string => Submission::args( array( 'page' => $value ) )['page'];
		// The stubbed site is https://example.test.
		$this->assertSame( '/contact/?from=popup', $page( 'https://example.test/contact/?from=popup' ) );
		$this->assertSame( '/contact/', $page( 'http://EXAMPLE.test/contact/#form' ), 'http and another case of the host are the same site' );
		$this->assertSame( '/', $page( 'https://example.test' ) );
		$this->assertSame( '', $page( 'https://example.org/contact/' ), 'another site' );
		$this->assertSame( '', $page( 'https://example.test.example.org/contact/' ), 'another site that starts like this one' );
		$this->assertSame( '', $page( '//example.test/contact/' ), 'no scheme' );
		$this->assertSame( '', $page( '/contact/' ), 'a path is not an address' );
		$this->assertSame( '', $page( 'ftp://example.test/contact/' ) );
		$this->assertSame( '', $page( 'javascript:alert(1)//example.test/' ) );
		foreach ( array( '', 'junk', 'http://', 'http:///x', null, 12, true, array( 'https://example.test/' ), new stdClass() ) as $junk ) {
			$this->assertSame( '', $page( $junk ), var_export( $junk, true ) );
		}
		$this->assertSame( 500, strlen( $page( 'https://example.test/' . str_repeat( 'a', 900 ) ) ), 'cut as the page of the built-in form is' );
	}

	public function test_the_consent_text_argument_is_plain_text_of_at_most_1000_characters(): void {
		$text = static fn( $value ): string => Submission::args( array( 'consent_text' => $value ) )['consent_text'];
		$this->assertSame( 'I accept the terms & conditions.', $text( "  I accept the <a href=\"https://example.com/terms\">terms</a> & conditions.\n" ) );
		$this->assertSame( "Line one\nLine two", $text( "Line one\nLine two" ), 'a text of several lines keeps its lines' );
		$this->assertSame( 1000, mb_strlen( $text( str_repeat( 'é', 1500 ) ) ) );
		foreach ( array( '', "  \n ", '<b></b>', null, array( 'I agree' ), new stdClass(), true ) as $none ) {
			$this->assertSame( '', $text( $none ), var_export( $none, true ) );
		}
	}

	public function test_the_privacy_url_argument_is_an_http_or_https_address_or_nothing(): void {
		$url = static fn( $value ): string => Submission::args( array( 'privacy_url' => $value ) )['privacy_url'];
		$this->assertSame( 'https://example.com/privacy/', $url( ' https://example.com/privacy/ ' ) );
		$this->assertSame( 'http://example.org/p?x=1', $url( 'http://example.org/p?x=1' ) );
		foreach ( array( '', '/privacy/', '//example.com/privacy/', 'example.com/privacy', 'javascript:alert(1)', 'ftp://example.com/p', 'mailto:someone@example.com', 'https://', null, array( 'https://example.com/' ), new stdClass() ) as $none ) {
			$this->assertSame( '', $url( $none ), var_export( $none, true ) );
		}
	}

	// ---- The consent record. ----

	public function test_the_consent_record_names_the_text_and_the_address_the_caller_gives(): void {
		$consent = Submission::consent( true, Submission::args( array( 'consent_text' => 'I accept the terms.', 'privacy_url' => 'https://example.com/privacy/' ) ) );
		$this->assertSame( array( 'given', 'time', 'text', 'privacy_url' ), array_keys( $consent ) );
		$this->assertTrue( $consent['given'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT/', $consent['time'] );
		$this->assertSame( 'I accept the terms.', $consent['text'] );
		$this->assertSame( 'https://example.com/privacy/', $consent['privacy_url'] );
	}

	public function test_without_the_arguments_the_consent_record_names_the_text_of_the_settings(): void {
		foreach ( array( array(), Submission::args( array() ), Submission::args( array( 'consent_text' => '<b></b>', 'privacy_url' => 'junk' ) ) ) as $args ) {
			$consent = Submission::consent( true, $args );
			$this->assertSame( Settings::consent_text(), $consent['text'] );
			$this->assertSame( '', $consent['privacy_url'], 'no privacy page is set in the test settings' );
		}
		$only_text = Submission::consent( true, Submission::args( array( 'consent_text' => 'I accept.' ) ) );
		$this->assertSame( 'I accept.', $only_text['text'] );
		$this->assertSame( '', $only_text['privacy_url'] );
	}

	public function test_a_consent_that_was_not_given_records_no_text_whatever_the_caller_passes(): void {
		$this->assertSame(
			array(
				'given'       => false,
				'time'        => '',
				'text'        => '',
				'privacy_url' => '',
			),
			Submission::consent( false, Submission::args( array( 'consent_text' => 'I accept the terms.', 'privacy_url' => 'https://example.com/privacy/' ) ) )
		);
	}

	// ---- A callback on quote_requests_created that throws. ----

	public function test_a_created_callback_that_throws_does_not_reach_the_caller(): void {
		$seen = array();
		add_action(
			'quote_requests_created',
			static function ( $id ) use ( &$seen ) {
				$seen[] = $id;
				throw new RuntimeException( 'boom' );
			}
		);
		Submission::created( 77 );
		$this->assertSame( array( 77 ), $seen );
	}

	public function test_a_created_callback_that_raises_an_error_does_not_reach_the_caller(): void {
		add_action(
			'quote_requests_created',
			static function () {
				throw new TypeError( 'boom' );
			}
		);
		Submission::created( 78 );
		$this->addToAssertionCount( 1 ); // Reached: nothing was thrown.
	}

	public function test_a_created_callback_that_throws_is_logged_when_debugging_is_on(): void {
		$lines                         = array();
		Quote_Requests\Hook_Guard::$debug = true;
		Quote_Requests\Hook_Guard::$log   = static function ( string $line ) use ( &$lines ): void {
			$lines[] = $line;
		};
		$after = array();
		add_action(
			'quote_requests_created',
			static function () {
				throw new RuntimeException( 'The CRM said no' );
			}
		);
		add_action(
			'quote_requests_created',
			static function ( $id ) use ( &$after ) {
				$after[] = $id;
			}
		);
		try {
			Submission::created( 77 );
			Submission::created( 78 );
		} finally {
			Quote_Requests\Hook_Guard::$debug = null;
			Quote_Requests\Hook_Guard::$log   = null;
		}
		$this->assertCount( 2, $lines, 'one line for each request' );
		$this->assertStringContainsString( 'quote_requests_created failed for quote 77. RuntimeException: The CRM said no', $lines[0] );
		$this->assertStringContainsString( 'for quote 78.', $lines[1] );
		$this->assertSame( array(), $after, 'a callback after the failing one does not run for that request' );
	}

	public function test_a_created_callback_that_throws_is_not_logged_when_debugging_is_off(): void {
		$lines                         = array();
		Quote_Requests\Hook_Guard::$debug = false;
		Quote_Requests\Hook_Guard::$log   = static function ( string $line ) use ( &$lines ): void {
			$lines[] = $line;
		};
		add_action(
			'quote_requests_created',
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);
		try {
			Submission::created( 77 );
		} finally {
			Quote_Requests\Hook_Guard::$debug = null;
			Quote_Requests\Hook_Guard::$log   = null;
		}
		$this->assertSame( array(), $lines );
	}

	public function test_a_created_callback_that_throws_leaves_the_list_of_running_hooks_as_it_was(): void {
		Quote_Requests\Hook_Guard::$debug = false;
		$GLOBALS['wp_current_filter']  = array( 'outer_hook' );
		$inside                        = array();
		add_action(
			'quote_requests_created',
			static function () use ( &$inside ) {
				$inside = $GLOBALS['wp_current_filter'];
				throw new RuntimeException( 'boom' );
			}
		);
		try {
			Submission::created( 77 );
			$left = $GLOBALS['wp_current_filter'];
		} finally {
			Quote_Requests\Hook_Guard::$debug = null;
			$GLOBALS['wp_current_filter']  = array();
		}
		$this->assertSame( array( 'outer_hook', 'quote_requests_created' ), $inside, 'while the callback runs the action is the current one' );
		$this->assertSame( array( 'outer_hook' ), $left, 'afterwards the list is what it was before the action' );
	}
}
