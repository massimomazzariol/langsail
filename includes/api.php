<?php
/**
 * Public API for themes and plugins, and translation of background responses.
 *
 * Texts that never appear in a page's HTML (a pre-filled message inside a link, a form's validation
 * messages, an email subject) go through the filter 'langsail_translate':
 *
 *     $text = apply_filters( 'langsail_translate', $text );
 *
 * HTML (a confirmation message with links) goes through 'langsail_translate_html' instead, so it is
 * collected and translated unit by unit like page content.
 *
 * Without LangSail the filters return the text unchanged. With it, a scan records the text for the
 * translation table (linked to the page being scanned), and a translated request returns its
 * translation, or the text when there is none yet.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

add_filter( 'langsail_translate', __NAMESPACE__ . '\\translate', 10, 1 );
add_filter( 'langsail_translate_html', __NAMESPACE__ . '\\translate_markup', 10, 1 );

/**
 * Translate a plain text into the request language (see the file comment).
 *
 * @param mixed $text Text.
 * @return mixed The translation, or the text.
 */
function translate( $text ) {
	if ( ! is_string( $text ) || '' === trim( $text ) || ! has_words( $text ) ) {
		return $text;
	}
	$key = normalize( $text );
	if ( is_scan() ) {
		collected( $key );
		return $text;
	}
	if ( ! is_translated_request() ) {
		return $text;
	}
	return dictionary( current_language() )[ md5( $key ) ] ?? $text;
}

/**
 * Translate an HTML fragment into the request language, unit by unit (see the file comment).
 *
 * @param mixed $html HTML.
 * @return mixed The translated HTML, or the HTML.
 */
function translate_markup( $html ) {
	if ( ! is_string( $html ) || '' === trim( $html ) ) {
		return $html;
	}
	if ( is_scan() ) {
		foreach ( units( $html ) as $key => $kind ) {
			collected( $key, $kind );
		}
		return $html;
	}
	return is_translated_request() ? translate_html( $html, current_language() ) : $html;
}

/**
 * Texts recorded through the API during this request: add one, or read them all.
 *
 * @param string|null $key  Normalized text to record.
 * @param string      $kind Unit kind: 'attr' for plain text, or the kind units() gave.
 * @return array<string, string> Text => kind.
 */
function collected( $key = null, $kind = 'attr' ) {
	static $texts = array();
	if ( null !== $key ) {
		$texts[ $key ] = $texts[ $key ] ?? $kind;
	}
	return $texts;
}

/**
 * Translate the response of a background request made by a translated page: every string of a
 * JSON response (messages, HTML fragments), or the whole body when it is HTML.
 *
 * @param string $body Response body.
 */
function translate_response( $body ) {
	if ( '' === trim( $body ) ) {
		return $body;
	}
	$locale = current_language();
	$data   = json_decode( $body, true );
	if ( ! is_array( $data ) ) {
		return str_contains( $body, '<' ) ? translate_html( $body, $locale ) : $body;
	}
	$changed = false;
	array_walk_recursive(
		$data,
		function ( &$value ) use ( $locale, &$changed ) {
			if ( ! is_string( $value ) || ! has_words( $value ) ) {
				return;
			}
			$new = translate_html( $value, $locale );
			if ( $new === $value && ! str_contains( $value, '<' ) ) {
				$new = dictionary( $locale )[ md5( normalize( $value ) ) ] ?? $value;
			}
			if ( $new !== $value ) {
				$value   = $new;
				$changed = true;
			}
		}
	);
	return $changed ? wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : $body;
}
