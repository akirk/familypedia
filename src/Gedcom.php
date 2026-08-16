<?php
namespace Familypedia;

class Gedcom {
	const URL_PATH = 'import-export';
	const EXPORT_ACTION = 'familypedia_gedcom_export';
	const IMPORT_ACTION = 'familypedia_gedcom_import';
	const SELECT_ACTION = 'familypedia_gedcom_import_selected';
	const XREF_META = '_familypedia_gedcom_xref';
	const IMPORT_TRANSIENT_PREFIX = 'familypedia_gedcom_import_';

	/**
	 * The instance Main built, so that the app template renders the page
	 * through the object that already handled the request.
	 *
	 * @var Gedcom|null
	 */
	private static $instance;

	public function __construct() {
		if ( ! self::$instance ) {
			self::$instance = $this;
		}

		add_action( 'wp_loaded', array( $this, 'maybe_handle' ) );
	}

	public static function get_page_url() {
		return home_url( '/' . App::URL_PATH . '/' . self::URL_PATH . '/' );
	}

	/**
	 * Exporting hands out everyone's dates and places at once, which is the
	 * whole wiki in one file, so it stays with the people who administer it.
	 */
	public static function can_export() {
		return current_user_can( 'manage_options' );
	}

	public static function can_import() {
		return current_user_can( 'import' );
	}

	public static function can_use() {
		return self::can_export() || self::can_import();
	}

	/**
	 * The page body, for the app template.
	 */
	public static function render() {
		$gedcom = self::$instance ? self::$instance : new self();
		$gedcom->render_page();
	}

	/**
	 * Handle the forms before any template output, so that an import can
	 * redirect and an export can send its own headers.
	 */
	public function maybe_handle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every handler below checks its own nonce.
		$action = isset( $_POST['familypedia_action'] ) ? sanitize_key( wp_unslash( $_POST['familypedia_action'] ) ) : '';

		if ( self::EXPORT_ACTION === $action ) {
			$this->export_download();
		} elseif ( self::IMPORT_ACTION === $action ) {
			$this->import_upload();
		} elseif ( self::SELECT_ACTION === $action ) {
			$this->import_selected();
		}
	}

	public function render_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$review = isset( $_GET['familypedia_review'] ) ? sanitize_key( wp_unslash( $_GET['familypedia_review'] ) ) : '';

		if ( $review && self::can_import() ) {
			$this->render_import_review( $review );
			return;
		}

		if ( self::can_export() ) {
			?>
			<section class="familypedia-gedcom">
				<h2><?php esc_html_e( 'Export', 'familypedia' ); ?></h2>
				<p><?php esc_html_e( 'Download the people on this wiki as a GEDCOM file.', 'familypedia' ); ?></p>
				<form method="post" action="<?php echo esc_url( self::get_page_url() ); ?>">
					<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( self::EXPORT_ACTION ); ?>" />
					<?php wp_nonce_field( self::EXPORT_ACTION ); ?>
					<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Download GEDCOM', 'familypedia' ); ?></button>
				</form>
			</section>
			<?php
		}

		if ( self::can_import() ) {
			?>
			<section class="familypedia-gedcom">
				<h2><?php esc_html_e( 'Import', 'familypedia' ); ?></h2>
				<?php $this->render_upload_form(); ?>
			</section>
			<?php
		}
	}

	private function render_upload_form() {
		?>
		<p><?php esc_html_e( 'Upload a GEDCOM file, review the people in it, and choose which entries or descendant subtrees to import. Existing people are matched by prior GEDCOM xref first, then by name.', 'familypedia' ); ?></p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( self::get_page_url() ); ?>">
			<?php wp_nonce_field( self::IMPORT_ACTION ); ?>
			<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>" />
			<p>
				<input type="file" name="gedcom" accept=".ged,.gedcom,text/plain" required />
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
			<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Upload and review GEDCOM', 'familypedia' ); ?></button>
		</form>
		<?php
	}

	private function render_import_review( $token ) {
		$contents = $this->get_import_file( $token );
		if ( false === $contents ) {
			?>
			<p class="familypedia-notice familypedia-notice--error"><?php echo esc_html( $this->error_message( 'review_expired' ) ); ?></p>
			<?php
			$this->render_upload_form();
			return;
		}

		$records = $this->parse_records( $contents );
		if ( empty( $records['INDI'] ) ) {
			?>
			<p class="familypedia-notice familypedia-notice--error"><?php echo esc_html( $this->error_message( 'no_individuals' ) ); ?></p>
			<?php
			$this->render_upload_form();
			return;
		}

		$families     = empty( $records['FAM'] ) ? array() : $records['FAM'];
		$people       = $this->review_people( $records );
		$total        = count( $people );
		$family_count = count( $families );
		$tree_data    = $this->review_tree_data( $families, $people );
		?>
		<section class="familypedia-gedcom-review">
			<h2><?php esc_html_e( 'Review GEDCOM import', 'familypedia' ); ?></h2>
			<?php
			$connected = 0;
			$matched   = 0;
			foreach ( $people as $person ) {
				if ( $person['wiki_hits'] ) {
					++$connected;
				}
				if ( $person['match_id'] ) {
					++$matched;
				}
			}
			?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						// translators: %1$d is a number of people, %2$d a number of family records, %3$d a number of people.
						__( 'Found %1$d people and %2$d family records. %3$d of them would land on a page that already exists.', 'familypedia' ),
						$total,
						$family_count,
						$matched
					)
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( self::get_page_url() ); ?>">
				<input type="hidden" name="familypedia_action" value="<?php echo esc_attr( self::SELECT_ACTION ); ?>" />
				<input type="hidden" name="familypedia_review" value="<?php echo esc_attr( $token ); ?>" />
				<?php wp_nonce_field( self::SELECT_ACTION ); ?>

				<?php
				/*
				 * Taking the whole file is the common case, and picking through a
				 * table of hundreds to say so is a poor way to spend an afternoon.
				 * It sits above the table, so the answer to "import all of it" is
				 * one button before any scrolling starts.
				 */
				?>
				<div class="familypedia-gedcom-review__actions">
					<button type="submit" name="familypedia_import_all" value="1" class="familypedia-button familypedia-button--primary">
						<?php
						echo esc_html(
							sprintf(
								// translators: %d is a number of people.
								_n( 'Import all %d person', 'Import all %d people', $total, 'familypedia' ),
								$total
							)
						);
						?>
					</button>
					<span class="familypedia-field__hint"><?php esc_html_e( 'Or pick people below and import just those.', 'familypedia' ); ?></span>
				</div>

				<?php if ( ! Front_Page::has_tree() ) : ?>
					<p class="familypedia-field familypedia-field--check">
						<label>
							<input type="checkbox" name="familypedia_front_page_tree" value="1" <?php checked( $this->front_page_tree_default() ); ?> />
							<?php esc_html_e( 'Put the biggest branch on the front page as a family tree', 'familypedia' ); ?>
						</label>
					</p>
				<?php endif; ?>

				<p class="familypedia-gedcom-review__views">
					<button type="button" class="familypedia-button familypedia-button--primary" data-familypedia-gedcom-view="connected">
						<?php
						echo esc_html(
							sprintf(
								// translators: %d is a number of people.
								__( 'Connects to your wiki (%d)', 'familypedia' ),
								$connected
							)
						);
						?>
					</button>
					<button type="button" class="familypedia-button" data-familypedia-gedcom-view="all">
						<?php
						echo esc_html(
							sprintf(
								// translators: %d is a number of people.
								__( 'All people (%d)', 'familypedia' ),
								$total
							)
						);
						?>
					</button>
					<input type="search" data-familypedia-gedcom-filter placeholder="<?php esc_attr_e( 'Filter by name', 'familypedia' ); ?>" />
				</p>
				<p>
					<button type="button" class="familypedia-button" data-familypedia-gedcom-select-all><?php esc_html_e( 'Select everyone shown', 'familypedia' ); ?></button>
					<button type="button" class="familypedia-button" data-familypedia-gedcom-clear><?php esc_html_e( 'Clear selection', 'familypedia' ); ?></button>
				</p>
				<table class="familypedia-gedcom-review__table">
					<thead>
						<tr>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Import', 'familypedia' ); ?></span></th>
							<th scope="col"><?php esc_html_e( 'Person', 'familypedia' ); ?></th>
							<th scope="col"><a href="#" data-familypedia-gedcom-sort="wiki"><?php esc_html_e( 'On your wiki', 'familypedia' ); ?></a></th>
							<th scope="col"><a href="#" data-familypedia-gedcom-sort="subtree"><?php esc_html_e( 'Subtree', 'familypedia' ); ?></a></th>
							<th scope="col"><?php esc_html_e( 'Birth', 'familypedia' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Death', 'familypedia' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Select subtree', 'familypedia' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $people as $person ) : ?>
							<tr
								data-familypedia-gedcom-row
								data-name="<?php echo esc_attr( strtolower( $person['name'] ) ); ?>"
								data-wiki="<?php echo esc_attr( $person['wiki_hits'] ); ?>"
								data-subtree="<?php echo esc_attr( $person['count'] ); ?>"
								<?php echo $person['wiki_hits'] ? 'data-connected="1"' : ''; ?>
							>
								<th scope="row">
									<input type="checkbox" name="familypedia_people[]" value="<?php echo esc_attr( $person['xref'] ); ?>" data-familypedia-gedcom-person="<?php echo esc_attr( $person['xref'] ); ?>" />
								</th>
								<td>
									<strong><?php echo esc_html( $person['name'] ); ?></strong>
									<?php if ( $person['match_id'] ) : ?>
										<br />
										<span class="familypedia-field__hint">
											<?php
											echo esc_html(
												sprintf(
													// translators: %s is a person's name.
													__( 'updates “%s”', 'familypedia' ),
													get_the_title( $person['match_id'] )
												)
											);
											?>
										</span>
									<?php endif; ?>
								</td>
								<td><?php echo $person['wiki_hits'] ? esc_html( $person['wiki_hits'] ) : '<span aria-hidden="true">-</span>'; ?></td>
								<td><?php echo $person['count'] ? esc_html( $person['count'] ) : '<span aria-hidden="true">-</span>'; ?></td>
								<td><?php echo esc_html( $person['birth'] ); ?></td>
								<td><?php echo esc_html( $person['death'] ); ?></td>
								<td>
									<?php if ( ! empty( $person['descendants'] ) ) : ?>
										<button type="button" class="familypedia-button familypedia-button--small" data-familypedia-gedcom-descendants="<?php echo esc_attr( implode( ',', array_merge( array( $person['xref'] ), $person['descendants'] ) ) ); ?>">
											<?php
											echo esc_html(
												sprintf(
													// translators: %d is a number of people in a descendant subtree.
													__( 'Select subtree (%d)', 'familypedia' ),
													$person['count'] + 1
												)
											);
											?>
										</button>
									<?php else : ?>
										<span aria-hidden="true">-</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div
					class="familypedia-gedcom-tree"
					data-familypedia-gedcom-drop-label="<?php esc_attr_e( 'Drop', 'familypedia' ); ?>"
					data-familypedia-gedcom-branch-label="<?php esc_attr_e( 'Drop branch', 'familypedia' ); ?>"
					data-familypedia-gedcom-toggle-label="<?php esc_attr_e( 'Show or hide this branch', 'familypedia' ); ?>"
				>
					<h3><?php esc_html_e( 'Selected people', 'familypedia' ); ?></h3>
					<p class="familypedia-field__hint" data-familypedia-gedcom-tree-empty><?php esc_html_e( 'Tick people above and the branches you picked are drawn here, so you can drop the ones you did not mean to take.', 'familypedia' ); ?></p>
					<ul class="familypedia-gedcom-tree__list" data-familypedia-gedcom-tree-list></ul>
					<p class="familypedia-field__hint" data-familypedia-gedcom-tree-more hidden>
						<?php
						echo esc_html(
							sprintf(
								// translators: %d is a number of people left out of a drawing of the selection.
								__( '%d more selected people are not drawn here.', 'familypedia' ),
								0
							)
						);
						?>
					</p>
				</div>
				<p>
					<strong data-familypedia-gedcom-count>
						<?php
						echo esc_html(
							sprintf(
								// translators: %1$d is a number of selected people, %2$d the total.
								__( '%1$d of %2$d selected', 'familypedia' ),
								0,
								$total
							)
						);
						?>
					</strong>
				</p>
				<p>
					<button type="submit" class="familypedia-button familypedia-button--primary"><?php esc_html_e( 'Import selected people', 'familypedia' ); ?></button>
				</p>
			</form>
			<script type="application/json" id="familypedia-gedcom-tree-data"><?php echo wp_json_encode( $tree_data, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
		</section>
		<?php
		wp_app_enqueue_script( 'familypedia-gedcom', Assets::url( 'gedcom.js' ), array(), Assets::version( 'gedcom.js' ), true );
	}

	/**
	 * Whether the front page tree is offered ticked. A wiki with nobody on it yet
	 * is one where this import is the whole family, and a family tree is what its
	 * front page wants to lead with. On a wiki that already has people, adding to
	 * their front page is a decision for them to make.
	 */
	private function front_page_tree_default() {
		return ! Person::get_all(
			array(
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
	}

	public function export_download() {
		if ( ! self::can_export() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export GEDCOM data.', 'familypedia' ), 403 );
		}
		check_admin_referer( self::EXPORT_ACTION );

		$filename = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) . '-familypedia-' . current_time( 'Ymd-His' ) . '.ged' );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $this->export_string();
		exit;
	}

	public function import_upload() {
		if ( ! self::can_import() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import GEDCOM data.', 'familypedia' ), 403 );
		}
		check_admin_referer( self::IMPORT_ACTION );

		// Report why the upload did not arrive, rather than asking for a file that was chosen.
		$upload_error = isset( $_FILES['gedcom']['error'] ) ? (int) $_FILES['gedcom']['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $upload_error ) {
			$error = in_array( $upload_error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'file_too_large' : 'upload_failed';
			if ( UPLOAD_ERR_NO_FILE === $upload_error ) {
				$error = 'missing_file';
			}
			$this->fail( $error );
		}

		if ( empty( $_FILES['gedcom']['tmp_name'] ) || ! is_uploaded_file( $_FILES['gedcom']['tmp_name'] ) ) {
			$this->fail( 'missing_file' );
		}

		$contents = file_get_contents( $_FILES['gedcom']['tmp_name'] );
		if ( false === $contents || '' === trim( $contents ) ) {
			$this->fail( 'empty_file' );
		}

		$records = $this->parse_records( $contents );
		if ( empty( $records['INDI'] ) ) {
			$this->fail( 'no_individuals' );
		}

		// The token travels back through sanitize_key(), which lowercases it, so
		// only generate lowercase tokens: on a case sensitive database a mixed
		// case token would no longer find its own transient.
		$token = strtolower( wp_generate_password( 32, false, false ) );
		if ( ! $this->store_import_file( $token, $contents ) ) {
			// Say so here, rather than letting the review screen report the file as expired.
			$this->fail( 'store_failed' );
		}

		wp_safe_redirect( add_query_arg( 'familypedia_review', $token, self::get_page_url() ) );
		exit;
	}

	/**
	 * Leave the reason on the page the form came from and go back to it.
	 *
	 * @param string $error A key of error_message().
	 * @param string $token The review being worked on, when there is one.
	 */
	private function fail( $error, $token = '' ) {
		Editor::set_notice( $this->error_message( $error ), 'error' );
		wp_safe_redirect( $token ? add_query_arg( 'familypedia_review', $token, self::get_page_url() ) : self::get_page_url() );
		exit;
	}

	/**
	 * Park the uploaded file between the upload and the selection request.
	 *
	 * The file itself stays on disk and only its location goes into the
	 * transient: a GEDCOM is easily hundreds of kilobytes, which is more than
	 * belongs in an option row, and more than some databases will accept there.
	 */
	private function store_import_file( $token, $contents ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$path = wp_tempnam( 'familypedia-gedcom-' . $token );
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

		if ( is_string( $path ) && 0 === strpos( $path, get_temp_dir() ) && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	public function import_selected() {
		if ( ! self::can_import() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import GEDCOM data.', 'familypedia' ), 403 );
		}
		check_admin_referer( self::SELECT_ACTION );

		$token    = isset( $_POST['familypedia_review'] ) ? sanitize_key( wp_unslash( $_POST['familypedia_review'] ) ) : '';
		$contents = $token ? $this->get_import_file( $token ) : false;
		if ( false === $contents ) {
			$this->fail( 'review_expired' );
		}

		// A null selection is everybody, which is what the button above the table asks for.
		if ( empty( $_POST['familypedia_import_all'] ) ) {
			$selected = isset( $_POST['familypedia_people'] ) && is_array( $_POST['familypedia_people'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['familypedia_people'] ) ) : array();
		} else {
			$selected = null;
		}

		$result = $this->import_string( $contents, $selected );
		if ( is_wp_error( $result ) ) {
			// The file is kept, so that the selection can be corrected and sent again.
			Editor::set_notice( $result->get_error_message(), 'error' );
			wp_safe_redirect( add_query_arg( 'familypedia_review', $token, self::get_page_url() ) );
			exit;
		}

		$this->delete_import_file( $token );

		$notice = sprintf(
			// translators: %1$d is a number of people created, %2$d a number updated.
			__( 'GEDCOM import complete. Created %1$d people and updated %2$d people.', 'familypedia' ),
			$result['created'],
			$result['updated']
		);

		// The import has just rewritten the family, so ask about it afterwards.
		if ( ! empty( $_POST['familypedia_front_page_tree'] ) && Front_Page::add_tree( Tree::suggest_root() ) ) {
			$notice .= ' ' . __( 'The biggest branch is now on the front page.', 'familypedia' );
		}

		Editor::set_notice( $notice );
		wp_safe_redirect( self::get_page_url() );
		exit;
	}

	public function export_string() {
		$people = $this->get_export_people();
		$ids    = $this->export_xrefs( $people );

		$families = $this->get_export_families( $people, $ids );
		$lines    = array(
			'0 HEAD',
			'1 SOUR Familypedia',
			'1 GEDC',
			'2 VERS 5.5.1',
			'2 FORM LINEAGE-LINKED',
			'1 CHAR UTF-8',
		);

		foreach ( $people as $person ) {
			$lines = array_merge( $lines, $this->export_individual( $person, $ids[ $person->ID ], $families ) );
		}

		$f = 1;
		foreach ( $families as $family ) {
			$family['xref'] = 'F' . $f++;
			$lines         = array_merge( $lines, $this->export_family( $family ) );
		}

		$lines[] = '0 TRLR';

		return implode( "\r\n", $lines ) . "\r\n";
	}

	public function import_string( $contents, $selected_xrefs = null ) {
		$records = $this->parse_records( $contents );
		if ( empty( $records['INDI'] ) ) {
			return new \WP_Error( 'no_individuals', __( 'The GEDCOM file does not contain individual records.', 'familypedia' ) );
		}
		if ( is_array( $selected_xrefs ) ) {
			$selected_xrefs = array_fill_keys( array_map( 'sanitize_text_field', $selected_xrefs ), true );
			foreach ( array_keys( $records['INDI'] ) as $xref ) {
				if ( empty( $selected_xrefs[ $xref ] ) ) {
					unset( $records['INDI'][ $xref ] );
				}
			}
			if ( empty( $records['INDI'] ) ) {
				return new \WP_Error( 'no_selection', __( 'Please select at least one GEDCOM person to import.', 'familypedia' ) );
			}
		}

		$created = 0;
		$updated = 0;
		$id_map  = array();
		$index   = $this->existing_page_index();
		$claimed = array();

		foreach ( $records['INDI'] as $xref => $record ) {
			$title   = $this->gedcom_name_to_title( $this->first_value( $record, 'NAME' ) );
			$post_id = $this->find_person_post( $xref, $title, $index, $claimed, $this->gedcom_birth_year( $record ) );
			$data    = array(
				'post_type'    => Person::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title ? $title : $xref,
				'post_content' => '',
			);

			if ( $post_id ) {
				$data['ID'] = $post_id;
				unset( $data['post_content'] );
				$result = wp_update_post( wp_slash( $data ), true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				++$updated;
			} else {
				$result = wp_insert_post( wp_slash( $data ), true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$post_id = $result;
				++$created;
			}

			$id_map[ $xref ]   = $post_id;
			$claimed[ $post_id ] = true;
			update_post_meta( $post_id, self::XREF_META, $xref );
			$this->import_individual_fields( $post_id, $record );
		}

		$this->import_family_links( isset( $records['FAM'] ) ? $records['FAM'] : array(), $id_map );
		Main::flush_family_data_cache();

		return array(
			'created' => $created,
			'updated' => $updated,
		);
	}

	/**
	 * The GEDCOM xref to write for each person.
	 *
	 * A person who arrived in an earlier import keeps the xref they came with.
	 * Handing out fresh ones instead would mean this plugin's own export no
	 * longer round-trips: on the way back in, an entry whose xref matches
	 * nobody falls through to matching by name, and a person already carrying a
	 * different xref is deliberately not matched by name — so every previously
	 * imported person would be created a second time.
	 *
	 * @param \WP_Post[] $people People being exported.
	 * @return array Post ID => xref.
	 */
	private function export_xrefs( $people ) {
		$ids   = array();
		$taken = array();

		foreach ( $people as $person ) {
			$xref = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) get_post_meta( $person->ID, self::XREF_META, true ) );
			$xref = substr( $xref, 0, 20 );

			if ( '' === $xref || isset( $taken[ $xref ] ) ) {
				continue;
			}

			$ids[ $person->ID ] = $xref;
			$taken[ $xref ]     = true;
		}

		$i = 1;
		foreach ( $people as $person ) {
			if ( isset( $ids[ $person->ID ] ) ) {
				continue;
			}

			do {
				$xref = 'I' . $i++;
			} while ( isset( $taken[ $xref ] ) );

			$ids[ $person->ID ] = $xref;
			$taken[ $xref ]     = true;
		}

		return $ids;
	}

	private function get_export_people() {
		return array_values( array_filter( Person::get_all(), array( $this, 'has_person_data' ) ) );
	}

	private function has_person_data( $person ) {
		foreach ( array( 'sex', 'born_as', 'birth_date', 'birth_place', 'death_date', 'death_place', 'father', 'mother', 'children', 'marriages', 'spouse', 'spouse_name', 'marriage_date', 'marriage_place' ) as $field ) {
			$value = $this->get_field_value( $field, $person->ID );
			if ( ! empty( $value ) ) {
				return true;
			}
		}

		return false;
	}

	private function export_individual( $person, $xref, $families ) {
		$lines = array(
			'0 @' . $xref . '@ INDI',
			'1 NAME ' . $this->format_gedcom_name( get_the_title( $person ) ),
		);

		$sex = $this->get_field_value( 'sex', $person->ID );
		if ( $sex ) {
			$lines[] = '1 SEX ' . $this->format_sex( $sex );
		}
		$lines = array_merge( $lines, $this->export_event( 'BIRT', $this->get_field_value( 'birth_date', $person->ID ), $this->get_field_value( 'birth_place', $person->ID ), $this->get_field_value( 'exact_birth_date_unknown', $person->ID ) ) );
		$lines = array_merge( $lines, $this->export_event( 'DEAT', $this->get_field_value( 'death_date', $person->ID ), $this->get_field_value( 'death_place', $person->ID ), $this->get_field_value( 'exact_death_date_unknown', $person->ID ) ) );

		foreach ( $families as $family ) {
			if ( in_array( $person->ID, $family['children'], true ) ) {
				$lines[] = '1 FAMC @' . $family['xref_key'] . '@';
			}
			if ( $person->ID === $family['husband'] || $person->ID === $family['wife'] ) {
				$lines[] = '1 FAMS @' . $family['xref_key'] . '@';
			}
		}

		return $lines;
	}

	private function export_family( $family ) {
		$lines = array( '0 @' . $family['xref'] . '@ FAM' );
		if ( $family['husband'] ) {
			$lines[] = '1 HUSB @' . $family['person_xrefs'][ $family['husband'] ] . '@';
		}
		if ( $family['wife'] ) {
			$lines[] = '1 WIFE @' . $family['person_xrefs'][ $family['wife'] ] . '@';
		}
		foreach ( $family['children'] as $child_id ) {
			$lines[] = '1 CHIL @' . $family['person_xrefs'][ $child_id ] . '@';
		}
		if ( $family['marriage_date'] || $family['marriage_place'] ) {
			$lines = array_merge( $lines, $this->export_event( 'MARR', $family['marriage_date'], $family['marriage_place'], false ) );
		}

		return $lines;
	}

	private function get_export_families( $people, $ids ) {
		$families = array();
		foreach ( $people as $person ) {
			$father = $this->post_id_from_field( $this->get_field_value( 'father', $person->ID ) );
			$mother = $this->post_id_from_field( $this->get_field_value( 'mother', $person->ID ) );
			if ( $father || $mother ) {
				// The same key a marriage gets, so that a couple who are both
				// married and recorded as a child's parents produce one family
				// record rather than two: split across two records, a re-import
				// would overwrite the dated marriage with the undated one.
				$key = $this->family_key_for_couple( $father, $mother );
				if ( empty( $families[ $key ] ) ) {
					$families[ $key ] = $this->empty_family( $father, $mother, $ids );
				}
				$families[ $key ]['children'][ $person->ID ] = $person->ID;
			}

			$marriages = $this->get_field_value( 'marriages', $person->ID );
			if ( is_array( $marriages ) ) {
				foreach ( $marriages as $marriage ) {
					if ( empty( $marriage['spouse'] ) ) {
						continue;
					}
					$spouse = absint( $marriage['spouse'] );
					if ( empty( $ids[ $spouse ] ) ) {
						continue;
					}
					$key = $this->family_key_for_couple( $person->ID, $spouse );
					if ( empty( $families[ $key ] ) ) {
						$families[ $key ] = $this->empty_family_for_couple( $person->ID, $spouse, $ids );
					}
					if ( ! empty( $marriage['marriage_date'] ) ) {
						$families[ $key ]['marriage_date'] = $marriage['marriage_date'];
					}
					if ( ! empty( $marriage['marriage_place'] ) ) {
						$families[ $key ]['marriage_place'] = $marriage['marriage_place'];
					}
				}
			}
		}

		$i = 1;
		foreach ( $families as $key => $family ) {
			$families[ $key ]['children'] = array_values( $family['children'] );
			$families[ $key ]['xref_key'] = 'F' . $i++;
		}

		return array_values( $families );
	}

	private function empty_family( $father, $mother, $ids ) {
		return array(
			'husband'        => isset( $ids[ $father ] ) ? $father : 0,
			'wife'           => isset( $ids[ $mother ] ) ? $mother : 0,
			'children'       => array(),
			'marriage_date'  => '',
			'marriage_place' => '',
			'person_xrefs'   => $ids,
		);
	}

	private function empty_family_for_couple( $person_id, $spouse_id, $ids ) {
		$person_sex = $this->get_field_value( 'sex', $person_id );
		$spouse_sex = $this->get_field_value( 'sex', $spouse_id );
		$husband    = 0;
		$wife       = 0;
		if ( 'Female' === $person_sex || 'Male' === $spouse_sex ) {
			$wife    = $person_id;
			$husband = $spouse_id;
		} else {
			$husband = $person_id;
			$wife    = $spouse_id;
		}

		return $this->empty_family( $husband, $wife, $ids );
	}

	private function export_event( $tag, $date, $place, $approximate ) {
		$lines = array();
		if ( ! $date && ! $place ) {
			return $lines;
		}

		$lines[] = '1 ' . $tag;
		if ( $date ) {
			$lines[] = '2 DATE ' . ( $approximate ? 'ABT ' : '' ) . $this->format_gedcom_date( $date );
		}
		if ( $place ) {
			$lines[] = '2 PLAC ' . $this->clean_gedcom_value( $place );
		}

		return $lines;
	}

	private function review_people( $records ) {
		$descendants = $this->descendants_by_person( empty( $records['FAM'] ) ? array() : $records['FAM'] );
		$existing    = $this->existing_page_index();
		$names       = array();
		$matches     = array();

		// Which entries would land on a page that already exists. Resolved with
		// the function the import uses, in the order the import runs, so the
		// screen promises exactly what will happen: where two people share a
		// name, only the first is shown as updating a page.
		$claimed = array();
		foreach ( $records['INDI'] as $xref => $record ) {
			$name           = $this->gedcom_name_to_title( $this->first_value( $record, 'NAME' ) );
			$names[ $xref ] = $name;

			$post_id = $this->find_person_post( $xref, $name, $existing, $claimed, $this->gedcom_birth_year( $record ) );
			if ( $post_id ) {
				$matches[ $xref ]  = $post_id;
				$claimed[ $post_id ] = true;
			}
		}

		$people = array();
		foreach ( $records['INDI'] as $xref => $record ) {
			$birth   = $this->event_values( $record, 'BIRT' );
			$death   = $this->event_values( $record, 'DEAT' );
			$subtree = isset( $descendants[ $xref ] ) ? $descendants[ $xref ] : array();

			// How much of this person's subtree is already on the wiki. This is
			// what tells a branch worth importing from one that is only distantly
			// related, which a plain descendant count does not.
			$hits = isset( $matches[ $xref ] ) ? 1 : 0;
			foreach ( $subtree as $descendant ) {
				if ( isset( $matches[ $descendant ] ) ) {
					++$hits;
				}
			}

			$people[] = array(
				'xref'        => $xref,
				'name'        => $names[ $xref ],
				'birth'       => $this->review_event_label( $birth ),
				'death'       => $this->review_event_label( $death ),
				'birth_year'  => $this->gedcom_year( $birth ),
				'death_year'  => $this->gedcom_year( $death ),
				'descendants' => $subtree,
				'count'       => count( $subtree ),
				'wiki_hits'   => $hits,
				'match_id'    => isset( $matches[ $xref ] ) ? $matches[ $xref ] : 0,
			);
		}

		usort( $people, array( $this, 'sort_review_people' ) );

		return $people;
	}

	/**
	 * The shape of the file, small enough to hand to the browser: the tree of
	 * selected people is drawn there and redrawn on every tick, so it needs the
	 * links between people without another round trip. Names and years only —
	 * the table above it already carries the detail.
	 */
	private function review_tree_data( $families, $people ) {
		list( $children_by_parent, $partners_by_person ) = $this->family_links( $families );

		$data = array();
		foreach ( $people as $person ) {
			$xref  = $person['xref'];
			$entry = array( 'name' => $person['name'] );

			if ( $person['birth_year'] ) {
				$entry['birth'] = $person['birth_year'];
			}
			if ( $person['death_year'] ) {
				$entry['death'] = $person['death_year'];
			}
			if ( ! empty( $children_by_parent[ $xref ] ) ) {
				$entry['children'] = array_values( $children_by_parent[ $xref ] );
			}
			if ( ! empty( $partners_by_person[ $xref ] ) ) {
				$entry['partners'] = array_values( $partners_by_person[ $xref ] );
			}

			$data[ $xref ] = $entry;
		}

		return $data;
	}

	/**
	 * Pages that a GEDCOM entry could land on, looked up the same way the
	 * import does: by a previously stored xref first, then by title.
	 */
	private function existing_page_index() {
		$index = array(
			'xref'  => array(),
			'title' => array(),
		);

		$pages = get_posts(
			array(
				'post_type'      => Person::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $pages as $page_id ) {
			$title = strtolower( trim( get_the_title( $page_id ) ) );
			// Every page with this title, not just the first: several people in a
			// GEDCOM commonly share a name, and each needs its own page.
			if ( $title ) {
				$index['title'][ $title ][] = $page_id;
			}

			$xref = get_post_meta( $page_id, self::XREF_META, true );
			if ( $xref ) {
				$index['xref'][ $xref ] = $page_id;
			}
		}

		return $index;
	}

	private function sort_review_people( $a, $b ) {
		// Entries that reach the existing wiki first, then the tighter subtree:
		// a small branch that is mostly already here beats a huge distant one.
		if ( $a['wiki_hits'] !== $b['wiki_hits'] ) {
			return $b['wiki_hits'] - $a['wiki_hits'];
		}

		if ( $a['wiki_hits'] && $a['count'] !== $b['count'] ) {
			return $a['count'] - $b['count'];
		}

		return strcasecmp( $a['name'], $b['name'] );
	}

	/**
	 * Who each person's children and partners are, read off the family records.
	 */
	private function family_links( $families ) {
		$children_by_parent = array();
		$partners_by_person = array();

		foreach ( $families as $family ) {
			$parents = array_filter(
				array(
					$this->first_pointer( $family, 'HUSB' ),
					$this->first_pointer( $family, 'WIFE' ),
				)
			);
			$children = $this->all_pointers( $family, 'CHIL' );
			foreach ( $parents as $parent ) {
				foreach ( $children as $child ) {
					$children_by_parent[ $parent ][ $child ] = $child;
				}
				foreach ( $parents as $other ) {
					if ( $other !== $parent ) {
						$partners_by_person[ $parent ][ $other ] = $other;
					}
				}
			}
		}

		return array( $children_by_parent, $partners_by_person );
	}

	private function descendants_by_person( $families ) {
		list( $children_by_parent, $partners_by_person ) = $this->family_links( $families );

		$descendants = array();
		foreach ( array_keys( $children_by_parent ) as $xref ) {
			$line = $this->collect_descendants( $xref, $children_by_parent );

			// Take the people they married along. A descendant line without the
			// spouses is half a family tree: every couple would show one partner
			// and a blank where the other belongs. Their ancestors are left out,
			// so this brings in the spouse and not their whole family as well.
			foreach ( array_merge( array( $xref ), array_keys( $line ) ) as $person ) {
				if ( empty( $partners_by_person[ $person ] ) ) {
					continue;
				}

				foreach ( $partners_by_person[ $person ] as $partner ) {
					if ( $partner !== $xref ) {
						$line[ $partner ] = $partner;
					}
				}
			}

			$descendants[ $xref ] = array_values( $line );
		}

		return $descendants;
	}

	private function collect_descendants( $xref, $children_by_parent, $seen = array() ) {
		if ( empty( $children_by_parent[ $xref ] ) ) {
			return $seen;
		}

		foreach ( $children_by_parent[ $xref ] as $child ) {
			if ( isset( $seen[ $child ] ) ) {
				continue;
			}
			$seen[ $child ] = $child;
			$seen = $this->collect_descendants( $child, $children_by_parent, $seen );
		}

		return $seen;
	}

	private function review_event_label( $event ) {
		$parts = array();
		if ( ! empty( $event['date'] ) ) {
			$parts[] = $event['approximate'] ? sprintf(
				// translators: %s is a date.
				__( 'about %s', 'familypedia' ),
				$event['date']
			) : $event['date'];
		}
		if ( ! empty( $event['place'] ) ) {
			$parts[] = $event['place'];
		}

		return implode( ', ', $parts );
	}

	private function parse_records( $contents ) {
		$contents = str_replace( array( "\r\n", "\r" ), "\n", $contents );
		$lines    = explode( "\n", $contents );
		$records  = array();
		$current  = null;

		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^(\d+)\s+(?:(@[^@]+@)\s+)?([A-Za-z0-9_]+)(?:\s+(.*))?$/', trim( $line ), $matches ) ) {
				continue;
			}

			$entry = array(
				'level' => (int) $matches[1],
				'tag'   => strtoupper( $matches[3] ),
				'value' => isset( $matches[4] ) ? $matches[4] : '',
			);

			if ( 0 === $entry['level'] && ! empty( $matches[2] ) ) {
				$current = array(
					'xref'  => trim( $matches[2], '@' ),
					'tag'   => $entry['tag'],
					'lines' => array(),
				);
				if ( empty( $records[ $current['tag'] ] ) ) {
					$records[ $current['tag'] ] = array();
				}
				$records[ $current['tag'] ][ $current['xref'] ] = array();
				continue;
			}

			if ( $current && $entry['level'] > 0 ) {
				$records[ $current['tag'] ][ $current['xref'] ][] = $entry;
			}
		}

		return $records;
	}

	private function import_individual_fields( $post_id, $record ) {
		$name = $this->gedcom_name_to_title( $this->first_value( $record, 'NAME' ) );
		if ( $name ) {
			$this->update_field( 'born_as', $name, $post_id );
		}

		$sex = $this->first_value( $record, 'SEX' );
		if ( $sex ) {
			$this->update_field( 'sex', $this->import_sex( $sex ), $post_id );
		}

		$birth = $this->event_values( $record, 'BIRT' );
		if ( $birth['date'] ) {
			$this->update_field( 'birth_date', $birth['date'], $post_id );
			$this->update_field( 'exact_birth_date_unknown', $birth['approximate'] ? 1 : 0, $post_id );
		}
		if ( $birth['place'] ) {
			$this->update_field( 'birth_place', $birth['place'], $post_id );
		}

		$death = $this->event_values( $record, 'DEAT' );
		if ( $death['date'] ) {
			$this->update_field( 'death_date', $death['date'], $post_id );
			$this->update_field( 'exact_death_date_unknown', $death['approximate'] ? 1 : 0, $post_id );
			$this->update_field( 'alive', 0, $post_id );
		} elseif ( $this->has_level_one_tag( $record, 'DEAT' ) ) {
			$this->update_field( 'alive', 0, $post_id );
		} else {
			$this->update_field( 'alive', 1, $post_id );
		}
		if ( $death['place'] ) {
			$this->update_field( 'death_place', $death['place'], $post_id );
		}
	}

	private function import_family_links( $families, $id_map ) {
		$children_by_parent = array();
		$marriages_by_person = array();

		foreach ( $families as $family ) {
			$husband = $this->xref_post_id( $this->first_pointer( $family, 'HUSB' ), $id_map );
			$wife    = $this->xref_post_id( $this->first_pointer( $family, 'WIFE' ), $id_map );
			$event   = $this->event_values( $family, 'MARR' );
			$children = $this->all_pointers( $family, 'CHIL' );

			foreach ( $children as $child_xref ) {
				$child = $this->xref_post_id( $child_xref, $id_map );
				if ( ! $child ) {
					continue;
				}
				if ( $husband ) {
					$this->update_field( 'father', $husband, $child );
					$children_by_parent[ $husband ][ $child ] = $child;
				}
				if ( $wife ) {
					$this->update_field( 'mother', $wife, $child );
					$children_by_parent[ $wife ][ $child ] = $child;
				}
			}

			if ( $husband && $wife ) {
				$marriage = array(
					'spouse'         => $wife,
					'spouse_name'    => '',
					'marriage_date'  => $event['date'],
					'marriage_year'  => '',
					'marriage_place' => $event['place'],
					'ended_date'     => '',
					'ended_year'     => '',
					'ended_reason'   => '',
				);
				$marriages_by_person[ $husband ][ $wife ] = $marriage;
				$marriage['spouse'] = $husband;
				$marriages_by_person[ $wife ][ $husband ] = $marriage;
			}
		}

		foreach ( $children_by_parent as $parent_id => $children ) {
			$this->update_field( 'children', $this->merge_post_ids( $this->get_field_value( 'children', $parent_id ), array_values( $children ) ), $parent_id );
		}
		foreach ( $marriages_by_person as $person_id => $marriages ) {
			$this->update_field( 'marriages', $this->merge_marriages( $this->get_field_value( 'marriages', $person_id ), array_values( $marriages ) ), $person_id );
		}
	}

	private function merge_post_ids( $existing, $incoming ) {
		$merged = array();
		if ( ! is_array( $existing ) ) {
			$existing = $existing ? array( $existing ) : array();
		}
		foreach ( $existing as $value ) {
			$post_id = $this->post_id_from_field( $value );
			if ( $post_id ) {
				$merged[ $post_id ] = $post_id;
			}
		}
		foreach ( $incoming as $value ) {
			$post_id = $this->post_id_from_field( $value );
			if ( $post_id ) {
				$merged[ $post_id ] = $post_id;
			}
		}

		return array_values( $merged );
	}

	private function merge_marriages( $existing, $incoming ) {
		$merged = array();
		if ( is_array( $existing ) ) {
			foreach ( $existing as $marriage ) {
				if ( ! is_array( $marriage ) ) {
					continue;
				}
				$spouse = isset( $marriage['spouse'] ) ? $this->post_id_from_field( $marriage['spouse'] ) : 0;
				$key    = $spouse ? 'spouse:' . $spouse : 'name:' . ( isset( $marriage['spouse_name'] ) ? $marriage['spouse_name'] : count( $merged ) );
				$merged[ $key ] = $marriage;
			}
		}
		foreach ( $incoming as $marriage ) {
			if ( empty( $marriage['spouse'] ) ) {
				continue;
			}
			$merged[ 'spouse:' . absint( $marriage['spouse'] ) ] = $marriage;
		}

		return array_values( $merged );
	}

	private function first_value( $record, $tag ) {
		foreach ( $record as $line ) {
			if ( $tag === $line['tag'] ) {
				return $line['value'];
			}
		}

		return '';
	}

	private function has_level_one_tag( $record, $tag ) {
		foreach ( $record as $line ) {
			if ( 1 === $line['level'] && $tag === $line['tag'] ) {
				return true;
			}
		}

		return false;
	}

	private function first_pointer( $record, $tag ) {
		return trim( $this->first_value( $record, $tag ), '@' );
	}

	private function all_pointers( $record, $tag ) {
		$values = array();
		foreach ( $record as $line ) {
			if ( $tag === $line['tag'] ) {
				$values[] = trim( $line['value'], '@' );
			}
		}

		return $values;
	}

	private function event_values( $record, $tag ) {
		$event = array(
			'date'        => '',
			'place'       => '',
			'approximate' => false,
		);
		$in_event = false;
		foreach ( $record as $line ) {
			if ( 1 === $line['level'] ) {
				$in_event = $tag === $line['tag'];
				continue;
			}
			if ( ! $in_event || 2 !== $line['level'] ) {
				continue;
			}
			if ( 'DATE' === $line['tag'] ) {
				$parsed = $this->parse_gedcom_date( $line['value'] );
				$event['date']        = $parsed['date'];
				$event['approximate'] = $parsed['approximate'];
			} elseif ( 'PLAC' === $line['tag'] ) {
				$event['place'] = sanitize_text_field( $line['value'] );
			}
		}

		return $event;
	}

	private function parse_gedcom_date( $date ) {
		$date        = trim( strtoupper( $date ) );
		$approximate = false;
		if ( preg_match( '/^(ABT|ABOUT|CAL|EST)\s+(.+)$/', $date, $matches ) ) {
			$approximate = true;
			$date        = $matches[2];
		}

		$months = array_flip( $this->gedcom_months() );
		if ( preg_match( '/^(\d{1,2})\s+([A-Z]{3})\s+(\d{3,4})$/', $date, $matches ) && isset( $months[ $matches[2] ] ) ) {
			return array(
				'date'        => sprintf( '%04d-%02d-%02d', $matches[3], $months[ $matches[2] ], $matches[1] ),
				'approximate' => $approximate,
			);
		}
		if ( preg_match( '/^([A-Z]{3})\s+(\d{3,4})$/', $date, $matches ) && isset( $months[ $matches[1] ] ) ) {
			return array(
				'date'        => sprintf( '%04d-%02d-01', $matches[2], $months[ $matches[1] ] ),
				'approximate' => true,
			);
		}
		if ( preg_match( '/^(\d{3,4})$/', $date, $matches ) ) {
			return array(
				'date'        => sprintf( '%04d-01-01', $matches[1] ),
				'approximate' => true,
			);
		}

		return array(
			'date'        => '',
			'approximate' => $approximate,
		);
	}

	/**
	 * The page a GEDCOM entry belongs on, or 0 to create one.
	 *
	 * A stored xref is definitive. Falling back to the title is a guess, so it
	 * only accepts a page that no other entry has a stronger claim to: one that
	 * carries a different xref belongs to a different person, and one already
	 * taken during this import would otherwise be overwritten by every namesake
	 * that follows.
	 *
	 * Where a birth year is known on both sides it has to agree, so that the
	 * page for one Alexander Kirk is not overwritten by another one who merely
	 * shares the name.
	 *
	 * @param string $xref       The GEDCOM xref.
	 * @param string $title      The person's name.
	 * @param array  $index      Existing pages, from existing_page_index().
	 * @param array  $claimed    Page IDs already taken in this run, keyed by ID.
	 * @param string $birth_year The person's birth year, if known.
	 */
	private function find_person_post( $xref, $title, $index, $claimed = array(), $birth_year = '' ) {
		if ( isset( $index['xref'][ $xref ] ) ) {
			return (int) $index['xref'][ $xref ];
		}

		$key = strtolower( trim( (string) $title ) );
		if ( '' === $key || empty( $index['title'][ $key ] ) ) {
			return 0;
		}

		foreach ( $index['title'][ $key ] as $page_id ) {
			if ( isset( $claimed[ $page_id ] ) ) {
				continue;
			}

			$stored = get_post_meta( $page_id, self::XREF_META, true );
			if ( $stored && $stored !== $xref ) {
				continue;
			}

			if ( $birth_year ) {
				$page_year = substr( preg_replace( '/\D/', '', (string) get_post_meta( $page_id, 'birth_date', true ) ), 0, 4 );
				if ( $page_year && $page_year !== $birth_year ) {
					continue;
				}
			}

			return (int) $page_id;
		}

		return 0;
	}

	private function gedcom_birth_year( $record ) {
		return $this->gedcom_year( $this->event_values( $record, 'BIRT' ) );
	}

	private function gedcom_year( $event ) {
		return empty( $event['date'] ) ? '' : substr( preg_replace( '/\D/', '', $event['date'] ), 0, 4 );
	}

	private function get_field_value( $field, $post_id ) {
		return Person::field( $field, $post_id );
	}

	private function update_field( $field, $value, $post_id ) {
		Person::update( $field, $value, $post_id );
	}

	private function post_id_from_field( $value ) {
		if ( $value instanceof \WP_Post ) {
			return (int) $value->ID;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		return 0;
	}

	private function xref_post_id( $xref, $id_map ) {
		return $xref && isset( $id_map[ $xref ] ) ? $id_map[ $xref ] : 0;
	}

	private function gedcom_name_to_title( $name ) {
		$name = str_replace( '/', '', $name );
		return trim( preg_replace( '/\s+/', ' ', $name ) );
	}

	private function format_gedcom_name( $name ) {
		$name  = $this->clean_gedcom_value( $name );
		$parts = preg_split( '/\s+/', $name );
		if ( count( $parts ) < 2 ) {
			return $name;
		}
		$surname = array_pop( $parts );

		return implode( ' ', $parts ) . ' /' . $surname . '/';
	}

	private function format_sex( $sex ) {
		if ( 'Male' === $sex ) {
			return 'M';
		}
		if ( 'Female' === $sex ) {
			return 'F';
		}

		return 'U';
	}

	private function import_sex( $sex ) {
		$sex = strtoupper( trim( $sex ) );
		if ( 'M' === $sex ) {
			return 'Male';
		}
		if ( 'F' === $sex ) {
			return 'Female';
		}

		return 'Unknown';
	}

	private function format_gedcom_date( $date ) {
		$date = trim( (string) $date );
		if ( preg_match( '/^(\d{4})-?(\d{2})-?(\d{2})$/', $date, $matches ) ) {
			$months = $this->gedcom_months();
			return (int) $matches[3] . ' ' . $months[ (int) $matches[2] ] . ' ' . $matches[1];
		}
		if ( preg_match( '/^\d{4}$/', $date ) ) {
			return $date;
		}

		return $this->clean_gedcom_value( $date );
	}

	private function gedcom_months() {
		return array(
			1  => 'JAN',
			2  => 'FEB',
			3  => 'MAR',
			4  => 'APR',
			5  => 'MAY',
			6  => 'JUN',
			7  => 'JUL',
			8  => 'AUG',
			9  => 'SEP',
			10 => 'OCT',
			11 => 'NOV',
			12 => 'DEC',
		);
	}

	private function clean_gedcom_value( $value ) {
		return trim( preg_replace( '/[\r\n]+/', ' ', wp_strip_all_tags( (string) $value ) ) );
	}

	private function family_key_for_couple( $person_id, $spouse_id ) {
		$ids = array( (int) $person_id, (int) $spouse_id );
		sort( $ids );

		return 'm:' . implode( ':', $ids );
	}

	private function error_message( $error ) {
		$messages = array(
			'missing_file'   => __( 'Please choose a GEDCOM file to import.', 'familypedia' ),
			'file_too_large' => sprintf(
				// translators: %s is a file size, for example 2 MB.
				__( 'The GEDCOM file is larger than the maximum upload size of %s.', 'familypedia' ),
				size_format( wp_max_upload_size() )
			),
			'upload_failed'  => __( 'The GEDCOM file could not be uploaded.', 'familypedia' ),
			'store_failed'   => __( 'The GEDCOM file could not be stored for review.', 'familypedia' ),
			'empty_file'     => __( 'The uploaded GEDCOM file was empty.', 'familypedia' ),
			'no_individuals' => __( 'The GEDCOM file does not contain individual records.', 'familypedia' ),
			'no_selection'   => __( 'Please select at least one GEDCOM person to import.', 'familypedia' ),
			'review_expired' => __( 'The GEDCOM import review expired. Please upload the file again.', 'familypedia' ),
		);

		return isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'The GEDCOM import failed.', 'familypedia' );
	}
}
