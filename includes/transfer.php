<?php
/**
 * Import and export of texts and translations.
 *
 * JSON: every text, every language and the translated address words in one file, lossless; importing it on another site (local to
 * live) creates the texts that site has not scanned yet. PO: one language per file for Poedit or a
 * translator; texts show markers instead of HTML, fuzzy entries come back as "review".
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

// LangSail's own tables: names come from tables() ($wpdb->prefix plus fixed names), every value is
// prepared or cast to an integer, and the results that pages read are cached.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders

const EXPORT_FORMAT = 'langsail';

/**
 * Every text with its translations.
 *
 * @return array{format: string, version: int, base: string, strings: array, slugs: object}
 */
function export_json() {
	global $wpdb;
	$t       = tables();
	$strings = $wpdb->get_results( "SELECT id, source, kind FROM {$t['strings']} ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	$pages   = array();
	foreach ( $wpdb->get_results( "SELECT string_id, page FROM {$t['pages']} ORDER BY page, position", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
		$pages[ $row['string_id'] ][] = $row['page'];
	}
	$translations = array();
	foreach ( $wpdb->get_results( "SELECT string_id, locale, text, status FROM {$t['translations']}", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
		$translations[ $row['string_id'] ][ $row['locale'] ] = array(
			'text'   => $row['text'],
			'status' => $row['status'],
		);
	}
	$out = array();
	foreach ( $strings as $row ) {
		$out[] = array(
			'source'       => $row['source'],
			'kind'         => $row['kind'],
			'pages'        => $pages[ $row['id'] ] ?? array(),
			'translations' => (object) ( $translations[ $row['id'] ] ?? array() ),
		);
	}
	return array(
		'format'  => EXPORT_FORMAT,
		'version' => 1,
		'base'    => settings()['base'],
		'strings' => $out,
		'slugs'   => (object) get_option( SLUGS_OPTION, array() ),
	);
}

/**
 * Import a JSON export: texts are matched by their source (created when missing, with their pages
 * when the site has none for them), translations of the site languages are written.
 *
 * @param mixed $data Decoded export.
 * @return array{texts: int, translations: int}|\WP_Error
 */
function import_json( $data ) {
	if ( ! is_array( $data ) || EXPORT_FORMAT !== ( $data['format'] ?? '' ) || ! isset( $data['strings'] ) || ! is_array( $data['strings'] ) ) {
		return new \WP_Error( 'langsail_import', __( 'This is not a LangSail export.', 'langsail' ) );
	}
	if ( ( $data['base'] ?? '' ) !== settings()['base'] ) {
		return new \WP_Error( 'langsail_import', __( 'The export was made from a site with a different base language.', 'langsail' ) );
	}
	$languages = settings()['languages'];
	$count     = array(
		'texts'        => 0,
		'translations' => 0,
	);
	$by_locale = array();
	foreach ( $data['strings'] as $item ) {
		if ( ! is_array( $item ) || ! isset( $item['source'] ) || ! is_string( $item['source'] ) || '' === trim( $item['source'] ) ) {
			continue;
		}
		$kind = in_array( $item['kind'] ?? 'text', array( 'text', 'attr', 'title' ), true ) ? $item['kind'] : 'text';
		$id   = ensure_string( normalize( $item['source'] ), $kind, array_filter( (array) ( $item['pages'] ?? array() ), 'is_string' ) );
		++$count['texts'];
		foreach ( (array) ( $item['translations'] ?? array() ) as $locale => $translation ) {
			if ( isset( $languages[ $locale ] ) && is_array( $translation ) && isset( $translation['text'] ) && is_string( $translation['text'] ) ) {
				$status                                   = 'review' === ( $translation['status'] ?? '' ) ? 'review' : 'translated';
				$by_locale[ $locale ][ $status ][ $id ]   = 'text' === $kind ? wp_kses_post( $translation['text'] ) : sanitize_text_field( $translation['text'] );
				++$count['translations'];
			}
		}
	}
	foreach ( $by_locale as $locale => $statuses ) {
		foreach ( $statuses as $status => $texts ) {
			save_translations( $locale, $texts, $status );
		}
	}
	if ( isset( $data['slugs'] ) && is_array( $data['slugs'] ) ) {
		set_slugs( $data['slugs'] );
	}
	return $count;
}

/**
 * The id of a text, creating it (and linking it to the given pages if it has none) when missing.
 *
 * @param string   $source Normalized source.
 * @param string   $kind   Kind.
 * @param string[] $pages  Pages it appears on.
 */
function ensure_string( $source, $kind, array $pages ) {
	global $wpdb;
	$t  = tables();
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['strings']} WHERE hash = %s", md5( $source ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	if ( ! $id ) {
		$now = current_time( 'mysql', true );
		$wpdb->insert( $t['strings'], array( 'hash' => md5( $source ), 'source' => $source, 'kind' => $kind, 'created' => $now, 'seen' => $now ) );
		$id = (int) $wpdb->insert_id;
	}
	if ( $pages && ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['pages']} WHERE string_id = %d", $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
		foreach ( array_values( array_unique( $pages ) ) as $position => $page ) {
			$wpdb->insert( $t['pages'], array( 'string_id' => $id, 'page' => $page, 'position' => 1000 + $position ) );
		}
		wp_cache_delete( 'pages', 'langsail' );
	}
	return $id;
}

/**
 * Escape a PO string.
 *
 * @param string $text Text.
 */
function po_quote( $text ) {
	return '"' . str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\\"', '\\n', '\\t' ), $text ) . '"';
}

/**
 * A PO file of one language: the texts on at least one page, as translators see them (markers).
 *
 * @param string $locale Locale.
 */
function export_po( $locale ) {
	global $wpdb;
	$t     = tables();
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT s.id, MAX(s.source) AS source, MAX(s.kind) AS kind, MAX(tr.text) AS text, MAX(tr.status) AS status, GROUP_CONCAT(DISTINCT p.page) AS pages FROM {$t['strings']} s JOIN {$t['pages']} p ON p.string_id = s.id LEFT JOIN {$t['translations']} tr ON tr.string_id = s.id AND tr.locale = %s GROUP BY s.id ORDER BY MIN(p.page), MIN(p.position)", $locale ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names.
	$out   = array(
		'# LangSail translations of ' . wp_parse_url( home_url(), PHP_URL_HOST ) . ' into ' . language_name( $locale ) . '.',
		'# Markers [1]...[/1] stand for links and formatting, [n/] for line breaks: keep them in the translation.',
		'msgid ""',
		'msgstr ""',
		po_quote( "Content-Type: text/plain; charset=UTF-8\n" ),
		po_quote( 'Language: ' . $locale . "\n" ),
		po_quote( 'X-Generator: LangSail ' . VERSION . "\n" ),
		po_quote( 'X-LangSail-Base: ' . settings()['base'] . "\n" ),
		'',
	);
	$seen = array();
	foreach ( $rows as $row ) {
		$msgid = display_text( $row['source'], $row['kind'] );
		if ( isset( $seen[ $msgid ] ) ) {
			continue; // Same visible text: one entry translates both.
		}
		$seen[ $msgid ] = true;
		$out[]          = '#: ' . str_replace( ',', ' ', (string) $row['pages'] );
		if ( 'review' === $row['status'] ) {
			$out[] = '#, fuzzy';
		}
		$out[] = 'msgid ' . po_quote( $msgid );
		$out[] = 'msgstr ' . po_quote( null === $row['text'] ? '' : display_text( $row['text'], $row['kind'], $row['source'] ) );
		$out[] = '';
	}
	return implode( "\n", $out );
}

/**
 * Parse a PO file: entries with msgid, msgstr and the fuzzy flag (plurals and contexts are not used).
 *
 * @param string $po File content.
 * @return array<int, array{msgid: string, msgstr: string, fuzzy: bool}>
 */
function parse_po( $po ) {
	$entries = array();
	$entry   = array( 'msgid' => null, 'msgstr' => null, 'fuzzy' => false );
	$field   = null;
	$unquote = fn( $s ) => stripcslashes( substr( $s, 1, -1 ) );
	$flush   = function () use ( &$entry, &$entries, &$field ) {
		if ( null !== $entry['msgid'] && '' !== $entry['msgid'] ) {
			$entries[] = array( 'msgid' => $entry['msgid'], 'msgstr' => (string) $entry['msgstr'], 'fuzzy' => $entry['fuzzy'] );
		}
		$entry = array( 'msgid' => null, 'msgstr' => null, 'fuzzy' => false );
		$field = null;
	};
	foreach ( preg_split( '/\r\n|\r|\n/', $po ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			$flush();
		} elseif ( str_starts_with( $line, '#,' ) ) {
			$entry['fuzzy'] = str_contains( $line, 'fuzzy' );
		} elseif ( preg_match( '/^(msgid|msgstr)\s+(".*")$/', $line, $m ) ) {
			if ( 'msgid' === $m[1] && null !== $entry['msgstr'] ) {
				$fuzzy = $entry['fuzzy'];
				$flush();
				$entry['fuzzy'] = $fuzzy;
			}
			$field           = $m[1];
			$entry[ $field ] = $unquote( $m[2] );
		} elseif ( $field && str_starts_with( $line, '"' ) ) {
			$entry[ $field ] .= $unquote( $line );
		}
	}
	$flush();
	return $entries;
}

/**
 * Import a PO file into one language: each entry goes to every text that shows the same msgid.
 *
 * @param string $po     File content.
 * @param string $locale Locale.
 * @return array{translations: int, errors: string[]}|\WP_Error
 */
function import_po( $po, $locale ) {
	return import_entries( parse_po( $po ), $locale );
}

/**
 * Import a translation map into one language: { "text as translators see it": "translation" }.
 * A simple format for translators and tools; the same matching as PO files.
 *
 * @param mixed  $map    Decoded JSON object.
 * @param string $locale Locale.
 * @return array{translations: int, errors: string[]}|\WP_Error
 */
function import_map( $map, $locale ) {
	if ( ! is_array( $map ) ) {
		return new \WP_Error( 'langsail_import', __( 'This is not a translation map.', 'langsail' ) );
	}
	$entries = array();
	foreach ( $map as $msgid => $msgstr ) {
		if ( is_string( $msgid ) && '_' !== substr( $msgid, 0, 1 ) && is_string( $msgstr ) ) {
			$entries[] = array( 'msgid' => $msgid, 'msgstr' => $msgstr, 'fuzzy' => false );
		}
	}
	return import_entries( $entries, $locale );
}

/**
 * Write entries (msgid as translators see the text, msgstr, fuzzy) into one language.
 *
 * @param array  $entries Entries.
 * @param string $locale  Locale.
 * @return array{translations: int, errors: string[]}|\WP_Error
 */
function import_entries( array $entries, $locale ) {
	global $wpdb;
	if ( ! isset( settings()['languages'][ $locale ] ) ) {
		return new \WP_Error( 'langsail_import', __( 'Choose one of the site languages for this file.', 'langsail' ) );
	}
	$targets = array();
	foreach ( $wpdb->get_results( 'SELECT id, source, kind FROM ' . tables()['strings'], ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name only.
		$targets[ display_text( $row['source'], $row['kind'] ) ][] = $row;
	}
	$result = array(
		'translations' => 0,
		'errors'       => array(),
	);
	$save   = array();
	foreach ( $entries as $entry ) {
		$msgid = normalize( $entry['msgid'] );
		if ( '' === trim( $entry['msgstr'] ) || ! isset( $targets[ $msgid ] ) ) {
			continue;
		}
		foreach ( $targets[ $msgid ] as $row ) {
			$text = 'text' === $row['kind'] ? from_placeholders( $entry['msgstr'], $row['source'] ) : sanitize_text_field( $entry['msgstr'] );
			if ( is_wp_error( $text ) ) {
				$result['errors'][] = wp_html_excerpt( $entry['msgid'], 60, '...' ) . ': ' . $text->get_error_message();
				continue;
			}
			$save[ $entry['fuzzy'] ? 'review' : 'translated' ][ (int) $row['id'] ] = $text;
			++$result['translations'];
		}
	}
	foreach ( $save as $status => $texts ) {
		save_translations( $locale, $texts, $status );
	}
	return $result;
}

/**
 * Import any supported file: a LangSail JSON export, a translation map (.json, one language) or a PO file.
 *
 * @param string $name    File name (its extension picks the format).
 * @param string $content File content.
 * @param string $locale  Language of a PO file or a map.
 * @return array|\WP_Error
 */
function import_file( $name, $content, $locale ) {
	if ( str_ends_with( strtolower( $name ), '.po' ) ) {
		return import_po( $content, $locale );
	}
	$data = json_decode( $content, true );
	return is_array( $data ) && isset( $data['format'] ) ? import_json( $data ) : import_map( $data, $locale );
}
