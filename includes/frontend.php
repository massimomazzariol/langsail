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
add_action( 'parse_request', __NAMESPACE__ . '\\start_sitemap_buffer', 0 );
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
		// A language version is indexed only once enough of the page is translated (settings threshold).
		if ( ! is_ready( current_language() ) ) {
			header( 'X-Robots-Tag: noindex, follow' );
		}
		ob_start( __NAMESPACE__ . '\\translate_page' );
	}
}

/** Sitemaps are sent while WordPress parses the request (SEO plugins) or at template_redirect (core): buffer them first. */
function start_sitemap_buffer() {
	if ( ! is_admin() && ! is_translated_request() && settings()['languages'] && str_ends_with( page_key(), '.xml' ) ) {
		ob_start( __NAMESPACE__ . '\\localize_sitemap' );
	}
}

/**
 * Whether a language version of a page may be indexed: the base language always, a translation when
 * at least the settings threshold of the page's texts is translated.
 *
 * @param string      $locale Locale.
 * @param string|null $page   Page key; the current page when null.
 */
function is_ready( $locale, $page = null ) {
	if ( settings()['base'] === $locale ) {
		return true;
	}
	$progress = page_progress( $page ?? page_key() );
	return $progress['total'] > 0 && 100 * ( $progress['done'][ $locale ] ?? 0 ) >= settings()['threshold'] * $progress['total'];
}

/**
 * Add the ready language versions of each page to an XML sitemap: one entry per language, every
 * entry listing all versions as xhtml:link alternates (with x-default), as search engines expect.
 *
 * @param string $xml Sitemap.
 */
function localize_sitemap( $xml ) {
	if ( ! str_contains( $xml, '<urlset' ) ) {
		return $xml;
	}
	if ( ! str_contains( $xml, 'xmlns:xhtml=' ) ) {
		$xml = preg_replace( '/<urlset\b/', '<urlset xmlns:xhtml="http://www.w3.org/1999/xhtml"', $xml, 1 );
	}
	$base = settings()['base'];
	return preg_replace_callback(
		'#<url>(.*?)</url>#s',
		function ( $m ) use ( $base ) {
			if ( ! preg_match( '#<loc>(.*?)</loc>#s', $m[1], $loc ) ) {
				return $m[0];
			}
			$url  = html_entity_decode( trim( $loc[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
			$page = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( $url === localize_url( $url, array_key_first( settings()['languages'] ) ) ) {
				return $m[0]; // Not a page of this site (another host, a file).
			}
			$ready = array_values( array_filter( locales(), fn( $locale ) => is_ready( $locale, '' === $page ? '/' : $page ) ) );
			$links = '';
			foreach ( $ready as $locale ) {
				$links .= sprintf( "\t\t<xhtml:link rel=\"alternate\" hreflang=\"%s\" href=\"%s\"/>\n", esc_attr( hreflang( $locale ) ), esc_url( localize_url( $url, $locale ) ) );
			}
			$links .= sprintf( "\t\t<xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"%s\"/>\n", esc_url( $url ) );
			$out    = '';
			foreach ( $ready as $locale ) {
				$entry = str_replace( $loc[0], '<loc>' . esc_url( localize_url( $url, $locale ) ) . '</loc>', $m[1] );
				$out  .= '<url>' . rtrim( $entry ) . "\n" . $links . "\t</url>\n\t";
			}
			return rtrim( $out );
		},
		$xml
	);
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
	$units = units( $html ) + collected(); // Texts in the page, then texts passed through the API.
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

/** Link the indexable language versions of the page (hreflang alternates, base language as x-default). */
function print_alternates() {
	if ( ! settings()['languages'] || is_404() || is_search() ) {
		return;
	}
	$base = settings()['base'];
	foreach ( locales() as $locale ) {
		if ( is_ready( $locale ) ) {
			printf( '<link rel="alternate" hreflang="%s" href="%s">' . "\n", esc_attr( hreflang( $locale ) ), esc_url( current_url_in( $locale ) ) );
		}
	}
	printf( '<link rel="alternate" hreflang="x-default" href="%s">' . "\n", esc_url( current_url_in( $base ) ) );
}
