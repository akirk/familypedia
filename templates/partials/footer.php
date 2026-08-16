<?php
/**
 * Closing markup for every app page.
 *
 * @package Familypedia
 */

namespace Familypedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
		</main>

		<footer class="familypedia-footer">
			<nav aria-label="<?php esc_attr_e( 'Familypedia', 'familypedia' ); ?>">
				<a href="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/people/' ) ); ?>"><?php esc_html_e( 'People', 'familypedia' ); ?></a>
				<?php if ( Calendar::is_calendar_enabled() ) : ?>
					<a href="<?php echo esc_url( Calendar::get_calendar_url() ); ?>"><?php esc_html_e( 'Calendar', 'familypedia' ); ?></a>
				<?php endif; ?>
				<?php if ( Calendar::is_birthdays_enabled() ) : ?>
					<a href="<?php echo esc_url( Calendar::get_birthdays_url() ); ?>"><?php esc_html_e( 'Birthdays', 'familypedia' ); ?></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/tree/' ) ); ?>"><?php esc_html_e( 'Tree', 'familypedia' ); ?></a>
				<?php if ( Editor::can_create() ) : ?>
					<a href="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/new/' ) ); ?>"><?php esc_html_e( 'Add Person', 'familypedia' ); ?></a>
				<?php endif; ?>
			</nav>
		</footer>
	</div>

	<?php wp_app_body_close(); ?>
</body>
</html>
