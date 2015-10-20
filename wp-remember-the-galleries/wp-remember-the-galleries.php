<?php
/*
Plugin Name: WP "remember the galleries"
Description: Lightweight galleries
Author: Ben Doherty @ Oomph, Inc.
Version: 0.0.1
Author URI: http://www.oomphinc.com/thinking/author/bdoherty/
License: GPLv2 or later

		Copyright © 2015 Oomph, Inc. <http://oomphinc.com>

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * WordPress "Remember the Galleries"
 *
 * Lightweight gallery implementation using a custom taxonomy
 * and a custom postype.
 *
 * Extend native gallery shortcode to allow for named galleries.
 *
 * Track objects with taxonomies, save metadata in CPT.
 */
class WP_Remember_The_Galleries {
	/**
	 * The generic name used for both CPT and taxonomy
	 */
	const entity = 'wp_rtg';

	/**
	 * The gallery attachments
	 */
	static $attachments = array();

	/**
	 * Register actions / filters, object types.
	 *
	 * Registers a taxonomy and a custom post type each with the same
	 * type identifier given in self::entity.
	 */
	static function _setup() {
		$c = get_called_class();

		add_action( 'admin_enqueue_scripts', array( $c, 'enqueue_scripts' ) );
		add_action( 'print_media_templates', array( $c, 'print_media_templates' ) );

		add_action( 'wp_ajax_rtg_save_gallery', array( $c, 'save_gallery' ) );
		add_action( 'wp_ajax_rtg_gallery_search', array( $c, 'gallery_search' ) );
		add_action( 'wp_ajax_rtg_query_attachments', array( $c, 'query_attachments' ) );
		add_action( 'init', array( $c, 'action_init_post_type' ) );

		// Accept 'slug' in [gallery] shortcode and emit a saved gallery
		add_action( 'shortcode_atts_gallery', array( $c, 'munge_shortcode' ), 10, 3 );

		add_filter( 'manage_' . self::entity . '_posts_custom_column', array( $c, 'render_custom_columns' ), 15, 2 );
		add_filter( 'manage_edit-' . self::entity . '_columns',  array( $c, 'manage_columns' ) );
		add_filter( 'bulk_actions-edit-wp_rtg', array( $c, 'bulk_actions' ) );

		if ( is_admin() ) {
			add_filter( 'clean_url', array( $c, 'clean_url' ), 10, 3 );
		}
	}

	static function action_init_post_type() {
		$labels = array(
			'name'              => __( "Galleries" ),
			'singular_name'     => __( "Gallery" ),
			'search_items'      => __( "Search Galleries" ),
			'popular_items'     => __( "Top Galleries" ),
			'all_items'         => __( "All Galleries" ),
			'parent_item'       => __( "Parent gallery" ),
			'parent_item_colon' => __( "Parent gallery:" ),
			'edit_item'         => __( "Edit gallery" ),
			'update_item'       => __( "Update Gallery" ),
			'add_new_item'      => __( "Add New Gallery" ),
			'new_item_name'     => __( "New Gallery" )
		);

		register_post_type( self::entity, array(
			'labels' => $labels,
			'show_ui' => true,
			'public' => true,
			'map_meta_cap' => true,
			'show_in_menu' => 'upload.php',
			'supports' => array(
				'title',
				'editor',
				'thumbnail'
			),
			'hierarchical' => false,
			'has_archive' => false,
			'taxonomies' => array(),
		) );

		register_taxonomy( self::entity, 'attachment', array(
			'labels'        => $labels,
			'public'        => false,
		) );
	}

	static function query_attachments() {
		if( !isset( $_REQUEST['ids'] ) || !is_array( $_REQUEST['ids'] ) ) {
			self::json_error();
		}

		$ids = array_filter( array_unique( array_map( 'absint', $_REQUEST['ids'] ) ) );

		if( !current_user_can( 'upload_files' ) ) {
			self::json_error();
		}

		$result = array();

		foreach( $ids as $id ) {
			$result[] = wp_prepare_attachment_for_js( $id );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Insert "image" column
	 */
	static function manage_columns( $columns ) {
		unset( $columns['posts'], $columns['title'] );
		return array(
			'cb' => $columns['cb'],
			'gallerytitle' => __( "Title" ),
			'images' => __( "Images" ),
			'date' => $columns['date']
		);
	}

	/**
	 * Render "Image" column
	 */
	static function render_custom_columns( $column, $post_id ) {
		switch ( $column ) {
		case 'gallerytitle' :
		case 'images' :
			$objects = get_post_meta( $post_id, 'order', true );
			$term = get_term_by( 'slug', self::entity . '-' . $post_id, self::entity );
			$label = $column == 'gallerytitle' ? $term->name : count( $objects );
			$classes = array('open-gallery-edit');
			$before = $after = '';

			if( $column == 'gallerytitle' ) {
				$classes[] = 'row-title';
				$before = '<strong>';
				$after = '</strong>';
			}

			if( $objects ) {
				echo $before . '<a class="' . join( ' ', $classes ) . '" href="javascript:void(0);" data-name="' . esc_attr( $term->name ) . '" data-id="' . esc_attr( $term->term_id ) . '" data-ids="' . esc_attr( join( ',', $objects ) ) . '">' . $label .  $after;

				if( $column == 'images' ) {
					echo '<br />';

					foreach( array_slice( $objects, 0, 10 ) as $attachment_id ) {
						echo '<img width="40" src="' . esc_url( wp_get_attachment_thumb_url( $attachment_id ) ) . '" />&nbsp;';
					}

					if( count( $objects ) > 10 ) {
						echo '&nbsp;&hellip;';
					}
				}

				echo '</a>';

				if( $column == 'gallerytitle' ) {
					echo '<div class="row-actions">';
					echo '<span class="trash"><a class="submitdelete" href="' . get_delete_post_link( $post_id ) . '">' . __( 'Trash' ) . '</a></span>';
					echo '</div>';
				}
			}
			break;
		}
	}

	/**
	 * Render relevant media templates
	 *
	 * @action print_media_templates
	 */
	static function print_media_templates() {
		require_once( __DIR__ . '/media-templates.php' );
	}

	/**
	 * Register and enqueue gallery management scripts.
	 *
	 * @action init
	 */
	static function enqueue_scripts() {
		global $current_screen;

		wp_register_script( 'wp-rtg', plugins_url( 'wp-remember-the-galleries.js', __FILE__ ), array( 'jquery-ui-autocomplete' ), 1, true );

		$js_object = array(
			'select-gallery' => __( 'Select gallery or name a new gallery...', 'boston-chefs' ),
			'load-gallery' => __( 'Load', 'boston-chefs' ),
			'new-gallery' => __( 'New gallery...', 'boston-chefs' ),
			'are-you-sure' => __( 'Are you sure you want to replace the images in the gallery "%s"?', 'boston-chefs' ),
			'errors' => array(
				'empty-name' => __( 'Empty gallery name', 'boston-chefs' ),
				'invalid-input' => __( 'Missing IDs', 'boston-chefs' ),
				'need-confirm' => __( 'Are you sure you want to overwrite the gallery "%s"?', 'boston-chefs' )
			)
		);

		if( $current_screen->id === 'upload' || $current_screen->id === get_post_type() ||
				( $current_screen->base === 'edit' && $current_screen->post_type == self::entity ) ) {
			global $post;

			// Detect shortcode slugs and add them to the localization so the editor
			// knows what's in the galleries
			$regex = get_shortcode_regex();

			if( $post && preg_match_all( '/' . $regex . '/', $post->post_content, $matches ) ) {
				foreach( $matches as $match ) {
					if( $match[2] == 'gallery' ) {
						$atts = parse_shortcode_atts( $match[3] );

						if( isset( $atts['slug'] ) ) {
							$term = get_term_by( 'slug', $atts['slug'], self::entity );
							$order = get_post_meta( $post_id, 'order', true );
							$out['ids'] = join(',', $order);

							$js_object['slugs'][$atts['slug']] = $out;
						}
					}
				}
			}

			wp_enqueue_media();
			wp_enqueue_script( 'wp-rtg' );
			wp_enqueue_style( 'wp-rtg', plugins_url( 'rtg-admin.css', __FILE__ ), array(), 1 );
		}

		wp_localize_script( 'wp-rtg', 'wpRememberTheGalleries',  $js_object );
	}

	/**
	 * Send an error message and any additional data
	 */
	static function json_error( $message, $data = array() ) {
		wp_send_json_error( array(
			'message' => $message,
			'data' => $data
		) );
	}

	/**
	 * Process AJAX request to save a gallery. Key galleries by title, just
	 * because it's easier that way and makes it editorially simpler to identify
	 * different galleries.
	 */
	static function save_gallery() {
		$input = filter_input_array( INPUT_POST, array(
			'images' => array(
				'filter' => FILTER_VALIDATE_INT,
				'flags' => FILTER_REQUIRE_ARRAY,
				'options' => array( 'min_range' => 1 )
			),
			'name' => array(
				'filter' => FILTER_SANITIZE_STRING,
				'flags' => FILTER_REQUIRE_SCALAR
			),
			'term_id' => FILTER_VALIDATE_INT,
			'settings' => array(
				'flags' => FILTER_REQUIRE_ARRAY
			),
			'yes' => FILTER_VALIDATE_BOOLEAN
		) );

		extract( $input );

		if( !isset( $images ) ) {
			self::json_error( 'invalid-input' );
		}

		if( empty( $images ) ) {
			self::json_error( 'no-images' );
		}

		$ids = array();
		$captions = array();

		foreach( $images as $image ) {
			if( is_array( $image ) && isset( $image['id'] ) && (int) $image['id'] > 0 ) {
				$id = (int) $image['id'];

				$ids[] = $id;

				if( isset( $image['caption'] ) && is_string( $image['caption'] ) ) {
					$captions[$id] = sanitize_text_field( $image['caption'] );
				}
			}
			else {
				self::json_error( 'invalid-input' );
			}

		}

		if( empty( $ids ) ) {
			self::json_error( 'invalid-input' );
		}

		$gallery_name = sanitize_text_field( trim( $name ) );

		if( empty( $gallery_name ) ) {
			self::json_error( 'empty-name' );
		}

		// Does a gallery with this name already exist?
		$term_info = term_exists( $gallery_name, self::entity );
		$allow_rename = true;
		if ( isset( $term_info['term_id'] ) && $term_info['term_id'] != $term_id ) {
			// Ask user to confirm that they want to overwrite an existing gallery
			if ( !$yes ) {
				$this->json_error( 'need-confirm', array( 'post_name' => $gallery_name ) );
			}
			// User has confirmed they want to overwrite the existing gallery, so let's use that term id
			else {
				$allow_rename = false;
				$term_id = $term_info['term_id'];
			}
		}

		if( !$term_id ) {
			$post_id = wp_insert_post( array(
				'post_title' => $gallery_name,
				'post_type' => self::entity,
				'post_status' => 'publish'
			) );

			if( is_wp_error( $post_id ) ) {
				$this->json_error( $post_id );
			}

			$term_info = wp_insert_term( $gallery_name, self::entity, array( 'slug' => self::entity . '-' . $post_id ) );

			if( is_wp_error( $term_info ) ) {
				$this->json_error( $term_info->get_error_message() );
			}

			$term_id = $term_info['term_id'];
		}
		else {
			$post_id = self::get_post_id( $term_id );
		}

		if( $term_id && $allow_rename ) {
			wp_update_term( $term_id, self::entity, array( 'name' => $gallery_name ) );

			$updated = wp_update_post( array(
				'ID' => $post_id,
				'post_title' => $gallery_name,
				'post_name' => null
			) );

			if ( is_wp_error( $updated ) ) {
				$this->json_error( $post_id );
			}
		}

		foreach( $ids as $id ) {
			wp_set_object_terms( (int) $id, $term_id, self::entity, true );
		}

		update_post_meta( $post_id, 'order', $ids );
		update_post_meta( $post_id, 'captions', $captions );

		$settings = filter_var_array( $settings, array(
			'columns' => FILTER_VALIDATE_INT,
			'link' => FILTER_SANITIZE_STRING,
			'size' => FILTER_VALIDATE_INT,
			'random' => FILTER_VALIDATE_BOOLEAN
		) );

		if( !empty( $settings ) ) {
			update_post_meta( $post_id, 'settings', $settings );
		}

		_update_generic_term_count( $term_id, self::entity );
		wp_send_json_success( 'gallery-saved' );
	}

	/**
	 * Get the attachments associated with a particular gallery, by term ID
	 */
	static function get_attachments( $term_id ) {
		global $wpdb;

		if( !isset(self::$attachments[ $term_id ] ) ) {
			// NOTE: we don't use get_objects_in_term() here because we
			// need to be able to differentiate between attachments and
			// the actual gallery post. Using a custom query we can get
			// the info we need all in one swoop.
			$results = $wpdb->get_results( $wpdb->prepare( "
				SELECT tr.object_id, p.post_type
				FROM $wpdb->term_relationships AS tr
				INNER JOIN $wpdb->term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN $wpdb->posts AS p ON p.ID = tr.object_id
				WHERE 1=1
					AND tt.taxonomy = %s
					AND tt.term_id = %d
			", self::entity, $term_id ) );

			self::$attachments[ $term_id ] = wp_list_pluck( $results, 'post_type', 'object_id' );
		}

		$post_id = array_search( self::entity, self::$attachments[ $term_id ] );

		$order = get_post_meta( $post_id, 'order', true );
		$attach_ids = array_keys( self::$attachments[ $term_id ], 'attachment' );
		$ids = array_intersect( $order, array_diff( $attach_ids, array( $post_id ) ) );

		return $ids;
	}

	/**
	 * Search for a gallery by name. This plugin tracks galleries as terms,
	 * and saves any associated metadata for those in an associated post object.
	 *
	 * As a corrolary to this, galleries have unique names. This is a design
	 * decision partially driven by the limitations in jQuery-ui-autocomplete.
	 */
	static function gallery_search() {
		global $wpdb;

		if( !isset( $_POST['term'] ) ) {
			return;
		}

		$get_term_args = array( 'number' => 10, 'hide_empty' => false );
		if( !empty( $_POST['term'] ) ) {
			$get_term_args['search'] = $_POST['term'];
		}

		$galleries = get_terms( self::entity, $get_term_args );

		// Add ids for quick loading
		foreach( $galleries as $gallery ) {
			$gallery->ids = self::get_attachments( $gallery->term_id );
		}

		// Exlude associated post object from IDs
		wp_send_json_success( $galleries );
	}

	static function munge_shortcode( $out, $pairs, $atts ) {
		if( isset( $atts['slug'] ) ) {
			$term = get_term_by( 'slug', $atts['slug'], self::entity );

			if( $term ) {
				// Get the post and its meta:
				$post_id = self::get_post_id( $term );
				$order = get_post_meta( $post_id, 'order', true );

				$out['ids'] = join(',', $order);
			}
		}

		return $out;
	}

	/**
	 * Return the post ID for a gallery term, if any
	 *
	 * @param term_id|WP_Term The term ID or object
	 * @return int|null
	 */
	static function get_post_id( $term ) {
		if( is_object( $term ) && isset( $term->term_id ) ) {
			$term_id = $term->term_id;
		}
		elseif( intval( $term ) == $term ) {
			$term_id = $term;
		}
		else {
			throw new InvalidArgumentException( '$term is expected to be a term object or term ID' );
		}

		$term = get_term( $term_id, self::entity );

		if ( $term && preg_match( '/-(\d+)$/', $term->slug, $matches ) ) {
			return (int) $matches[1];
		}
	}

	// Remove "edit from bulk actions dropdown
	static function bulk_actions( $actions ) {
		unset( $actions['edit'] );
		return $actions;
	}

	// change the "add new" url
	static function clean_url( $clean_url, $unclean_url, $context ) {
		if ( strpos( $clean_url, 'post-new.php?post_type=' . self::entity ) !== false ) {
			return 'javascript:void(0);';
		}

		return $clean_url;
	}
}
WP_Remember_The_Galleries::_setup();
