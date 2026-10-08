/* Editor: settings and a server-rendered preview of the switcher (no build step). */
( function ( wp ) {
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, ToggleControl, SelectControl } = wp.components;

	wp.blocks.registerBlockType( 'langsail/switcher', {
		edit: ( { attributes, setAttributes } ) =>
			el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Display', 'langsail' ) },
						el( ToggleControl, {
							__nextHasNoMarginBottom: true,
							label: __( 'Show flags', 'langsail' ),
							help: __( 'Flags stand for countries, not languages: the name or code is always shown too.', 'langsail' ),
							checked: attributes.showFlags,
							onChange: ( showFlags ) => setAttributes( { showFlags } ),
						} ),
						el( SelectControl, {
							__next40pxDefaultSize: true,
							__nextHasNoMarginBottom: true,
							label: __( 'Text', 'langsail' ),
							value: attributes.label,
							options: [
								{ value: 'name', label: __( 'Language name (Italiano)', 'langsail' ) },
								{ value: 'code', label: __( 'Short code (IT)', 'langsail' ) },
							],
							onChange: ( label ) => setAttributes( { label } ),
						} )
					)
				),
				el(
					'div',
					useBlockProps(),
					el( wp.serverSideRender, { block: 'langsail/switcher', attributes } )
				)
			),
		save: () => null,
	} );
} )( window.wp );
