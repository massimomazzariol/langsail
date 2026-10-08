<?php
/**
 * Run from a clone of the repository: wp eval-file path/to/langsail/tests/integration.php
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
$langsail_slugs  = get_option( SLUGS_OPTION, null );
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
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['pages']} WHERE page IN (%s, %s)", '/langsail-test-page/', '/langsail-keep-test/' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	foreach ( array( 'LangSail test sentence one.', 'LangSail test alt', 'LangSail keep test', 'LangSail test sentence one, edited.', 'Something completely different here.' ) as $source ) {
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
	$ld   = '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebPage","@id":"' . home_url( '/' ) . '","url":"' . home_url( '/' ) . '","name":"Venice & Treviso","inLanguage":"en-US"}]}</script><meta property="og:title" content="Venice &amp; Treviso">';
	$seen = units( $ld );
	$check( array( 'Venice & Treviso' => 'attr' ) === $seen, 'JSON-LD texts and attributes share one unit when their decoded text is the same' );
	$out2 = translate_tags( $ld, fn( $s ) => 'Venice & Treviso' === $s ? 'Venezia e <Treviso>' : null, fn( $url ) => localize_url( $url, 'it_IT' ) );
	$check( str_contains( $out2, '"name":"Venezia e <Treviso>"' ) && str_contains( $out2, '"url":"' . home_url( '/it/' ) . '"' ) && str_contains( $out2, 'content="Venezia e &lt;Treviso&gt;"' ), 'JSON-LD names and URLs are translated, attributes are escaped' );
	$same = translate_tags( translate_text( $html, fn() => null ), fn() => null );
	$check( $same === $html, 'With no translations the document is unchanged' );
	$switch = '<a href="' . esc_url( home_url( '/' ) ) . '" hreflang="en">English</a>';
	$check( $switch === translate_tags( $switch, fn() => null, fn( $url ) => localize_url( $url, 'it_IT' ) ), 'Links that name their language are not moved' );

	$nav = units( '<ul><li><a class="item" href="/#transfers"><span class="label">Airport transfers</span></a></li></ul>' );
	$check( array( 'Airport transfers' => 'text' ) === $nav, 'A text wholly inside a link or label is a unit without its wrappers' );
	$check( array( 'Quote within 24 hours' => 'text' ) === units( '<p><span class="dot" aria-hidden="true">&#9679;</span> Quote within 24 hours</p>' ), 'Decorative elements at the start of a unit stay out of it' );

	$check( array( 'Choose a time' => 'attr', 'Hour' => 'attr' ) === units( '<input data-label-panel="Choose a time" data-label-hour="Hour" data-step="15" data-name="time">' ), 'Label data attributes (data-label-*) are units, other data attributes are not' );

	$check( array( 'Your request: {inputs.pickup}' => 'text' ) === units( '<p>{all_data}</p><p>Your request: {inputs.pickup}</p>' ), 'Units made only of merge placeholders are skipped' );

	$check( array( '[1]WhatsApp[/1] [2](opens in a new tab)[/2]' ) === array_map( fn( $s ) => to_placeholders( $s )['text'], array_keys( units( "<a href='#'>
  <span class='icon' aria-hidden='true'></span>
  <span class='label'>WhatsApp</span>
  <span class='sr'>(opens in a new tab)</span>
</a>" ) ) ), 'An empty icon inside a link with whitespace around is left out of the unit' );

	$check( array() === units( '<link rel="alternate" title="Site &raquo; Feed" href="/feed/">' ), 'Titles of <link> tags are not units' );
	$kept = normalize_settings( array( 'keep' => array( " Acme's  Cab ", '', 'VCE', 'VCE' ) ) )['keep'];
	$check( array( "Acme's Cab", 'VCE' ) === $kept, 'The never-translate list is trimmed and deduplicated' );

	$check( normalize( '&#171;Ciao&#187; e &#8220;ciao&#8221;' ) === normalize( '"Ciao" e "ciao"' ) && normalize( 'Acme&#8217;s' ) === normalize( "Acme's" ), 'Quotes written the way each language does match the same unit' );
	$check( array( plugin_basename( FILE ), 'a/a.php', 'z/z.php' ) === load_first( array( 'a/a.php', plugin_basename( FILE ), 'z/z.php' ) ), 'LangSail is kept first among the active plugins' );

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
	$check( str_contains( $coded, '<img class="langsail-flag"' ) && str_contains( $coded, 'alt=""' ) && str_contains( $coded, '<span>RU</span><span class="screen-reader-text"> Русский</span>' ), 'With flags and codes, flags are decorative and the accessible name is the code plus the full name' );

	// Storage and dictionary.
	update_option( OPTION, array_merge( get_option( OPTION ), array( 'keep' => array( 'Brand Name Kept' ) ) ) );
	settings( true );
	$check( 1 === record_page( '/langsail-keep-test/', array( 'Brand Name Kept' => 'text', '<strong>Brand Name Kept</strong>' => 'text', 'LangSail keep test' => 'attr' ) ) && is_kept( '<strong>Brand Name Kept</strong>' ), 'Never-translate texts are not recorded, with or without formatting' );
	$page = '/langsail-test-page/';
	$new  = record_page( $page, array( 'LangSail test sentence one.' => 'text', 'LangSail test alt' => 'attr' ) );
	$check( 2 === $new && 2 === pages()[ $page ], 'Scanned units are stored and linked to their page' );
	$check( 0 === record_page( $page, array( 'LangSail test sentence one.' => 'text' ) ) && 1 === (int) pages()[ $page ], 'A second scan adds nothing and drops texts no longer on the page' );
	$rows = query_strings( array( 'page' => $page, 'locales' => array( 'it_IT' ) ) )['rows'];
	save_translations( 'it_IT', array( $rows[0]['id'] => 'Frase di prova LangSail uno.' ) );
	$check( 'Frase di prova LangSail uno.' === ( dictionary( 'it_IT' )[ md5( 'LangSail test sentence one.' ) ] ?? '' ), 'Saved translations are in the dictionary' );
	$check( 1 === (int) query_strings( array( 'page' => $page, 'status' => 'missing', 'locales' => array( 'it_IT', 'es_ES' ) ) )['total'], 'A text missing in any language is listed as missing' );
	$check( '<p>Frase di prova LangSail uno.</p>' === translate_html( '<p>LangSail test sentence one.</p>', 'it_IT' ), 'Pages are translated from the dictionary' );

	// Import and export.
	$export = export_json();
	$entry  = current( array_filter( $export['strings'], fn( $s ) => 'LangSail test sentence one.' === $s['source'] ) );
	$check( 'langsail' === $export['format'] && $entry && 'Frase di prova LangSail uno.' === ( (array) $entry['translations'] )['it_IT']['text'] && in_array( $page, $entry['pages'], true ), 'The JSON export carries texts, pages and translations' );
	$entry['translations'] = array( 'es_ES' => array( 'text' => 'Frase de prueba LangSail uno.', 'status' => 'translated' ) );
	$imported = import_json( array( 'format' => 'langsail', 'version' => 1, 'base' => settings()['base'], 'strings' => array( $entry ) ) );
	$check( ! is_wp_error( $imported ) && 1 === $imported['translations'] && 'Frase de prueba LangSail uno.' === ( dictionary( 'es_ES' )[ md5( 'LangSail test sentence one.' ) ] ?? '' ), 'A JSON import writes the translations of the site languages' );
	$check( is_wp_error( import_json( array( 'format' => 'langsail', 'base' => 'xx_XX', 'strings' => array() ) ) ) && is_wp_error( import_json( array( 'foo' ) ) ), 'Exports from another base language or other files are refused' );
	$po = export_po( 'it_IT' );
	$check( str_contains( $po, 'msgid "LangSail test sentence one."' ) && str_contains( $po, 'msgstr "Frase di prova LangSail uno."' ) && str_contains( $po, '#: ' . $page ) && str_contains( $po, '"Language: it_IT\\n"' ), 'The PO export lists the texts of the language with their pages' );
	$check( array( array( 'msgid' => 'A "quoted" line', 'msgstr' => "Una riga\ncon \"virgolette\"", 'fuzzy' => true ) ) === parse_po( "#, fuzzy\nmsgid \"A \\\"quoted\\\" \"\n\"line\"\nmsgstr \"Una riga\\ncon \\\"virgolette\\\"\"\n" ), 'PO files are parsed with continuation lines, escapes and the fuzzy flag' );
	$po_import = import_po( "#, fuzzy\nmsgid \"LangSail test sentence one.\"\nmsgstr \"Frase rivista.\"\n", 'ru_RU' );
	$row       = current( query_strings( array( 'page' => $page, 'locales' => array( 'ru_RU' ) ) )['rows'] );
	$check( ! is_wp_error( $po_import ) && 1 === $po_import['translations'] && 'review' === ( $row['translations']['ru_RU']['status'] ?? '' ), 'A fuzzy PO entry is imported as "to review"' );
	$check( is_wp_error( import_po( '', 'de_DE' ) ), 'A PO file for a language the site does not have is refused' );
	$mapped = import_file( 'it.json', wp_json_encode( array( '_note' => 'ignored', 'LangSail test sentence one.' => 'Frase dalla mappa.' ) ), 'it_IT' );
	$check( ! is_wp_error( $mapped ) && 1 === $mapped['translations'] && 'Frase dalla mappa.' === ( dictionary( 'it_IT' )[ md5( 'LangSail test sentence one.' ) ] ?? '' ), 'A translation map (source text to translation) imports into one language' );
	save_translations( 'it_IT', array( $row['id'] => 'Frase di prova LangSail uno.' ) );

	// API and background responses.
	$check( 'LangSail test sentence one.' === apply_filters( 'langsail_translate', 'LangSail test sentence one.' ), 'Outside a translated request the API returns the text unchanged' );
	current_language( 'it_IT' );
	$check( 'Frase di prova LangSail uno.' === apply_filters( 'langsail_translate', "LangSail test\n sentence one." ) && 'Untranslated text' === apply_filters( 'langsail_translate', 'Untranslated text' ), 'In a translated request the API returns the translation, or the text when there is none' );
	$json = translate_response( wp_json_encode( array( 'success' => true, 'data' => array( 'message' => '<p>LangSail test sentence one.</p>', 'plain' => 'LangSail test sentence one.', 'count' => 3 ) ) ) );
	$check( str_contains( $json, '"message":"<p>Frase di prova LangSail uno.</p>"' ) && str_contains( $json, '"plain":"Frase di prova LangSail uno."' ) && str_contains( $json, '"count":3' ), 'JSON responses of background requests are translated string by string' );
	current_language( settings()['base'] );
	$_SERVER['HTTP_REFERER'] = home_url( '/es/privacy-policy/?x=1' );
	$check( array( 'locale' => 'es_ES', 'uri' => (string) wp_parse_url( home_url( '/privacy-policy/?x=1' ), PHP_URL_PATH ) . '?x=1' ) === language_from_uri( page_referer() ), 'A background request takes the language of the page that made it' );
	$_SERVER['HTTP_REFERER'] = 'https://example.com/es/';
	$check( '' === page_referer(), 'A referer from another site is ignored' );
	unset( $_SERVER['HTTP_REFERER'] );

	// Indexing readiness and sitemap.
	$check( is_ready( 'en_US', $page ) && ! is_ready( 'ru_RU', $page ) && is_ready( 'it_IT', $page ), 'A page is indexable in a language once all its texts are translated, reviews excluded (default threshold 100%)' );
	$check( ! is_ready( 'it_IT', '/never-scanned/' ), 'A page never scanned is not indexable in a translation' );
	$map_xml = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' . home_url( $page ) . '</loc><lastmod>2026-10-08</lastmod></url><url><loc>https://example.com/x/</loc></url></urlset>';
	$map_out = localize_sitemap( $map_xml );
	$check( str_contains( $map_out, 'xmlns:xhtml=' ) && 2 === substr_count( $map_out, '<loc>' . esc_url( home_url( $page ) ) ) + substr_count( $map_out, '<loc>' . esc_url( home_url( '/it' . $page ) ) ) && ! str_contains( $map_out, home_url( '/ru' . $page ) ) && str_contains( $map_out, 'hreflang="x-default"' ) && str_contains( $map_out, '<loc>https://example.com/x/</loc>' ), 'The sitemap lists every ready language version with alternates and leaves other hosts alone' );

	// Real pages: scan the home page, translate one of its texts, read it in Italian.
	$http     = array( 'timeout' => 60 ); // Local sites can be slow on a cold request.
	$response = wp_remote_get( add_query_arg( SCAN_ARG, 'invalid', home_url( '/' ) ), $http );
	$check( ! is_wp_error( $response ) && ! str_starts_with( wp_remote_retrieve_header( $response, 'content-type' ), 'application/json' ), 'A scan without a valid nonce and administrator is a normal page' );
	$it = wp_remote_get( home_url( '/it/' ), $http );
	$check( ! is_wp_error( $it ) && 200 === wp_remote_retrieve_response_code( $it ), 'The home page answers in a translation language' );
	$body = wp_remote_retrieve_body( $it );
	$check( str_contains( $body, 'lang="it-IT"' ) || str_contains( $body, 'lang="it"' ), 'The page declares the request language' );
	$check( str_contains( $body, 'hreflang="x-default"' ) && str_contains( $body, 'hreflang="ru"' ), 'Language versions are linked with hreflang' );

	// A complete scan forgets pages it did not find.
	record_page( '/langsail-keep-test/', array( 'LangSail keep test' => 'text' ) );
	$check( 1 === prune_pages( array_values( array_diff( array_keys( pages() ), array( '/langsail-keep-test/' ) ) ) ) && ! isset( pages()['/langsail-keep-test/'] ) && isset( pages()[ $page ] ), 'Pages missing from a complete scan are forgotten, the others kept' );
	$check( 0 === prune_pages( array() ), 'An empty scan result forgets nothing' );

	// Edited texts and cleanup.
	record_page( $page, array( 'LangSail test sentence one, edited.' => 'text' ) );
	$edited = current( query_strings( array( 'page' => $page, 'locales' => array( 'it_IT' ) ) )['rows'] );
	$check( 'LangSail test sentence one, edited.' === $edited['source'] && 'review' === ( $edited['translations']['it_IT']['status'] ?? '' ) && '' !== ( $edited['translations']['it_IT']['text'] ?? '' ), 'An edited text inherits the old translations, marked to review' );
	$check( in_array( $edited['id'], array_column( query_strings( array( 'status' => 'review', 'locales' => array( 'it_IT' ), 'per_page' => 500 ) )['rows'], 'id' ), true ), 'The "to review" filter finds texts to check in one language' );
	record_page( $page, array( 'Something completely different here.' => 'text' ) );
	$other = current( query_strings( array( 'page' => $page, 'locales' => array( 'it_IT' ) ) )['rows'] );
	$check( array() === $other['translations'], 'A text unlike the one it replaced starts untranslated' );
	$check( 1 === query_strings( array( 'page' => $page, 'status' => 'missing', 'locales' => array( 'es_ES' ), 'offset' => 0 ) )['total'], 'The "missing" filter works for a single language' );
	$rebuilt = rebuild_translations( array( $other['id'] => 'Qualcosa [1]di[/1] diverso.', 999999999 => 'x' ) );
	$check( isset( $rebuilt['errors'][ $other['id'] ], $rebuilt['errors'][999999999] ) && ! $rebuilt['clean'], 'Translations with markers the source lacks, or for unknown texts, are rejected' );

	$honeypot = units( '<form><div class="ff-el-group ff-hpsf-container"><label>Newsletter</label></div><p class="x notranslate">Brand</p><label>Your name</label></form>' );
	$check( array( 'Your name' ) === array_keys( $honeypot ), 'Hidden anti-spam fields and notranslate elements are not collected' );

	// Messages of other plugins (a Mintchat button with its own message) go through the same table.
	save_translations( 'it_IT', array( (int) $other['id'] => 'Qualcosa di completamente diverso.' ) );
	current_language( 'it_IT' );
	$message = apply_filters( 'mintchat_message', 'Something completely different here.', '' );
	current_language( settings()['base'] );
	$check( 'Qualcosa di completamente diverso.' === $message, 'Chat button messages are translated through the mintchat_message filter' );
	save_translations( 'it_IT', array( (int) $other['id'] => '' ) );

	// AI abilities and scans without a browser.
	if ( function_exists( 'wp_get_ability' ) ) {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		wp_set_current_user( (int) $admins[0] );
		$listed = wp_get_ability( 'langsail/list-texts' )->execute( array( 'locale' => 'it_IT', 'page' => $page, 'status' => 'all' ) );
		$check( 1 === $listed['total'] && 'Something completely different here.' === $listed['texts'][0]['source'] && 'missing' === $listed['texts'][0]['status'], 'The list-texts ability returns the texts of a page in one language' );
		$saved = wp_get_ability( 'langsail/save-translations' )->execute( array( 'locale' => 'it_IT', 'translations' => array( array( 'id' => (int) $other['id'], 'translation' => 'Qualcosa di completamente diverso.' ) ) ) );
		$check( 1 === $saved['saved'] && 'Qualcosa di completamente diverso.' === dictionary( 'it_IT' )[ md5( 'Something completely different here.' ) ], 'The save-translations ability stores a translation' );
		$check( is_wp_error( wp_get_ability( 'langsail/list-texts' )->execute( array( 'locale' => 'xx_XX' ) ) ), 'Abilities refuse languages the site does not use' );
		wp_set_current_user( 0 );
		$check( is_wp_error( wp_get_ability( 'langsail/list-languages' )->execute() ), 'Abilities need the translate capability' );
	}
	ensure_roles();
	$check( get_role( TRANSLATOR ) && get_role( TRANSLATOR )->has_cap( CAP_TRANSLATE ) && ! get_role( TRANSLATOR )->has_cap( 'manage_options' ), 'The Translator role can translate and nothing more' );
	$token = scan_token();
	$check( is_scan_token( $token ) && ! is_scan_token( '' ) && ! is_scan_token( 'x' . $token ), 'Scan tokens are checked exactly' );
	delete_transient( SCAN_TOKEN );

	// Translated addresses.
	update_option( SLUGS_OPTION, array( 'it_IT' => array( 'privacy-policy' => 'privacy', 'about' => 'chi-siamo' ) ) );
	$check( home_url( '/it/privacy/?a=1' ) === localize_url( home_url( '/privacy-policy/?a=1' ), 'it_IT' ) && home_url( '/es/privacy-policy/' ) === localize_url( home_url( '/privacy-policy/' ), 'es_ES' ), 'Links use the translated words of the address in that language only' );
	$check( $home . 'privacy-policy/?a=1' === language_from_uri( $home . 'it/privacy/?a=1' )['uri'] && $home . 'privacy-policy/' === language_from_uri( $home . 'it/privacy-policy/' )['uri'], 'Translated and original words both lead to the page' );
	$moved = export_json();
	update_option( SLUGS_OPTION, array() );
	update_option( OPTION, stored_settings( normalize_settings( array( 'base' => 'en_US', 'confirmed' => true ) ) ) );
	settings( true );
	import_json( json_decode( wp_json_encode( $moved ), true ) );
	$check( array( 'it_IT' => array( 'privacy-policy' => 'privacy', 'about' => 'chi-siamo' ) ) === get_option( SLUGS_OPTION ), 'Translated address words travel with the JSON export' );
	$check( array( 'en_US', 'it_IT', 'es_ES', 'ru_RU' ) === locales() && 'Italiano' === settings()['languages']['it_IT']['name'], 'A site without languages takes the settings of the imported backup' );

	// Deleting the plugin keeps the data unless the owner asked otherwise.
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'langsail/langsail.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core-required uninstall guard, defined only in this CLI test.
	}
	$check( false === settings()['delete'], 'Deleting data on uninstall is off by default' );
	require dirname( __DIR__ ) . '/uninstall.php';
	global $wpdb;
	$check( is_array( get_option( OPTION ) ) && is_array( get_option( SLUGS_OPTION ) ) && tables()['strings'] === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', tables()['strings'] ) ) && dictionary( 'it_IT' ), 'Uninstall with the default setting keeps settings, tables and translations' );
	$privacy = url_to_postid( home_url( '/privacy-policy/' ) );
	if ( $privacy ) {
		$response = wp_remote_get( home_url( '/it/privacy/' ), $http );
		$check( 200 === wp_remote_retrieve_response_code( $response ) && str_contains( wp_remote_retrieve_body( $response ), 'hreflang="it"' ), 'A page answers at its translated address' );
	}

	$check( remove_unused() >= 2 && ! isset( dictionary( 'it_IT' )[ md5( 'LangSail test sentence one.' ) ] ), 'Texts on no page are removed with their translations' );

	\WP_CLI::success( $langsail_passes . ' integration checks passed on WordPress ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION );
} finally {
	$cleanup();
	null === $langsail_old ? delete_option( OPTION ) : update_option( OPTION, $langsail_old );
	null === $langsail_slugs ? delete_option( SLUGS_OPTION ) : update_option( SLUGS_OPTION, $langsail_slugs );
}
