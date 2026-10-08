<?php
/**
 * Placeholders: translators see a unit as plain text with numbered markers for its tags,
 * "Hello [1]our [2]team[/2][/1] today." and "Line[3/]break", never the HTML itself. Saving puts
 * the original tags back, so a translation cannot break the markup or add new tags.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

const MARKER_PATTERN = '/\[(\/?)(\d+)(\/?)\]/';

/**
 * Split a unit into readable text with markers and the tags behind them.
 *
 * @param string $html Unit HTML (as stored).
 * @return array{text: string, tags: array<int, array{open: string, close: string}>}
 */
function to_placeholders( $html ) {
	$text  = '';
	$tags  = array();
	$stack = array();
	foreach ( tokens( $html ) as $token ) {
		$raw = substr( $html, $token['start'], $token['end'] - $token['start'] );
		if ( 'tag' !== $token['type'] ) {
			$text .= html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			continue;
		}
		if ( in_array( $token['name'], VOID_TAGS, true ) ) {
			$n          = count( $tags ) + 1;
			$tags[ $n ] = array( 'open' => $raw, 'close' => '' );
			$text      .= "[$n/]";
		} elseif ( ! $token['closing'] ) {
			$n          = count( $tags ) + 1;
			$tags[ $n ] = array( 'open' => $raw, 'close' => '' );
			$stack[]    = $n;
			$text      .= "[$n]";
		} else {
			$n                   = (int) array_pop( $stack );
			$tags[ $n ]['close'] = $raw;
			$text               .= "[/$n]";
		}
	}
	return array(
		'text' => $text,
		'tags' => $tags,
	);
}

/**
 * Rebuild HTML from a translation with markers. Every paired marker of the source must appear,
 * opened and closed in a valid order; void markers (line breaks) may be moved, repeated or dropped.
 *
 * @param string $text   Translation with markers.
 * @param string $source Source unit HTML.
 * @return string|\WP_Error HTML, or an error naming the problem.
 */
function from_placeholders( $text, $source ) {
	$tags  = to_placeholders( $source )['tags'];
	$html  = '';
	$stack = array();
	$seen  = array();
	$parts = preg_split( MARKER_PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
	for ( $i = 0, $count = count( $parts ); $i < $count; $i += 4 ) {
		$html .= esc_html( $parts[ $i ] );
		if ( ! isset( $parts[ $i + 1 ] ) ) {
			break;
		}
		$closing = '/' === $parts[ $i + 1 ];
		$n       = (int) $parts[ $i + 2 ];
		$void    = '/' === $parts[ $i + 3 ];
		if ( ! isset( $tags[ $n ] ) || $void !== ( '' === $tags[ $n ]['close'] ) ) {
			/* translators: %d: marker number. */
			return new \WP_Error( 'langsail_marker', sprintf( __( 'Marker %d does not exist in the original text.', 'langsail' ), $n ) );
		}
		if ( $void ) {
			$html .= $tags[ $n ]['open'];
		} elseif ( ! $closing ) {
			if ( isset( $seen[ $n ] ) ) {
				/* translators: %d: marker number. */
				return new \WP_Error( 'langsail_marker', sprintf( __( 'Marker [%d] is used twice.', 'langsail' ), $n ) );
			}
			$seen[ $n ] = true;
			$stack[]    = $n;
			$html      .= $tags[ $n ]['open'];
		} else {
			if ( array_pop( $stack ) !== $n ) {
				/* translators: %d: marker number. */
				return new \WP_Error( 'langsail_marker', sprintf( __( 'Marker [/%d] closes in the wrong place.', 'langsail' ), $n ) );
			}
			$html .= $tags[ $n ]['close'];
		}
	}
	$paired = array_keys( array_filter( $tags, fn( $tag ) => '' !== $tag['close'] ) );
	if ( $stack || array_diff( $paired, array_keys( $seen ) ) ) {
		return new \WP_Error( 'langsail_marker', __( 'Every [n]...[/n] marker of the original text must be in the translation.', 'langsail' ) );
	}
	return $html;
}

/**
 * Show a saved translation with the markers of its source: each tag is numbered by the source tag
 * it copies, so moved parts keep their numbers.
 *
 * @param string $html   Translation HTML.
 * @param string $source Source unit HTML.
 */
function translation_placeholders( $html, $source ) {
	$numbers = array();
	foreach ( to_placeholders( $source )['tags'] as $n => $tag ) {
		$numbers[ $tag['open'] ][] = $n;
	}
	$used  = array();
	$stack = array();
	$text  = '';
	foreach ( tokens( $html ) as $token ) {
		$raw = substr( $html, $token['start'], $token['end'] - $token['start'] );
		if ( 'tag' !== $token['type'] ) {
			$text .= html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			continue;
		}
		if ( $token['closing'] ) {
			$text .= '[/' . (int) array_pop( $stack ) . ']';
			continue;
		}
		$free = array_values( array_diff( $numbers[ $raw ] ?? array( 0 ), $used ) );
		$n    = $free[0] ?? ( $numbers[ $raw ][0] ?? 0 );
		if ( in_array( $token['name'], VOID_TAGS, true ) ) {
			$text .= "[$n/]";
			continue;
		}
		$used[]  = $n;
		$stack[] = $n;
		$text   .= "[$n]";
	}
	return $text;
}

/**
 * A stored text as translators see it: markers instead of tags for text units, plain characters
 * instead of entities for everything.
 *
 * @param string $html   Stored text.
 * @param string $kind   text, attr or title.
 * @param string $source Source unit, for a translation (keeps the source's marker numbers).
 */
function display_text( $html, $kind, $source = null ) {
	if ( 'text' !== $kind ) {
		return html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
	return null === $source ? to_placeholders( $html )['text'] : translation_placeholders( $html, $source );
}
