<?php
/**
 * Run with: wp eval-file wp-content/plugins/langsail/tests/integration.php
 * In WordPress Studio, prefix the command with studio. Settings and translations are restored in finally.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

$langsail_old    = get_option( OPTION, null );
$langsail_passes = 0;
$check           = static function ( $condition, $description ) use ( &$langsail_passes ) {
	if ( ! $condition ) {
		throw new \RuntimeException( $description );
	}
	++$langsail_passes;
	\WP_CLI::log( 'PASS: ' . $description );
};

// Test rows are removed before and after, so an interrupted run never leaves data behind.
$cleanup = static function () {
	global $wpdb;
	$t = tables();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['pages']} WHERE page = %s", '/langsail-test-page/' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	foreach ( array( 'LangSail test sentence one.', 'LangSail test alt' ) as $source ) {
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['strings']} WHERE hash = %s", md5( $source ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
		$wpdb->delete( $t['translations'], array( 'string_id' => $id ) );
		$wpdb->delete( $t['strings'], array( 'id' => $id ) );
	}
	wp_cache_flush();
};

try {
	$cleanup();
	// Settings and prefixes.
	$check( array( 'en_US' => 'en', 'it_IT' => 'it', 'pt_BR' => 'pt-br', 'pt_PT' => 'pt-pt' ) === prefixes( array( 'en_US', 'it_IT', 'pt_BR', 'pt_PT' ) ), 'Prefixes use the language code, the full locale only when two locales share it' );
	$normalized = normalize_settings( array( 'base' => 'en_US', 'languages' => array( 'it_IT', 'en_US', 'it_IT', 'bad locale', 'ru_RU' ), 'names' => array( 'it_IT' => 'Italiano', 'ru_RU' => 'Русский' ) ) );
	$check( array( 'it_IT', 'ru_RU' ) === array_keys( $normalized['languages'] ) && 'Italiano' === $normalized['languages']['it_IT']['name'], 'Invalid, duplicate and base locales are dropped from the translation languages' );
	update_option( OPTION, stored_settings( normalize_settings( array( 'base' => 'en_US', 'confirmed' => true, 'languages' => array( 'it_IT', 'es_ES', 'ru_RU' ), 'names' => array( 'en_US' => 'English', 'it_IT' => 'Italiano', 'es_ES' => 'Español', 'ru_RU' => 'Русский' ) ) ) ) );
	settings( true );

	// Router.
	$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$check( array( 'locale' => 'it_IT', 'uri' => $home . 'privacy-policy/?a=1' ) === language_from_uri( $home . 'it/privacy-policy/?a=1' ), 'A language prefix is read and removed from the request' );
	$check( array( 'locale' => 'ru_RU', 'uri' => $home ) === language_from_uri( $home . 'ru' ) && null === language_from_uri( $home . 'italy/' ) && null === language_from_uri( $home . 'privacy-policy/' ), 'Prefixes match whole path segments only' );
	$check( home_url( '/it/privacy-policy/#top' ) === localize_url( home_url( '/privacy-policy/#top' ), 'it_IT' ), 'Links to pages get the language prefix, fragment kept' );
	$check( '/es/contact/?x=1' === localize_url( '/contact/?x=1', 'es_ES' ) || str_ends_with( localize_url( '/contact/?x=1', 'es_ES' ), '/es/contact/?x=1' ), 'Root-relative links get the language prefix' );
	foreach ( array( home_url( '/wp-admin/' ), home_url( '/wp-content/uploads/a.jpg' ), home_url( '/it/privacy-policy/' ), 'https://example.com/page/', 'mailto:a@example.com', '#top', 'page/' ) as $url ) {
		$check( $url === localize_url( $url, 'it_IT' ), "Link left alone: $url" );
	}

	// Text units.
	$html  = '<p class="x">Hello <a href="/about/">our <strong>team</strong></a> today.</p><h2> Title  here </h2><p>2026</p>'
		. '<img src="a.jpg" alt="A red car"><input type="submit" value="Send now"><button aria-label="Close menu">x</button>'
		. '<script>var s = "Hello script";</script><p translate="no">Brand Name</p><div class="notranslate"><p>Keep me</p></div>'
		. '<meta name="description" content="Rides in Venice"><ul><li>One<br>Two</li></ul><figure><img src="b.jpg" alt=""><figcaption>A caption</figcaption></figure>';
	$found = units( $html );
	$check( isset( $found['Hello <a href="/about/">our <strong>team</strong></a> today.'] ), 'A paragraph with a link and bold text is one unit' );
	$check( isset( $found['Title here'] ) && ! isset( $found['2026'] ), 'Whitespace is collapsed; numbers alone are not units' );
	$check( isset( $found['A red car'], $found['Send now'], $found['Close menu'], $found['Rides in Venice'], $found['A caption'] ), 'Alt, button value, aria-label, meta description and captions are units' );
	$check( ! isset( $found['Hello script'] ) && ! isset( $found['Brand Name'] ) && ! isset( $found['Keep me'] ) && ! isset( $found['var s = "Hello script";'] ), 'Scripts, translate="no" and notranslate are never units' );
	$check( isset( $found['One<br>Two'] ), 'A line break stays inside its unit' );

	$map  = array(
		'Hello <a href="/about/">our <strong>team</strong></a> today.' => 'Ciao <a href="/about/">al nostro <strong>team</strong></a> oggi.',
		'Title here'      => 'Titolo qui',
		'A red car'       => 'Un\'auto "rossa"',
		'Rides in Venice' => 'Corse a Venezia',
	);
	$out  = translate_tags( translate_text( $html, fn( $s ) => $map[ $s ] ?? null ), fn( $s ) => $map[ $s ] ?? null, fn( $url ) => localize_url( $url, 'it_IT' ) );
	$check( str_contains( $out, '<p class="x">Ciao <a href="' . esc_url( localize_url( '/about/', 'it_IT' ) ) . '">al nostro <strong>team</strong></a> oggi.</p>' ), 'A unit is replaced in place and its internal link moves into the language' );
	$check( str_contains( $out, '<h2> Titolo qui </h2>' ), 'Whitespace around a unit is kept' );
	$check( str_contains( $out, 'alt="Un&#039;auto &quot;rossa&quot;"' ) && str_contains( $out, 'content="Corse a Venezia"' ), 'Attribute translations are escaped for their quotes' );
	$check( str_contains( $out, '<script>var s = "Hello script";</script>' ) && str_contains( $out, '<p translate="no">Brand Name</p>' ), 'Untranslated parts are byte for byte the same' );
	$same = translate_tags( translate_text( $html, fn() => null ), fn() => null );
	$check( $same === $html, 'With no translations the document is unchanged' );
	$switch = '<a href="' . esc_url( home_url( '/' ) ) . '" hreflang="en">English</a>';
	$check( $switch === translate_tags( $switch, fn() => null, fn( $url ) => localize_url( $url, 'it_IT' ) ), 'Links that name their language are not moved' );

	$nav = units( '<ul><li><a class="item" href="/#transfers"><span class="label">Airport transfers</span></a></li></ul>' );
	$check( array( 'Airport transfers' => 'text' ) === $nav, 'A text wholly inside a link or label is a unit without its wrappers' );
	$check( array( 'Quote within 24 hours' => 'text' ) === units( '<p><span class="dot" aria-hidden="true">&#9679;</span> Quote within 24 hours</p>' ), 'Decorative elements at the start of a unit stay out of it' );

	// Placeholders.
	$source = 'Hello <a href="/about/">our <strong>team</strong></a> &amp; friends<br>today.';
	$shown  = to_placeholders( $source );
	$check( 'Hello [1]our [2]team[/2][/1] & friends[3/]today.' === $shown['text'], 'Translators see markers and plain characters, not HTML' );
	$check( $source === from_placeholders( $shown['text'], $source ), 'Markers turn back into the original tags' );
	$check( is_string( from_placeholders( '[2]Squadra[/2] [1]nostra[/1] & amici[3/]oggi', $source ) ), 'Parts of a sentence can be moved, even out of each other' );
	$check( is_wp_error( from_placeholders( '[1]la [2]squadra[/1] nostra[/2]', $source ) ), 'Crossed markers are refused' );
	$moved = from_placeholders( 'Ciao [1]alla [2]squadra[/2][/1] < amici[3/]oggi', $source );
	$check( 'Ciao <a href="/about/">alla <strong>squadra</strong></a> &lt; amici<br>oggi' === $moved, 'Text around markers is escaped' );
	$check( 'Ciao [1]alla [2]squadra[/2][/1] < amici[3/]oggi' === translation_placeholders( $moved, $source ), 'A saved translation shows the source marker numbers' );
	$check( is_wp_error( from_placeholders( 'Ciao amici', $source ) ) && is_wp_error( from_placeholders( 'Ciao [9]x[/9] [1][2]a[/2][/1]', $source ) ), 'Missing or unknown markers are refused' );
	$check( 'Ciao [1][2]a[/2][/1]' === translation_placeholders( (string) from_placeholders( 'Ciao [1][2]a[/2][/1]', $source ), $source ), 'Dropping a line break is allowed' );

	// Flags and switcher.
	$check( str_ends_with( flag_url( 'it_IT' ), '/assets/flags/it.svg' ) && str_ends_with( flag_url( 'en_US' ), '/assets/flags/en-us.svg' ) && str_ends_with( flag_url( 'de_DE_formal' ), '/assets/flags/de.svg' ) && '' === flag_url( 'qqq' ), 'Flags: regional when available, else the language, else none' );
	$plain = switcher_markup();
	$coded = switcher_markup( '', array( 'showFlags' => true, 'label' => 'code' ) );
	$check( str_contains( $plain, '>Italiano</a>' ) && ! str_contains( $plain, '<img' ) && 4 === substr_count( $plain, 'hreflang=' ), 'The switcher lists every language by name, without flags by default' );
	$check( str_contains( $coded, '<img class="langsail-flag"' ) && str_contains( $coded, 'alt=""' ) && str_contains( $coded, '<span aria-hidden="true">RU</span><span class="screen-reader-text">Русский</span>' ), 'With flags and codes, flags are decorative and screen readers hear the full name' );

	// Storage and dictionary.
	$page = '/langsail-test-page/';
	$new  = record_page( $page, array( 'LangSail test sentence one.' => 'text', 'LangSail test alt' => 'attr' ) );
	$check( 2 === $new && 2 === pages()[ $page ], 'Scanned units are stored and linked to their page' );
	$check( 0 === record_page( $page, array( 'LangSail test sentence one.' => 'text' ) ) && 1 === (int) pages()[ $page ], 'A second scan adds nothing and drops texts no longer on the page' );
	$rows = query_strings( array( 'page' => $page, 'locales' => array( 'it_IT' ) ) )['rows'];
	save_translations( 'it_IT', array( $rows[0]['id'] => 'Frase di prova LangSail uno.' ) );
	$check( 'Frase di prova LangSail uno.' === ( dictionary( 'it_IT' )[ md5( 'LangSail test sentence one.' ) ] ?? '' ), 'Saved translations are in the dictionary' );
	$check( 1 === (int) query_strings( array( 'page' => $page, 'status' => 'missing', 'locales' => array( 'it_IT', 'es_ES' ) ) )['total'], 'A text missing in any language is listed as missing' );
	$check( '<p>Frase di prova LangSail uno.</p>' === translate_html( '<p>LangSail test sentence one.</p>', 'it_IT' ), 'Pages are translated from the dictionary' );

	// Real pages: scan the home page, translate one of its texts, read it in Italian.
	$http     = array( 'timeout' => 60 ); // Local sites can be slow on a cold request.
	$response = wp_remote_get( add_query_arg( SCAN_ARG, 'invalid', home_url( '/' ) ), $http );
	$check( ! is_wp_error( $response ) && ! str_starts_with( wp_remote_retrieve_header( $response, 'content-type' ), 'application/json' ), 'A scan without a valid nonce and administrator is a normal page' );
	$it = wp_remote_get( home_url( '/it/' ), $http );
	$check( ! is_wp_error( $it ) && 200 === wp_remote_retrieve_response_code( $it ), 'The home page answers in a translation language' );
	$body = wp_remote_retrieve_body( $it );
	$check( str_contains( $body, 'lang="it-IT"' ) || str_contains( $body, 'lang="it"' ), 'The page declares the request language' );
	$check( str_contains( $body, 'hreflang="x-default"' ) && str_contains( $body, 'hreflang="ru"' ), 'Language versions are linked with hreflang' );

	\WP_CLI::success( $langsail_passes . ' integration checks passed on WordPress ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION );
} finally {
	$cleanup();
	null === $langsail_old ? delete_option( OPTION ) : update_option( OPTION, $langsail_old );
}
