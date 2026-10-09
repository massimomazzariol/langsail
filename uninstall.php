<?php
/**
 * Uninstall: remove the settings and the LangSail tables (texts and translations). Content is untouched.
 *
 * @package LangSail
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Data stays unless the site owner asked for it to go (LangSail > Settings > Your data): deleting the
// plugin by mistake must never lose translations.
$langsail_settings = get_option( 'langsail_settings' );
if ( ! is_array( $langsail_settings ) || empty( $langsail_settings['delete'] ) ) {
	return;
}

global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Drops LangSail's own tables, fixed names.
foreach ( array( 'langsail_strings', 'langsail_string_pages', 'langsail_translations' ) as $langsail_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $langsail_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixed table names.
}
delete_option( 'langsail_settings' );
delete_option( 'langsail_slugs' );
delete_option( 'langsail_roles_version' );
remove_role( 'langsail_translator' );
$langsail_admin = get_role( 'administrator' );
if ( $langsail_admin ) {
	$langsail_admin->remove_cap( 'langsail_translate' );
}
delete_option( 'langsail_db_version' );
delete_option( 'langsail_cache_version' ); // The cache files themselves go on deactivation.
