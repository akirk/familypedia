wp.blocks.registerBlockType(
	'familypedia/family-calendar',
	{
		title: wp.i18n.__( 'Family Calendar', 'familypedia' ),
		edit: function () {
			return wp.element.createElement(
				wp.serverSideRender,
				{
					block: 'familypedia/family-calendar'
				}
			);
		}
	}
);
