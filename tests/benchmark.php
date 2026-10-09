<?php
/**
 * Benchmark of the translation cache. Run with: wp eval-file path/to/langsail/tests/benchmark.php
 * (in WordPress Studio, prefix the command with studio). It adds synthetic texts to a test locale,
 * measures, and removes everything in finally.
 *
 * "cache_ms" is what LangSail reads in this PHP: the compiled file when OPcache holds it, else the
 * serialized copy. WP-CLI usually runs without OPcache, so this is the slower path; with OPcache the
 * map comes from shared memory (tests/opcache-benchmark.php measures both).
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

$langsail_bench = static function ( callable $run, $times ) {
	$run(); // Warm up.
	$start = hrtime( true );
	for ( $i = 0; $i < $times; $i++ ) {
		$run();
	}
	return ( hrtime( true ) - $start ) / 1e6 / $times;
};

global $wpdb;
$langsail_t      = tables();
$langsail_locale = 'zz_ZZ';
$langsail_clean  = static function () use ( $wpdb, $langsail_t, $langsail_locale ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$langsail_t['translations']} WHERE locale = %s", $langsail_locale ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	$wpdb->query( "DELETE FROM {$langsail_t['strings']} WHERE source LIKE 'LangSail bench %'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	foreach ( (array) glob( cache_dir() . '/dict-' . $langsail_locale . '-*.php' ) as $file ) {
		wp_delete_file( $file );
	}
};

try {
	$langsail_clean();
	$langsail_rows = array();
	$langsail_done = 0;
	foreach ( array( 200, 5000, 50000 ) as $langsail_size ) {
		// Add texts up to the size, 500 per query.
		for ( $i = $langsail_done; $i < $langsail_size; $i += 500 ) {
			$values = array();
			$now    = current_time( 'mysql', true );
			for ( $j = $i; $j < min( $langsail_size, $i + 500 ); $j++ ) {
				$source   = "LangSail bench $j: a sentence of ordinary length on a web page.";
				$values[] = $wpdb->prepare( '(%s, %s, %s, %s, %s)', md5( $source ), $source, 'text', $now, $now );
			}
			$wpdb->query( "INSERT INTO {$langsail_t['strings']} (hash, source, kind, created, seen) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- Values prepared above.
		}
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$langsail_t['translations']} (string_id, locale, text, status, updated) SELECT id, %s, CONCAT('Traduzione: ', source), 'translated', created FROM {$langsail_t['strings']} WHERE source LIKE 'LangSail bench %%' AND id NOT IN (SELECT string_id FROM {$langsail_t['translations']} WHERE locale = %s)", $langsail_locale, $langsail_locale ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names.
		$langsail_done = $langsail_size;

		$query = static function () use ( $wpdb, $langsail_t, $langsail_locale ) {
			return array_column( $wpdb->get_results( $wpdb->prepare( "SELECT s.hash, t.text FROM {$langsail_t['translations']} t JOIN {$langsail_t['strings']} s ON s.id = t.string_id WHERE t.locale = %s AND t.status = 'translated' AND t.text <> ''", $langsail_locale ), ARRAY_A ), 'text', 'hash' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names.
		};
		$map  = $query();
		$file = cache_dir() . '/dict-' . $langsail_locale . '-bench.php';
		write_compiled( $file, 'dict-' . $langsail_locale, $map );
		$times = $langsail_size > 10000 ? 3 : 20;

		$memory = memory_get_usage();
		$copy   = $query();
		$db_mem = memory_get_usage() - $memory;
		unset( $copy );

		$langsail_rows[] = array(
			'texts'       => count( $map ),
			'database_ms' => round( $langsail_bench( $query, $times ), 2 ),
			'cache_ms'    => round( $langsail_bench( static fn() => read_compiled( $file ), $times ), 2 ),
			'database_kb' => round( $db_mem / 1024 ),
		);
	}
	\WP_CLI\Utils\format_items( 'table', $langsail_rows, array_keys( $langsail_rows[0] ) );

	// A whole page: translating it, against serving the cached copy (fingerprint and file read).
	$html  = wp_remote_retrieve_body( wp_remote_get( home_url( '/' ), array( 'timeout' => 120 ) ) );
	$it    = locales()[1] ?? '';
	$pages = array();
	if ( '' !== $it && '' !== $html ) {
		$translate = static fn() => translate_html( $html, $it );
		$cached    = cache_dir() . '/bench-page.html';
		file_put_contents( $cached, $translate() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Benchmark file in the cache folder.
		$pages[] = array(
			'page_kb'          => round( strlen( $html ) / 1024 ),
			'translate_ms'     => round( $langsail_bench( $translate, 20 ), 2 ),
			'cached_copy_ms'   => round( $langsail_bench( static fn() => page_fingerprint( $html ) . file_get_contents( $cached ), 20 ), 2 ), // phpcs:ignore WordPress.WP.AlternativeFunctions -- Benchmark.
		);
		wp_delete_file( $cached );
		\WP_CLI\Utils\format_items( 'table', $pages, array_keys( $pages[0] ) );
	}
} finally {
	$langsail_clean();
	wp_delete_file( cache_dir() . '/dict-zz_ZZ-bench.php' );
	wp_delete_file( cache_dir() . '/dict-zz_ZZ-bench.php.ser' );
}
