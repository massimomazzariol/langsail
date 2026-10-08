/* Editor preview of the server-rendered switcher (no build step). */
( function ( wp ) {
	const { createElement: el } = wp.element;
	wp.blocks.registerBlockType( 'langsail/switcher', {
		edit: ( props ) =>
			el(
				'div',
				wp.blockEditor.useBlockProps(),
				el( wp.serverSideRender, {
					block: 'langsail/switcher',
					attributes: props.attributes,
				} )
			),
		save: () => null,
	} );
} )( window.wp );
