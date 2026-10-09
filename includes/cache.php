<?php
/**
 * Cache: a translated page costs no database query and, when the page has not changed, no work.
 *
 * - Compiled maps. The approved translations of each language and the progress of every page are
 *   written once to files. With OPcache they are PHP files that return an array (the way WordPress
 *   stores its own translations since 6.5): PHP keeps them in shared memory and a request reads them
 *   for free, with no copy. Without OPcache a serialized copy is read instead, still faster than the
 *   database.
 * - Translated pages. The translated HTML is kept on disk, named after a fingerprint of the page in
 *   the base language: the same page is served again without being translated a second time, and
 *   any change to the page gives it a new fingerprint.
 * - One version number. Every change that can alter a translated page (translations, scans,
 *   settings, address words) raises it. Files carry the version in their name, so they are never
 *   rewritten in place, never stale, and old ones are deleted.
 *
 * Without a writable wp-content/cache folder everything still works, straight from the database.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

// The cache writes its own files in its own folder; WP_Filesystem cannot be used on the front end.
// phpcs:disable WordPress.WP.AlternativeFunctions

const CACHE_VERSION = 'langsail_cache_version';

add_action( 'langsail_translations_saved', __NAMESPACE__ . '\\bump_cache' );
add_action( 'langsail_page_scanned', __NAMESPACE__ . '\\bump_cache' );
foreach ( array( OPTION, SLUGS_OPTION ) as $langsail_option ) {
	add_action( "add_option_$langsail_option", __NAMESPACE__ . '\\bump_cache' );
	add_action( "update_option_$langsail_option", __NAMESPACE__ . '\\bump_cache' );
}

/** The current cache version. */
function cache_version() {
	return (int) get_option( CACHE_VERSION, 1 );
}

/** Something that changes translated pages happened: every cached file becomes outdated. */
function bump_cache() {
	update_option( CACHE_VERSION, cache_version() + 1 );
}

/** The cache folder (wp-content/cache/langsail/), without trailing slash. */
function cache_dir() {
	return WP_CONTENT_DIR . '/cache/langsail';
}

/** Cache limits: data/cache.json, through the langsail_cache_limits filter. */
function cache_limits() {
	static $limits = null;
	if ( null === $limits ) {
		$limits = (array) json_decode( (string) file_get_contents( dirname( FILE ) . '/data/cache.json' ), true );
	}
	return (array) apply_filters( 'langsail_cache_limits', $limits );
}

/**
 * A map built from the database once per cache version and then read from a compiled PHP file.
 *
 * @param string   $name  File name (letters, digits, - and _).
 * @param callable $build Builds the map from the database.
 * @return array
 */
function compiled_map( $name, callable $build ) {
	static $loaded = array();
	$file = cache_dir() . '/' . $name . '-' . cache_version() . '.php';
	if ( isset( $loaded[ $file ] ) ) {
		return $loaded[ $file ];
	}
	$map = read_compiled( $file );
	if ( ! is_array( $map ) ) {
		$map = $build();
		write_compiled( $file, $name, $map );
	}
	$loaded[ $file ] = $map;
	return $map;
}

/** Whether this request may use the OPcache functions (enabled, and the API not restricted to other paths). */
function opcache_usable() {
	static $usable = null;
	if ( null === $usable ) {
		$restrict = (string) ini_get( 'opcache.restrict_api' );
		$status   = function_exists( 'opcache_get_status' ) && ( '' === $restrict || str_starts_with( __FILE__, $restrict ) ) ? opcache_get_status( false ) : false;
		$usable   = is_array( $status ) && ! empty( $status['opcache_enabled'] );
	}
	return $usable;
}

/**
 * Read a compiled map: the PHP file when OPcache holds it (no parsing, no copy), else the serialized
 * copy, which is also handed to OPcache for the next requests.
 *
 * @param string $file PHP file of the map (the serialized copy has the same name plus .ser).
 * @return array|null
 */
function read_compiled( $file ) {
	if ( opcache_usable() ) {
		if ( opcache_is_script_cached( $file ) ) {
			$map = include $file;
			return is_array( $map ) ? $map : null;
		}
		if ( is_readable( $file ) ) {
			opcache_compile_file( $file );
		}
	}
	$data = is_readable( $file . '.ser' ) ? file_get_contents( $file . '.ser' ) : false;
	$map  = is_string( $data ) ? unserialize( $data, array( 'allowed_classes' => false ) ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Own cache file, no objects allowed.
	return is_array( $map ) ? $map : null;
}

/**
 * Write a compiled map atomically (a temporary file renamed into place), then delete the files of
 * the same map from older versions.
 *
 * @param string $file Target file.
 * @param string $name Map name.
 * @param array  $map  Map.
 */
function write_compiled( $file, $name, array $map ) {
	if ( ! wp_mkdir_p( dirname( $file ) ) ) {
		return;
	}
	$copies = array(
		$file          => "<?php\n// LangSail cache, rebuilt from the database whenever translations change. Safe to delete.\nreturn " . var_export( $map, true ) . ";\n", // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Writes PHP code, not a log.
		$file . '.ser' => serialize( $map ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Own cache file of plain arrays.
	);
	foreach ( $copies as $target => $content ) {
		$tmp = $target . '.' . wp_generate_password( 8, false ) . '.tmp';
		if ( false === file_put_contents( $tmp, $content, LOCK_EX ) || ! rename( $tmp, $target ) ) {
			wp_delete_file( $tmp );
		}
	}
	foreach ( (array) glob( dirname( $file ) . '/' . $name . '-*.php*' ) as $old ) {
		if ( ! in_array( $old, array( $file, $file . '.ser' ), true ) ) {
			wp_delete_file( $old );
		}
	}
}

/**
 * Serve a translated page from the cache, or translate it and keep it. Only for visitors (logged-in
 * pages differ per user) and GET requests.
 *
 * @param string   $html      Page in the base language.
 * @param string   $locale    Locale.
 * @param callable $translate Translates the page when it is not cached.
 */
function cached_page( $html, $locale, callable $translate ) {
	$cacheable = 'get' === sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && ! is_user_logged_in() && ! ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	if ( ! $cacheable ) {
		return $translate( $html );
	}
	$dir  = cache_dir() . '/pages-' . cache_version() . '/' . sanitize_file_name( $locale );
	$file = $dir . '/' . page_fingerprint( $html ) . '.html';
	if ( is_readable( $file ) ) {
		$cached = file_get_contents( $file );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
	}
	$out = $translate( $html );
	if ( wp_mkdir_p( $dir ) ) {
		$tmp = $file . '.' . wp_generate_password( 8, false ) . '.tmp';
		if ( false === file_put_contents( $tmp, $out, LOCK_EX ) || ! rename( $tmp, $file ) ) {
			wp_delete_file( $tmp );
		}
		trim_page_cache( $dir );
	}
	return $out;
}

/**
 * Fingerprint of a page, leaving out the parts that change on every load without changing the page
 * (data/cache.json "volatile"): a timing comment or a random id would otherwise make every visit a
 * new page.
 *
 * @param string $html Page in the base language.
 */
function page_fingerprint( $html ) {
	foreach ( (array) ( cache_limits()['volatile'] ?? array() ) as $rule ) {
		if ( isset( $rule['pattern'], $rule['replace'] ) ) {
			$stable = preg_replace( $rule['pattern'], $rule['replace'], $html );
			$html   = is_string( $stable ) ? $stable : $html;
		}
	}
	return md5( $html );
}

/**
 * Keep the page cache small: drop the folders of older versions, and the oldest half of a language
 * folder that grew past the limit in data/cache.json.
 *
 * @param string $dir Language folder just written to.
 */
function trim_page_cache( $dir ) {
	$current = dirname( $dir );
	foreach ( (array) glob( cache_dir() . '/pages-*', GLOB_ONLYDIR ) as $old ) {
		if ( $old !== $current ) {
			delete_tree( $old );
		}
	}
	$files = (array) glob( $dir . '/*.html' );
	$max   = max( 1, (int) ( cache_limits()['max_pages_per_language'] ?? 1 ) );
	if ( count( $files ) <= $max ) {
		return;
	}
	usort( $files, fn( $a, $b ) => filemtime( $a ) <=> filemtime( $b ) );
	foreach ( array_slice( $files, 0, intdiv( count( $files ), 2 ) ) as $file ) {
		wp_delete_file( $file );
	}
}

/**
 * Delete a folder of the cache with everything in it.
 *
 * @param string $dir Folder inside cache_dir().
 */
function delete_tree( $dir ) {
	if ( ! str_starts_with( $dir, cache_dir() ) || ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $item ) {
		if ( in_array( basename( $item ), array( '.', '..' ), true ) ) {
			continue;
		}
		is_dir( $item ) ? delete_tree( $item ) : wp_delete_file( $item );
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Own cache folder.
}

/** Empty the whole cache (deactivation, uninstall). */
function clear_cache() {
	delete_tree( cache_dir() );
}
