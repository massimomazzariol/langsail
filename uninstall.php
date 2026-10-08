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
delete_option( 'langsail_db_version' );
