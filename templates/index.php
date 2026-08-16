<?php
/**
 * The app's home page.
 *
 * @package Familypedia
 */

namespace Familypedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$familypedia_page_title = __( 'Familypedia', 'familypedia' );
require __DIR__ . '/partials/header.php';

$familypedia_recent = Person::get_all(
	array(
		'posts_per_page' => 10,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	)
);
$familypedia_total  = count( Person::get_all( array( 'fields' => 'ids' ) ) );
?>

<h1 class="screen-reader-text"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>

<?php
// Built from escaped parts.
echo Highlights::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>

<section class="familypedia-recent">
	<h2><?php esc_html_e( 'Recently updated', 'familypedia' ); ?></h2>
	<?php if ( empty( $familypedia_recent ) ) : ?>
		<p><?php esc_html_e( 'This wiki has no people yet.', 'familypedia' ); ?></p>
		<?php if ( Editor::can_create() ) : ?>
			<p><a class="familypedia-button" href="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/new/' ) ); ?>"><?php esc_html_e( 'Add the first person', 'familypedia' ); ?></a></p>
		<?php endif; ?>
	<?php else : ?>
		<ul class="familypedia-person-list">
			<?php foreach ( $familypedia_recent as $familypedia_item ) : ?>
				<li>
					<a href="<?php echo esc_url( Person::url( $familypedia_item ) ); ?>"><?php echo esc_html( get_the_title( $familypedia_item ) ); ?></a>
					<span class="familypedia-person-list__meta"><?php echo esc_html( get_the_modified_date( '', $familypedia_item ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<p>
			<a href="<?php echo esc_url( home_url( '/' . App::URL_PATH . '/people/' ) ); ?>">
				<?php
				echo esc_html(
					sprintf(
						// translators: %d is a number of people.
						_n( 'All %d person', 'All %d people', $familypedia_total, 'familypedia' ),
						$familypedia_total
					)
				);
				?>
			</a>
		</p>
	<?php endif; ?>
</section>

<?php
require __DIR__ . '/partials/footer.php';
