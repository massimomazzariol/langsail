<?php
/**
 * Language switcher block and markup.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

add_action( 'init', __NAMESPACE__ . '\\register_switcher' );

/** Register the dynamic block. */
function register_switcher() {
	register_block_type( dirname( FILE ) . '/blocks/switcher' );
}

/**
 * The switcher: one link per language to the current page, marked translate="no" so names are
 * never translated. Options: flags (decorative, the text always stays) and the text as the native
 * name or the short code; with the code, screen readers still hear the full name.
 *
 * @param string $wrapper    Wrapper attributes (already escaped).
 * @param array  $attributes Block attributes: showFlags, label.
 */
function switcher_markup( $wrapper = '', array $attributes = array() ) {
	if ( ! settings()['languages'] ) {
		return '';
	}
	$flags = ! empty( $attributes['showFlags'] );
	$code  = 'code' === ( $attributes['label'] ?? 'name' );
	$items = '';
	foreach ( locales() as $locale ) {
		$name  = esc_html( language_name( $locale ) );
		$label = $code ? '<span aria-hidden="true">' . esc_html( strtoupper( prefixes( locales() )[ $locale ] ) ) . '</span><span class="screen-reader-text">' . $name . '</span>' : $name;
		$items .= sprintf(
			'<li><a href="%1$s" hreflang="%2$s" lang="%2$s" translate="no"%3$s>%4$s%5$s</a></li>',
			esc_url( current_url_in( $locale ) ),
			esc_attr( hreflang( $locale ) ),
			current_language() === $locale ? ' aria-current="true"' : '',
			$flags ? flag_img( $locale ) : '',
			$label
		);
	}
	return sprintf( '<nav %s aria-label="%s"><ul>%s</ul></nav>', $wrapper ? $wrapper : 'class="wp-block-langsail-switcher"', esc_attr__( 'Language', 'langsail' ), $items );
}
