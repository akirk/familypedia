( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'familypedia/highlights', {
		title: __( 'Family Highlights', 'familypedia' ),
		description: __( 'The person of the hour, and the dates coming up next.', 'familypedia' ),
		icon: 'star-filled',
		category: 'widgets',

		edit: function () {
			return el( 'div', blockEditor.useBlockProps(),
				el( wp.serverSideRender, {
					block: 'familypedia/highlights'
				} )
			);
		},

		save: function () {
			return null;
		}
	} );

	blocks.registerBlockType( 'familypedia/recent', {
		title: __( 'Recently Updated People', 'familypedia' ),
		description: __( 'The people whose pages changed last.', 'familypedia' ),
		icon: 'backup',
		category: 'widgets',

		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el( 'div', blockEditor.useBlockProps(),
				el( blockEditor.InspectorControls, {},
					el( components.PanelBody, { title: __( 'List', 'familypedia' ) },
						el( components.RangeControl, {
							label: __( 'People to show', 'familypedia' ),
							value: attributes.count,
							onChange: function ( value ) {
								setAttributes( { count: value } );
							},
							min: 1,
							max: 50,
							__nextHasNoMarginBottom: true
						} )
					)
				),
				el( wp.serverSideRender, {
					block: 'familypedia/recent',
					attributes: attributes
				} )
			);
		},

		save: function () {
			return null;
		}
	} );
}( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n ) );
