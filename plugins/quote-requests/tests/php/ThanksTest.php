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
use Quote_Requests\Settings;
use Quote_Requests\Thanks;

final class ThanksTest extends TestCase {

	protected function setUp(): void {
		qr_test_reset_options( array( 'admin_email' => 'admin@example.test' ) );
		$GLOBALS['qr_test_filters']     = array();
		$GLOBALS['qr_test_shop_page']   = 0;
		$GLOBALS['qr_test_shop_status'] = 'publish';
	}

	protected function tearDown(): void {
		qr_test_reset_options( array() );
		$GLOBALS['qr_test_filters']     = array();
		$GLOBALS['qr_test_shop_page']   = 0;
		$GLOBALS['qr_test_shop_status'] = 'publish';
	}

	private function link( string $key, string $url, string $label ): array {
		return array(
			'key'   => $key,
			'url'   => $url,
			'label' => $label,
		);
	}

	private function quote(): array {
		return array(
			'id'  => 5,
			'ref' => 'Q-2026-0007',
		);
	}

	// ---- The two built-in links. ----

	public function test_defaults_are_the_shop_page_then_the_home_page(): void {
		$this->assertSame(
			array(
				$this->link( 'continue', 'https://example.test/shop/', 'Continue browsing products' ),
				$this->link( 'home', 'https://example.test/', 'Back to home page' ),
			),
			Thanks::defaults( 'https://example.test/shop/', 'https://example.test/', 'Continue browsing products', 'Back to home page' )
		);
	}

	public function test_without_a_shop_page_the_continue_link_is_left_out(): void {
		$this->assertSame(
			array( $this->link( 'home', 'https://example.test/', 'Back to home page' ) ),
			Thanks::defaults( '', 'https://example.test/', 'Continue browsing products', 'Back to home page' )
		);
	}

	// ---- links(): settings, the shop page and the filter. ----

	public function test_links_with_a_shop_page_uses_the_labels_of_the_settings(): void {
		$GLOBALS['qr_test_shop_page'] = 9;
		qr_test_set_option(
			Settings::OPTION,
			array(
				'contact_rule'          => 'either',
				'thanks_continue_label' => 'See all products',
			)
		);
		$this->assertSame(
			array(
				$this->link( 'continue', 'https://example.test/shop/', 'See all products' ),
				$this->link( 'home', 'https://example.test/', 'Back to home page' ),
			),
			Thanks::links( $this->quote() )
		);
	}

	public function test_links_without_a_shop_page_is_the_home_link_alone(): void {
		$this->assertSame( array( $this->link( 'home', 'https://example.test/', 'Back to home page' ) ), Thanks::links( $this->quote() ) );
	}

	public static function statuses_visitors_cannot_open(): array {
		return array(
			'draft'   => array( 'draft' ),
			'private' => array( 'private' ),
			'pending' => array( 'pending' ),
			'trash'   => array( 'trash' ),
			'deleted' => array( false ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'statuses_visitors_cannot_open' )]
	public function test_links_with_a_shop_page_that_is_not_published_is_the_home_link_alone( $status ): void {
		$GLOBALS['qr_test_shop_page']   = 9;
		$GLOBALS['qr_test_shop_status'] = $status;
		$this->assertSame( array( $this->link( 'home', 'https://example.test/', 'Back to home page' ) ), Thanks::links( $this->quote() ) );
	}

	public function test_links_switched_off_gives_none_and_the_filter_does_not_run(): void {
		$GLOBALS['qr_test_shop_page'] = 9;
		$calls                        = 0;
		add_filter(
			'quote_requests_thanks_links',
			static function ( $links ) use ( &$calls ) {
				++$calls;
				return $links;
			}
		);
		qr_test_set_option(
			Settings::OPTION,
			array(
				'contact_rule' => 'either',
				'thanks_links' => false,
			)
		);
		$this->assertSame( array(), Thanks::links( $this->quote() ) );
		$this->assertSame( 0, $calls );
	}

	public function test_the_filter_gets_the_built_links_and_the_quote(): void {
		$seen = array();
		add_filter(
			'quote_requests_thanks_links',
			static function ( $links, $quote ) use ( &$seen ) {
				$seen = array( $links, $quote );
				return $links;
			},
			10,
			2
		);
		Thanks::links( $this->quote() );
		$this->assertSame( array( array( $this->link( 'home', 'https://example.test/', 'Back to home page' ) ), $this->quote() ), $seen );
	}

	public function test_the_filter_can_reorder_add_and_remove_links(): void {
		$GLOBALS['qr_test_shop_page'] = 9;
		add_filter(
			'quote_requests_thanks_links',
			function ( array $links ): array {
				$links   = array_reverse( $links );
				$links[] = $this->link( 'catalogue', '/catalogue.pdf', 'Download the catalogue' );
				return $links;
			}
		);
		$this->assertSame(
			array(
				$this->link( 'home', 'https://example.test/', 'Back to home page' ),
				$this->link( 'continue', 'https://example.test/shop/', 'Continue browsing products' ),
				$this->link( 'catalogue', '/catalogue.pdf', 'Download the catalogue' ),
			),
			Thanks::links( $this->quote() )
		);

		$GLOBALS['qr_test_filters'] = array();
		add_filter( 'quote_requests_thanks_links', static fn(): array => array() );
		$this->assertSame( array(), Thanks::links( $this->quote() ), 'a callback may remove every link' );
	}

	public static function results_that_are_not_a_list(): array {
		return array(
			'null'   => array( null ),
			'false'  => array( false ),
			'string' => array( 'https://example.test/' ),
			'number' => array( 7 ),
			'object' => array( new stdClass() ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'results_that_are_not_a_list' )]
	public function test_a_filter_result_that_is_not_an_array_is_ignored( $junk ): void {
		add_filter( 'quote_requests_thanks_links', static fn() => $junk );
		$this->assertSame( array( $this->link( 'home', 'https://example.test/', 'Back to home page' ) ), Thanks::links( $this->quote() ), 'the built links stay' );
	}

	public function test_a_callback_that_throws_is_ignored_and_the_built_links_stay(): void {
		$GLOBALS['qr_test_shop_page'] = 9;
		$built                        = array(
			$this->link( 'continue', 'https://example.test/shop/', 'Continue browsing products' ),
			$this->link( 'home', 'https://example.test/', 'Back to home page' ),
		);
		add_filter(
			'quote_requests_thanks_links',
			static function (): array {
				throw new RuntimeException( 'boom' );
			}
		);
		$this->assertSame( $built, Thanks::links( $this->quote() ), 'an exception' );

		// One callback returns null by mistake, the next one is typed as the documentation shows: PHP throws a TypeError.
		$GLOBALS['qr_test_filters'] = array();
		add_filter( 'quote_requests_thanks_links', static fn() => null );
		add_filter( 'quote_requests_thanks_links', static fn( array $links ): array => array_reverse( $links ) );
		$this->assertSame( $built, Thanks::links( $this->quote() ), 'an error' );
	}

	public function test_a_callback_that_throws_is_logged_when_debugging_is_on_and_the_running_hooks_are_put_back(): void {
		$lines                         = array();
		Quote_Requests\Hook_Guard::$debug = true;
		Quote_Requests\Hook_Guard::$log   = static function ( string $line ) use ( &$lines ): void {
			$lines[] = $line;
		};
		$GLOBALS['wp_current_filter']  = array( 'outer_hook' );
		add_filter(
			'quote_requests_thanks_links',
			static function (): array {
				throw new RuntimeException( 'No links today' );
			}
		);
		try {
			$links = Thanks::links( $this->quote() );
			$left  = $GLOBALS['wp_current_filter'];
		} finally {
			Quote_Requests\Hook_Guard::$debug = null;
			Quote_Requests\Hook_Guard::$log   = null;
			$GLOBALS['wp_current_filter']  = array();
		}
		$this->assertSame( array( $this->link( 'home', 'https://example.test/', 'Back to home page' ) ), $links, 'the built links stay' );
		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'quote_requests_thanks_links failed for quote 5. RuntimeException: No links today', $lines[0] );
		$this->assertSame( array( 'outer_hook' ), $left );
	}

	// ---- harden(): what a list may hold. ----

	public function test_harden_drops_entries_without_a_usable_url_or_label(): void {
		$good = $this->link( 'a', 'https://example.com/a', 'A' );
		$list = array(
			'not an entry',
			null,
			array(),
			array( 'key' => 'no-url', 'label' => 'No address' ),
			array( 'key' => 'no-label', 'url' => 'https://example.com/b' ),
			array( 'key' => 'empty-url', 'url' => '   ', 'label' => 'Empty address' ),
			array( 'key' => 'empty-label', 'url' => 'https://example.com/c', 'label' => " \t " ),
			array( 'key' => 'array-url', 'url' => array( 'https://example.com/d' ), 'label' => 'Array' ),
			array( 'key' => 'array-label', 'url' => 'https://example.com/e', 'label' => array( 'E' ) ),
			array( 'key' => 'markup-only', 'url' => 'https://example.com/f', 'label' => '<b></b>' ),
			$good,
		);
		$this->assertSame( array( $good ), Thanks::harden( $list ) );
	}

	public static function urls(): array {
		return array(
			'https'                         => array( 'https://example.com/page?x=1&y=2#top', true ),
			'http'                          => array( 'http://example.com/', true ),
			'upper case scheme'             => array( 'HTTPS://example.com/', true ),
			'site-relative'                 => array( '/products/', true ),
			'site root'                     => array( '/', true ),
			'javascript'                    => array( 'javascript:alert(1)', false ),
			'javascript with a tab'         => array( "java\tscript:alert(1)", false ),
			'data'                          => array( 'data:text/html,<script>alert(1)</script>', false ),
			'mailto'                        => array( 'mailto:sales@example.com', false ),
			'tel'                           => array( 'tel:+15550100000', false ),
			'ftp'                           => array( 'ftp://example.com/file', false ),
			'another host without a scheme' => array( '//example.com/page', false ),
			'backslash form of the same'    => array( '/\\example.com/page', false ),
			'tab that a browser drops'      => array( "/\t/example.com/page", false ),
			'no scheme, no slash'           => array( 'example.com/page', false ),
			'relative path'                 => array( 'products/', false ),
			'query only'                    => array( '?page=2', false ),
			'scheme alone'                  => array( 'https://', false ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'urls' )]
	public function test_harden_keeps_only_http_https_and_site_relative_urls( string $url, bool $kept ): void {
		$out = Thanks::harden( array( $this->link( 'x', $url, 'Label' ) ) );
		$this->assertCount( $kept ? 1 : 0, $out, $url );
	}

	public function test_harden_cleans_the_url(): void {
		// Only what holds on WordPress too: the stand-in for esc_url_raw() drops a space inside an address, WordPress encodes it.
		$out = Thanks::harden( array( $this->link( 'x', "  https://example.com/a?b=1&c=2\n ", 'Label' ) ) );
		$this->assertSame( 'https://example.com/a?b=1&c=2', $out[0]['url'], 'the space around an address is cut, the address itself is kept as it is' );
	}

	public function test_harden_makes_labels_plain_text_of_at_most_100_characters(): void {
		$out = Thanks::harden(
			array(
				$this->link( 'markup', 'https://example.com/', " <b>Bold</b>\n<i>x</i> label " ),
				$this->link( 'long', 'https://example.com/', str_repeat( 'é', 150 ) ),
				array( 'key' => 'number', 'url' => 'https://example.com/', 'label' => 2026 ),
			)
		);
		$this->assertSame( 'Bold x label', $out[0]['label'] );
		$this->assertSame( str_repeat( 'é', 100 ), $out[1]['label'], 'cut by characters, not bytes' );
		$this->assertSame( '2026', $out[2]['label'] );
	}

	public function test_harden_gives_a_label_with_an_ampersand_or_a_lone_less_than_sign_as_the_characters_themselves(): void {
		// The script prints a label with textContent and the page without the script with esc_html(): both need the characters, not entities.
		$out = Thanks::harden(
			array(
				$this->link( 'lone', '/a', '< Back to home page' ),
				$this->link( 'amp', '/b', 'Fish & Chips' ),
				$this->link( 'entity', '/c', 'Fish &amp; Chips' ),
				$this->link( 'quotes', '/d', 'The "best" of Fish &#039;n&#039; Chips' ),
			)
		);
		$this->assertSame( '< Back to home page', $out[0]['label'], 'what sanitize_text_field() turns into &lt; is turned back' );
		$this->assertSame( 'Fish & Chips', $out[1]['label'] );
		$this->assertSame( 'Fish & Chips', $out[2]['label'] );
		$this->assertSame( 'The "best" of Fish \'n\' Chips', $out[3]['label'] );
		$this->assertSame( $out, Thanks::harden( $out ), 'cleaning the stored answer again changes nothing' );
		$long = Thanks::harden( array( $this->link( 'cut', '/e', str_repeat( '&amp;', 150 ) ) ) );
		$this->assertSame( str_repeat( '&', 100 ), $long[0]['label'], 'cut after decoding: 100 characters as the visitor sees them' );
	}

	public function test_harden_keeps_at_most_four_links_in_their_order(): void {
		$list = array();
		foreach ( array( 'a', 'b', 'c', 'd', 'e', 'f' ) as $k ) {
			$list[ 'k-' . $k ] = $this->link( $k, 'https://example.com/' . $k, strtoupper( $k ) );
		}
		$out = Thanks::harden( array_merge( array( 'junk' ), $list ) );
		$this->assertSame( array( 'a', 'b', 'c', 'd' ), array_column( $out, 'key' ), 'the first four usable entries' );
		$this->assertSame( array( 0, 1, 2, 3 ), array_keys( $out ), 'a plain list, whatever the keys were' );
	}

	public function test_harden_gives_every_link_a_plain_key(): void {
		$out = Thanks::harden(
			array(
				array( 'url' => '/a', 'label' => 'A' ),
				array( 'key' => 'My Link <b>!', 'url' => '/b', 'label' => 'B' ),
				array( 'key' => array( 'x' ), 'url' => '/c', 'label' => 'C' ),
				array( 'key' => 'catalogue-2', 'url' => '/d', 'label' => 'D', 'extra' => 'dropped' ),
			)
		);
		$this->assertSame( array( 'link', 'mylinkb', 'link', 'catalogue-2' ), array_column( $out, 'key' ) );
		$this->assertSame( array( 'key', 'url', 'label' ), array_keys( $out[3] ), 'only the three keys leave' );
	}

	public function test_harden_reads_anything_that_is_not_a_list_as_no_links(): void {
		foreach ( array( null, false, 'x', 5, new stdClass() ) as $junk ) {
			$this->assertSame( array(), Thanks::harden( $junk ) );
		}
	}

	public function test_what_links_returns_is_hardened(): void {
		add_filter(
			'quote_requests_thanks_links',
			fn(): array => array(
				$this->link( 'bad', 'javascript:alert(1)', 'Bad' ),
				$this->link( 'one', '/one', '<i>One</i>' ),
				$this->link( 'two', '/two', 'Two' ),
				$this->link( 'three', '/three', 'Three' ),
				$this->link( 'four', '/four', 'Four' ),
				$this->link( 'five', '/five', 'Five' ),
			)
		);
		$out = Thanks::links( $this->quote() );
		$this->assertSame( array( 'one', 'two', 'three', 'four' ), array_column( $out, 'key' ) );
		$this->assertSame( 'One', $out[0]['label'] );
	}
}
