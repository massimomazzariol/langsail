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
const SCAN_STATE = 'langsail_scan_state';

/**
 * Addresses to scan: the home page, every published post of a public type, the post type archives,
 * the archives of every term in use, the not-found page and the search results.
 */
function scan_urls() {
	$urls  = array( home_url( '/' ) );
	$types = array_values( get_post_types( array( 'public' => true ) ) );
	$types = array_diff( $types, array( 'attachment' ) );
	$ids   = get_posts(
		array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => -1, // A scan that missed pages would forget their texts (prune_pages).
			'fields'         => 'ids',
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		)
	);
	foreach ( $ids as $id ) {
		$urls[] = get_permalink( $id );
	}
	// Archives have texts of their own (term names and descriptions) and their own indexing.
	foreach ( $types as $type ) {
		$archive = get_post_type_archive_link( $type );
		if ( $archive && 'post' !== $type ) {
			$urls[] = $archive;
		}
	}
	$taxonomies = array_diff( get_taxonomies( array( 'public' => true ) ), array( 'post_format' ) );
	foreach ( $taxonomies ? get_terms( array( 'taxonomy' => array_values( $taxonomies ), 'hide_empty' => true ) ) : array() as $term ) {
		$link = get_term_link( $term );
		if ( is_string( $link ) ) {
			$urls[] = $link;
		}
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
 * Scan the pages of the site from the server (each page is requested like a visitor would see it):
 * all of them, or a batch, so a web request (an AI agent through REST or MCP) stays within its time
 * limit. Batches started at offset 0 add up; once the last one ends without failures, pages the scan
 * did not find are forgotten (prune_pages).
 *
 * @param int $offset First address (scan_urls() order).
 * @param int $limit  Addresses in this batch; 0 for all from $offset.
 * @return array{pages: int, new: int, failed: string[], total: int, next_offset: int|null}
 */
function scan_site( $offset = 0, $limit = 0 ) {
	$urls   = scan_urls();
	$offset = max( 0, (int) $offset );
	$batch  = array_slice( $urls, $offset, (int) $limit > 0 ? (int) $limit : null );
	$state  = 0 === $offset ? array( 'keys' => array(), 'failed' => false ) : get_transient( SCAN_STATE );
	$state  = is_array( $state ) ? $state : array( 'keys' => array(), 'failed' => true ); // A batch without its start: never prune.
	$token  = scan_token();
	$result = array(
		'pages'       => 0,
		'new'         => 0,
		'failed'      => array(),
		'total'       => count( $urls ),
		'next_offset' => null,
	);
	foreach ( $batch as $url ) {
		$response = wp_remote_get( add_query_arg( SCAN_ARG, $token, $url ), array( 'timeout' => 120 ) );
		$report   = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $report ) && isset( $report['page'] ) ) {
			++$result['pages'];
			$result['new']   += (int) ( $report['new'] ?? 0 );
			$state['keys'][]  = (string) $report['page'];
		} else {
			$result['failed'][] = $url;
			$state['failed']    = true;
		}
	}
	delete_transient( SCAN_TOKEN );
	$next = $offset + count( $batch );
	if ( $next < count( $urls ) ) {
		$result['next_offset'] = $next;
		set_transient( SCAN_STATE, $state, HOUR_IN_SECONDS );
		return $result;
	}
	delete_transient( SCAN_STATE );
	if ( ! $state['failed'] ) {
		prune_pages( $state['keys'] );
	}
	return $result;
}
