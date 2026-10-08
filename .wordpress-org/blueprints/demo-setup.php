<?php
/**
 * Live Preview demo (WordPress Playground): a small bakery site with a language switcher in the
 * header, then the texts, translations and settings of demo.json through LangSail's own import.
 * build.cjs puts this file and demo.json into blueprint.json.
 *
 * @package LangSail
 */

require '/wordpress/wp-load.php';

update_option( 'blogname', 'Harbor Bakery' );
update_option( 'blogdescription', 'Bread and pastries by the sea' );
wp_delete_post( 1, true );
wp_delete_post( 2, true );

$langsail_demo_pages = array(
	'home'    => array( 'Home', '<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} --><div class="wp-block-group alignwide" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)"><!-- wp:heading {"level":1,"fontSize":"xx-large"} --><h1 class="wp-block-heading has-xx-large-font-size">Fresh bread, every morning since 1987</h1><!-- /wp:heading --><!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size">We bake with <strong>slow-rising sourdough</strong> and flour from local mills. Come by the harbor or <a href="/contact/">order for pickup</a>.</p><!-- /wp:paragraph --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/menu/">See today\'s menu</a></div><!-- /wp:button --><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/contact/">Opening hours</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group --><!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide"><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Sourdough</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Twenty hours of fermentation for a crisp crust and an open crumb.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Pastries</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Croissants and cinnamon rolls, out of the oven at 7 a.m.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Coffee</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Beans roasted two streets away, served until noon.</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->' ),
	'menu'    => array( 'Menu', '<!-- wp:paragraph --><p>Our bread changes with the seasons. Ask at the counter for today\'s specials.</p><!-- /wp:paragraph -->' ),
	'about'   => array( 'About us', '<!-- wp:paragraph --><p>A family bakery on the harbor, open every day except Monday.</p><!-- /wp:paragraph -->' ),
	'contact' => array( 'Contact', '<!-- wp:paragraph --><p>Open Tuesday to Sunday, 7 a.m. to 2 p.m.</p><!-- /wp:paragraph -->' ),
);
$langsail_demo_ids   = array();
foreach ( $langsail_demo_pages as $langsail_demo_slug => $langsail_demo_page ) {
	$langsail_demo_ids[ $langsail_demo_slug ] = wp_insert_post(
		array(
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'post_name'     => $langsail_demo_slug,
			'post_title'    => $langsail_demo_page[0],
			'post_content'  => wp_slash( $langsail_demo_page[1] ),
			'page_template' => 'home' === $langsail_demo_slug ? 'page-no-title' : '',
		)
	);
}
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $langsail_demo_ids['home'] );

$langsail_demo_nav = '';
foreach ( array( 'menu', 'about', 'contact' ) as $langsail_demo_slug ) {
	$langsail_demo_nav .= '<!-- wp:navigation-link {"label":"' . $langsail_demo_pages[ $langsail_demo_slug ][0] . '","type":"page","id":' . $langsail_demo_ids[ $langsail_demo_slug ] . ',"url":"/' . $langsail_demo_slug . '/","kind":"post-type"} /-->';
}
$langsail_demo_nav_id = wp_insert_post( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Main', 'post_content' => $langsail_demo_nav ) );
$langsail_demo_header = '<!-- wp:group {"align":"full","layout":{"type":"default"}} --><div class="wp-block-group alignfull"><!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} --><div class="wp-block-group alignwide" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)"><!-- wp:site-title {"level":0} /--><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"right"}} --><div class="wp-block-group"><!-- wp:navigation {"ref":' . $langsail_demo_nav_id . ',"overlayBackgroundColor":"base","overlayTextColor":"contrast","layout":{"type":"flex","justifyContent":"right","flexWrap":"wrap"}} /--><!-- wp:langsail/switcher {"showFlags":true,"label":"code","fontSize":"small"} /--></div><!-- /wp:group --></div><!-- /wp:group --></div><!-- /wp:group --></div><!-- /wp:group -->';
$langsail_demo_part   = wp_insert_post( array( 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_name' => 'header', 'post_title' => 'Header', 'post_content' => wp_slash( $langsail_demo_header ) ) );
wp_set_object_terms( $langsail_demo_part, get_stylesheet(), 'wp_theme' );
wp_set_object_terms( $langsail_demo_part, 'header', 'wp_template_part_area' );

LangSail\import_json( json_decode( (string) file_get_contents( '/tmp/langsail-demo.json' ), true ) );
flush_rewrite_rules();
