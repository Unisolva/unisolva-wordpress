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

// Minimal stand-ins for the WordPress functions the pure classes use.
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return filter_var( $url, FILTER_SANITIZE_URL ); }
/** Enough of esc_url_raw() for the tests: characters a URL cannot hold are removed. The schemes are checked by the caller. */
function esc_url_raw( $url, $protocols = null ) { return (string) filter_var( (string) $url, FILTER_SANITIZE_URL ); }
/** As WordPress does (wp_pre_kses_less_than), a "<" that opens no tag comes back as the entity, with the rest of that tail escaped; "&" stays as typed. */
function sanitize_text_field( $str ) {
	$str = preg_replace_callback( '%<[^>]*?((?=<)|>|$)%', static fn( $m ) => false === strpos( $m[0], '>' ) ? htmlspecialchars( $m[0], ENT_QUOTES, 'UTF-8', false ) : $m[0], (string) $str );
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $str ) ) );
}
function sanitize_textarea_field( $str ) { return trim( strip_tags( (string) $str ) ); }
function wp_strip_all_tags( $str ) { return trim( strip_tags( (string) $str ) ); }

$GLOBALS['qr_test_transients'] = array();
function get_transient( $key ) { return $GLOBALS['qr_test_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['qr_test_transients'][ $key ] = $value; return true; }

$GLOBALS['qr_test_options']      = array();
$GLOBALS['qr_test_option_reads'] = array(); // How often each option was read, by name.
function get_option( $key, $default = false ) {
	$GLOBALS['qr_test_option_reads'][ $key ] = ( $GLOBALS['qr_test_option_reads'][ $key ] ?? 0 ) + 1;
	return $GLOBALS['qr_test_options'][ $key ] ?? $default;
}
/** Sets one option the way update_option() does on a site: the settings kept for the request are dropped. */
function qr_test_set_option( $key, $value ) {
	$GLOBALS['qr_test_options'][ $key ] = $value;
	Quote_Requests\Settings::flush();
}
/** Replaces every option, for the start or the end of a test. */
function qr_test_reset_options( array $options ) {
	$GLOBALS['qr_test_options']      = $options;
	$GLOBALS['qr_test_option_reads'] = array();
	Quote_Requests\Settings::flush();
}
// The site title as WordPress stores it (special characters as HTML entities). A test may set $GLOBALS['qr_test_blogname'].
function get_bloginfo( $show = '' ) { return $GLOBALS['qr_test_blogname'] ?? 'Test Site'; }
function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $text, $quote_style ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
// The WooCommerce shop page: none unless a test sets $GLOBALS['qr_test_shop_page'] to a page ID.
$GLOBALS['qr_test_shop_page'] = 0;
function wc_get_page_id( $page ) { return 'shop' === $page && $GLOBALS['qr_test_shop_page'] > 0 ? (int) $GLOBALS['qr_test_shop_page'] : -1; }
function wc_get_page_permalink( $page ) { return wc_get_page_id( $page ) > 0 ? home_url( '/' . $page . '/' ) : home_url(); }
// The status of the shop page: published unless a test sets $GLOBALS['qr_test_shop_status']. Any other post does not exist.
$GLOBALS['qr_test_shop_status'] = 'publish';
function get_post_status( $post = null ) { return $GLOBALS['qr_test_shop_page'] > 0 && (int) $post === (int) $GLOBALS['qr_test_shop_page'] ? $GLOBALS['qr_test_shop_status'] : false; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
// The key the form token and the rate limit counters are signed with.
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
// The post types that are registered: none unless a test lists them in $GLOBALS['qr_test_post_types'].
$GLOBALS['qr_test_post_types'] = array();
function post_type_exists( $post_type ) { return in_array( $post_type, $GLOBALS['qr_test_post_types'], true ); }
// How often an action has run: never unless a test sets $GLOBALS['qr_test_did_actions'][ $hook ].
$GLOBALS['qr_test_did_actions'] = array();
function did_action( $hook ) { return (int) ( $GLOBALS['qr_test_did_actions'][ $hook ] ?? 0 ); }

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Quote_Requests\\';
		if ( 0 === strpos( $class, $prefix ) ) {
			$name = strtolower( str_replace( '_', '-', substr( $class, strlen( $prefix ) ) ) );
			require dirname( __DIR__, 2 ) . '/includes/class-' . $name . '.php';
		}
	}
);

require dirname( __DIR__, 2 ) . '/includes/functions.php'; // The global functions: quote_requests_submit() and the others.

function is_email( $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false; }
function absint( $n ) { return abs( (int) $n ); }

$GLOBALS['qr_test_filters'] = array();
// The hooks that are running, as WordPress lists them. As in WordPress, a hook whose callback throws stays in the list.
$GLOBALS['wp_current_filter'] = array();
function apply_filters( $hook, $value, ...$args ) {
	$GLOBALS['wp_current_filter'][] = $hook;
	foreach ( $GLOBALS['qr_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	array_pop( $GLOBALS['wp_current_filter'] );
	return $value;
}
/** Registers a callback for apply_filters() below. Priority and the accepted argument count are not modelled: every callback gets every argument. */
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['qr_test_filters'][ $hook ][] = $callback;
	return true;
}
/** Actions share the list of the filters, as they do in WordPress. */
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return add_filter( $hook, $callback, $priority, $accepted_args ); }
function do_action( $hook, ...$args ) {
	$GLOBALS['wp_current_filter'][] = $hook;
	foreach ( $GLOBALS['qr_test_filters'][ $hook ] ?? array() as $callback ) {
		$callback( ...$args );
	}
	array_pop( $GLOBALS['wp_current_filter'] );
}
$GLOBALS['qr_test_settings_errors'] = array();
/** Records what the settings screen would show; the message is printed there as HTML. */
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['qr_test_settings_errors'][] = array( 'code' => $code, 'message' => $message );
}
function wp_json_encode( $data, $flags = 0, $depth = 512 ) { return json_encode( $data, $flags, $depth ); }

// Enough of remove_accents() for the Latin letters the tests use (Latin-1 and part of Latin Extended-A).
function remove_accents( $text ) {
	return strtr(
		(string) $text,
		array(
			'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE', 'Ç' => 'C',
			'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
			'Ð' => 'D', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O',
			'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'ß' => 'ss',
			'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c',
			'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
			'ð' => 'd', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
			'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
			'Ā' => 'A', 'ā' => 'a', 'Ć' => 'C', 'ć' => 'c', 'Č' => 'C', 'č' => 'c', 'Ē' => 'E', 'ē' => 'e',
			'Ğ' => 'G', 'ğ' => 'g', 'İ' => 'I', 'ı' => 'i', 'Ł' => 'L', 'ł' => 'l', 'Ń' => 'N', 'ń' => 'n',
			'Ō' => 'O', 'ō' => 'o', 'Ś' => 'S', 'ś' => 's', 'Š' => 'S', 'š' => 's', 'Ş' => 'S', 'ş' => 's',
			'Ū' => 'U', 'ū' => 'u', 'Ź' => 'Z', 'ź' => 'z', 'Ż' => 'Z', 'ż' => 'z', 'Ž' => 'Z', 'ž' => 'z',
		)
	);
}
