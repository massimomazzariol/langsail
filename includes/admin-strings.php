<?php
/**
 * Admin: the translation table. Every text of the site in the base language, one column per
 * translation language, with page and status filters, search, progress and a site scan.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_langsail_translations', __NAMESPACE__ . '\\save_strings' );
add_action( 'admin_post_langsail_export', __NAMESPACE__ . '\\download_export' );
add_action( 'admin_post_langsail_import', __NAMESPACE__ . '\\upload_import' );
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\strings_assets' );

/** Rows per table page. */
const PER_PAGE = 50;

/**
 * Load the admin styles on the LangSail screens and the scan script on the Translations screen.
 *
 * @param string $hook Admin screen hook.
 */
function strings_assets( $hook ) {
	$base = plugins_url( 'assets/', FILE );
	if ( in_array( $hook, array( 'toplevel_page_langsail', 'langsail_page_langsail-settings' ), true ) ) {
		wp_enqueue_style( 'langsail-admin', $base . 'admin.css', array(), VERSION );
	}
	if ( 'toplevel_page_langsail' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'langsail-admin', $base . 'admin.js', array(), VERSION, array( 'strategy' => 'defer' ) );
	wp_add_inline_script(
		'langsail-admin',
		'window.langsailScan = ' . wp_json_encode(
			array(
				'urls'  => scan_urls(),
				'arg'   => SCAN_ARG,
				'nonce' => wp_create_nonce( 'langsail_scan' ),
				'i18n'  => array(
					/* translators: 1: pages done, 2: pages in total. */
					'progress' => __( 'Scanning page %1$d of %2$d...', 'langsail' ),
					/* translators: 1: pages scanned, 2: new texts found. */
					'done'     => __( 'Scan complete: %1$d pages, %2$d new texts. Reloading...', 'langsail' ),
					/* translators: %s: page address. */
					'failed'   => __( 'Could not scan %s', 'langsail' ),
				),
			)
		) . ';',
		'before'
	);
}

/** Addresses to scan: the home page and every published page and post of a public type. */
function scan_urls() {
	$urls  = array( home_url( '/' ) );
	$types = array_values( get_post_types( array( 'public' => true ) ) );
	$types = array_diff( $types, array( 'attachment' ) );
	$ids   = get_posts(
		array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		)
	);
	foreach ( $ids as $id ) {
		$urls[] = get_permalink( $id );
	}
	return array_values( array_unique( $urls ) );
}

/**
 * Current filters from the query string.
 *
 * @return array{page: string, status: string, search: string, paged: int}
 */
function table_filters() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
	return array(
		'page'   => isset( $_GET['ls_page'] ) ? sanitize_text_field( wp_unslash( $_GET['ls_page'] ) ) : '',
		'status' => isset( $_GET['ls_status'] ) && 'missing' === $_GET['ls_status'] ? 'missing' : '',
		'search' => isset( $_GET['ls_search'] ) ? sanitize_text_field( wp_unslash( $_GET['ls_search'] ) ) : '',
		'paged'  => max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ),
	);
	// phpcs:enable
}

/** The Translations screen. */
function strings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings  = settings();
	$languages = $settings['languages'];
	$filters   = table_filters();
	$result    = query_strings( $filters + array( 'locales' => array_keys( $languages ), 'per_page' => PER_PAGE ) );
	$progress  = progress( array_keys( $languages ) );
	$pages     = pages();
	?>
	<div class="wrap langsail">
		<h1><?php esc_html_e( 'Translations', 'langsail' ); ?></h1>
		<?php if ( ! $languages ) : ?>
			<div class="notice notice-info"><p>
				<?php esc_html_e( 'Choose the translation languages first.', 'langsail' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=langsail-settings' ) ); ?>"><?php esc_html_e( 'Open the settings', 'langsail' ); ?></a>
			</p></div>
			<?php
			echo '</div>';
			return;
		endif;
		?>
		<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only. ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Translations saved.', 'langsail' ); ?></p></div>
		<?php endif; ?>
		<?php $imported = get_transient( 'langsail_imported_' . get_current_user_id() ); ?>
		<?php if ( $imported ) : ?>
			<?php delete_transient( 'langsail_imported_' . get_current_user_id() ); ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $imported ); ?></p></div>
		<?php endif; ?>
		<?php $errors = get_transient( 'langsail_errors_' . get_current_user_id() ); ?>
		<?php if ( $errors ) : ?>
			<?php delete_transient( 'langsail_errors_' . get_current_user_id() ); ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'These translations were not saved:', 'langsail' ); ?></p><ul>
				<?php foreach ( $errors as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul></div>
		<?php endif; ?>

		<div class="langsail-summary">
			<p>
				<?php
				/* translators: %d: number of texts. */
				echo esc_html( sprintf( _n( '%d text on the site.', '%d texts on the site.', $progress['total'], 'langsail' ), $progress['total'] ) );
				?>
			</p>
			<ul class="langsail-progress">
				<?php foreach ( $languages as $locale => $language ) : ?>
					<?php $percent = $progress['total'] ? (int) floor( 100 * $progress['done'][ $locale ] / $progress['total'] ) : 0; ?>
					<li>
						<span><?php echo flag_img( $locale ) . ' ' . esc_html( $language['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_img() escapes. ?></span>
						<progress max="100" value="<?php echo esc_attr( $percent ); ?>" aria-label="<?php echo esc_attr( $language['name'] ); ?>"></progress>
						<span><?php echo esc_html( $percent . '%' ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<button type="button" class="button" id="langsail-scan"><?php esc_html_e( 'Scan the site for new texts', 'langsail' ); ?></button>
				<span id="langsail-scan-status" role="status" aria-live="polite"></span>
			</p>
		</div>

		<details class="langsail-transfer">
			<summary><?php esc_html_e( 'Import and export', 'langsail' ); ?></summary>
			<p>
				<?php esc_html_e( 'Export:', 'langsail' ); ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=langsail_export&format=json' ), 'langsail_export' ) ); ?>"><?php esc_html_e( 'All languages (JSON)', 'langsail' ); ?></a>
				<?php foreach ( $languages as $locale => $language ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=langsail_export&format=po&locale=' . rawurlencode( $locale ) ), 'langsail_export' ) ); ?>"><?php echo flag_img( $locale ) . ' ' . esc_html( $language['name'] ) . ' (PO)'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_img() escapes. ?></a>
				<?php endforeach; ?>
			</p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data" class="langsail-filters">
				<input type="hidden" name="action" value="langsail_import">
				<?php wp_nonce_field( 'langsail_import' ); ?>
				<label for="langsail-import-file"><?php esc_html_e( 'File (.json or .po)', 'langsail' ); ?></label>
				<input type="file" id="langsail-import-file" name="file" accept=".json,.po" required>
				<label for="langsail-import-locale"><?php esc_html_e( 'Language of a .po file', 'langsail' ); ?></label>
				<select id="langsail-import-locale" name="locale">
					<?php foreach ( $languages as $locale => $language ) : ?>
						<option value="<?php echo esc_attr( $locale ); ?>"><?php echo esc_html( $language['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Import', 'langsail' ), 'secondary', '', false ); ?>
			</form>
			<p class="description"><?php esc_html_e( 'JSON carries every text and language, for moving translations between sites (local to live). PO holds one language for Poedit or a translator; fuzzy entries are imported as "to review".', 'langsail' ); ?></p>
		</details>

		<form method="get" class="langsail-filters">
			<input type="hidden" name="page" value="langsail">
			<label for="langsail-filter-page"><?php esc_html_e( 'Page', 'langsail' ); ?></label>
			<select id="langsail-filter-page" name="ls_page">
				<option value=""><?php esc_html_e( 'All pages', 'langsail' ); ?></option>
				<?php foreach ( $pages as $page => $count ) : ?>
					<option value="<?php echo esc_attr( $page ); ?>" <?php selected( $filters['page'], $page ); ?>><?php echo esc_html( $page . ' (' . $count . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="langsail-filter-status"><?php esc_html_e( 'Show', 'langsail' ); ?></label>
			<select id="langsail-filter-status" name="ls_status">
				<option value=""><?php esc_html_e( 'All texts', 'langsail' ); ?></option>
				<option value="missing" <?php selected( $filters['status'], 'missing' ); ?>><?php esc_html_e( 'Missing a translation', 'langsail' ); ?></option>
			</select>
			<label for="langsail-filter-search"><?php esc_html_e( 'Search', 'langsail' ); ?></label>
			<input type="search" id="langsail-filter-search" name="ls_search" value="<?php echo esc_attr( $filters['search'] ); ?>">
			<?php submit_button( __( 'Filter', 'langsail' ), 'secondary', '', false ); ?>
			<?php if ( '' !== $filters['page'] ) : ?>
				<span class="langsail-view"><?php esc_html_e( 'View this page:', 'langsail' ); ?>
					<?php foreach ( $languages as $locale => $language ) : ?>
						<a href="<?php echo esc_url( localize_url( home_url( $filters['page'] ), $locale ) ); ?>" target="_blank" rel="noopener"><?php echo flag_img( $locale ) . ' ' . esc_html( $language['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_img() escapes. ?></a>
					<?php endforeach; ?>
				</span>
			<?php endif; ?>
		</form>

		<?php if ( ! $result['rows'] ) : ?>
			<p><?php esc_html_e( 'No texts here yet. Scan the site to collect them.', 'langsail' ); ?></p>
		<?php else : ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="langsail_translations">
				<input type="hidden" name="return" value="<?php echo esc_attr( remove_query_arg( 'saved', wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Escaped, and redirect is validated on save. ?>">
				<?php wp_nonce_field( 'langsail_translations' ); ?>
				<p class="description"><?php esc_html_e( 'Markers like [1]...[/1] stand for links and formatting, [2/] for a line break: keep them around the matching words. The page shows the base text wherever a translation is empty.', 'langsail' ); ?></p>
				<table class="widefat striped langsail-table">
					<thead><tr>
						<th scope="col"><?php echo flag_img( $settings['base'] ) . ' ' . esc_html( $settings['base_name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_img() escapes. ?></th>
						<?php foreach ( $languages as $language ) : ?>
							<th scope="col"><?php echo flag_img( $language['locale'] ) . ' ' . esc_html( $language['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_img() escapes. ?></th>
						<?php endforeach; ?>
					</tr></thead>
					<tbody>
						<?php foreach ( $result['rows'] as $row ) : ?>
							<tr>
								<th scope="row" class="langsail-source" lang="<?php echo esc_attr( hreflang( $settings['base'] ) ); ?>">
									<span class="langsail-text"><?php echo esc_html( display_text( $row['source'], $row['kind'] ) ); ?></span>
									<?php if ( 'text' !== $row['kind'] ) : ?>
										<span class="langsail-kind"><?php echo esc_html( 'title' === $row['kind'] ? __( 'page title', 'langsail' ) : __( 'attribute', 'langsail' ) ); ?></span>
									<?php endif; ?>
								</th>
								<?php foreach ( $languages as $locale => $language ) : ?>
									<?php $tr = $row['translations'][ $locale ] ?? null; ?>
									<td class="<?php echo esc_attr( $tr ? 'is-' . $tr['status'] : 'is-missing' ); ?>">
										<label class="screen-reader-text" for="<?php echo esc_attr( "ls-{$row['id']}-{$locale}" ); ?>">
											<?php
											/* translators: %s: language name. */
											echo esc_html( sprintf( __( 'Translation in %s', 'langsail' ), $language['name'] ) );
											?>
										</label>
										<textarea id="<?php echo esc_attr( "ls-{$row['id']}-{$locale}" ); ?>" name="<?php echo esc_attr( "tr[{$locale}][{$row['id']}]" ); ?>" lang="<?php echo esc_attr( hreflang( $locale ) ); ?>" dir="auto" rows="<?php echo esc_attr( (string) min( 6, 2 + intdiv( strlen( $row['source'] ), 60 ) ) ); ?>"><?php echo esc_textarea( $tr ? display_text( $tr['text'], $row['kind'], $row['source'] ) : '' ); ?></textarea>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save translations', 'langsail' ) ); ?>
			</form>
			<?php
			$pages_total = (int) ceil( $result['total'] / PER_PAGE );
			if ( $pages_total > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $filters['paged'],
							'total'   => $pages_total,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		<?php endif; ?>
	</div>
	<?php
}

/** Save the submitted translations, rebuilding text units from their markers. */
function save_strings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to edit translations.', 'langsail' ), 403 );
	}
	check_admin_referer( 'langsail_translations' );
	global $wpdb;
	$languages = settings()['languages'];
	$submitted = isset( $_POST['tr'] ) && is_array( $_POST['tr'] ) ? wp_unslash( $_POST['tr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Rebuilt from markers or escaped below.
	$ids       = array();
	foreach ( $submitted as $texts ) {
		$ids = array_merge( $ids, array_map( 'absint', array_keys( (array) $texts ) ) );
	}
	$ids     = array_filter( array_unique( $ids ) );
	$strings = $ids ? array_column( $wpdb->get_results( 'SELECT id, kind, source FROM ' . tables()['strings'] . ' WHERE id IN (' . implode( ',', $ids ) . ')', ARRAY_A ), null, 'id' ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL -- Integers.
	$errors  = array();
	foreach ( $submitted as $locale => $texts ) {
		if ( ! isset( $languages[ $locale ] ) || ! is_array( $texts ) ) {
			continue;
		}
		$clean = array();
		foreach ( $texts as $id => $text ) {
			$string = $strings[ absint( $id ) ] ?? null;
			if ( ! $string || ! is_string( $text ) ) {
				continue;
			}
			$text = trim( $text );
			if ( '' === $text || 'text' !== $string['kind'] ) {
				$clean[ $string['id'] ] = sanitize_text_field( $text ); // Plain text: escaped where it is output.
				continue;
			}
			$html = from_placeholders( $text, $string['source'] );
			if ( is_wp_error( $html ) ) {
				$errors[] = sprintf( '%s (%s): %s', wp_html_excerpt( to_placeholders( $string['source'] )['text'], 60, '...' ), $languages[ $locale ]['name'], $html->get_error_message() );
				continue;
			}
			$clean[ $string['id'] ] = $html;
		}
		save_translations( $locale, $clean );
	}
	if ( $errors ) {
		set_transient( 'langsail_errors_' . get_current_user_id(), $errors, HOUR_IN_SECONDS );
	}
	$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : '';
	wp_safe_redirect( add_query_arg( 'saved', '1', $return ? $return : admin_url( 'admin.php?page=langsail' ) ) );
	exit;
}

/** Send an export file: every language as JSON, or one language as PO. */
function download_export() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to export translations.', 'langsail' ), 403 );
	}
	check_admin_referer( 'langsail_export' );
	$host = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$date = gmdate( 'Y-m-d' );
	if ( 'po' === ( $_GET['format'] ?? '' ) ) {
		$locale = sanitize_locale( wp_unslash( $_GET['locale'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Whitelisted format.
		if ( ! isset( settings()['languages'][ $locale ] ) ) {
			wp_die( esc_html__( 'Unknown language.', 'langsail' ), 400 );
		}
		$name    = "langsail-$host-$locale-$date.po";
		$type    = 'text/x-gettext-translation';
		$content = export_po( $locale );
	} else {
		$name    = "langsail-$host-$date.json";
		$type    = 'application/json';
		$content = wp_json_encode( export_json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
	nocache_headers();
	header( 'Content-Type: ' . $type . '; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $name . '"' );
	echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- File download.
	exit;
}

/** Import an uploaded .json export or .po file. */
function upload_import() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to import translations.', 'langsail' ), 403 );
	}
	check_admin_referer( 'langsail_import' );
	$file    = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only read from its temporary path.
	$name    = is_array( $file ) ? strtolower( (string) ( $file['name'] ?? '' ) ) : '';
	$content = is_array( $file ) && UPLOAD_ERR_OK === ( $file['error'] ?? -1 ) && is_uploaded_file( $file['tmp_name'] ) ? file_get_contents( $file['tmp_name'] ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Uploaded file.
	$result  = false === $content ? new \WP_Error( 'langsail_import', __( 'The file could not be read.', 'langsail' ) )
		: ( str_ends_with( $name, '.po' ) ? import_po( $content, sanitize_locale( wp_unslash( $_POST['locale'] ?? '' ) ) ) : import_json( json_decode( $content, true ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Whitelisted format.
	$user    = get_current_user_id();
	if ( is_wp_error( $result ) ) {
		set_transient( 'langsail_errors_' . $user, array( $result->get_error_message() ), HOUR_IN_SECONDS );
	} else {
		/* translators: %d: number of translations. */
		set_transient( 'langsail_imported_' . $user, sprintf( _n( '%d translation imported.', '%d translations imported.', $result['translations'], 'langsail' ), $result['translations'] ), HOUR_IN_SECONDS );
		if ( ! empty( $result['errors'] ) ) {
			set_transient( 'langsail_errors_' . $user, $result['errors'], HOUR_IN_SECONDS );
		}
	}
	wp_safe_redirect( admin_url( 'admin.php?page=langsail' ) );
	exit;
}
