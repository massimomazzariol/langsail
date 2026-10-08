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
add_action( 'admin_post_langsail_cleanup', __NAMESPACE__ . '\\cleanup_unused' );
add_action( 'wp_ajax_langsail_prune', __NAMESPACE__ . '\\ajax_prune' );
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\editor_scan_assets' );
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
				'prune' => admin_url( 'admin-ajax.php?action=langsail_prune' ),
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

/** Scan after saving in the block and site editors, so new texts reach the table without a click. */
function editor_scan_assets() {
	if ( ! can_translate() || ! settings()['languages'] ) {
		return;
	}
	wp_enqueue_script( 'langsail-editor-scan', plugins_url( 'assets/editor-scan.js', FILE ), array( 'wp-data' ), VERSION, array( 'in_footer' => true ) );
	wp_add_inline_script(
		'langsail-editor-scan',
		'window.langsailScan = ' . wp_json_encode(
			array(
				'urls'  => scan_urls(),
				'arg'   => SCAN_ARG,
				'nonce' => wp_create_nonce( 'langsail_scan' ),
			)
		) . ';',
		'before'
	);
}
/**
 * Current filters from the query string.
 *
 * @return array{page: string, status: string, language: string, search: string, paged: int}
 */
function table_filters() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
	return array(
		'page'   => isset( $_GET['ls_page'] ) ? sanitize_text_field( wp_unslash( $_GET['ls_page'] ) ) : '',
		'status'   => isset( $_GET['ls_status'] ) && in_array( $_GET['ls_status'], array( 'missing', 'review' ), true ) ? sanitize_key( $_GET['ls_status'] ) : '',
		'language' => isset( $_GET['ls_language'] ) && isset( settings()['languages'][ $_GET['ls_language'] ] ) ? sanitize_text_field( wp_unslash( $_GET['ls_language'] ) ) : '',
		'search' => isset( $_GET['ls_search'] ) ? sanitize_text_field( wp_unslash( $_GET['ls_search'] ) ) : '',
		'paged'  => max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ),
	);
	// phpcs:enable
}

/** The Translations screen. */
function strings_page() {
	if ( ! can_translate() ) {
		return;
	}
	$settings  = settings();
	$languages = $settings['languages'];
	$filters   = table_filters();
	$columns   = '' === $filters['language'] ? $languages : array( $filters['language'] => $languages[ $filters['language'] ] );
	$result    = query_strings( $filters + array( 'locales' => array_keys( $columns ), 'per_page' => PER_PAGE ) );
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
				<label for="langsail-import-locale"><?php esc_html_e( 'Language of a .po file or a map', 'langsail' ); ?></label>
				<select id="langsail-import-locale" name="locale">
					<?php foreach ( $languages as $locale => $language ) : ?>
						<option value="<?php echo esc_attr( $locale ); ?>"><?php echo esc_html( $language['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Import', 'langsail' ), 'secondary', '', false ); ?>
			</form>
			<p class="description"><?php esc_html_e( 'JSON carries every text and language, for moving translations between sites (local to live). PO holds one language for Poedit or a translator; fuzzy entries are imported as "to review".', 'langsail' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="langsail_cleanup">
				<?php wp_nonce_field( 'langsail_cleanup' ); ?>
				<?php submit_button( __( 'Remove texts no longer on any page', 'langsail' ), 'secondary', '', false ); ?>
				<span class="description"><?php esc_html_e( 'Scan the site first: texts removed from every page are deleted with their translations.', 'langsail' ); ?></span>
			</form>
		</details>

		<?php slugs_form( $languages ); ?>

		<?php pages_overview( $languages, $filters ); ?>

		<form method="get" class="langsail-filters" id="langsail-texts">
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
				<option value="review" <?php selected( $filters['status'], 'review' ); ?>><?php esc_html_e( 'To review', 'langsail' ); ?></option>
			</select>
			<label for="langsail-filter-language"><?php esc_html_e( 'Language', 'langsail' ); ?></label>
			<select id="langsail-filter-language" name="ls_language">
				<option value=""><?php esc_html_e( 'All languages', 'langsail' ); ?></option>
				<?php foreach ( $languages as $locale => $language ) : ?>
					<option value="<?php echo esc_attr( $locale ); ?>" <?php selected( $filters['language'], $locale ); ?>><?php echo esc_html( $language['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="langsail-filter-search"><?php esc_html_e( 'Search', 'langsail' ); ?></label>
			<input type="search" id="langsail-filter-search" name="ls_search" value="<?php echo esc_attr( $filters['search'] ); ?>">
			<?php submit_button( __( 'Filter', 'langsail' ), 'secondary', '', false ); ?>
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
						<?php foreach ( $columns as $language ) : ?>
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
								<?php foreach ( $columns as $locale => $language ) : ?>
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
	if ( ! can_translate() ) {
		wp_die( esc_html__( 'You are not allowed to edit translations.', 'langsail' ), 403 );
	}
	check_admin_referer( 'langsail_translations' );
	$languages = settings()['languages'];
	$submitted = isset( $_POST['tr'] ) && is_array( $_POST['tr'] ) ? wp_unslash( $_POST['tr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Rebuilt from markers or escaped by rebuild_translations().
	$errors    = array();
	foreach ( $submitted as $locale => $texts ) {
		if ( ! isset( $languages[ $locale ] ) || ! is_array( $texts ) ) {
			continue;
		}
		$result = rebuild_translations( $texts );
		foreach ( $result['errors'] as $error ) {
			$errors[] = sprintf( '%s (%s)', $error, $languages[ $locale ]['name'] );
		}
		save_translations( $locale, $result['clean'] );
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
	if ( ! can_translate() ) {
		wp_die( esc_html__( 'You are not allowed to export translations.', 'langsail' ), 403 );
	}
	check_admin_referer( 'langsail_export' );
	$host = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$date = gmdate( 'Y-m-d' );
	if ( 'po' === sanitize_key( wp_unslash( $_GET['format'] ?? '' ) ) ) {
		$locale = sanitize_locale( wp_unslash( $_GET['locale'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_locale().
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
	if ( ! can_translate() ) {
		wp_die( esc_html__( 'You are not allowed to import translations.', 'langsail' ), 403 );
	}
	check_admin_referer( 'langsail_import' );
	$file    = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only read from its temporary path.
	$name    = is_array( $file ) ? strtolower( (string) ( $file['name'] ?? '' ) ) : '';
	$content = is_array( $file ) && UPLOAD_ERR_OK === ( $file['error'] ?? -1 ) && is_uploaded_file( $file['tmp_name'] ) ? file_get_contents( $file['tmp_name'] ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Uploaded file.
	$result  = false === $content ? new \WP_Error( 'langsail_import', __( 'The file could not be read.', 'langsail' ) )
		: import_file( $name, $content, sanitize_locale( wp_unslash( $_POST['locale'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Whitelisted format.
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

/** Delete the texts no page uses any more. */
function cleanup_unused() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to change translations.', 'langsail' ), 403 );
	}
	check_admin_referer( 'langsail_cleanup' );
	$removed = remove_unused();
	/* translators: %d: number of texts. */
	set_transient( 'langsail_imported_' . get_current_user_id(), sprintf( _n( '%d unused text removed.', '%d unused texts removed.', $removed, 'langsail' ), $removed ), HOUR_IN_SECONDS );
	wp_safe_redirect( admin_url( 'admin.php?page=langsail' ) );
	exit;
}

/**
 * A readable name for a page key: the post title, or the path.
 *
 * @param string $page Page key.
 */
function page_label( $page ) {
	$labels = array(
		'/'        => __( 'Home page', 'langsail' ),
		'(404)'    => __( 'Page not found (404)', 'langsail' ),
		'(search)' => __( 'Search results', 'langsail' ),
	);
	if ( isset( $labels[ $page ] ) ) {
		return $labels[ $page ];
	}
	$id = url_to_postid( home_url( $page ) );
	return $id ? get_the_title( $id ) : $page;
}

/**
 * Where a page key can be opened in a language, or '' for pages without an address of their own.
 *
 * @param string $page   Page key.
 * @param string $locale Locale.
 */
function page_url( $page, $locale ) {
	if ( '(search)' === $page ) {
		return localize_url( home_url( '/?s=' ), $locale );
	}
	return '/' === substr( $page, 0, 1 ) ? localize_url( home_url( $page ), $locale ) : '';
}

/**
 * Every scanned page with what is missing in each language (a link to exactly those texts) and a
 * link to see the page in that language. With a page chosen, only that page.
 *
 * @param array $languages Translation languages.
 * @param array $filters   Table filters.
 */
function pages_overview( array $languages, array $filters ) {
	$pages = pages();
	if ( '' !== $filters['page'] ) {
		$pages = array_intersect_key( $pages, array( $filters['page'] => true ) );
	}
	if ( ! $pages ) {
		return;
	}
	$base = admin_url( 'admin.php?page=langsail' );
	?>
	<details class="langsail-pages" open>
		<summary><?php esc_html_e( 'Pages', 'langsail' ); ?></summary>
		<table class="widefat striped langsail-pages-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Page', 'langsail' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Texts', 'langsail' ); ?></th>
				<?php foreach ( $languages as $locale => $language ) : ?>
					<th scope="col"><?php echo flag_img( $locale ) . ' ' . esc_html( $language['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_img() escapes. ?></th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
				<?php foreach ( $pages as $page => $count ) : ?>
					<?php $done = page_progress( $page )['done']; ?>
					<tr>
						<th scope="row">
							<a class="langsail-page-name" href="<?php echo esc_url( add_query_arg( 'ls_page', rawurlencode( $page ), $base ) . '#langsail-texts' ); ?>"><?php echo esc_html( page_label( $page ) ); ?></a>
							<code><?php echo esc_html( $page ); ?></code>
						</th>
						<td><?php echo esc_html( (string) $count ); ?></td>
						<?php foreach ( $languages as $locale => $language ) : ?>
							<?php
							$missing = $count - ( $done[ $locale ] ?? 0 );
							$url     = page_url( $page, $locale );
							?>
							<td class="<?php echo esc_attr( $missing > 0 ? 'is-missing' : 'is-done' ); ?>">
								<?php if ( $missing > 0 ) : ?>
									<a href="<?php echo esc_url( add_query_arg( array( 'ls_page' => rawurlencode( $page ), 'ls_language' => rawurlencode( $locale ), 'ls_status' => 'missing' ), $base ) . '#langsail-texts' ); ?>">
										<?php
										/* translators: %d: number of texts without a translation. */
										echo esc_html( sprintf( _n( '%d missing', '%d missing', $missing, 'langsail' ), $missing ) );
										?>
									</a>
								<?php else : ?>
									<span><?php esc_html_e( 'Translated', 'langsail' ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $url ) : ?>
									<a class="langsail-open" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
										<?php
										/* translators: %s: language name. */
										echo esc_html( sprintf( __( 'View in %s', 'langsail' ), $language['name'] ) );
										?>
									</a>
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( '' !== $filters['page'] ) : ?>
			<p><a href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'All pages', 'langsail' ); ?></a></p>
		<?php endif; ?>
	</details>
	<?php
}

/** After a complete scan from the Translations screen: forget the pages it did not find. */
function ajax_prune() {
	if ( ! can_translate() ) {
		wp_send_json_error( null, 403 );
	}
	check_ajax_referer( 'langsail_scan' );
	$keys = isset( $_POST['pages'] ) && is_array( $_POST['pages'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['pages'] ) ) : array();
	wp_send_json_success( prune_pages( $keys ) );
}
