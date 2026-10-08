<?php
/**
 * Front end: translates each page of a translation language on its way out, collects the units of
 * base-language pages for the translation table (scan requests from the admin), and links the
 * language versions for search engines.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', __NAMESPACE__ . '\\start_buffer', 0 );
add_action( 'wp_head', __NAMESPACE__ . '\\print_alternates', 1 );
add_action( 'wp', __NAMESPACE__ . '\\scan_setup' );

/** Query argument of scan requests; its value is a nonce. */
const SCAN_ARG = 'langsail_scan';

/** A scan request: an administrator's request for a base-language page, with a valid nonce. */
function is_scan() {
	static $scan = null;
	if ( null === $scan ) {
		$nonce = isset( $_GET[ SCAN_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ SCAN_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
		$scan  = '' !== $nonce && ! is_translated_request() && current_user_can( 'manage_options' ) && wp_verify_nonce( $nonce, 'langsail_scan' );
	}
	return $scan;
}

/** Scan requests render the page as visitors see it: no admin bar. */
function scan_setup() {
	if ( is_scan() ) {
		add_filter( 'show_admin_bar', '__return_false' );
	}
}

/** Buffer front-end HTML pages that must be translated or scanned. */
function start_buffer() {
	if ( is_admin() || wp_doing_ajax() || is_feed() || is_robots() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( is_scan() ) {
		ob_start( __NAMESPACE__ . '\\scan_page' );
	} elseif ( is_translated_request() ) {
		ob_start( __NAMESPACE__ . '\\translate_page' );
	}
}

/** Whether the response being sent is HTML (sitemaps and other XML pass through). */
function is_html_response() {
	foreach ( headers_list() as $header ) {
		if ( 0 === stripos( $header, 'content-type:' ) ) {
			return false !== stripos( $header, 'text/html' );
		}
	}
	return true;
}

/**
 * Translate a whole page into the request language: text and attribute units from the dictionary,
 * links to this site moved into the same language.
 *
 * @param string $html Page.
 */
function translate_page( $html ) {
	if ( '' === $html || ! is_html_response() ) {
		return $html;
	}
	return translate_html( $html, current_language() );
}

/**
 * Translate HTML into a locale.
 *
 * @param string $html   HTML.
 * @param string $locale Locale.
 */
function translate_html( $html, $locale ) {
	$dictionary = dictionary( $locale );
	$lookup     = function ( $source ) use ( $dictionary ) {
		return $dictionary[ md5( $source ) ] ?? null;
	};
	$html = translate_text( $html, $lookup );
	return translate_tags( $html, $lookup, fn( $url ) => localize_url( $url, $locale ) );
}

/**
 * Record the units of the scanned page and answer with a short JSON report instead of the page.
 *
 * @param string $html Page.
 */
function scan_page( $html ) {
	if ( '' === $html || ! is_html_response() ) {
		return $html;
	}
	$page  = page_key();
	$units = units( $html );
	$new   = record_page( $page, $units );
	header( 'Content-Type: application/json; charset=utf-8' );
	return wp_json_encode(
		array(
			'page'  => $page,
			'units' => count( $units ),
			'new'   => $new,
		)
	);
}

/** The current page as a table key: its path, without language prefix and query. */
function page_key() {
	$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Used as a key, escaped on output.
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	return '' === $path ? '/' : $path;
}

/**
 * The hreflang code of a locale: the language alone unless two site locales share it.
 *
 * @param string $locale Locale.
 */
function hreflang( $locale ) {
	$prefix = prefixes( locales() )[ $locale ] ?? strtolower( $locale );
	return str_contains( $prefix, '-' ) ? str_replace( '_', '-', $locale ) : $prefix;
}

/** Link the language versions of the page (hreflang alternates, base language as x-default). */
function print_alternates() {
	if ( ! settings()['languages'] || is_404() || is_search() ) {
		return;
	}
	$base = settings()['base'];
	foreach ( locales() as $locale ) {
		printf( '<link rel="alternate" hreflang="%s" href="%s">' . "\n", esc_attr( hreflang( $locale ) ), esc_url( current_url_in( $locale ) ) );
	}
	printf( '<link rel="alternate" hreflang="x-default" href="%s">' . "\n", esc_url( current_url_in( $base ) ) );
}
