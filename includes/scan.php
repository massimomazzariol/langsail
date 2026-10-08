<?php
/**
 * Scans without a browser: WP-CLI and the AI abilities request every page with a short-lived token
 * instead of a logged-in administrator's nonce.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

const SCAN_TOKEN = 'langsail_scan_token';
/** Addresses to scan: the home page and every published page and post of a public type. */
function scan_urls() {
	$urls  = array( home_url( '/' ) );
	$types = array_values( get_post_types( array( 'public' => true ) ) );
	$types = array_diff( $types, array( 'attachment' ) );
	$ids   = get_posts(
		array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		)
	);
	foreach ( $ids as $id ) {
		$urls[] = get_permalink( $id );
	}
	// The "not found" page and the search results have texts of their own.
	$urls[] = home_url( '/langsail-scan-not-found/' );
	$urls[] = add_query_arg( 's', 'langsail', home_url( '/' ) );
	return array_values( array_unique( $urls ) );
}


/** A new scan token, valid for ten minutes. */
function scan_token() {
	$token = wp_generate_password( 32, false );
	set_transient( SCAN_TOKEN, wp_hash( $token ), 10 * MINUTE_IN_SECONDS );
	return $token;
}

/**
 * Whether a value is the current scan token.
 *
 * @param string $value Value from the request.
 */
function is_scan_token( $value ) {
	$hash = get_transient( SCAN_TOKEN );
	return is_string( $hash ) && '' !== $value && hash_equals( $hash, wp_hash( $value ) );
}

/**
 * Scan every page of the site from the server (each page is requested like a visitor would see it).
 *
 * @return array{pages: int, new: int, failed: string[]}
 */
function scan_site() {
	$token  = scan_token();
	$result = array(
		'pages'  => 0,
		'new'    => 0,
		'failed' => array(),
	);
	foreach ( scan_urls() as $url ) {
		$response = wp_remote_get( add_query_arg( SCAN_ARG, $token, $url ), array( 'timeout' => 120 ) );
		$report   = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $report ) && isset( $report['page'] ) ) {
			++$result['pages'];
			$result['new'] += (int) ( $report['new'] ?? 0 );
		} else {
			$result['failed'][] = $url;
		}
	}
	delete_transient( SCAN_TOKEN );
	return $result;
}
