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
use Quote_Requests\Client_Info;

final class ClientInfoTest extends TestCase {

	public function test_ip_uses_remote_addr_by_default_and_ignores_forged_headers(): void {
		$s = array( 'REMOTE_ADDR' => '212.30.36.41', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4', 'HTTP_X_FORWARDED_FOR' => '5.6.7.8' );
		$this->assertSame( '212.30.36.41', Client_Info::ip( $s, false ) );
	}

	public function test_ip_trusts_proxy_headers_only_when_enabled(): void {
		$this->assertSame( '1.2.3.4', Client_Info::ip( array( 'REMOTE_ADDR' => '172.70.1.1', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4' ), true ) );
		$this->assertSame( '5.6.7.8', Client_Info::ip( array( 'REMOTE_ADDR' => '172.70.1.1', 'HTTP_X_FORWARDED_FOR' => '5.6.7.8, 10.0.0.1' ), true ) );
		$this->assertSame( '172.70.1.1', Client_Info::ip( array( 'REMOTE_ADDR' => '172.70.1.1', 'HTTP_CF_CONNECTING_IP' => 'not-an-ip' ), true ) );
		$this->assertSame( '2a01:4f8::2', Client_Info::ip( array( 'REMOTE_ADDR' => '2a01:4f8::2' ), false ) );
		$this->assertSame( '', Client_Info::ip( array(), false ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'uas' )]
	public function test_parse_ua( string $ua, string $browser, string $os, string $device ): void {
		$p = Client_Info::parse_ua( $ua );
		$this->assertSame( $browser, $p['browser'], $ua );
		$this->assertSame( $os, $p['os'], $ua );
		$this->assertSame( $device, $p['device'], $ua );
	}

	public static function uas(): array {
		return array(
			array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'Chrome', 'Windows', 'desktop' ),
			array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', 'Edge', 'Windows', 'desktop' ),
			array( 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36', 'Samsung Internet', 'Android', 'mobile' ),
			array( 'Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Chrome', 'Android', 'tablet' ),
			array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1', 'Safari', 'iOS', 'mobile' ),
			array( 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', 'Safari', 'iOS', 'tablet' ),
			array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox', 'macOS', 'desktop' ),
			array( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 OPR/105.0', 'Opera', 'Linux', 'desktop' ),
			array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Other', 'Other', 'bot' ),
			array( '', 'Other', 'Other', 'unknown' ),
		);
	}

	public function test_browser_version_is_major_only(): void {
		$this->assertSame( '141', Client_Info::parse_ua( 'Mozilla/5.0 (Windows NT 10.0) Chrome/141.0.7390.37 Safari/537.36' )['browser_version'] );
	}

	public function test_language(): void {
		$this->assertSame( 'ar-EG', Client_Info::language( 'ar-EG,ar;q=0.9,en;q=0.8' ) );
		$this->assertSame( '', Client_Info::language( '<script>' ) );
		$this->assertSame( '', Client_Info::language( '' ) );
	}

	public function test_same_site_path_and_referrer(): void {
		$home = 'https://www.example.com';
		$this->assertSame( '/product/sample-product/?utm_source=fb', Client_Info::same_site_path( 'https://www.example.com/product/sample-product/?utm_source=fb', $home ) );
		$this->assertSame( '', Client_Info::same_site_path( 'https://evil.example/x', $home ) );
		$this->assertSame( 'https://www.google.com/', Client_Info::referrer( 'https://www.google.com/?q=sample+search', $home ) );
		$this->assertSame( '', Client_Info::referrer( 'https://www.example.com/about/', $home ) );
		$this->assertSame( '', Client_Info::referrer( 'javascript:alert(1)', $home ) );
	}

	public function test_page_is_the_path_of_an_http_or_https_address_on_the_host_of_the_site(): void {
		$home = 'https://www.example.com';
		$this->assertSame( '/contact/?a=1', Client_Info::page( 'https://www.example.com/contact/?a=1', $home ) );
		$this->assertSame( '/contact/', Client_Info::page( ' HTTP://www.example.com/contact/ ', $home ) );
		$this->assertSame( '', Client_Info::page( 'https://example.com/contact/', $home ), 'another host, also when it is the same domain' );
		$this->assertSame( '', Client_Info::page( 'https://evil.example/contact/', $home ) );
		$this->assertSame( '', Client_Info::page( '//www.example.com/contact/', $home ) );
		$this->assertSame( '', Client_Info::page( 'ftp://www.example.com/contact/', $home ) );
		$this->assertSame( '', Client_Info::page( 'not an address', $home ) );
		$this->assertSame( '', Client_Info::page( array( 'https://www.example.com/' ), $home ) );
		$this->assertSame( '', Client_Info::page( null, $home ) );
	}

	public function test_collect_shapes_everything(): void {
		$c = Client_Info::collect(
			array( 'REMOTE_ADDR' => '212.30.36.41', 'HTTP_USER_AGENT' => str_repeat( 'A', 900 ), 'HTTP_ACCEPT_LANGUAGE' => 'en-US' ),
			array( 'landing' => 'https://www.example.com/solutions/', 'referrer' => 'https://l.facebook.com/l.php?u=x', 'page' => 'https://www.example.com/request-a-quote/', 'tz' => '-180' ),
			false,
			'https://www.example.com'
		);
		$this->assertSame( '212.30.36.41', $c['ip'] );
		$this->assertSame( 500, strlen( $c['user_agent'] ) );
		$this->assertSame( '/solutions/', $c['landing'] );
		$this->assertSame( 'https://l.facebook.com/l.php', $c['referrer'] );
		$this->assertSame( '/request-a-quote/', $c['page'] );
		$this->assertSame( -180, $c['tz_offset'] );
		$this->assertSame( 'en-US', $c['language'] );
	}
}
