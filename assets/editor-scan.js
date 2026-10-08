/* After a successful save in the editor, scan the saved page (or, for templates, parts, menus and
   patterns, every page) in the background so its new texts appear in the translation table. */
( function ( wp, config ) {
	if ( ! wp || ! wp.data || ! config ) {
		return;
	}
	const shared = [
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'wp_block',
		'wp_global_styles',
	];
	const scan = async ( urls ) => {
		for ( const address of urls ) {
			const url = new URL( address );
			url.searchParams.set( config.arg, config.nonce );
			try {
				await window.fetch( url, { credentials: 'same-origin' } );
			} catch {}
		}
	};
	let saving = false;
	wp.data.subscribe( () => {
		const editor = wp.data.select( 'core/editor' );
		if ( ! editor ) {
			return;
		}
		const now = editor.isSavingPost() && ! editor.isAutosavingPost();
		if ( saving && ! now && editor.didPostSaveRequestSucceed() ) {
			const type = editor.getCurrentPostType();
			const link = editor.getPermalink && editor.getPermalink();
			const published =
				'publish' === editor.getEditedPostAttribute( 'status' );
			if ( shared.includes( type ) ) {
				scan( config.urls );
			} else if ( link && published ) {
				scan( [ link ] );
			}
		}
		saving = now;
	} );
} )( window.wp, window.langsailScan );
