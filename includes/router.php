<?php
/**
 * URLs: /{prefix}/ selects a translation language. The prefix is removed from the request before
 * WordPress parses it, so every page keeps a single structure and the base-language URL.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

/**
 * Read the language from the request path and strip its prefix. Runs when the plugin loads, before
 * WordPress parses the request or loads any translation file.
 */
function boot_router() {
	if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || empty( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}
	$uri    = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compared and rewritten, never printed.
	$found  = language_from_uri( $uri );
	if ( ! $found ) {
		return;
	}
	current_language( $found['locale'] );
	$_SERVER['REQUEST_URI'] = $found['uri'];
	// Some servers (PHP's built-in one, some FastCGI setups) also pass the path as PATH_INFO,
	// which WordPress prefers when matching rewrite rules.
	foreach ( array( 'PATH_INFO', 'ORIG_PATH_INFO' ) as $key ) {
		$path = isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? $_SERVER[ $key ] : '';
		$info = '' !== $path ? language_from_uri( wp_unslash( $path ) ) : null;
		if ( $info ) {
			$_SERVER[ $key ] = $info['uri'];
		}
	}

	add_filter( 'locale', __NAMESPACE__ . '\\request_locale' );
	add_filter( 'wp_redirect', __NAMESPACE__ . '\\localize_redirect' );
}

/**
 * The translation language named by a request URI, and the URI without its prefix.
 *
 * @param string $uri Request URI, path and query.
 * @return array{locale: string, uri: string}|null
 */
function language_from_uri( $uri ) {
	$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	if ( ! str_starts_with( $uri, $home ) ) {
		return null;
	}
	$rest = substr( $uri, strlen( $home ) );
	foreach ( settings()['languages'] as $locale => $language ) {
		$prefix = $language['prefix'];
		if ( $rest === $prefix || str_starts_with( $rest, $prefix . '/' ) || str_starts_with( $rest, $prefix . '?' ) ) {
			return array(
				'locale' => $locale,
				'uri'    => $home . ltrim( substr( $rest, strlen( $prefix ) ), '/' ),
			);
		}
	}
	return null;
}

/**
 * WordPress runs in the request language on the front end; the admin keeps each user's own language.
 *
 * @param string $locale Locale chosen by WordPress.
 */
function request_locale( $locale ) {
	return is_admin() ? $locale : current_language();
}

/**
 * Add the language prefix to a URL of this site, unless it already has one or points to a file,
 * the admin, the login or the REST API.
 *
 * @param string $url    Absolute or root-relative URL.
 * @param string $locale Target locale; the base locale removes nothing and adds nothing.
 */
function localize_url( $url, $locale ) {
	$languages = settings()['languages'];
	if ( ! isset( $languages[ $locale ] ) || ! is_string( $url ) || '' === $url ) {
		return $url;
	}
	$home  = wp_parse_url( home_url( '/' ) );
	$parts = wp_parse_url( $url );
	// Only http(s) links to this host, or root-relative paths: not mailto:, tel:, #anchors or relative paths.
	if (
		false === $parts ||
		( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) ||
		( isset( $parts['host'] ) && strcasecmp( $parts['host'], $home['host'] ?? '' ) ) ||
		( ! isset( $parts['host'] ) && ! str_starts_with( $parts['path'] ?? '', '/' ) )
	) {
		return $url;
	}
	$base = $home['path'] ?? '/';
	$path = $parts['path'] ?? '/';
	if ( ! str_starts_with( $path, $base ) && $path . '/' !== $base ) {
		return $url;
	}
	$rest = ltrim( substr( $path, strlen( $base ) ), '/' );
	if ( preg_match( '#^(?:wp-admin|wp-login\.php|wp-json|wp-content|wp-includes|xmlrpc\.php|feed)(?:/|$)#', $rest ) || preg_match( '#\.[a-z0-9]{2,5}$#i', $rest ) || null !== language_from_uri( $base . $rest ) ) {
		return $url;
	}
	$localized = rtrim( $base, '/' ) . '/' . $languages[ $locale ]['prefix'] . '/' . $rest;
	$origin    = isset( $parts['host'] ) ? ( isset( $parts['scheme'] ) ? $parts['scheme'] . ':' : '' ) . '//' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) : '';
	return $origin . $localized . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
}

/**
 * Redirects issued while a translation language is active stay in that language.
 *
 * @param string $location Redirect target.
 */
function localize_redirect( $location ) {
	return localize_url( $location, current_language() );
}

/**
 * The current page's URL in a given language (the path without prefix, query kept).
 *
 * @param string $locale Locale.
 */
function current_url_in( $locale ) {
	$uri = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Rebuilt as a URL and escaped on output.
	$url = home_url( '/' ) . ltrim( substr( $uri, strlen( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) ), '/' );
	return localize_url( $url, $locale );
}
