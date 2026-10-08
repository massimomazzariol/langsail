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

// Mintchat: the pre-filled WhatsApp message lives inside a link, not in the page text.
add_filter( 'mintchat_message', __NAMESPACE__ . '\\translate' );

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

// Fluent Forms: validation messages live in the form configuration (browser rules and server checks);
// the confirmation message is only shown after submitting, in a background request.
add_filter( 'fluentform/form_vars_for_JS', __NAMESPACE__ . '\\fluentform_vars', 10, 2 );
add_filter( 'fluentform/validations', __NAMESPACE__ . '\\fluentform_validations' );
add_filter( 'fluentform/integration_feed_before_parse', __NAMESPACE__ . '\\fluentform_visitor_email' );
add_filter( 'fluentform/date_i18n', __NAMESPACE__ . '\\fluentform_date_labels' );

/**
 * Translate the browser validation messages of a form, and record its confirmation message while
 * the form is on a scanned page.
 *
 * @param array  $vars Form variables for the browser.
 * @param object $form Form.
 */
function fluentform_vars( $vars, $form ) {
	foreach ( (array) ( $vars['rules'] ?? array() ) as $field => $rules ) {
		foreach ( (array) $rules as $name => $rule ) {
			if ( isset( $rule['message'] ) ) {
				$vars['rules'][ $field ][ $name ]['message'] = translate( $rule['message'] );
			}
		}
	}
	if ( is_scan() && isset( $form->id ) && is_callable( array( '\FluentForm\App\Helpers\Helper', 'getFormMeta' ) ) ) {
		$settings = \FluentForm\App\Helpers\Helper::getFormMeta( $form->id, 'formSettings', array() );
		translate_markup( $settings['confirmation']['messageToShow'] ?? '' );
		foreach ( fluentform_visitor_notifications( (int) $form->id ) as $notification ) {
			translate( $notification['subject'] ?? '' );
			translate_markup( $notification['message'] ?? '' );
		}
	}
	return $vars;
}

/**
 * Whether an email notification goes to the visitor: its recipient is taken from a form field.
 * Those speak the visitor's language; notifications to fixed addresses (the site owner) keep the base language.
 *
 * @param mixed $settings Notification settings.
 */
function fluentform_is_visitor_email( $settings ) {
	return is_array( $settings ) && 'field' === ( $settings['sendTo']['type'] ?? '' );
}

/**
 * Visitor email notifications of a form (settings arrays).
 *
 * @param int $form_id Form id.
 */
function fluentform_visitor_notifications( $form_id ) {
	global $wpdb;
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT value FROM {$wpdb->prefix}fluentform_form_meta WHERE form_id = %d AND meta_key = 'notifications'", $form_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fluent Forms' own table, read during scans only.
	return array_filter( array_map( fn( $json ) => json_decode( $json, true ), $rows ), __NAMESPACE__ . '\\fluentform_is_visitor_email' );
}

/**
 * Translate a visitor email (subject and message) before Fluent Forms fills in the submitted data;
 * the submission request already runs in the language of the page that sent it.
 *
 * @param array $feed Notification feed.
 */
function fluentform_visitor_email( $feed ) {
	if ( 'notifications' === ( $feed['meta_key'] ?? '' ) && fluentform_is_visitor_email( $feed['settings'] ?? null ) && is_translated_request() ) {
		$feed['settings']['subject'] = translate( $feed['settings']['subject'] ?? '' );
		$feed['settings']['message'] = translate_markup( $feed['settings']['message'] ?? '' );
	}
	return $feed;
}

/**
 * Translate the server-side validation messages ( array( rules, messages ) ).
 *
 * @param array $validations Rules and messages.
 */
function fluentform_validations( $validations ) {
	if ( isset( $validations[1] ) && is_array( $validations[1] ) ) {
		foreach ( $validations[1] as $key => $message ) {
			$validations[1][ $key ] = translate( $message );
		}
	}
	return $validations;
}

/**
 * The date picker's screen reader labels (month, year, hour, minute) are not in Fluent Forms'
 * translations: add flatpickr's defaults (data/flatpickr.json) through the translation table.
 *
 * @param array $i18n flatpickr locale.
 */
function fluentform_date_labels( $i18n ) {
	$labels = json_decode( (string) file_get_contents( dirname( FILE ) . '/data/flatpickr.json' ), true );
	unset( $labels['_note'] );
	foreach ( (array) $labels as $key => $text ) {
		if ( ! isset( $i18n[ $key ] ) ) {
			$i18n[ $key ] = translate( $text );
		}
	}
	return $i18n;
}
