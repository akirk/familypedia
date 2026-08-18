<?php
/**
 * A second, independent export/import alongside GEDCOM: the text written on
 * a person's page, which GEDCOM has no room for. Downloaded and uploaded the
 * same way as a GEDCOM file, and matched onto people a GEDCOM import already
 * put on the wiki — by the same xref where one is available, otherwise by an
 * unambiguous exact name match. It only ever updates a person that already
 * exists; it never creates one.
 *
 * @package Familypedia
 */

namespace Familypedia;

class Content_Export {
	const EXPORT_ACTION = 'familypedia_content_export';
	const IMPORT_ACTION = 'familypedia_content_import';

	/**
	 * The meta key this format uses to match a person, independent of
	 * either plugin's own GEDCOM xref meta key: its value is whatever xref
	 * the paired GEDCOM export assigned that person in the same request.
	 */
	const META_KEY = '_gedcom_xref';

	private $gedcom;

	public function __construct( Gedcom $gedcom ) {
		$this->gedcom = $gedcom;

		add_action( 'wp_loaded', array( $this, 'maybe_handle' ) );
		add_action( 'familypedia_gedcom_page_after_export', array( $this, 'render_export_button' ) );
		add_action( 'familypedia_gedcom_page_after_import', array( $this, 'render_upload_form' ) );
	}

	public function maybe_handle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every handler below checks its own nonce.
		$action = isset( $_POST['familypedia_action'] ) ? sanitize_key( wp_unslash( $_POST['familypedia_action'] ) ) : '';

		if ( self::EXPORT_ACTION === $action ) {
			$this->export_download();
		} elseif ( self::IMPORT_ACTION === $action ) {
			$this->import_upload();
		}
	}

	/**
	 * Grouped with the GEDCOM download button, not a section of its own.
	 */
	public function render_export_button() {
		?>
		<p><?php esc_html_e( 'The content file carries the page text GEDCOM has no room for.', 'familypedia' ); ?></p>
		<form class="familypedia-download-form" method="post" action="<?php echo esc_url( Gedcom::get_page_url() ); ?>">
			<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( self::EXPORT_ACTION ); ?>" />
			<?php wp_nonce_field( self::EXPORT_ACTION ); ?>
			<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Download Content', 'familypedia' ); ?></button>
			<span class="familypedia-download-check" aria-hidden="true" hidden>&#10003;</span>
		</form>
		<?php
	}

	/**
	 * Grouped with the GEDCOM upload form, not a section of its own.
	 */
	public function render_upload_form() {
		?>
		<p><?php esc_html_e( 'Upload a content file to fill in page text for people already on the wiki. It never creates a person on its own.', 'familypedia' ); ?></p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( Gedcom::get_page_url() ); ?>">
			<?php wp_nonce_field( self::IMPORT_ACTION ); ?>
			<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>" />
			<p>
				<input type="file" name="content" accept=".xml,text/xml" required />
				<span class="familypedia-field__hint">
					<?php
					echo esc_html(
						sprintf(
							// translators: %s is a file size, for example 2 MB.
							__( 'Maximum size: %s', 'familypedia' ),
							size_format( wp_max_upload_size() )
						)
					);
					?>
				</span>
			</p>
			<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Upload content', 'familypedia' ); ?></button>
		</form>
		<?php
	}

	public function export_download() {
		if ( ! Gedcom::can_export() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export content.', 'familypedia' ), 403 );
		}
		check_admin_referer( self::EXPORT_ACTION );

		$filename = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) . '-familypedia-content-' . current_time( 'Ymd-His' ) . '.xml' );

		nocache_headers();
		header( 'Content-Type: text/xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $this->export_string();
		exit;
	}

	public function export_string() {
		$people = $this->gedcom->get_export_people();
		$ids    = $this->gedcom->export_xrefs( $people );

		$lines = array(
			'<?xml version="1.0" encoding="UTF-8" ?>',
			'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">',
			'<channel>',
		);

		foreach ( $people as $person ) {
			$lines[] = '<item>';
			$lines[] = '<title>' . $this->cdata( get_the_title( $person ) ) . '</title>';
			$lines[] = '<content:encoded>' . $this->cdata( $person->post_content ) . '</content:encoded>';
			$lines[] = '<wp:postmeta>';
			$lines[] = '<wp:meta_key>' . $this->cdata( self::META_KEY ) . '</wp:meta_key>';
			$lines[] = '<wp:meta_value>' . $this->cdata( $ids[ $person->ID ] ) . '</wp:meta_value>';
			$lines[] = '</wp:postmeta>';
			$lines[] = '</item>';
		}

		$lines[] = '</channel>';
		$lines[] = '</rss>';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The same CDATA-splitting WordPress core's own WXR export uses, so a
	 * page whose text happens to contain "]]>" can't break the file.
	 */
	private function cdata( $value ) {
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', (string) $value ) . ']]>';
	}

	public function import_upload() {
		if ( ! Gedcom::can_import() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import content.', 'familypedia' ), 403 );
		}
		check_admin_referer( self::IMPORT_ACTION );

		$upload_error = isset( $_FILES['content']['error'] ) ? (int) $_FILES['content']['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $upload_error ) {
			$error = in_array( $upload_error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'file_too_large' : 'upload_failed';
			if ( UPLOAD_ERR_NO_FILE === $upload_error ) {
				$error = 'missing_file';
			}
			$this->fail( $error );
		}

		if ( empty( $_FILES['content']['tmp_name'] ) || ! is_uploaded_file( $_FILES['content']['tmp_name'] ) ) {
			$this->fail( 'missing_file' );
		}

		$contents = file_get_contents( $_FILES['content']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents || '' === trim( $contents ) ) {
			$this->fail( 'empty_file' );
		}

		$result = $this->apply_content( $contents );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_code() );
		}

		Editor::set_notice(
			sprintf(
				// translators: %1$d is a number of updated pages, %2$d a number of skipped entries.
				__( 'Updated %1$d pages. %2$d entries in the file did not match a person on the wiki and were skipped.', 'familypedia' ),
				$result['updated'],
				$result['skipped']
			)
		);
		wp_safe_redirect( Gedcom::get_page_url() );
		exit;
	}

	/**
	 * Leave the reason on the page the form came from and go back to it.
	 *
	 * @param string $error A key of error_message().
	 */
	private function fail( $error ) {
		Editor::set_notice( $this->error_message( $error ), 'error' );
		wp_safe_redirect( Gedcom::get_page_url() );
		exit;
	}

	/**
	 * Apply an uploaded content file to the people it matches. Never
	 * creates a person and never touches anything but post_content.
	 */
	public function apply_content( $contents ) {
		$previous_setting = libxml_use_internal_errors( true );
		$xml               = simplexml_load_string( $contents, 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_setting );

		if ( false === $xml || ! isset( $xml->channel ) ) {
			return new \WP_Error( 'invalid_file', __( 'This does not look like a content file.', 'familypedia' ) );
		}

		$index   = $this->gedcom->existing_page_index();
		$updated = 0;
		$skipped = 0;

		foreach ( $xml->channel->item as $item ) {
			$wp_fields  = $item->children( 'http://wordpress.org/export/1.2/' );
			$content_ns = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
			$title      = trim( (string) $item->title );
			$content    = isset( $content_ns->encoded ) ? (string) $content_ns->encoded : '';

			$post_id = $this->match_post( $wp_fields, $title, $index );
			if ( ! $post_id ) {
				++$skipped;
				continue;
			}

			wp_update_post(
				wp_slash(
					array(
						'ID'           => $post_id,
						'post_content' => $content,
					)
				)
			);
			++$updated;
		}

		return array(
			'updated' => $updated,
			'skipped' => $skipped,
		);
	}

	/**
	 * The person a content entry belongs to: their xref first, an
	 * unambiguous exact title match otherwise. A title shared by more than
	 * one person is left alone rather than guessed at.
	 */
	private function match_post( $wp_fields, $title, $index ) {
		$xref = '';
		foreach ( $wp_fields->postmeta as $meta ) {
			$meta_fields = $meta->children( 'http://wordpress.org/export/1.2/' );
			if ( self::META_KEY === (string) $meta_fields->meta_key ) {
				$xref = (string) $meta_fields->meta_value;
				break;
			}
		}

		if ( $xref && isset( $index['xref'][ $xref ] ) ) {
			return (int) $index['xref'][ $xref ];
		}

		$key = strtolower( $title );
		if ( $key && ! empty( $index['title'][ $key ] ) && 1 === count( $index['title'][ $key ] ) ) {
			return (int) $index['title'][ $key ][0];
		}

		return 0;
	}

	private function error_message( $error ) {
		$messages = array(
			'missing_file'   => __( 'Please choose a content file to import.', 'familypedia' ),
			'file_too_large' => sprintf(
				// translators: %s is a file size, for example 2 MB.
				__( 'The content file is larger than the maximum upload size of %s.', 'familypedia' ),
				size_format( wp_max_upload_size() )
			),
			'upload_failed'  => __( 'The content file could not be uploaded.', 'familypedia' ),
			'empty_file'     => __( 'The uploaded content file was empty.', 'familypedia' ),
			'invalid_file'   => __( 'This does not look like a content file.', 'familypedia' ),
		);

		return isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'The content import failed.', 'familypedia' );
	}
}
