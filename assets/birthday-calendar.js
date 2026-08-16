wp.blocks.registerBlockType(
	'familypedia/birthday-calendar',
	{
		title: wp.i18n.__( 'Birthday Calendar', 'familypedia' ),
		edit: function () {
			return wp.element.createElement(
				wp.serverSideRender,
				{
					block: 'familypedia/birthday-calendar'
				}
			);
		}
	}
);
