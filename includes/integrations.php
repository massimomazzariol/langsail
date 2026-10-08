<?php
/**
 * Integrations with other plugins. Each one hooks only when its plugin is active.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

add_filter( 'the_seo_framework_sitemap_additional_urls', __NAMESPACE__ . '\\tsf_sitemap_urls' );
add_action( 'langsail_translations_saved', __NAMESPACE__ . '\\tsf_clear_sitemap' );
add_action( 'langsail_page_scanned', __NAMESPACE__ . '\\tsf_clear_sitemap' );

/**
 * The SEO Framework writes its sitemap itself (output buffers are cleared): add the indexable language
 * versions of every scanned page through its own filter. Search engines pair them through the
 * hreflang links of each page.
 *
 * @param array $urls URL => array( lastmod ).
 */
function tsf_sitemap_urls( $urls ) {
	if ( ! settings()['languages'] ) {
		return $urls;
	}
	foreach ( array_keys( pages() ) as $page ) {
		$url     = home_url( $page );
		$post_id = url_to_postid( $url ) ? url_to_postid( $url ) : ( '/' === $page ? (int) get_option( 'page_on_front' ) : 0 );
		$lastmod = $post_id ? get_post_modified_time( 'Y-m-d H:i:s', true, $post_id ) : '';
		foreach ( array_keys( settings()['languages'] ) as $locale ) {
			if ( is_ready( $locale, $page ) ) {
				$urls[ localize_url( $url, $locale ) ] = array( 'lastmod' => $lastmod );
			}
		}
	}
	return $urls;
}

/** New translations or texts change which language versions are indexable: rebuild the sitemap. */
function tsf_clear_sitemap() {
	if ( class_exists( '\The_SEO_Framework\Sitemap\Cache' ) && method_exists( '\The_SEO_Framework\Sitemap\Cache', 'clear_sitemap_caches' ) ) {
		\The_SEO_Framework\Sitemap\Cache::clear_sitemap_caches();
	}
}
