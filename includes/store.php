<?php
/**
 * Storage: strings (one row per unique base-language unit), where each string appears, and translations.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

const DB_VERSION = '1';
const STATUSES   = array( 'translated', 'review' );

add_action( 'plugins_loaded', __NAMESPACE__ . '\\maybe_install' );

/**
 * Table names.
 *
 * @return array{strings: string, pages: string, translations: string}
 */
function tables() {
	global $wpdb;
	return array(
		'strings'      => $wpdb->prefix . 'langsail_strings',
		'pages'        => $wpdb->prefix . 'langsail_string_pages',
		'translations' => $wpdb->prefix . 'langsail_translations',
	);
}

/** Create or update the tables. */
function install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t       = tables();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$t['strings']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			hash char(32) NOT NULL,
			source longtext NOT NULL,
			kind varchar(10) NOT NULL DEFAULT 'text',
			created datetime NOT NULL,
			seen datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY hash (hash)
		) $charset;
		CREATE TABLE {$t['pages']} (
			string_id bigint(20) unsigned NOT NULL,
			page varchar(191) NOT NULL,
			position int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (string_id,page),
			KEY page (page)
		) $charset;
		CREATE TABLE {$t['translations']} (
			string_id bigint(20) unsigned NOT NULL,
			locale varchar(20) NOT NULL,
			text longtext NOT NULL,
			status varchar(10) NOT NULL DEFAULT 'translated',
			updated datetime NOT NULL,
			PRIMARY KEY  (string_id,locale)
		) $charset;"
	);
	update_option( 'langsail_db_version', DB_VERSION );
}

/** Install after an update that changed the schema. */
function maybe_install() {
	if ( DB_VERSION !== get_option( 'langsail_db_version' ) ) {
		install_tables();
	}
}

/**
 * Record the units seen on a page: new strings are added, and the page's list is replaced, so
 * strings no longer on it stop being listed for it (their translations are kept). When a text of the
 * page was edited, the new version gets the old translations marked "to review".
 *
 * @param string                $page  Page key (path without language prefix).
 * @param array<string, string> $units Source => kind, in page order.
 * @return int Number of new strings.
 */
function record_page( $page, array $units ) {
	global $wpdb;
	$t       = tables();
	$now     = current_time( 'mysql', true );
	$ids     = array();
	$created = array();
	$before  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT string_id FROM {$t['pages']} WHERE page = %s", $page ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	foreach ( $units as $source => $kind ) {
		if ( is_kept( $source ) ) {
			continue; // Never translated: not listed, not counted.
		}
		$hash = md5( $source );
		$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['strings']} WHERE hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
		if ( $id ) {
			$wpdb->update( $t['strings'], array( 'seen' => $now ), array( 'id' => $id ) );
		} else {
			$wpdb->insert( $t['strings'], array( 'hash' => $hash, 'source' => $source, 'kind' => $kind, 'created' => $now, 'seen' => $now ) );
			$id                = (int) $wpdb->insert_id;
			$created[ $id ]    = $source;
		}
		$ids[] = $id;
	}
	$wpdb->delete( $t['pages'], array( 'page' => $page ) );
	foreach ( array_values( array_unique( $ids ) ) as $position => $id ) {
		$wpdb->insert( $t['pages'], array( 'string_id' => $id, 'page' => $page, 'position' => $position ) );
	}
	carry_over_translations( $created, array_diff( $before, $ids ) );
	wp_cache_delete( 'pages', 'langsail' );
	do_action( 'langsail_page_scanned', $page, count( $created ) );
	return count( $created );
}

/**
 * Edited texts: each new text takes the translations of the most similar text that left the same
 * page (at least the share in data/similarity.json), marked "to review", so a small change in the
 * base language does not throw away the translations.
 *
 * @param array<int, string> $created New strings: id => source.
 * @param int[]              $removed Ids of strings no longer on the page.
 */
function carry_over_translations( array $created, array $removed ) {
	global $wpdb;
	if ( ! $created || ! $removed ) {
		return;
	}
	$t       = tables();
	$min     = (float) ( json_decode( (string) file_get_contents( dirname( FILE ) . '/data/similarity.json' ), true )['minimum'] ?? 100 );
	$plain   = fn( $html ) => normalize( wp_strip_all_tags( $html ) );
	$sources = $wpdb->get_results( 'SELECT id, source, kind FROM ' . $t['strings'] . ' WHERE id IN (' . implode( ',', array_map( 'intval', $removed ) ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- Integers.
	foreach ( $created as $id => $source ) {
		$best  = null;
		$score = 0.0;
		foreach ( $sources as $old ) {
			similar_text( $plain( $source ), $plain( $old['source'] ), $percent );
			if ( $percent > $score ) {
				$best  = $old;
				$score = $percent;
			}
		}
		if ( ! $best || $score < $min ) {
			continue;
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT locale, text FROM {$t['translations']} WHERE string_id = %d", $best['id'] ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
			$text = $row['text'];
			// Markers must match the new text's tags: a text unit whose tags changed keeps the old translation only if it still fits.
			if ( 'text' === $best['kind'] && is_wp_error( from_placeholders( translation_placeholders( $text, $best['source'] ), $source ) ) ) {
				$text = wp_strip_all_tags( $text );
			} elseif ( 'text' === $best['kind'] ) {
				$text = from_placeholders( translation_placeholders( $text, $best['source'] ), $source );
			}
			save_translations( $row['locale'], array( $id => $text ), 'review' );
		}
	}
}
/**
 * Translations of a locale: hash => text. One query per request, kept in the object cache.
 *
 * @param string $locale Locale.
 * @return array<string, string>
 */
function dictionary( $locale ) {
	global $wpdb;
	$found = wp_cache_get( 'dict_' . $locale, 'langsail' );
	if ( is_array( $found ) ) {
		return $found;
	}
	$t     = tables();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT s.hash, t.text FROM {$t['translations']} t JOIN {$t['strings']} s ON s.id = t.string_id WHERE t.locale = %s AND t.text <> ''", $locale ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names.
	$found = array_column( $rows, 'text', 'hash' );
	wp_cache_set( 'dict_' . $locale, $found, 'langsail' );
	return $found;
}

/**
 * Save translations of one locale: string id => text. Empty text deletes the translation.
 *
 * @param string             $locale       Locale.
 * @param array<int, string> $translations Texts.
 * @param string             $status       'translated' or 'review'.
 */
function save_translations( $locale, array $translations, $status = 'translated' ) {
	global $wpdb;
	$t   = tables();
	$now = current_time( 'mysql', true );
	foreach ( $translations as $id => $text ) {
		$id   = (int) $id;
		$text = trim( (string) $text );
		$wpdb->delete( $t['translations'], array( 'string_id' => $id, 'locale' => $locale ) );
		if ( '' !== $text && $id > 0 ) {
			$wpdb->insert( $t['translations'], array( 'string_id' => $id, 'locale' => $locale, 'text' => $text, 'status' => in_array( $status, STATUSES, true ) ? $status : 'translated', 'updated' => $now ) );
		}
	}
	wp_cache_delete( 'dict_' . $locale, 'langsail' );
	do_action( 'langsail_translations_saved', $locale );
}

/** Pages with strings: page => count. */
function pages() {
	global $wpdb;
	$found = wp_cache_get( 'pages', 'langsail' );
	if ( is_array( $found ) ) {
		return $found;
	}
	$t     = tables();
	$found = array_map( 'intval', array_column( $wpdb->get_results( "SELECT page, COUNT(*) AS n FROM {$t['pages']} GROUP BY page ORDER BY page", ARRAY_A ), 'n', 'page' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table name, cached.
	wp_cache_set( 'pages', $found, 'langsail' );
	return $found;
}

/**
 * Strings for the translation table, with their translations.
 *
 * @param array $args page, search, status ('missing' in any language, or ''), locales, per_page, paged.
 * @return array{rows: array, total: int}
 */
function query_strings( array $args ) {
	global $wpdb;
	$t      = tables();
	$where  = array( '1=1' );
	$params = array();
	$join   = '';
	$order  = 's.id';
	if ( ! empty( $args['page'] ) ) {
		$join     = "JOIN {$t['pages']} p ON p.string_id = s.id AND p.page = %s";
		$params[] = $args['page'];
		$order    = 'p.position';
	} else {
		$where[] = "EXISTS (SELECT 1 FROM {$t['pages']} p2 WHERE p2.string_id = s.id)";
	}
	if ( ! empty( $args['search'] ) ) {
		$where[]  = '(s.source LIKE %s OR EXISTS (SELECT 1 FROM ' . $t['translations'] . ' t3 WHERE t3.string_id = s.id AND t3.text LIKE %s))';
		$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		$params[] = $like;
		$params[] = $like;
	}
	if ( 'missing' === ( $args['status'] ?? '' ) && $args['locales'] ) {
		$where[]  = '(SELECT COUNT(*) FROM ' . $t['translations'] . ' t4 WHERE t4.string_id = s.id AND t4.status = \'translated\' AND t4.locale IN (' . implode( ',', array_fill( 0, count( $args['locales'] ), '%s' ) ) . ')) < %d';
		$params   = array_merge( $params, $args['locales'], array( count( $args['locales'] ) ) );
	}
	$sql   = "FROM {$t['strings']} s $join WHERE " . implode( ' AND ', $where );
	$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) $sql", $params ) : "SELECT COUNT(*) $sql" ); // phpcs:ignore WordPress.DB.PreparedSQL -- Built from placeholders above.
	$limit = max( 1, (int) ( $args['per_page'] ?? 50 ) );
	$skip  = $limit * max( 0, (int) ( $args['paged'] ?? 1 ) - 1 );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.source, s.kind $sql ORDER BY $order LIMIT %d OFFSET %d", array_merge( $params, array( $limit, $skip ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- Built from placeholders above.

	$ids = array_map( 'intval', array_column( $rows, 'id' ) );
	if ( $ids ) {
		$found = $wpdb->get_results( "SELECT string_id, locale, text, status FROM {$t['translations']} WHERE string_id IN (" . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- Integers.
		$map   = array();
		foreach ( $found as $tr ) {
			$map[ $tr['string_id'] ][ $tr['locale'] ] = $tr;
		}
		foreach ( $rows as &$row ) {
			$row['translations'] = $map[ $row['id'] ] ?? array();
		}
	}
	return array(
		'rows'  => $rows,
		'total' => $total,
	);
}

/**
 * Translation progress per locale over the strings that are on at least one page.
 *
 * @param string[] $locales Locales.
 * @return array{total: int, done: array<string, int>}
 */
function progress( array $locales ) {
	global $wpdb;
	$t     = tables();
	$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT string_id) FROM {$t['pages']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	$done  = array();
	foreach ( $locales as $locale ) {
		$done[ $locale ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT t.string_id) FROM {$t['translations']} t JOIN {$t['pages']} p ON p.string_id = t.string_id WHERE t.locale = %s AND t.status = 'translated'", $locale ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names.
	}
	return array(
		'total' => $total,
		'done'  => $done,
	);
}

/**
 * Translation progress of one page: its number of strings and, per locale, how many are translated.
 *
 * @param string $page Page key.
 * @return array{total: int, done: array<string, int>}
 */
function page_progress( $page ) {
	global $wpdb;
	static $cache = array();
	if ( ! isset( $cache[ $page ] ) ) {
		$t              = tables();
		$total          = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['pages']} WHERE page = %s", $page ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
		$rows           = $wpdb->get_results( $wpdb->prepare( "SELECT t.locale, COUNT(*) AS n FROM {$t['pages']} p JOIN {$t['translations']} t ON t.string_id = p.string_id AND t.status = 'translated' WHERE p.page = %s GROUP BY t.locale", $page ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names.
		$cache[ $page ] = array(
			'total' => $total,
			'done'  => array_map( 'intval', array_column( $rows, 'n', 'locale' ) ),
		);
	}
	return $cache[ $page ];
}

/**
 * Delete the texts that are on no page any more, with their translations.
 *
 * @return int Number of texts deleted.
 */
function remove_unused() {
	global $wpdb;
	$t   = tables();
	$ids = array_map( 'intval', $wpdb->get_col( "SELECT s.id FROM {$t['strings']} s LEFT JOIN {$t['pages']} p ON p.string_id = s.id WHERE p.string_id IS NULL" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names.
	if ( $ids ) {
		$in = implode( ',', $ids );
		$wpdb->query( "DELETE FROM {$t['translations']} WHERE string_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL -- Integers.
		$wpdb->query( "DELETE FROM {$t['strings']} WHERE id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL -- Integers.
		wp_cache_flush_group( 'langsail' );
	}
	return count( $ids );
}
