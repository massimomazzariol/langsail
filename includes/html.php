<?php
/**
 * HTML text units: finds every translatable text in a page and replaces it in place.
 *
 * A text unit is a run of text and phrasing tags (strong, em, links...) between two structural tags,
 * so a paragraph with a link or a bold word is translated as one sentence. Attribute units are the
 * human-readable attributes (alt, title, placeholder, aria-label, button values, SEO meta). Nothing
 * outside the units is touched: the rest of the page stays byte for byte as WordPress rendered it.
 * Contents of script, style, svg, pre, textarea and elements marked translate="no" or class
 * "notranslate" are never translated (HTML standard opt-out).
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

/** Phrasing elements kept inside a text unit (HTML content model, not a setting). */
const INLINE_TAGS = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr' );

/** Elements whose content is not page text. */
const RAW_TAGS = array( 'script', 'style', 'textarea', 'template', 'noscript', 'svg', 'math', 'pre', 'title', 'iframe', 'object' );

/** Elements without a closing tag. */
const VOID_TAGS = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );


/** Meta tags whose content is page text (by name or property). */
const TEXT_META = array( 'description', 'og:title', 'og:description', 'og:image:alt', 'og:site_name', 'twitter:title', 'twitter:description', 'twitter:image:alt' );

const TAG_PATTERN  = '/<!--.*?-->|<![^>]*>|<\?.*?\?>|<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?)*)\s*(\/?)>/s';
const ATTR_PATTERN = '/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/';

/**
 * Split HTML into tokens: text, tag (with name, closing flag, attributes), raw (content of a raw
 * element) and other (comments, doctype). Each token knows whether it sits in an opt-out zone.
 *
 * @param string $html HTML.
 * @return array<int, array{type: string, start: int, end: int, skip: bool, name?: string, closing?: bool, attrs?: array}>
 */
function tokens( $html ) {
	$tokens = array();
	$stack  = array(); // Open elements: array( name, opt-out ).
	$pos    = 0;
	while ( preg_match( TAG_PATTERN, $html, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
		$start = $m[0][1];
		$end   = $start + strlen( $m[0][0] );
		$skip  = in_array( true, array_column( $stack, 1 ), true );
		if ( $start > $pos ) {
			$tokens[] = array( 'type' => 'text', 'start' => $pos, 'end' => $start, 'skip' => $skip );
		}
		if ( ! isset( $m[2] ) || '' === $m[2][0] ) {
			$tokens[] = array( 'type' => 'other', 'start' => $start, 'end' => $end, 'skip' => $skip );
			$pos      = $end;
			continue;
		}

		$name    = strtolower( $m[2][0] );
		$closing = '/' === $m[1][0];
		$attrs   = $closing ? array() : attributes( $m[3][0], $start + strlen( '<' . $m[2][0] ) );
		$tokens[] = array( 'type' => 'tag', 'start' => $start, 'end' => $end, 'skip' => $skip, 'name' => $name, 'closing' => $closing, 'attrs' => $attrs );
		$pos      = $end;

		if ( $closing ) {
			for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
				if ( $stack[ $i ][0] === $name ) {
					array_splice( $stack, $i );
					break;
				}
			}
			continue;
		}
		if ( in_array( $name, VOID_TAGS, true ) || '/' === $m[4][0] ) {
			continue;
		}
		if ( in_array( $name, RAW_TAGS, true ) ) {
			// Jump to the matching closing tag: the content is one raw token.
			if ( ! preg_match( '#</' . preg_quote( $name, '#' ) . '\s*>#i', $html, $close, PREG_OFFSET_CAPTURE, $end ) ) {
				break;
			}
			$tokens[] = array( 'type' => 'raw', 'start' => $end, 'end' => $close[0][1], 'skip' => true, 'name' => $name );
			$pos      = $close[0][1];
			continue;
		}
		$class   = ' ' . ( $attrs['class']['value'] ?? '' ) . ' ';
		$stack[] = array( $name, 'no' === strtolower( $attrs['translate']['value'] ?? '' ) || str_contains( $class, ' notranslate ' ) );
	}
	if ( $pos < strlen( $html ) ) {
		$tokens[] = array( 'type' => 'text', 'start' => $pos, 'end' => strlen( $html ), 'skip' => in_array( true, array_column( $stack, 1 ), true ) );
	}
	return $tokens;
}

/**
 * Parse a tag's attribute string.
 *
 * @param string $source Attribute part of the tag.
 * @param int    $offset Its offset in the document.
 * @return array<string, array{value: string, start: int, end: int, quote: string}> Value offsets are absolute; start === end for valueless attributes.
 */
function attributes( $source, $offset ) {
	$attrs = array();
	preg_match_all( ATTR_PATTERN, $source, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
	foreach ( $all as $a ) {
		$name = strtolower( $a[1][0] );
		foreach ( array( 2 => '"', 3 => "'", 4 => '' ) as $group => $quote ) {
			if ( isset( $a[ $group ] ) && -1 !== $a[ $group ][1] ) {
				$attrs[ $name ] = array( 'value' => $a[ $group ][0], 'start' => $offset + $a[ $group ][1], 'end' => $offset + $a[ $group ][1] + strlen( $a[ $group ][0] ), 'quote' => $quote );
				continue 2;
			}
		}
		$attrs[ $name ] = array( 'value' => '', 'start' => $offset + $a[1][1], 'end' => $offset + $a[1][1], 'quote' => '' );
	}
	return $attrs;
}

/**
 * The key of a unit: whitespace collapsed, trimmed.
 *
 * @param string $text Unit HTML or attribute value.
 */
function normalize( $text ) {
	return trim( preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Whether a unit holds words (at least one letter), not just numbers, symbols, tags or merge
 * placeholders like {all_data} that a plugin fills in later.
 *
 * @param string $html Unit HTML.
 */
function has_words( $html ) {
	$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return (bool) preg_match( '/\p{L}/u', preg_replace( '/\{[^{}\s]+\}/', '', $text ) );
}

/**
 * Replace every text unit through $translate( $source, 'text' ), which returns the new HTML or null
 * to keep the original.
 *
 * @param string   $html      HTML.
 * @param callable $translate Callback.
 */
function translate_text( $html, callable $translate ) {
	$out    = '';
	$run    = array();
	$tokens = tokens( $html );
	$flush  = function () use ( &$run, &$out, $html, $translate ) {
		if ( $run ) {
			$out .= translate_run( $html, $run, $translate );
			$run  = array();
		}
	};
	foreach ( $tokens as $token ) {
		$inline = 'text' === $token['type'] || ( 'tag' === $token['type'] && in_array( $token['name'], INLINE_TAGS, true ) );
		if ( $inline && ! $token['skip'] ) {
			$run[] = $token;
			continue;
		}
		$flush();
		$out .= substr( $html, $token['start'], $token['end'] - $token['start'] );
	}
	$flush();
	return $out;
}

/**
 * Translate one run of text and phrasing tags. The unit is the run without surrounding whitespace,
 * leading closing tags and trailing opening tags; when its tags are still unbalanced, each text
 * piece is translated on its own.
 *
 * @param string   $html      Document.
 * @param array    $run       Tokens of the run.
 * @param callable $translate Callback.
 */
function translate_run( $html, array $run, callable $translate ) {
	$first = 0;
	$last  = count( $run ) - 1;
	while ( $first <= $last && ( ( 'tag' === $run[ $first ]['type'] && ( $run[ $first ]['closing'] || 'br' === $run[ $first ]['name'] ) ) || ( 'text' === $run[ $first ]['type'] && '' === trim( substr( $html, $run[ $first ]['start'], $run[ $first ]['end'] - $run[ $first ]['start'] ) ) ) ) ) {
		++$first;
	}
	while ( $last >= $first && ( ( 'tag' === $run[ $last ]['type'] && ( ! $run[ $last ]['closing'] || 'br' === $run[ $last ]['name'] ) ) || ( 'text' === $run[ $last ]['type'] && '' === trim( substr( $html, $run[ $last ]['start'], $run[ $last ]['end'] - $run[ $last ]['start'] ) ) ) ) ) {
		--$last;
	}
	$from = $run[0]['start'];
	$to   = end( $run )['end'];
	if ( $first > $last ) {
		return substr( $html, $from, $to - $from );
	}

	// A unit wholly wrapped in phrasing tags (a menu link, a label span) is translated inside them.
	while ( $first < $last && 'tag' === $run[ $first ]['type'] && ! $run[ $first ]['closing'] && 'tag' === $run[ $last ]['type'] && $run[ $last ]['closing'] && $run[ $first ]['name'] === $run[ $last ]['name'] && balanced( array_slice( $run, $first + 1, $last - $first - 1 ) ) ) {
		++$first;
		--$last;
	}
	// Decorative elements at either end (a bullet, an icon span: no words inside) stay out of the unit.
	$words = fn( $a, $b ) => has_words( substr( $html, $run[ $a ]['start'], $run[ $b ]['end'] - $run[ $a ]['start'] ) );
	$blank = fn( $i ) => 'text' === $run[ $i ]['type'] && '' === trim( substr( $html, $run[ $i ]['start'], $run[ $i ]['end'] - $run[ $i ]['start'] ) );
	do {
		$trimmed = false;
		$close   = 'tag' === $run[ $first ]['type'] && ! $run[ $first ]['closing'] ? matching_close( $run, $first, $last ) : null;
		if ( null !== $close && $close < $last && ! $words( $first, $close ) ) {
			$first = $close + 1;
			while ( $first < $last && $blank( $first ) ) {
				++$first;
			}
			$trimmed = true;
		}
		$open = 'tag' === $run[ $last ]['type'] && $run[ $last ]['closing'] ? matching_open( $run, $first, $last ) : null;
		if ( null !== $open && $open > $first && ! $words( $open, $last ) ) {
			$last = $open - 1;
			while ( $last > $first && $blank( $last ) ) {
				--$last;
			}
			$trimmed = true;
		}
	} while ( $trimmed && $first < $last );

	if ( $first > $last ) {
		return substr( $html, $from, $to - $from );
	}

	if ( ! balanced( array_slice( $run, $first, $last - $first + 1 ) ) ) {
		$out = '';
		foreach ( $run as $token ) {
			$out .= 'text' === $token['type'] ? translate_piece( $html, $token['start'], $token['end'], $translate ) : substr( $html, $token['start'], $token['end'] - $token['start'] );
		}
		return $out;
	}
	return substr( $html, $from, $run[ $first ]['start'] - $from )
		. translate_piece( $html, $run[ $first ]['start'], $run[ $last ]['end'], $translate )
		. substr( $html, $run[ $last ]['end'], $to - $run[ $last ]['end'] );
}

/**
 * Translate html[start, end) as one unit, keeping its outer whitespace.
 *
 * @param string   $html      Document.
 * @param int      $start     Start offset.
 * @param int      $end       End offset.
 * @param callable $translate Callback.
 */
function translate_piece( $html, $start, $end, callable $translate ) {
	$piece = substr( $html, $start, $end - $start );
	$core  = trim( $piece );
	if ( '' === $core || ! has_words( $core ) ) {
		return $piece;
	}
	$new = $translate( normalize( $core ), 'text' );
	if ( ! is_string( $new ) ) {
		return $piece;
	}
	$lead = strspn( $piece, " \t\n\r\f" );
	return substr( $piece, 0, $lead ) . $new . substr( $piece, $lead + strlen( $core ) );
}

/**
 * Index of the tag closing the element opened at $open, within $open..$last, or null.
 *
 * @param array $run  Run tokens.
 * @param int   $open Index of an opening tag.
 * @param int   $last Last index to look at.
 */
function matching_close( array $run, $open, $last ) {
	$depth = 0;
	for ( $i = $open; $i <= $last; $i++ ) {
		if ( 'tag' !== $run[ $i ]['type'] || $run[ $i ]['name'] !== $run[ $open ]['name'] ) {
			continue;
		}
		$depth += $run[ $i ]['closing'] ? -1 : 1;
		if ( 0 === $depth ) {
			return $i;
		}
	}
	return null;
}

/**
 * Index of the tag opening the element closed at $close, within $first..$close, or null.
 *
 * @param array $run   Run tokens.
 * @param int   $first First index to look at.
 * @param int   $close Index of a closing tag.
 */
function matching_open( array $run, $first, $close ) {
	$depth = 0;
	for ( $i = $close; $i >= $first; $i-- ) {
		if ( 'tag' !== $run[ $i ]['type'] || $run[ $i ]['name'] !== $run[ $close ]['name'] ) {
			continue;
		}
		$depth += $run[ $i ]['closing'] ? 1 : -1;
		if ( 0 === $depth ) {
			return $i;
		}
	}
	return null;
}

/**
 * Whether opening and closing phrasing tags pair up in order.
 *
 * @param array $tokens Run tokens.
 */
function balanced( array $tokens ) {
	$stack = array();
	foreach ( $tokens as $token ) {
		if ( 'tag' !== $token['type'] || in_array( $token['name'], VOID_TAGS, true ) ) {
			continue;
		}
		if ( ! $token['closing'] ) {
			$stack[] = $token['name'];
		} elseif ( array_pop( $stack ) !== $token['name'] ) {
			return false;
		}
	}
	return ! $stack;
}

/**
 * Replace the plain-text units: readable attributes, the document title and the texts of JSON-LD
 * structured data, all keyed by their decoded text (so "&amp;" in an attribute and "&" in JSON are
 * the same unit) through $translate( $source, kind ). Optionally map links (a/area href, canonical,
 * og:url, JSON-LD url and @id) through $link( $url ).
 *
 * @param string        $html      HTML.
 * @param callable      $translate Callback returning the new plain text or null.
 * @param callable|null $link      Callback returning the new URL.
 */
function translate_tags( $html, callable $translate, $link = null ) {
	$edits  = array(); // start => array( end, replacement ).
	$plain  = function ( $raw, $kind ) use ( $translate ) {
		$text = trim( html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return '' !== $text && has_words( $text ) ? $translate( normalize( $text ), $kind ) : null;
	};
	$jsonld = false;
	foreach ( tokens( $html ) as $token ) {
		if ( 'raw' === $token['type'] ) {
			$raw = substr( $html, $token['start'], $token['end'] - $token['start'] );
			$new = null;
			if ( 'title' === $token['name'] ) {
				$new = $plain( $raw, 'title' );
				$new = is_string( $new ) ? esc_html( $new ) : null;
			} elseif ( $jsonld ) {
				$new = translate_json_ld( $raw, $translate, $link );
			}
			if ( is_string( $new ) ) {
				$edits[ $token['start'] ] = array( $token['end'], $new );
			}
			continue;
		}
		if ( 'tag' !== $token['type'] || $token['closing'] || $token['skip'] ) {
			continue;
		}
		$attrs  = $token['attrs'];
		$jsonld = 'script' === $token['name'] && 'application/ld+json' === strtolower( $attrs['type']['value'] ?? '' );
		$names  = text_attributes( array_keys( $attrs ) );
		if ( 'input' === $token['name'] && in_array( strtolower( $attrs['type']['value'] ?? '' ), array( 'submit', 'button', 'reset' ), true ) ) {
			$names[] = 'value';
		}
		if ( 'meta' === $token['name'] && in_array( strtolower( $attrs['name']['value'] ?? $attrs['property']['value'] ?? '' ), TEXT_META, true ) ) {
			$names[] = 'content';
		}
		foreach ( $names as $name ) {
			$new = isset( $attrs[ $name ] ) ? $plain( $attrs[ $name ]['value'], 'attr' ) : null;
			if ( is_string( $new ) ) {
				$edits[ $attrs[ $name ]['start'] ] = array( $attrs[ $name ]['end'], quote_value( esc_attr( $new ), $attrs[ $name ]['quote'] ) );
			}
		}
		// Links that name their language (the switcher, alternates) already point where they should.
		if ( $link && ! isset( $attrs['hreflang'] ) ) {
			$target = null;
			if ( in_array( $token['name'], array( 'a', 'area' ), true ) || ( 'link' === $token['name'] && 'canonical' === strtolower( $attrs['rel']['value'] ?? '' ) ) ) {
				$target = 'href';
			} elseif ( 'meta' === $token['name'] && 'og:url' === strtolower( $attrs['property']['value'] ?? '' ) ) {
				$target = 'content';
			}
			if ( $target && isset( $attrs[ $target ] ) ) {
				$url = html_entity_decode( $attrs[ $target ]['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$new = $link( $url );
				if ( $new !== $url ) {
					$edits[ $attrs[ $target ]['start'] ] = array( $attrs[ $target ]['end'], quote_value( esc_url( $new ), $attrs[ $target ]['quote'] ) );
				}
			}
		}
	}
	krsort( $edits );
	foreach ( $edits as $start => $edit ) {
		$html = substr_replace( $html, $edit[1], $start, $edit[0] - $start );
	}
	return $html;
}

/**
 * Which of a tag's attributes are read by people (data/attributes.json: names and prefixes).
 *
 * @param string[] $present Attribute names of the tag.
 * @return string[]
 */
function text_attributes( array $present ) {
	static $rules = null;
	if ( null === $rules ) {
		$rules = json_decode( (string) file_get_contents( dirname( FILE ) . '/data/attributes.json' ), true );
	}
	return array_values(
		array_filter(
			$present,
			function ( $name ) use ( $rules ) {
				if ( in_array( $name, $rules['names'], true ) ) {
					return true;
				}
				foreach ( $rules['prefixes'] as $prefix ) {
					if ( str_starts_with( $name, $prefix ) ) {
						return true;
					}
				}
				return false;
			}
		)
	);
}

/**
 * Translate a JSON-LD block: the readable properties (data/json-ld.json) through $translate, the
 * URL properties through $link. Returns the new JSON, or null when nothing changed or it is not JSON.
 *
 * @param string        $json      JSON-LD.
 * @param callable      $translate Callback returning the new plain text or null.
 * @param callable|null $link      Callback returning the new URL.
 */
function translate_json_ld( $json, callable $translate, $link = null ) {
	static $keys = null;
	if ( null === $keys ) {
		$keys = json_decode( (string) file_get_contents( dirname( FILE ) . '/data/json-ld.json' ), true );
	}
	$data = json_decode( $json, true );
	if ( ! is_array( $data ) ) {
		return null;
	}
	$changed = false;
	$walk    = function ( &$node ) use ( &$walk, &$changed, $keys, $translate, $link ) {
		foreach ( $node as $key => &$value ) {
			if ( is_array( $value ) ) {
				$walk( $value );
			} elseif ( is_string( $value ) && in_array( $key, $keys['text'], true ) && has_words( $value ) ) {
				$new = $translate( normalize( $value ), 'attr' );
				if ( is_string( $new ) ) {
					$value   = $new;
					$changed = true;
				}
			} elseif ( is_string( $value ) && $link && in_array( $key, $keys['url'], true ) ) {
				$new = $link( $value );
				if ( $new !== $value ) {
					$value   = $new;
					$changed = true;
				}
			}
		}
	};
	$walk( $data );
	return $changed ? str_replace( '</', '<\/', wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) : null;
}

/**
 * Quote an escaped value when its attribute had no quotes.
 *
 * @param string $value Escaped value.
 * @param string $quote The original quote character, or '' for an unquoted value.
 */
function quote_value( $value, $quote ) {
	return '' === $quote && preg_match( '/[\s>=`"\']/', $value ) ? '"' . $value . '"' : $value;
}
/**
 * Every unit of a page: source => kind.
 *
 * @param string $html HTML.
 * @return array<string, string>
 */
function units( $html ) {
	$found  = array();
	$record = function ( $source, $kind ) use ( &$found ) {
		$found[ $source ] = $found[ $source ] ?? $kind;
		return null;
	};
	translate_text( $html, $record );
	translate_tags( $html, $record );
	return $found;
}
