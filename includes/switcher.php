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
 * The switcher: one link per language to the current page, each name in its own language and
 * marked translate="no" so it is never translated.
 *
 * @param string $wrapper Wrapper attributes (already escaped).
 */
function switcher_markup( $wrapper = '' ) {
	if ( ! settings()['languages'] ) {
		return '';
	}
	$items = '';
	foreach ( locales() as $locale ) {
		$current = current_language() === $locale;
		$items  .= sprintf(
			'<li><a href="%s" hreflang="%s" lang="%s" translate="no"%s>%s</a></li>',
			esc_url( current_url_in( $locale ) ),
			esc_attr( hreflang( $locale ) ),
			esc_attr( hreflang( $locale ) ),
			$current ? ' aria-current="true"' : '',
			esc_html( language_name( $locale ) )
		);
	}
	return sprintf( '<nav %s aria-label="%s"><ul>%s</ul></nav>', $wrapper ? $wrapper : 'class="wp-block-langsail-switcher"', esc_attr__( 'Language', 'langsail' ), $items );
}
