<?php
/**
 * The lightweight form for an additional page under a person: a title and
 * its parent only. Like everywhere else in the app, the page's own text is
 * written in the block editor, not here.
 *
 * @package Familypedia
 */

namespace Familypedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$familypedia_parent_slug = wp_app_get_route_var( 'person', '' );
$familypedia_parent      = Person::get_by_path( $familypedia_parent_slug );

if ( ! $familypedia_parent ) {
	require __DIR__ . '/404.php';
	return;
}

$familypedia_related_slug = wp_app_get_route_var( 'related', '' );
$familypedia_target       = $familypedia_related_slug ? Person::get_by_path( $familypedia_parent_slug . '/' . $familypedia_related_slug ) : null;
$familypedia_id           = $familypedia_target ? (int) $familypedia_target->ID : 0;

if ( $familypedia_id ? ! Editor::can_edit( $familypedia_id ) : ! Related_Page::can_create( $familypedia_parent->ID ) ) {
	status_header( 403 );
	$familypedia_page_title = __( 'Not allowed', 'familypedia' );
	require __DIR__ . '/partials/header.php';
	?>
	<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>
	<p><?php esc_html_e( 'You do not have permission to edit this wiki.', 'familypedia' ); ?></p>
	<?php
	require __DIR__ . '/partials/footer.php';
	return;
}

$familypedia_title = $familypedia_id ? get_the_title( $familypedia_id ) : '';

$familypedia_page_title = $familypedia_id
	? sprintf(
		// translators: %s is a page's title.
		__( 'Edit %s', 'familypedia' ),
		$familypedia_title
	)
	: sprintf(
		// translators: %s is a person's name.
		__( 'Add a page under %s', 'familypedia' ),
		get_the_title( $familypedia_parent )
	);

require __DIR__ . '/partials/header.php';
?>

<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>

<form class="familypedia-form" method="post" action="<?php echo esc_url( $familypedia_id ? Person::edit_url( $familypedia_id ) : Related_Page::add_url( $familypedia_parent ) ); ?>">
	<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( Related_Page::ACTION ); ?>" />
	<input type="hidden" name="related_id" value="<?php echo esc_attr( $familypedia_id ); ?>" />
	<input type="hidden" name="parent_id" value="<?php echo esc_attr( $familypedia_parent->ID ); ?>" />
	<?php wp_nonce_field( Related_Page::ACTION . '_' . $familypedia_id . '_' . $familypedia_parent->ID ); ?>

	<p class="familypedia-field">
		<label for="familypedia-post-title"><?php esc_html_e( 'Title', 'familypedia' ); ?></label>
		<input id="familypedia-post-title" type="text" name="post_title" value="<?php echo esc_attr( $familypedia_title ); ?>" required />
	</p>

	<p class="familypedia-form__actions">
		<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Save', 'familypedia' ); ?></button>
		<?php if ( $familypedia_id ) : ?>
			<a href="<?php echo esc_url( Person::url( $familypedia_id ) ); ?>"><?php esc_html_e( 'Cancel', 'familypedia' ); ?></a>
			<a href="<?php echo esc_url( get_edit_post_link( $familypedia_id, '' ) ); ?>"><?php esc_html_e( 'Edit the text in the block editor', 'familypedia' ); ?></a>
		<?php else : ?>
			<a href="<?php echo esc_url( Person::url( $familypedia_parent ) ); ?>"><?php esc_html_e( 'Cancel', 'familypedia' ); ?></a>
		<?php endif; ?>
	</p>
</form>

<?php
require __DIR__ . '/partials/footer.php';
