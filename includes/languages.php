<?php
/**
 * Languages: settings, URL prefixes and the language of the current request.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

const OPTION = 'langsail_settings';

/**
 * Settings, normalized: the base locale, whether it was confirmed, the translation languages (each
 * with its locale, native name and URL prefix) and the native names of all of them.
 *
 * @param bool $refresh Read the option again (after saving it).
 */
function settings( $refresh = false ) {
	static $cache = null;
	if ( null === $cache || $refresh ) {
		$cache = normalize_settings( get_option( OPTION, array() ) );
	}
	return $cache;
}
/**
 * A locale code as WordPress writes them (en_US, it_IT, de_DE_formal, pt_BR), or ''.
 *
 * @param mixed $locale Candidate locale.
 */
function sanitize_locale( $locale ) {
	return is_string( $locale ) && preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?$/D', $locale ) ? $locale : '';
}

/**
 * URL prefix per locale: the language part (it, es, ru) unless two enabled locales share it,
 * then the whole locale in lowercase (pt-br, pt-pt).
 *
 * @param string[] $locales Locales, base included.
 */
function prefixes( array $locales ) {
	$language = array();
	foreach ( $locales as $locale ) {
		$language[ $locale ] = strtolower( strtok( $locale, '_' ) );
	}
	$counts = array_count_values( $language );
	$result = array();
	foreach ( $language as $locale => $code ) {
		$result[ $locale ] = 1 === $counts[ $code ] ? $code : strtolower( str_replace( '_', '-', $locale ) );
	}
	return $result;
}

/**
 * Validate stored or submitted settings ( base, confirmed, threshold: percent of a page that must be
 * translated before that language version is indexed, keep: texts never translated (brands, codes),
 * languages: locale list, names: locale => native name ). Invalid or duplicate locales, and the base among the translations, are dropped.
 *
 * @param mixed $value Settings array.
 */
function normalize_settings( $value ) {
	$value = is_array( $value ) ? $value : array();
	$base  = sanitize_locale( $value['base'] ?? '' );
	$base  = '' !== $base ? $base : 'en_US';
	$names = array();
	foreach ( (array) ( $value['names'] ?? array() ) as $locale => $name ) {
		if ( '' !== sanitize_locale( $locale ) && is_string( $name ) && '' !== trim( $name ) ) {
			$names[ $locale ] = sanitize_text_field( $name );
		}
	}

	$locales = array();
	foreach ( (array) ( $value['languages'] ?? array() ) as $locale ) {
		$locale = sanitize_locale( $locale );
		if ( '' !== $locale && $locale !== $base && ! in_array( $locale, $locales, true ) ) {
			$locales[] = $locale;
		}
	}

	$prefixes  = prefixes( array_merge( array( $base ), $locales ) );
	$languages = array();
	foreach ( $locales as $locale ) {
		$languages[ $locale ] = array(
			'locale' => $locale,
			'name'   => $names[ $locale ] ?? native_name( $locale ),
			'prefix' => $prefixes[ $locale ],
		);
	}

	return array(
		'base'      => $base,
		'base_name' => $names[ $base ] ?? native_name( $base ),
		'confirmed' => ! empty( $value['confirmed'] ),
		'delete'    => ! empty( $value['delete'] ),
		'threshold' => isset( $value['threshold'] ) && is_numeric( $value['threshold'] ) ? max( 0, min( 100, (int) $value['threshold'] ) ) : 100,
		'brief'     => sanitize_textarea_field( (string) ( $value['brief'] ?? '' ) ),
		'keep'      => array_values( array_unique( array_filter( array_map( fn( $line ) => normalize( sanitize_text_field( (string) $line ) ), (array) ( $value['keep'] ?? array() ) ) ) ) ),
		'languages' => $languages,
		'names'     => array_intersect_key( $names, array_flip( array_merge( array( $base ), $locales ) ) ),
	);
}

/**
 * The stored form of normalized settings.
 *
 * @param array $settings Normalized settings.
 */
function stored_settings( array $settings ) {
	return array(
		'base'      => $settings['base'],
		'confirmed' => $settings['confirmed'],
		'delete'    => $settings['delete'],
		'threshold' => $settings['threshold'],
		'brief'     => $settings['brief'],
		'keep'      => $settings['keep'],
		'languages' => array_keys( $settings['languages'] ),
		'names'     => $settings['names'],
	);
}
/** Every locale of the site, base first. */
function locales() {
	$settings = settings();
	return array_merge( array( $settings['base'] ), array_keys( $settings['languages'] ) );
}

/**
 * Native name of a locale when the settings have none (languages set from code or WP-CLI): from the
 * WordPress.org list WordPress keeps after installing languages, never fetched here.
 *
 * @param string $locale Locale.
 */
function native_name( $locale ) {
	$data  = json_decode( (string) file_get_contents( dirname( FILE ) . '/data/common-languages.json' ), true );
	$known = (array) get_site_transient( 'available_translations' ) + (array) ( $data['builtin'] ?? array() );
	return is_array( $known[ $locale ] ?? null ) && ! empty( $known[ $locale ]['native_name'] ) ? sanitize_text_field( $known[ $locale ]['native_name'] ) : $locale;
}

/**
 * Display name of a locale from the settings.
 *
 * @param string $locale Locale.
 */
function language_name( $locale ) {
	$settings = settings();
	return $settings['names'][ $locale ] ?? native_name( $locale );
}

/**
 * The locale of the current request, set once by the router: the base locale unless the URL has a
 * language prefix.
 *
 * @param string|null $set Locale to set (router only).
 */
function current_language( $set = null ) {
	static $current = null;
	if ( null !== $set ) {
		$current = $set;
	}
	return $current ?? settings()['base'];
}

/** True when the request is in a translation language, not in the base one. */
function is_translated_request() {
	return current_language() !== settings()['base'];
}

/**
 * Every locale WordPress can install, as locale => array( english_name, native_name ), the common
 * ones (data/common-languages.json) first. Admin only: it may ask the WordPress.org API once (cached).
 */
function available_languages() {
	require_once ABSPATH . 'wp-admin/includes/translation-install.php';
	$data = json_decode( (string) file_get_contents( dirname( FILE ) . '/data/common-languages.json' ), true );
	$all  = $data['builtin'] ?? array();
	foreach ( wp_get_available_translations() as $locale => $info ) {
		$all[ $locale ] = array(
			'english_name' => $info['english_name'],
			'native_name'  => $info['native_name'],
		);
	}
	$common = array_values( array_intersect( $data['common'] ?? array(), array_keys( $all ) ) );
	$sorted = array();
	foreach ( $common as $locale ) {
		$sorted[ $locale ] = $all[ $locale ] + array( 'common' => true );
	}
	uasort( $all, fn( $a, $b ) => strcasecmp( $a['english_name'], $b['english_name'] ) );
	foreach ( $all as $locale => $info ) {
		$sorted[ $locale ] = $sorted[ $locale ] ?? $info + array( 'common' => false );
	}
	return $sorted;
}

/**
 * URL of the flag of a locale, from assets/flags (circle-flags language set): the regional flag
 * when there is one (en-us, pt-br), else the language's (it, ru); '' when none fits.
 *
 * @param string $locale Locale.
 */
function flag_url( $locale ) {
	static $found = array();
	if ( ! isset( $found[ $locale ] ) ) {
		$found[ $locale ] = '';
		$parts            = explode( '-', strtolower( str_replace( '_', '-', $locale ) ) );
		for ( $n = min( 2, count( $parts ) ); $n > 0; $n-- ) {
			$name = implode( '-', array_slice( $parts, 0, $n ) ) . '.svg';
			if ( is_readable( dirname( FILE ) . '/assets/flags/' . $name ) ) {
				$found[ $locale ] = plugins_url( 'assets/flags/' . $name, FILE );
				break;
			}
		}
	}
	return $found[ $locale ];
}

/**
 * A decorative flag image (the language name is always written next to it), or ''.
 *
 * @param string $locale Locale.
 * @param string $class  CSS class.
 */
function flag_img( $locale, $class = 'langsail-flag' ) {
	$url = flag_url( $locale );
	return '' === $url ? '' : sprintf( '<img class="%s" src="%s" alt="" width="20" height="20" loading="lazy" decoding="async">', esc_attr( $class ), esc_url( $url ) );
}

/**
 * Whether a text is on the "never translate" list (brand names, codes): compared as plain text.
 *
 * @param string $source Unit (HTML or plain).
 */
function is_kept( $source ) {
	$keep = settings()['keep'];
	return $keep && in_array( normalize( html_entity_decode( wp_strip_all_tags( $source ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), $keep, true );
}
