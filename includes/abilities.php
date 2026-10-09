<?php
/**
 * Abilities API: AI agents and other clients (REST, MCP) can read the languages and the progress,
 * fetch the texts still to translate, save translations and scan the site. Everything runs with
 * the permissions of the user behind the request and nothing is sent anywhere: the agent the site
 * owner connects does the translating.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\register_ability_category' );
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_abilities' );

/** Most texts one list-texts call returns. */
const ABILITY_MAX_TEXTS = 200;

/** Most pages one scan call visits: a web request must end within its time limit. */
const SCAN_BATCH_MAX = 20;

/** Register the LangSail ability category. */
function register_ability_category() {
	wp_register_ability_category(
		'langsail',
		array(
			'label'       => __( 'LangSail', 'langsail' ),
			'description' => __( 'Site translation: languages, texts to translate and translations.', 'langsail' ),
		)
	);
}

/**
 * Ability meta for the MCP Adapter default server.
 *
 * @param bool $readonly Whether the ability only reads.
 */
function ability_meta( $readonly ) {
	return array(
		'public'      => true,
		'mcp'         => array( 'public' => true ),
		'annotations' => array(
			'readonly'    => $readonly,
			'destructive' => false,
			'idempotent'  => true,
		),
	);
}

/** Register the LangSail abilities. */
function register_abilities() {
	$permission = __NAMESPACE__ . '\\can_translate';
	$locale     = array(
		'type'        => 'string',
		'description' => __( 'Locale of a translation language, from langsail/list-languages.', 'langsail' ),
	);

	wp_register_ability(
		'langsail/list-languages',
		array(
			'label'               => __( 'List languages', 'langsail' ),
			'description'         => __( 'Start here. Returns the site owner\'s instructions for translators (tone, audience, fixed terms: follow them in every translation), the base language, the texts that must never be translated, and the translation languages with their locale, URL prefix and progress.', 'langsail' ),
			'category'            => 'langsail',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'instructions'    => array( 'type' => 'string' ),
					'never_translate' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'base'            => array( 'type' => 'object' ),
					'texts'     => array( 'type' => 'integer' ),
					'languages' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\\ability_list_languages',
			'permission_callback' => $permission,
			'meta'                => ability_meta( true ),
		)
	);

	wp_register_ability(
		'langsail/list-texts',
		array(
			'label'               => __( 'List texts to translate', 'langsail' ),
			'description'         => __( 'Lists texts of the site in the base language with their translation in one language. Markers like [1]...[/1] stand for links and formatting and [2/] for a line break: a translation must keep the same markers around the matching words. Kind "attr" and "title" are plain text. Follow the instructions from langsail/list-languages. Translate with langsail/save-translations.', 'langsail' ),
			'category'            => 'langsail',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'locale' => $locale,
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'missing', 'review', 'all' ),
						'default'     => 'missing',
						'description' => __( 'missing: no translation yet; review: translation kept from an older version of the text, to check; all: every text.', 'langsail' ),
					),
					'page'   => array(
						'type'        => 'string',
						'default'     => '',
						'description' => __( 'Only the texts of this page (its path, like /about/). Empty for every page.', 'langsail' ),
					),
					'search' => array(
						'type'    => 'string',
						'default' => '',
					),
					'limit'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => ABILITY_MAX_TEXTS,
						'default' => 50,
					),
					'offset' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
				'required'             => array( 'locale' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'total' => array( 'type' => 'integer' ),
					'texts' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'          => array( 'type' => 'integer' ),
								'kind'        => array( 'type' => 'string' ),
								'source'      => array( 'type' => 'string' ),
								'translation' => array( 'type' => 'string' ),
								'status'      => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\\ability_list_texts',
			'permission_callback' => $permission,
			'meta'                => ability_meta( true ),
		)
	);

	wp_register_ability(
		'langsail/save-translations',
		array(
			'label'               => __( 'Save translations', 'langsail' ),
			'description'         => __( 'Saves translations of texts from langsail/list-texts into one language. Each translation must keep the markers of its source. Translations with wrong markers are not saved and are returned as errors. Use status "review" to ask a person to check them.', 'langsail' ),
			'category'            => 'langsail',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'locale'       => $locale,
					'status'       => array(
						'type'    => 'string',
						'enum'    => STATUSES,
						'default' => 'translated',
					),
					'translations' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => ABILITY_MAX_TEXTS,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'id'          => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'translation' => array( 'type' => 'string' ),
							),
							'required'             => array( 'id', 'translation' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'locale', 'translations' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'saved'  => array( 'type' => 'integer' ),
					'errors' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\\ability_save_translations',
			'permission_callback' => $permission,
			'meta'                => ability_meta( false ),
		)
	);

	wp_register_ability(
		'langsail/scan',
		array(
			'label'               => __( 'Scan the site', 'langsail' ),
			'description'         => __( 'Visits the pages of the site to collect new and changed texts, a batch at a time: start with offset 0 and call again with next_offset until it is null. Run it after content changes, before listing the texts to translate.', 'langsail' ),
			'category'            => 'langsail',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'offset' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'limit'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => SCAN_BATCH_MAX,
						'default' => SCAN_BATCH_MAX,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'pages'       => array( 'type' => 'integer' ),
					'new'         => array( 'type' => 'integer' ),
					'failed'      => array( 'type' => 'array' ),
					'total'       => array( 'type' => 'integer' ),
					'next_offset' => array( 'type' => array( 'integer', 'null' ) ),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\\ability_scan',
			'permission_callback' => $permission,
			'meta'                => ability_meta( false ),
		)
	);
}

/**
 * The translation language behind an ability input, or an error.
 *
 * @param array $input Ability input.
 * @return string|\WP_Error
 */
function ability_locale( $input ) {
	$locale = is_array( $input ) && isset( $input['locale'] ) ? (string) $input['locale'] : '';
	return isset( settings()['languages'][ $locale ] ) ? $locale : new \WP_Error( 'langsail_unknown_locale', __( 'Not a translation language of this site. See langsail/list-languages.', 'langsail' ) );
}

/** Languages with their progress. */
function ability_list_languages() {
	$settings = settings();
	$progress = progress( locales() );
	$out      = array(
		'instructions'    => $settings['brief'],
		'never_translate' => $settings['keep'],
		'base'            => array(
			'locale' => $settings['base'],
			'name'   => $settings['base_name'],
		),
		'texts'           => $progress['total'],
		'languages'       => array(),
	);
	foreach ( $settings['languages'] as $locale => $language ) {
		$out['languages'][] = array(
			'locale'     => $locale,
			'name'       => $language['name'],
			'prefix'     => $language['prefix'],
			'translated' => $progress['done'][ $locale ] ?? 0,
		);
	}
	return $out;
}

/**
 * Texts with their translation in one language, as translators see them.
 *
 * @param array $input Ability input.
 * @return array|\WP_Error
 */
function ability_list_texts( $input ) {
	$locale = ability_locale( $input );
	if ( is_wp_error( $locale ) ) {
		return $locale;
	}
	$limit  = min( ABILITY_MAX_TEXTS, max( 1, (int) ( $input['limit'] ?? 50 ) ) );
	$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
	$status = $input['status'] ?? 'missing';
	$result = query_strings(
		array(
			'page'     => (string) ( $input['page'] ?? '' ),
			'search'   => (string) ( $input['search'] ?? '' ),
			'status'   => 'all' === $status ? '' : $status,
			'locales'  => array( $locale ),
			'per_page' => $limit,
			'offset'   => $offset,
		)
	);
	$texts  = array();
	foreach ( $result['rows'] as $row ) {
		$tr      = $row['translations'][ $locale ] ?? null;
		$texts[] = array(
			'id'          => (int) $row['id'],
			'kind'        => $row['kind'],
			'source'      => display_text( $row['source'], $row['kind'] ),
			'translation' => $tr ? display_text( $tr['text'], $row['kind'], $row['source'] ) : '',
			'status'      => $tr ? $tr['status'] : 'missing',
		);
	}
	return array(
		'total' => $result['total'],
		'texts' => $texts,
	);
}

/**
 * Save translations into one language.
 *
 * @param array $input Ability input.
 * @return array|\WP_Error
 */
function ability_save_translations( $input ) {
	$locale = ability_locale( $input );
	if ( is_wp_error( $locale ) ) {
		return $locale;
	}
	$texts = array();
	foreach ( (array) $input['translations'] as $item ) {
		$texts[ (int) $item['id'] ] = (string) $item['translation'];
	}
	$result = rebuild_translations( $texts );
	$clean  = array_filter( $result['clean'], 'strlen' ); // An empty translation would delete one.
	save_translations( $locale, $clean, $input['status'] ?? 'translated' );
	$errors = array();
	foreach ( $result['errors'] as $id => $message ) {
		$errors[] = array(
			'id'      => $id,
			'message' => $message,
		);
	}
	return array(
		'saved'  => count( $clean ),
		'errors' => $errors,
	);
}

/**
 * Scan a batch of pages.
 *
 * @param array|null $input Ability input.
 */
function ability_scan( $input ) {
	$input = is_array( $input ) ? $input : array();
	return scan_site( (int) ( $input['offset'] ?? 0 ), min( SCAN_BATCH_MAX, max( 1, (int) ( $input['limit'] ?? SCAN_BATCH_MAX ) ) ) );
}
