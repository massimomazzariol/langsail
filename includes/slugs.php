<?php
/**
 * Translated addresses: each part of a path (a slug) can have its own word in each language, so
 * /it/privacy/ can stand for /privacy-policy/. Links are rewritten to the translated words and
 * requests are mapped back before WordPress reads them. Untranslated words keep working.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

const SLUGS_OPTION = 'langsail_slugs';

add_action( 'admin_post_langsail_slugs', __NAMESPACE__ . '\\save_slugs' );

/**
 * Translated slugs of one language: base slug => translated slug.
 *
 * @param string $locale Locale.
 * @return array<string, string>
 */
function slugs( $locale ) {
	$all = get_option( SLUGS_OPTION, array() );
	return is_array( $all ) && isset( $all[ $locale ] ) && is_array( $all[ $locale ] ) ? $all[ $locale ] : array();
}

/**
 * Translate (or, reversed, untranslate) every slug of a path.
 *
 * @param string $path    Path, with or without query string.
 * @param string $locale  Locale.
 * @param bool   $reverse From translated slugs back to the base ones.
 */
function map_slugs( $path, $locale, $reverse = false ) {
	$map = slugs( $locale );
	if ( ! $map ) {
		return $path;
	}
	if ( $reverse ) {
		$map = array_flip( $map );
	}
	$query = strpbrk( $path, '?#' );
	$path  = false === $query ? $path : substr( $path, 0, -strlen( $query ) );
	$parts = explode( '/', $path );
	foreach ( $parts as &$part ) {
		$key = strtolower( $part );
		if ( isset( $map[ $key ] ) ) {
			$part = $map[ $key ];
		}
	}
	return implode( '/', $parts ) . ( false === $query ? '' : $query );
}

/**
 * Every slug of the scanned pages, in page order.
 *
 * @return string[]
 */
function page_slugs() {
	$found = array();
	foreach ( array_keys( pages() ) as $page ) {
		if ( '/' === substr( $page, 0, 1 ) ) {
			$found = array_merge( $found, array_filter( explode( '/', $page ), 'strlen' ) );
		}
	}
	return array_values( array_unique( $found ) );
}

/**
 * Store translated slugs of the site languages (from the form or an import). An empty slug, or one
 * equal to the original, removes the translation.
 *
 * @param mixed $submitted Locale => (base slug => translated slug).
 * @return int Number of slugs written or removed.
 */
function set_slugs( $submitted ) {
	$all   = get_option( SLUGS_OPTION, array() );
	$all   = is_array( $all ) ? $all : array();
	$count = 0;
	foreach ( settings()['languages'] as $locale => $language ) {
		$items = $submitted[ $locale ] ?? array();
		foreach ( is_array( $items ) ? $items : array() as $base => $slug ) {
			$base = sanitize_title( (string) $base );
			$slug = sanitize_title( is_string( $slug ) ? $slug : '' );
			if ( '' === $base ) {
				continue;
			}
			if ( '' === $slug || $slug === $base ) {
				unset( $all[ $locale ][ $base ] );
			} else {
				$all[ $locale ][ $base ] = $slug;
			}
			++$count;
		}
		if ( empty( $all[ $locale ] ) ) {
			unset( $all[ $locale ] );
		}
	}
	update_option( SLUGS_OPTION, $all );
	return $count;
}

/** Save the translated slugs from the Translations screen. */
function save_slugs() {
	if ( ! can_translate() ) {
		wp_die( esc_html__( 'You are not allowed to edit translations.', 'langsail' ), 403 );
	}
	check_admin_referer( 'langsail_slugs' );
	$submitted = isset( $_POST['slug'] ) && is_array( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by set_slugs().
	set_slugs( $submitted );
	$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : '';
	wp_safe_redirect( add_query_arg( 'saved', '1', $return ? $return : admin_url( 'admin.php?page=langsail' ) ) );
	exit;
}

/**
 * The translated addresses form on the Translations screen.
 *
 * @param array $languages Translation languages.
 */
function slugs_form( array $languages ) {
	$slugs = page_slugs();
	if ( ! $slugs ) {
		return;
	}
	?>
	<details class="langsail-transfer">
		<summary><?php esc_html_e( 'Translated addresses', 'langsail' ); ?></summary>
		<p class="description"><?php esc_html_e( 'Optional: a word of an address in each language, like "chi-siamo" for "about". Leave empty to keep the original word. Links, the language switcher and the sitemap follow automatically.', 'langsail' ); ?></p>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="langsail_slugs">
			<input type="hidden" name="return" value="<?php echo esc_attr( remove_query_arg( 'saved', wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Escaped, and redirect is validated on save. ?>">
			<?php wp_nonce_field( 'langsail_slugs' ); ?>
			<table class="widefat striped langsail-slugs">
				<thead><tr>
					<th scope="col"><?php echo flag_img( settings()['base'] ) . ' ' . esc_html( settings()['base_name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_img() escapes. ?></th>
					<?php foreach ( $languages as $locale => $language ) : ?>
						<th scope="col"><?php echo flag_img( $locale ) . ' ' . esc_html( $language['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_img() escapes. ?></th>
					<?php endforeach; ?>
				</tr></thead>
				<tbody>
					<?php foreach ( $slugs as $slug ) : ?>
						<tr>
							<th scope="row"><code><?php echo esc_html( urldecode( $slug ) ); ?></code></th>
							<?php foreach ( $languages as $locale => $language ) : ?>
								<?php $id = 'ls-slug-' . md5( $locale . $slug ); ?>
								<td>
									<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>">
										<?php
										/* translators: 1: address word, 2: language name. */
										echo esc_html( sprintf( __( '"%1$s" in %2$s', 'langsail' ), urldecode( $slug ), $language['name'] ) );
										?>
									</label>
									<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( "slug[{$locale}][{$slug}]" ); ?>" value="<?php echo esc_attr( urldecode( slugs( $locale )[ $slug ] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( urldecode( $slug ) ); ?>" lang="<?php echo esc_attr( hreflang( $locale ) ); ?>">
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Save addresses', 'langsail' ), 'secondary' ); ?>
		</form>
	</details>
	<?php
}
