<?php
/**
 * WP-CLI: wp langsail export|import|stats.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

/**
 * Export, import and inspect LangSail translations.
 */
class CLI {

	/**
	 * Export translations.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Destination file (.json for every language, .po for one).
	 *
	 * [--locale=<locale>]
	 * : Language of a .po export.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function export( $args, $assoc_args ) {
		$file = $args[0];
		if ( str_ends_with( strtolower( $file ), '.po' ) ) {
			if ( ! isset( settings()['languages'][ $assoc_args['locale'] ?? '' ] ) ) {
				\WP_CLI::error( 'Pass --locale with one of the site languages for a .po export.' );
			}
			$content = export_po( $assoc_args['locale'] );
		} else {
			$content = wp_json_encode( export_json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		if ( false === file_put_contents( $file, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI output file.
			\WP_CLI::error( "Cannot write $file" );
		}
		\WP_CLI::success( "Exported to $file" );
	}

	/**
	 * Import translations.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : A LangSail .json export, a translation map (.json, one language) or a .po file.
	 *
	 * [--locale=<locale>]
	 * : Language of a .po file or a translation map.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function import( $args, $assoc_args ) {
		$content = file_get_contents( $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( false === $content ) {
			\WP_CLI::error( "Cannot read {$args[0]}" );
		}
		$result = import_file( $args[0], $content, $assoc_args['locale'] ?? '' );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		foreach ( $result['errors'] ?? array() as $error ) {
			\WP_CLI::warning( $error );
		}
		\WP_CLI::success( sprintf( 'Imported %d translations%s.', $result['translations'], isset( $result['texts'] ) ? " for {$result['texts']} texts" : '' ) );
	}

	/**
	 * Show translation progress per language.
	 */
	public function stats() {
		$progress = progress( array_keys( settings()['languages'] ) );
		\WP_CLI::log( "{$progress['total']} texts on the site." );
		foreach ( $progress['done'] as $locale => $done ) {
			\WP_CLI::log( sprintf( '%s: %d translated (%d%%)', $locale, $done, $progress['total'] ? floor( 100 * $done / $progress['total'] ) : 0 ) );
		}
	}
}

\WP_CLI::add_command( 'langsail', __NAMESPACE__ . '\\CLI' );
