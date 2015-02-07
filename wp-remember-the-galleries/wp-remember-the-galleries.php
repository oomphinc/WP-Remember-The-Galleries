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

		// Accept 'slug' in [gallery] shortcode and emit a saved gallery
		add_action( 'shortcode_atts_gallery', array( $c, 'munge_shortcode' ), 10, 2 );

		add_filter( 'manage_' . self::entity . '_custom_column', array( $c, 'render_custom_columns' ), 15, 3 );
		add_filter( 'manage_edit-' . self::entity . '_columns',  array( $c, 'manage_columns' ) );

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
			'show_ui' => false,
			'public' => true,
			'menu_position' => 9,
			'map_meta_cap' => true,
			'supports' => array(
				'title',
				'editor',
				'thumbnail'
			),
			'hierarchical' => false,
			'has_archive' => false,
			'capability_type' => self::entity,
			'taxonomies' => array(),
		) );

		register_taxonomy( self::entity, 'attachment', array(
			'labels'        => $labels,
			'public'        => true,
			'show_in_nav_menus' => false,
			'show_ui'       => true,
			'capabilities'  => array(
				'manage_terms' => 'manage_' . self::entity,
				'edit_terms'   => 'edit_' . self::entity,
				'delete_terms' => 'delete_' . self::entity,
				'assign_terms' => 'edit_posts'
			)
		) );
	}

	/**
	 * Insert "image" column
	 */
	static function manage_columns( $columns ) {
		unset( $columns['posts'] );
		$columns['images'] = __( "Images" );
		return $columns;
	}

	/**
	 * Render "Image" column
	 */
	function render_custom_columns( $row, $column_name, $term_id ) {
		if( $column_name === 'images' ) {
			$objects = get_objects_in_term( $term_id, self::entity );

			return '<a class="open-gallery-edit" href="javascript:void(0);" data-ids="' . esc_attr( join( ',', $objects ) ) . '">' . count( $objects ) . '</a>';
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

		wp_register_script( 'wp-rtg', plugins_url( 'wp-remember-the-galleries.js', basename( __DIR__ ) ), array( 'jquery-ui-autocomplete' ), 1, true );

		$js_object = array(
			'select-gallery' => __( "Select gallery or name a new gallery..." ),
			'load-gallery' => __( "Load" ),
			'new-gallery' => __( "New gallery..." ),
			'are-you-sure' => __( "Are you sure you want to replace the images in this gallery?" ),
			'errors' => array(
				'empty-name' => __( "Empty gallery name" ),
				'invalid-input' => __( "Missing IDs" ),
				'need-confirm' => __( "Are you sure you want to replace this gallery?" )
			)
		);

		if( $current_screen->id === 'upload' || $current_screen->id === 'post' ||
				( $current_screen->base === 'edit-tags' && $current_screen->taxonomy == self::entity ) ) {
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
			wp_enqueue_style( 'wp-rtg', plugins_url( 'rtg-admin.css', basename( __DIR__ ) ), array(), 1 );
		}

		wp_localize_script( 'wp-rtg', 'wpRememberTheGalleries',  $js_object );
	}

	/**
	 * Process AJAX request to save a gallery. Key galleries by title, just
	 * because it's easier that way and makes it editorially simpler to identify
	 * different galleries.
	 */
	static function save_gallery() {
		if( !isset( $_POST['images'] ) || !is_array( $_POST['images'] ) ) {
			wp_send_json_error( 'invalid-input' );
		}

		$ids = array();
		$captions = array();

		foreach( $_POST['images'] as $image ) {
			if( is_array( $image ) && isset( $image['id'] ) && (int) $image['id'] > 0 ) {
				$id = (int) $image['id'];

				$ids[] = $id;

				if( isset( $image['caption'] ) && is_string( $image['caption'] ) ) {
					$captions[$id] = sanitize_text_field( $image['caption'] );
				}
			}
		}

		if( empty( $ids ) ) {
			wp_send_json_error( 'invalid-input' );
		}

		if( !isset( $_POST['name'] ) || empty( $_POST['name'] ) ) {
			wp_send_json_error( 'empty-name' );
		}

		$gallery_name = sanitize_text_field( trim( $_POST['name'] ) );

		if( empty( $gallery_name ) ) {
			wp_send_json_error( 'empty-name' );
		}

		$term_info = term_exists( $gallery_name, self::entity );

		if( !isset( $term_info['term_id'] ) ) {
			$term_info = wp_insert_term( $gallery_name, self::entity );
		}

		if( is_wp_error( $term_info ) ) {
			wp_send_json_error( $term_info );
		}

		$term = get_term( $term_info['term_id'], self::entity );

		$post_id = self::get_post_id( $term );

		if( !$post_id ) {
			$post_id = wp_insert_post( array(
				'post_type' => self::entity,
				'post_name' => $post_name,
				'post_title' => $gallery_name
			) );
		}

		if( is_wp_error( $post_id ) ) {
			wp_send_json_error( $post_id );
		}

		$settings = array(
			'columns' => null,
			'link' => null,
			'size' => null,
			'random' => null
		);

		if( isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ) {
			foreach( $settings as $setting => $default ) {
				if( isset( $_POST['settings'][$setting] ) ) {
					$settings[$setting] = $_POST['settings'][$setting];
				}
			}
		}

		$objects = get_objects_in_term( $term->term_id, self::entity );

		if( !empty( $objects ) && !isset( $_POST['yes'] ) ) {
			wp_send_json_error( 'need-confirm' );
		}

		foreach( $ids as $id ) {
			wp_set_object_terms( (int) $id, (int) $term->term_id, self::entity, true );
		}

		update_post_meta( $post_id, 'order', $ids );
		update_post_meta( $post_id, 'captions', $captions );

		if( !empty( $settings ) ) {
			update_post_meta( $post_id, 'settings', $captions );
		}

		_update_generic_term_count( $term->term_id, $term->taxonomy );
		wp_send_json_success( 'gallery-saved' );
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
	}

	// Return the post ID for a gallery term, if any
	static function get_post_id( $term ) {
		$post_name = self::entity . '-' . $term->term_id;

		// Manage the gallery meta in a post with a predictable slug
		$post_query = new WP_Query( array(
			'post_type' => self::entity,
			'name' => $post_name,
			'post_status' => 'any',
			'fields' => 'ids'
		) );

		if( $post_query->have_posts() ) {
			return $post_query->next_post();
		}
	}
}
WP_Remember_The_Galleries::_setup();
