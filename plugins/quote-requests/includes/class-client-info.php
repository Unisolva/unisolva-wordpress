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

final class Client_Info {

	public static function ip( array $server, bool $trust_proxy ): string {
		$candidates = array();
		if ( $trust_proxy ) {
			$candidates[] = (string) ( $server['HTTP_CF_CONNECTING_IP'] ?? '' );
			$xff          = (string) ( $server['HTTP_X_FORWARDED_FOR'] ?? '' );
			$candidates[] = trim( explode( ',', $xff )[0] );
		}
		$candidates[] = (string) ( $server['REMOTE_ADDR'] ?? '' );
		foreach ( $candidates as $ip ) {
			if ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return '';
	}

	public static function parse_ua( string $ua ): array {
		$out = array(
			'browser'         => 'Other',
			'browser_version' => '',
			'os'              => 'Other',
			'device'          => 'unknown',
		);
		if ( '' === trim( $ua ) ) {
			return $out;
		}
		if ( preg_match( '/bot|crawler|spider|slurp|facebookexternalhit|headless/i', $ua ) ) {
			$out['device'] = 'bot';
			return $out;
		}
		$browsers = array(
			'Edge'             => '/Edg(?:e|A|iOS)?\/(\d+)/',
			'Opera'            => '/(?:OPR|Opera)\/(\d+)/',
			'Samsung Internet' => '/SamsungBrowser\/(\d+)/',
			'Firefox'          => '/(?:Firefox|FxiOS)\/(\d+)/',
			'Chrome'           => '/(?:Chrome|CriOS)\/(\d+)/',
			'Safari'           => '/Version\/(\d+).*Safari/',
		);
		foreach ( $browsers as $name => $re ) {
			if ( preg_match( $re, $ua, $m ) ) {
				$out['browser']         = $name;
				$out['browser_version'] = $m[1];
				break;
			}
		}
		if ( preg_match( '/iPhone|iPad|iPod/', $ua ) ) {
			$out['os'] = 'iOS';
		} elseif ( false !== strpos( $ua, 'Android' ) ) {
			$out['os'] = 'Android';
		} elseif ( false !== strpos( $ua, 'Windows' ) ) {
			$out['os'] = 'Windows';
		} elseif ( false !== strpos( $ua, 'CrOS' ) ) {
			$out['os'] = 'ChromeOS';
		} elseif ( false !== strpos( $ua, 'Mac OS X' ) ) {
			$out['os'] = 'macOS';
		} elseif ( false !== strpos( $ua, 'Linux' ) ) {
			$out['os'] = 'Linux';
		}
		if ( preg_match( '/iPad|Tablet/', $ua ) || ( 'Android' === $out['os'] && false === strpos( $ua, 'Mobile' ) ) ) {
			$out['device'] = 'tablet';
		} elseif ( preg_match( '/Mobi|iPhone|iPod/', $ua ) ) {
			$out['device'] = 'mobile';
		} else {
			$out['device'] = 'desktop';
		}
		return $out;
	}

	public static function language( string $accept ): string {
		$first = trim( explode( ';', explode( ',', $accept )[0] )[0] );
		return preg_match( '/^[A-Za-z]{1,8}(?:-[A-Za-z0-9]{1,8})*$/', $first ) ? substr( $first, 0, 35 ) : '';
	}

	public static function same_site_path( string $url, string $home ): string {
		$u = wp_parse_url( $url );
		$h = wp_parse_url( $home );
		if ( ! is_array( $u ) || empty( $u['host'] ) || ! is_array( $h ) || strtolower( $u['host'] ) !== strtolower( (string) ( $h['host'] ?? '' ) ) ) {
			return '';
		}
		$path = ( $u['path'] ?? '/' ) . ( isset( $u['query'] ) ? '?' . $u['query'] : '' );
		return substr( $path, 0, 500 );
	}

	public static function referrer( string $url, string $home ): string {
		$u = wp_parse_url( $url );
		$h = wp_parse_url( $home );
		if ( ! is_array( $u ) || ! in_array( strtolower( (string) ( $u['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || empty( $u['host'] ) ) {
			return '';
		}
		if ( is_array( $h ) && strtolower( $u['host'] ) === strtolower( (string) ( $h['host'] ?? '' ) ) ) {
			return '';
		}
		return substr( strtolower( $u['scheme'] ) . '://' . strtolower( $u['host'] ) . ( $u['path'] ?? '/' ), 0, 500 );
	}

	public static function collect( array $server, array $posted, bool $trust_proxy, string $home ): array {
		$ua     = substr( (string) ( $server['HTTP_USER_AGENT'] ?? '' ), 0, 500 );
		$parsed = self::parse_ua( $ua );
		return array(
			'ip'              => self::ip( $server, $trust_proxy ),
			'user_agent'      => sanitize_text_field( $ua ),
			'browser'         => $parsed['browser'],
			'browser_version' => $parsed['browser_version'],
			'os'              => $parsed['os'],
			'device'          => $parsed['device'],
			'language'        => self::language( (string) ( $server['HTTP_ACCEPT_LANGUAGE'] ?? '' ) ),
			'tz_offset'       => max( -840, min( 840, (int) ( $posted['tz'] ?? 0 ) ) ),
			'landing'         => self::same_site_path( (string) ( $posted['landing'] ?? '' ), $home ),
			'referrer'        => self::referrer( (string) ( $posted['referrer'] ?? '' ), $home ),
			'page'            => self::same_site_path( (string) ( $posted['page'] ?? '' ), $home ),
		);
	}
}
