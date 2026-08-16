<?php
/**
 * Moving people in and out of the wiki as GEDCOM.
 *
 * @package Familypedia
 */

namespace Familypedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$familypedia_page_title = __( 'Import / Export', 'familypedia' );

require __DIR__ . '/partials/header.php';
?>

<h1><?php echo esc_html( $familypedia_page_title ); ?></h1>

<?php if ( ! Gedcom::can_use() ) : ?>
	<p><?php esc_html_e( 'Sorry, you are not allowed to import or export GEDCOM data.', 'familypedia' ); ?></p>
<?php else : ?>
	<?php Gedcom::render(); ?>
<?php endif; ?>

<?php
require __DIR__ . '/partials/footer.php';
