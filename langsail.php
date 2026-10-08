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
require_once __DIR__ . '/includes/router.php';
require_once __DIR__ . '/includes/html.php';
require_once __DIR__ . '/includes/placeholders.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/frontend.php';
require_once __DIR__ . '/includes/switcher.php';

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin-settings.php';
	require_once __DIR__ . '/includes/admin-strings.php';
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

// The language is read from the URL before WordPress parses the request or loads any translation.
boot_router();

/** Create the tables and propose the current site language as the base language. */
function activate() {
	install_tables();
	if ( false === get_option( OPTION ) ) {
		add_option( OPTION, stored_settings( normalize_settings( array( 'base' => get_locale() ) ) ) );
	}
}
