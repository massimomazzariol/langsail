<?php
/**
 * Plugin Name: LangSail
 * Description: Cookie-free multilingual sites with one structure: pages are built once in the base language and every text is translated from a table.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Plugin URI: https://github.com/massimomazzariol/langsail
 * Author: Massimo Mazzariol
 * Author URI: https://github.com/massimomazzariol
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: langsail
 *
 * @package LangSail
 * @copyright 2026 Massimo Mazzariol - https://github.com/massimomazzariol/langsail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';
const FILE    = __FILE__;

require_once __DIR__ . '/includes/languages.php';
require_once __DIR__ . '/includes/roles.php';
require_once __DIR__ . '/includes/router.php';
require_once __DIR__ . '/includes/slugs.php';
require_once __DIR__ . '/includes/html.php';
require_once __DIR__ . '/includes/placeholders.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/frontend.php';
require_once __DIR__ . '/includes/scan.php';
require_once __DIR__ . '/includes/abilities.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/switcher.php';
require_once __DIR__ . '/includes/integrations.php';
require_once __DIR__ . '/includes/transfer.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/cli.php';
}

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin-settings.php';
	require_once __DIR__ . '/includes/admin-strings.php';
}

add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
add_filter( 'pre_update_option_active_plugins', __NAMESPACE__ . '\\load_first' );
add_action( 'admin_init', __NAMESPACE__ . '\\ensure_load_first' );

// The language is read from the URL before WordPress parses the request or loads any translation.
boot_router();

/** Load the interface translations shipped in languages/ (wordpress.org language packs take precedence). */
function load_textdomain() {
	load_plugin_textdomain( 'langsail', false, dirname( plugin_basename( FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Ships its own translations outside WordPress.org.
}

/** Create the tables and propose the current site language as the base language. */
function activate() {
	install_tables();
	if ( false === get_option( OPTION ) ) {
		add_option( OPTION, stored_settings( normalize_settings( array( 'base' => get_locale() ) ) ) );
	}
}

/**
 * Keep LangSail first among the active plugins: it sets the request language before any other
 * plugin loads its translations (WordPress loads plugins in this order).
 *
 * @param mixed $plugins Active plugins.
 */
function load_first( $plugins ) {
	$self = plugin_basename( FILE );
	if ( is_array( $plugins ) && in_array( $self, $plugins, true ) ) {
		$plugins = array_values( array_diff( $plugins, array( $self ) ) );
		array_unshift( $plugins, $self );
	}
	return $plugins;
}

/** Move LangSail to the front if it is not there (sites where it was activated before this rule). */
function ensure_load_first() {
	$plugins = (array) get_option( 'active_plugins', array() );
	if ( $plugins && plugin_basename( FILE ) !== reset( $plugins ) ) {
		update_option( 'active_plugins', $plugins );
	}
}
