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
	const APPLY_ACTION = 'familypedia_content_apply';
	const IMPORT_TRANSIENT_PREFIX = 'familypedia_content_import_';
	const RUN_TRANSIENT_PREFIX = 'familypedia_content_run_';

	/**
	 * How much of the file one request takes on. Smaller than GEDCOM's, since
	 * an item can carry more than one image to download when asked to.
	 */
	const BATCH_ITEMS = 5;

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
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	public function maybe_handle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every handler below checks its own nonce.
		$action = isset( $_POST['familypedia_action'] ) ? sanitize_key( wp_unslash( $_POST['familypedia_action'] ) ) : '';

		if ( self::EXPORT_ACTION === $action ) {
			$this->export_download();
		} elseif ( self::IMPORT_ACTION === $action ) {
			$this->import_upload();
		} elseif ( self::APPLY_ACTION === $action ) {
			$this->import_apply();
		}
	}

	/**
	 * The import, a batch per request — same shape as Gedcom::register_rest_routes().
	 */
	public function register_rest_routes() {
		register_rest_route(
			'familypedia/v1',
			'/content-import',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( Gedcom::class, 'can_import' ),
				'callback'            => array( $this, 'rest_import_start' ),
			)
		);

		register_rest_route(
			'familypedia/v1',
			'/content-import/(?P<run>[a-z0-9]{32})',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( Gedcom::class, 'can_import' ),
				'callback'            => array( $this, 'rest_import_step' ),
			)
		);
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$review = isset( $_GET['familypedia_content_review'] ) ? sanitize_key( wp_unslash( $_GET['familypedia_content_review'] ) ) : '';
		if ( $review ) {
			$this->render_apply_section( $review );
			return;
		}
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
			<p>
				<label>
					<input type="checkbox" name="download_images" value="1" />
					<?php esc_html_e( 'Also download images into the media library and use them as the page photo', 'familypedia' ); ?>
				</label>
			</p>
			<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Upload content', 'familypedia' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Between the upload and the batch that applies it: a progress bar
	 * driven by fetch(), same shape as the GEDCOM review's, and a plain
	 * button that applies the whole file in one request for whoever has no
	 * JavaScript.
	 */
	private function render_apply_section( $token ) {
		$contents = $this->get_import_file( $token );
		if ( false === $contents ) {
			?>
			<p class="familypedia-notice familypedia-notice--error"><?php esc_html_e( 'The content file was not found. Please upload it again.', 'familypedia' ); ?></p>
			<?php
			return;
		}

		$download_images = (bool) get_transient( self::IMPORT_TRANSIENT_PREFIX . $token . '_download_images' );
		?>
		<p><?php esc_html_e( 'Content file uploaded. Applying it to the people it matches.', 'familypedia' ); ?></p>
		<form method="post" action="<?php echo esc_url( Gedcom::get_page_url() ); ?>" data-familypedia-content-form>
			<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( self::APPLY_ACTION ); ?>" />
			<input type="hidden" name="familypedia_content_review" value="<?php echo esc_attr( $token ); ?>" />
			<?php if ( $download_images ) : ?>
				<input type="hidden" name="download_images" value="1" />
			<?php endif; ?>
			<?php wp_nonce_field( self::APPLY_ACTION ); ?>
			<div class="familypedia-content-progress" data-familypedia-content-progress hidden>
				<progress data-familypedia-content-progress-bar max="100" value="0"></progress>
				<p role="status" data-familypedia-content-progress-text></p>
			</div>
			<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Apply content', 'familypedia' ); ?></button>
		</form>
		<style>
			.familypedia-content-progress progress {
				width: 100%;
			}

			.familypedia-content-progress--failed progress {
				accent-color: #d63638;
			}
		</style>
		<script type="application/json" id="familypedia-content-import-data">
			<?php
			echo wp_json_encode(
				array(
					'endpoint'       => rest_url( 'familypedia/v1/content-import' ),
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'token'          => $token,
					'downloadImages' => $download_images,
					'l10n'           => array(
						'starting' => __( 'Reading the file…', 'familypedia' ),
						// translators: %1$s is a number of people done, %2$s the total.
						'progress' => __( 'Applying content: %1$s of %2$s', 'familypedia' ),
						// translators: %s is an error message.
						'failed'   => __( 'The import stopped: %s', 'familypedia' ),
					),
				),
				JSON_HEX_TAG | JSON_HEX_AMP
			);
			?>
		</script>
		<script>
			(function () {
				var form = document.querySelector('[data-familypedia-content-form]');
				if (!form) {
					return;
				}

				var progress = form.querySelector('[data-familypedia-content-progress]');
				var bar = progress ? progress.querySelector('[data-familypedia-content-progress-bar]') : null;
				var text = progress ? progress.querySelector('[data-familypedia-content-progress-text]') : null;
				var dataEl = document.getElementById('familypedia-content-import-data');
				var settings = dataEl ? JSON.parse(dataEl.textContent) : {};
				var l10n = settings.l10n || {};

				if (!progress || !settings.endpoint || !window.fetch) {
					return;
				}

				form.addEventListener('submit', function (event) {
					event.preventDefault();
					start();
				});

				function send(url, payload) {
					return fetch(url, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': settings.nonce },
						body: JSON.stringify(payload || {})
					}).then(function (response) {
						return response.json().then(function (data) {
							if (!response.ok) {
								throw new Error(data && data.message ? data.message : response.statusText);
							}

							return data;
						});
					});
				}

				function start() {
					progress.hidden = false;
					progress.classList.remove('familypedia-content-progress--failed');
					working(true);
					say(l10n.starting, 0);

					send(settings.endpoint, { token: settings.token, download_images: settings.downloadImages })
						.then(function (started) {
							return step(started.run);
						})
						.catch(failed);
				}

				function step(run) {
					return send(settings.endpoint + '/' + run).then(function (state) {
						if (state.done) {
							say(state.message, 100);
							window.location = state.redirect;
							return;
						}

						say(
							l10n.progress.replace('%1$s', state.position).replace('%2$s', state.total),
							state.total ? Math.round((state.position / state.total) * 100) : 0
						);

						return step(run);
					});
				}

				function say(message, percent) {
					text.textContent = message;
					bar.value = percent;
				}

				function failed(error) {
					say(l10n.failed.replace('%s', error.message), 0);
					progress.classList.add('familypedia-content-progress--failed');
					working(false);
				}

				function working(busy) {
					Array.prototype.slice.call(form.querySelectorAll('button, input')).forEach(function (control) {
						control.disabled = busy;
					});
				}
			}());
		</script>
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
			$lines[] = '<content:encoded>' . $this->cdata( $this->relativize_images( $person->post_content ) ) . '</content:encoded>';
			if ( has_post_thumbnail( $person ) ) {
				$lines[] = '<wp:attachment_url>' . $this->cdata( wp_get_attachment_url( get_post_thumbnail_id( $person ) ) ) . '</wp:attachment_url>';
			}
			foreach ( $this->content_image_urls( $person->post_content ) as $url ) {
				$lines[] = '<wp:content_image_url>' . $this->cdata( $url ) . '</wp:content_image_url>';
			}
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

	/**
	 * This site's own image URLs referenced in a person's text, so the
	 * importer has something to fetch — listed separately, in full, because
	 * the text itself keeps only the path (see relativize_images()).
	 */
	private function content_image_urls( $content ) {
		if ( ! preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches ) ) {
			return array();
		}

		$home = home_url();
		$urls = array();
		foreach ( array_unique( $matches[1] ) as $url ) {
			if ( 0 === strpos( $url, $home ) ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Strips this site's own domain from same-site image references in a
	 * person's text, so the file does not silently hotlink a private
	 * wiki's photos into wherever it ends up — the path is only good for
	 * anything once the importer has actually downloaded the image.
	 */
	private function relativize_images( $content ) {
		return preg_replace_callback(
			'/(<img[^>]+src=["\'])' . preg_quote( home_url(), '/' ) . '([^"\']*)(["\'])/i',
			function ( $matches ) {
				return $matches[1] . $matches[2] . $matches[3];
			},
			$content
		);
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

		if ( is_wp_error( $this->parse_content_xml( $contents ) ) ) {
			$this->fail( 'invalid_file' );
		}

		// The token travels back through sanitize_key(), which lowercases it, so
		// only generate lowercase tokens: on a case sensitive database a mixed
		// case token would no longer find its own transient.
		$token = strtolower( wp_generate_password( 32, false, false ) );
		if ( ! $this->store_import_file( $token, $contents ) ) {
			$this->fail( 'store_failed' );
		}

		if ( ! empty( $_POST['download_images'] ) ) {
			set_transient( self::IMPORT_TRANSIENT_PREFIX . $token . '_download_images', 1, HOUR_IN_SECONDS );
		}

		wp_safe_redirect( add_query_arg( 'familypedia_content_review', $token, Gedcom::get_page_url() ) );
		exit;
	}

	/**
	 * Applies the whole parked file in one request, for whoever has no
	 * JavaScript — what import_upload() used to do straight away.
	 */
	public function import_apply() {
		if ( ! Gedcom::can_import() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import content.', 'familypedia' ), 403 );
		}
		check_admin_referer( self::APPLY_ACTION );

		$token    = isset( $_POST['familypedia_content_review'] ) ? sanitize_key( wp_unslash( $_POST['familypedia_content_review'] ) ) : '';
		$contents = $token ? $this->get_import_file( $token ) : false;
		if ( false === $contents ) {
			$this->fail( 'review_expired' );
		}

		$result = $this->apply_content( $contents, ! empty( $_POST['download_images'] ) );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_code() );
		}

		$this->delete_import_file( $token );

		Editor::set_notice( $this->result_message( $result ) );
		wp_safe_redirect( Gedcom::get_page_url() );
		exit;
	}

	/**
	 * Work out what this import will do, and remember it under a run id.
	 */
	public function rest_import_start( $request ) {
		$token    = sanitize_key( (string) $request->get_param( 'token' ) );
		$contents = $token ? $this->get_import_file( $token ) : false;
		if ( false === $contents ) {
			return new \WP_Error( 'familypedia_content_review_expired', __( 'The content file was not found. Please upload it again.', 'familypedia' ), array( 'status' => 400 ) );
		}

		$xml = $this->parse_content_xml( $contents );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$run   = strtolower( wp_generate_password( 32, false, false ) );
		$state = array(
			'token'           => $token,
			'total'           => count( $xml->channel->item ),
			'cursor'          => 0,
			'download_images' => ! empty( $request->get_param( 'download_images' ) ),
			// Built once, as the form post builds it once, so that a person
			// written by this import is not matched against by a later entry.
			'index'           => $this->gedcom->existing_page_index(),
			'updated'         => 0,
			'skipped'         => 0,
			'images'          => 0,
		);

		if ( ! set_transient( self::RUN_TRANSIENT_PREFIX . $run, $state, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'familypedia_content_run_failed', __( 'The import could not be started. Please try again.', 'familypedia' ), array( 'status' => 500 ) );
		}

		return array(
			'run'   => $run,
			'total' => $state['total'],
		);
	}

	/**
	 * Carry one run a batch further.
	 */
	public function rest_import_step( $request ) {
		$run   = sanitize_key( (string) $request->get_param( 'run' ) );
		$state = get_transient( self::RUN_TRANSIENT_PREFIX . $run );
		if ( ! is_array( $state ) ) {
			return new \WP_Error( 'familypedia_content_run_expired', __( 'The import stopped before it finished. Please upload the file again.', 'familypedia' ), array( 'status' => 400 ) );
		}

		$contents = $this->get_import_file( $state['token'] );
		if ( false === $contents ) {
			return new \WP_Error( 'familypedia_content_review_expired', __( 'The content file was not found. Please upload it again.', 'familypedia' ), array( 'status' => 400 ) );
		}

		$xml = $this->parse_content_xml( $contents );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$total = $state['total'];
		$end   = min( $total, $state['cursor'] + self::BATCH_ITEMS );

		for ( ; $state['cursor'] < $end; $state['cursor']++ ) {
			$item   = $xml->channel->item[ $state['cursor'] ];
			$result = $this->apply_item( $item, $state['index'], $state['download_images'] );
			if ( $result['matched'] ) {
				++$state['updated'];
			} else {
				++$state['skipped'];
			}
			$state['images'] += $result['images'];
		}

		if ( $state['cursor'] < $total ) {
			set_transient( self::RUN_TRANSIENT_PREFIX . $run, $state, HOUR_IN_SECONDS );

			return array(
				'done'     => false,
				'position' => $state['cursor'],
				'total'    => $total,
			);
		}

		$this->delete_import_file( $state['token'] );
		delete_transient( self::RUN_TRANSIENT_PREFIX . $run );

		$message = $this->result_message( $state );
		// Set here, not read from the redirect: familypedia's notices are a
		// one-shot transient keyed to the current user (Editor::set_notice()),
		// not query args, and this REST request runs as that same user.
		Editor::set_notice( $message );

		return array(
			'done'     => true,
			'message'  => $message,
			'redirect' => Gedcom::get_page_url(),
		);
	}

	private function result_message( $result ) {
		$message = $result['skipped']
			? sprintf(
				// translators: %1$d is a number of updated pages, %2$d a number of skipped entries.
				__( 'Updated %1$d pages. %2$d entries in the file did not match a person on the wiki and were skipped.', 'familypedia' ),
				$result['updated'],
				$result['skipped']
			)
			: sprintf(
				// translators: %d is a number of updated pages.
				__( 'Updated %d pages.', 'familypedia' ),
				$result['updated']
			);

		if ( $result['images'] ) {
			$message .= ' ' . sprintf(
				// translators: %d is a number of downloaded images.
				__( 'Downloaded %d images into the media library.', 'familypedia' ),
				$result['images']
			);
		}

		return $message;
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
	 * Park a file between the upload and the point applying it finishes.
	 * Mirrors Gedcom::store_import_file(): the file stays on disk, only its
	 * path goes into the transient.
	 */
	private function store_import_file( $token, $contents ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$path = wp_tempnam( 'familypedia-content-' . $token );
		if ( ! $path ) {
			return false;
		}

		if ( ! file_put_contents( $path, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $path );
			return false;
		}

		if ( ! set_transient( self::IMPORT_TRANSIENT_PREFIX . $token, $path, HOUR_IN_SECONDS ) ) {
			wp_delete_file( $path );
			return false;
		}

		return true;
	}

	private function get_import_file( $token ) {
		$path = get_transient( self::IMPORT_TRANSIENT_PREFIX . $token );

		// Only ever read back a file this class parked in the temp directory.
		if ( ! is_string( $path ) || 0 !== strpos( $path, get_temp_dir() ) || ! is_readable( $path ) ) {
			return false;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return ( false === $contents || '' === $contents ) ? false : $contents;
	}

	private function delete_import_file( $token ) {
		$path = get_transient( self::IMPORT_TRANSIENT_PREFIX . $token );
		delete_transient( self::IMPORT_TRANSIENT_PREFIX . $token );
		delete_transient( self::IMPORT_TRANSIENT_PREFIX . $token . '_download_images' );

		if ( is_string( $path ) && 0 === strpos( $path, get_temp_dir() ) && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Apply an uploaded content file to the people it matches. Never
	 * creates a person and never touches anything but post_content — and,
	 * when asked, a person's photo.
	 *
	 * @param string $contents        The uploaded content file.
	 * @param bool   $download_images Whether to fetch each matched person's
	 *                                image into the media library and set
	 *                                it as their photo. Off by default: this
	 *                                is the one part of the file that makes
	 *                                an outbound request, to whatever URL
	 *                                the file names.
	 */
	public function apply_content( $contents, $download_images = false ) {
		$xml = $this->parse_content_xml( $contents );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$index   = $this->gedcom->existing_page_index();
		$updated = 0;
		$skipped = 0;
		$images  = 0;

		foreach ( $xml->channel->item as $item ) {
			$result = $this->apply_item( $item, $index, $download_images );
			if ( $result['matched'] ) {
				++$updated;
			} else {
				++$skipped;
			}
			$images += $result['images'];
		}

		return array(
			'updated' => $updated,
			'skipped' => $skipped,
			'images'  => $images,
		);
	}

	/**
	 * Parse a content file, the same way for the whole-file path and every
	 * batch of the REST-driven one.
	 *
	 * @return \SimpleXMLElement|\WP_Error
	 */
	private function parse_content_xml( $contents ) {
		$previous_setting = libxml_use_internal_errors( true );
		$xml               = simplexml_load_string( $contents, 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_setting );

		if ( false === $xml || ! isset( $xml->channel ) ) {
			return new \WP_Error( 'invalid_file', __( 'This does not look like a content file.', 'familypedia' ), array( 'status' => 400 ) );
		}

		return $xml;
	}

	/**
	 * Apply one <item> to the person it matches. Split out from
	 * apply_content() so a batched import can call it one at a time.
	 *
	 * @return array array( 'matched' => bool, 'images' => int ).
	 */
	private function apply_item( $item, $index, $download_images ) {
		$wp_fields  = $item->children( 'http://wordpress.org/export/1.2/' );
		$content_ns = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
		$title      = trim( (string) $item->title );
		$content    = isset( $content_ns->encoded ) ? (string) $content_ns->encoded : '';
		$image_url  = isset( $wp_fields->attachment_url ) ? trim( (string) $wp_fields->attachment_url ) : '';

		$post_id = $this->match_post( $wp_fields, $title, $index );
		if ( ! $post_id ) {
			return array(
				'matched' => false,
				'images'  => 0,
			);
		}

		$images = 0;

		if ( $download_images ) {
			if ( $image_url ) {
				$attachment_id = $this->sideload_image( $image_url, $post_id );
				if ( $attachment_id ) {
					set_post_thumbnail( $post_id, $attachment_id );
					++$images;
				}
			}

			foreach ( $wp_fields->content_image_url as $node ) {
				$url = trim( (string) $node );
				if ( ! $url ) {
					continue;
				}
				$attachment_id = $this->sideload_image( $url, $post_id );
				if ( $attachment_id ) {
					$content = $this->replace_image_reference( $content, $url, wp_get_attachment_url( $attachment_id ) );
					++$images;
				}
			}
		}

		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
				)
			)
		);

		return array(
			'matched' => true,
			'images'  => $images,
		);
	}

	/**
	 * Fetch an image into the media library, attached to the given person.
	 * Only ever called when the upload form's checkbox asked for it.
	 *
	 * @return int The new attachment's ID, or 0 on failure.
	 */
	private function sideload_image( $url, $post_id ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return 0;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );

		return is_wp_error( $attachment_id ) ? 0 : $attachment_id;
	}

	/**
	 * The text keeps only the path an image lived at on the exporting site
	 * (see Content_Export::relativize_images()), not its domain — this puts
	 * back wherever the image now lives here, once it has been downloaded.
	 */
	private function replace_image_reference( $content, $original_url, $new_url ) {
		$path = wp_parse_url( $original_url, PHP_URL_PATH );
		if ( ! $path ) {
			return $content;
		}

		$query = wp_parse_url( $original_url, PHP_URL_QUERY );
		if ( $query ) {
			$path .= '?' . $query;
		}

		return str_replace( $path, $new_url, $content );
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
			'store_failed'   => __( 'The content file could not be stored for review.', 'familypedia' ),
			'review_expired' => __( 'The content review expired. Please upload the file again.', 'familypedia' ),
		);

		return isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'The content import failed.', 'familypedia' );
	}
}
