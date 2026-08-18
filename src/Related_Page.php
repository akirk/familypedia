<?php
/**
 * Additional pages under a person: a chronology, a house, anything with no
 * facts of its own — just a title and, like everywhere else in the app, text
 * written in the block editor.
 *
 * @package Familypedia
 */

namespace Familypedia;

class Related_Page {
	const ACTION = 'familypedia_save_related_page';

	public function __construct() {
		add_action( 'wp_loaded', array( $this, 'maybe_save' ) );
	}

	public static function can_create( $parent_id ) {
		return $parent_id && current_user_can( 'edit_post', $parent_id );
	}

	/**
	 * Handle the form before any template output, so that saving can
	 * redirect back to the page.
	 */
	public function maybe_save() {
		if ( empty( $_POST['familypedia_action'] ) || self::ACTION !== $_POST['familypedia_action'] ) {
			return;
		}

		$post_id   = isset( $_POST['related_id'] ) ? absint( $_POST['related_id'] ) : 0;
		$parent_id = isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0;

		check_admin_referer( self::ACTION . '_' . $post_id . '_' . $parent_id );

		$parent = get_post( $parent_id );
		if ( ! $parent || Person::POST_TYPE !== $parent->post_type ) {
			wp_die( esc_html__( 'That person could not be found.', 'familypedia' ), 404 );
		}

		if ( $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to edit this page.', 'familypedia' ), 403 );
			}
		} elseif ( ! self::can_create( $parent_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to add pages here.', 'familypedia' ), 403 );
		}

		$title = isset( $_POST['post_title'] ) ? sanitize_text_field( wp_unslash( $_POST['post_title'] ) ) : '';
		if ( '' === $title ) {
			Editor::set_notice( __( 'A page needs a title.', 'familypedia' ), 'error' );
			wp_safe_redirect( $post_id ? Person::edit_url( $post_id ) : self::add_url( $parent_id ) );
			exit;
		}

		$data = array(
			'post_type'   => Person::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_parent' => $parent_id,
		);

		if ( $post_id ) {
			$data['ID'] = $post_id;
			$result     = wp_update_post( wp_slash( $data ), true );
		} else {
			$result = wp_insert_post( wp_slash( $data ), true );
		}

		if ( is_wp_error( $result ) ) {
			Editor::set_notice( $result->get_error_message(), 'error' );
			wp_safe_redirect( $post_id ? Person::edit_url( $post_id ) : self::add_url( $parent_id ) );
			exit;
		}

		Main::flush_family_data_cache( $parent_id );

		$is_new  = ! $post_id;
		$post_id = (int) $result;

		Editor::set_notice( __( 'Saved.', 'familypedia' ) );

		// A new page has nothing written yet, so go straight to where that
		// happens; an existing one goes back to its own page, like the
		// facts form does.
		wp_safe_redirect( $is_new ? get_edit_post_link( $post_id, '' ) : Person::url( $post_id ) );
		exit;
	}

	public static function add_url( $person ) {
		$post = get_post( $person );
		if ( ! $post ) {
			return home_url( '/' . App::URL_PATH . '/' );
		}

		return home_url( '/' . App::URL_PATH . '/' . Person::path( $post ) . '/add-page/' );
	}
}
