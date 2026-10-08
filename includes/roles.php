<?php
/**
 * Permissions: the langsail_translate capability (the translation table, imports, exports, scans, the
 * AI abilities) and a Translator role that has only that. Settings stay with administrators.
 *
 * @package LangSail
 */

namespace LangSail;

defined( 'ABSPATH' ) || exit;

const CAP_TRANSLATE = 'langsail_translate';
const TRANSLATOR    = 'langsail_translator';

add_action( 'admin_init', __NAMESPACE__ . '\\ensure_roles' );

/** Give administrators the capability and create the Translator role, once per version. */
function ensure_roles() {
	if ( VERSION === get_option( 'langsail_roles_version' ) ) {
		return;
	}
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		$admin->add_cap( CAP_TRANSLATE );
	}
	if ( ! get_role( TRANSLATOR ) ) {
		add_role(
			TRANSLATOR,
			__( 'Translator', 'langsail' ),
			array(
				'read'        => true,
				CAP_TRANSLATE => true,
			)
		);
	}
	update_option( 'langsail_roles_version', VERSION );
}

/** Whether the current user may translate. */
function can_translate() {
	return current_user_can( CAP_TRANSLATE ) || current_user_can( 'manage_options' );
}

