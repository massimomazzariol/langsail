<?php
/**
 * Admin: the LangSail menu and the settings screen (base language and translation languages).
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', __NAMESPACE__ . '\\admin_menu' );
add_action( 'admin_post_langsail_settings', __NAMESPACE__ . '\\save_settings' );
add_action( 'admin_notices', __NAMESPACE__ . '\\confirm_notice' );

/** LangSail menu: Translations (the table) and Settings. */
function admin_menu() {
	add_menu_page( __( 'LangSail', 'langsail' ), __( 'LangSail', 'langsail' ), 'manage_options', 'langsail', __NAMESPACE__ . '\\strings_page', 'dashicons-translation', 81 );
	add_submenu_page( 'langsail', __( 'Translations', 'langsail' ), __( 'Translations', 'langsail' ), 'manage_options', 'langsail', __NAMESPACE__ . '\\strings_page' );
	add_submenu_page( 'langsail', __( 'LangSail settings', 'langsail' ), __( 'Settings', 'langsail' ), 'manage_options', 'langsail-settings', __NAMESPACE__ . '\\settings_page' );
}

/** Until the base language is confirmed, nothing is translated: say so on every admin screen. */
function confirm_notice() {
	if ( settings()['confirmed'] || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
		esc_html__( 'LangSail: confirm the base language of the site before adding translations.', 'langsail' ),
		esc_url( admin_url( 'admin.php?page=langsail-settings' ) ),
		esc_html__( 'Open the settings', 'langsail' )
	);
}

/**
 * One <option> per locale, common ones in their own group.
 *
 * @param array    $available Locales from available_languages().
 * @param string[] $selected  Selected locales.
 */
function locale_options( array $available, array $selected ) {
	$groups = array(
		__( 'Common languages', 'langsail' ) => array_filter( $available, fn( $info ) => $info['common'] ),
		__( 'All languages', 'langsail' )    => $available,
	);
	foreach ( $groups as $label => $locales ) {
		printf( '<optgroup label="%s">', esc_attr( $label ) );
		foreach ( $locales as $locale => $info ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $locale ), selected( in_array( $locale, $selected, true ), true, false ), esc_html( $info['native_name'] . ' - ' . $info['english_name'] . ' (' . $locale . ')' ) );
		}
		echo '</optgroup>';
	}
}

/** Settings screen. */
function settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings  = settings();
	$available = available_languages();
	$has_texts = (bool) pages();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'LangSail settings', 'langsail' ); ?></h1>
		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only. ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved. Language packs for the new languages were installed where available.', 'langsail' ); ?></p></div>
		<?php endif; ?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="langsail_settings">
			<?php wp_nonce_field( 'langsail_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="langsail-base"><?php esc_html_e( 'Base language', 'langsail' ); ?></label></th>
					<td>
						<select id="langsail-base" name="base" aria-describedby="langsail-base-help">
							<?php locale_options( $available, array( $settings['base'] ) ); ?>
						</select>
						<p class="description" id="langsail-base-help"><?php esc_html_e( 'The language the site is written in. Every page is built once in this language; the others are translations of its texts.', 'langsail' ); ?></p>
						<?php if ( $settings['confirmed'] && $has_texts ) : ?>
							<p><label><input type="checkbox" name="change_base" value="1"> <?php esc_html_e( 'I am changing the base language: existing translations will no longer match the texts.', 'langsail' ); ?></label></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="langsail-languages"><?php esc_html_e( 'Translation languages', 'langsail' ); ?></label></th>
					<td>
						<select id="langsail-languages" name="languages[]" multiple size="14" aria-describedby="langsail-languages-help">
							<?php locale_options( $available, array_keys( $settings['languages'] ) ); ?>
						</select>
						<p class="description" id="langsail-languages-help"><?php esc_html_e( 'Hold Ctrl (Cmd on a Mac) to select more than one. Each language gets its own address prefix and the WordPress, theme and plugin translations are downloaded.', 'langsail' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="langsail-threshold"><?php esc_html_e( 'Indexing', 'langsail' ); ?></label></th>
					<td>
						<input type="number" id="langsail-threshold" name="threshold" min="0" max="100" step="5" value="<?php echo esc_attr( (string) $settings['threshold'] ); ?>" class="small-text" aria-describedby="langsail-threshold-help"> %
						<p class="description" id="langsail-threshold-help"><?php esc_html_e( 'A language version of a page is offered to search engines (hreflang, sitemap) only when at least this share of its texts is translated; until then it is marked noindex.', 'langsail' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="langsail-keep"><?php esc_html_e( 'Never translate', 'langsail' ); ?></label></th>
					<td>
						<textarea id="langsail-keep" name="keep" rows="5" class="large-text" aria-describedby="langsail-keep-help"><?php echo esc_textarea( implode( "\n", $settings['keep'] ) ); ?></textarea>
						<p class="description" id="langsail-keep-help"><?php esc_html_e( 'One text per line: brand names, codes, addresses. A text exactly like one of these stays as it is in every language and is not listed in the translation table.', 'langsail' ); ?></p>
					</td>
				</tr>
				<?php if ( $settings['languages'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Addresses', 'langsail' ); ?></th>
						<td><ul>
							<li><?php echo flag_img( $settings['base'] ) . ' ' . esc_html( $settings['base_name'] . ': ' . home_url( '/' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_img() escapes. ?></li>
							<?php foreach ( $settings['languages'] as $language ) : ?>
								<li><?php echo flag_img( $language['locale'] ) . ' ' . esc_html( $language['name'] . ': ' . home_url( '/' . $language['prefix'] . '/' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_img() escapes. ?></li>
							<?php endforeach; ?>
						</ul></td>
					</tr>
				<?php endif; ?>
			</table>
			<?php submit_button( $settings['confirmed'] ? __( 'Save settings', 'langsail' ) : __( 'Confirm and save', 'langsail' ) ); ?>
		</form>
	</div>
	<?php
}

/** Save the settings, then install the language packs of every site language. */
function save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to change these settings.', 'langsail' ), 403 );
	}
	check_admin_referer( 'langsail_settings' );
	$current   = settings();
	$available = available_languages();
	$base      = sanitize_locale( wp_unslash( $_POST['base'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_locale() whitelists the format.
	if ( '' === $base || ! isset( $available[ $base ] ) || ( $current['confirmed'] && $base !== $current['base'] && pages() && empty( $_POST['change_base'] ) ) ) {
		$base = $current['base'];
	}
	$languages = array();
	foreach ( (array) wp_unslash( $_POST['languages'] ?? array() ) as $locale ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value whitelisted below.
		$locale = sanitize_locale( $locale );
		if ( isset( $available[ $locale ] ) ) {
			$languages[] = $locale;
		}
	}
	$names = array();
	foreach ( array_merge( array( $base ), $languages ) as $locale ) {
		$names[ $locale ] = $available[ $locale ]['native_name'];
	}
	$settings = normalize_settings(
		array(
			'base'      => $base,
			'confirmed' => true,
			'threshold' => isset( $_POST['threshold'] ) ? absint( $_POST['threshold'] ) : $current['threshold'],
			'keep'      => isset( $_POST['keep'] ) ? preg_split( '/\R/', wp_unslash( $_POST['keep'] ) ) : $current['keep'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each line sanitized in normalize_settings().
			'languages' => $languages,
			'names'     => $names,
		)
	);
	update_option( OPTION, stored_settings( $settings ) );
	settings( true );
	install_language_packs( locales() );
	wp_safe_redirect( admin_url( 'admin.php?page=langsail-settings&updated=1' ) );
	exit;
}

/**
 * Download WordPress core translations for the locales, then the theme and plugin translations
 * WordPress.org has for every installed language.
 *
 * @param string[] $locales Locales.
 */
function install_language_packs( array $locales ) {
	if ( ! current_user_can( 'install_languages' ) || ! wp_can_install_language_pack() ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/translation-install.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	foreach ( $locales as $locale ) {
		if ( 'en_US' !== $locale ) {
			wp_download_language_pack( $locale );
		}
	}
	wp_clean_update_cache();
	wp_update_plugins();
	wp_update_themes();
	$updates = wp_get_translation_updates();
	if ( $updates ) {
		$upgrader = new \Language_Pack_Upgrader( new \Automatic_Upgrader_Skin() );
		$upgrader->bulk_upgrade( $updates );
	}
}
