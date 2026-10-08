<?php
/**
 * Uninstall: remove the settings and the LangSail tables (texts and translations). Content is untouched.
 *
 * @package LangSail
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
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
