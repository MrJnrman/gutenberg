<?php
/**
 * Temporary compatibility shims for features present in Gutenberg.
 * This file should be removed when WordPress 6.1.0 becomes the lowest
 * supported version by this plugin.
 *
 * @package gutenberg
 */

/**
 * Updates the list of default template types, containing their
 * localized titles and descriptions.
 *
 * We will only need to update `get_default_block_template_types` function.
 *
 * @param array $default_template_types The default template types.
 *
 * @return array The default template types.
 */
function gutenberg_get_default_block_template_types( $default_template_types ) {
	if ( isset( $default_template_types['single'] ) ) {
		$default_template_types['single'] = array(
			'title'       => _x( 'Single', 'Template name', 'gutenberg' ),
			'description' => __( 'The default template for displaying any single post or attachment.', 'gutenberg' ),
		);
	}
	return $default_template_types;
}
add_filter( 'default_template_types', 'gutenberg_get_default_block_template_types', 10 );


/**
 * Retrieves a list of unified template objects based on a query.
 *
 * @param array $query {
 *     Optional. Arguments to retrieve templates.
 *
 *     @type array  $slug__in  List of slugs to include.
 *     @type int    $wp_id     Post ID of customized template.
 *     @type string $area      A 'wp_template_part_area' taxonomy value to filter by (for wp_template_part template type only).
 *     @type string $post_type Post type to get the templates for.
 * }
 * @param array $template_type wp_template or wp_template_part.
 *
 * @return array Templates.
 */
function gutenberg_get_block_templates( $query = array(), $template_type = 'wp_template' ) {
	/**
	 * Filters the block templates array before the query takes place.
	 *
	 * Return a non-null value to bypass the WordPress queries.
	 *
	 * @since 10.8
	 *
	 * @param Gutenberg_Block_Template[]|null $block_templates Return an array of block templates to short-circuit the default query,
	 *                                                  or null to allow WP to run it's normal queries.
	 * @param array $query {
	 *     Optional. Arguments to retrieve templates.
	 *
	 *     @type array  $slug__in List of slugs to include.
	 *     @type int    $wp_id Post ID of customized template.
	 *     @type string $post_type Post type to get the templates for.
	 * }
	 * @param array $template_type wp_template or wp_template_part.
	 */
	$templates = apply_filters( 'pre_get_block_templates', null, $query, $template_type );
	if ( ! is_null( $templates ) ) {
		return $templates;
	}

	$post_type     = isset( $query['post_type'] ) ? $query['post_type'] : '';
	$wp_query_args = array(
		'post_status'    => array( 'auto-draft', 'draft', 'publish' ),
		'post_type'      => $template_type,
		'posts_per_page' => -1,
		'no_found_rows'  => true,
		'tax_query'      => array(
			array(
				'taxonomy' => 'wp_theme',
				'field'    => 'name',
				'terms'    => wp_get_theme()->get_stylesheet(),
			),
		),
	);

	if ( 'wp_template_part' === $template_type && isset( $query['area'] ) ) {
		$wp_query_args['tax_query'][]           = array(
			'taxonomy' => 'wp_template_part_area',
			'field'    => 'name',
			'terms'    => $query['area'],
		);
		$wp_query_args['tax_query']['relation'] = 'AND';
	}

	if ( isset( $query['slug__in'] ) ) {
		$wp_query_args['post_name__in'] = $query['slug__in'];
	}

	// This is only needed for the regular templates/template parts CPT listing and editor.
	if ( isset( $query['wp_id'] ) ) {
		$wp_query_args['p'] = $query['wp_id'];
	} else {
		$wp_query_args['post_status'] = 'publish';
	}

	$template_query = new WP_Query( $wp_query_args );
	$query_result   = array();
	foreach ( $template_query->posts as $post ) {
		$template = gutenberg_build_block_template_result_from_post( $post );
		if ( is_wp_error( $template ) ) {
			continue;
		}

		if ( $post_type && ! $template->is_custom ) {
			continue;
		}

		$query_result[] = $template;
	}
	if ( ! isset( $query['wp_id'] ) ) {
		$template_files = _get_block_templates_files( $template_type );
		foreach ( $template_files as $template_file ) {
			$template = _build_block_template_result_from_file( $template_file, $template_type );

			if ( $post_type && ! $template->is_custom ) {
				continue;
			}

			if ( $post_type &&
				isset( $template->post_types ) &&
				! in_array( $post_type, $template->post_types, true )
			) {
				continue;
			}

			$is_not_custom   = false === array_search(
				wp_get_theme()->get_stylesheet() . '//' . $template_file['slug'],
				array_column( $query_result, 'id' ),
				true
			);
			$fits_slug_query =
				! isset( $query['slug__in'] ) || in_array( $template_file['slug'], $query['slug__in'], true );
			$fits_area_query =
				! isset( $query['area'] ) || $template_file['area'] === $query['area'];
			$should_include  = $is_not_custom && $fits_slug_query && $fits_area_query;
			if ( $should_include ) {
				$query_result[] = $template;
			}
		}
	}
	/**
	 * Filters the array of queried block templates array after they've been fetched.
	 *
	 * @since 10.8
	 *
	 * @param Gutenberg_Block_Template[] $query_result Array of found block templates.
	 * @param array $query {
	 *     Optional. Arguments to retrieve templates.
	 *
	 *     @type array  $slug__in List of slugs to include.
	 *     @type int    $wp_id Post ID of customized template.
	 * }
	 * @param array $template_type wp_template or wp_template_part.
	 */
	return apply_filters( 'get_block_templates', $query_result, $query, $template_type );
}

function gutenberg_get_template_slugs( $template ) {
	$limit = 2;
	if ( strpos( $template, 'single-' ) === 0 || strpos( $template, 'taxonomy-' ) === 0 ) {
		// E.g. single-post-mypost or taxonomy-recipes-vegetarian.
		$limit = 3;
	}
	$parts = explode( '-', $template, $limit );
	$type  = array_shift( $parts );
	$slugs = array( $type );

	foreach ( $parts as $part ) {
		array_unshift( $slugs, $slugs[0] . '-' . $part );
	}
	return $slugs;
}

function gutenberg_get_block_template_type_for_slug( $slug ) {
	$slug_parts = explode( '-', $slug, 3 );

	if ( count( $slug_parts ) > 1 ) {
		if ( 'single' === $slug_parts [0] ) {
			// Get CPT labels
			$post_type = get_post_type_object( $slug_parts[1] );
			$labels    = $post_type->labels;

			if ( count( $slug_parts ) > 2 ) {
				// Now we look for the CPT with slug as defined in $slug_parts[2]
				$post  = get_page_by_path( $slug_parts[2], OBJECT, $slug_parts[1] );
				$title = sprintf(
					// translators: Represents the title of a user's custom template in the Site Editor, where %1$s is the singular name of a post type and %2$s is the name of the post, e.g. "Post: Hello, WordPress"
					__( '%1$s: %2$s' ),
					$labels->singular_name,
					$post->post_title
				);
				$description = sprintf(
						// translators: Represents the description of a user's custom template in the Site Editor, e.g. "Template for Post: Hello, WordPress"
					__( 'Template for %1$s' ),
					$title
				);
			} else {
				$title = sprintf(
					// translators: %s: Name of the post type e.g: "Post".
					__( 'Single item: %s' ),
					$labels->singular_name
				);
				$description = sprintf(
					// translators: %s: Name of the post type e.g: "Post".
					__( 'Displays a single item: %s.' ),
					$labels->singular_name
				);
			}
		}
	} else {
		$default_template_types = get_default_block_template_types();

		if ( array_key_exists( $slug, $default_template_types ) ) {
			$title       = $default_template_types[ $slug ]['title'];
			$description = $default_template_types[ $slug ]['description'];
		}
	}

	return array(
		'title'       => $title,
		'description' => $description,
	);
}

/**
 * Retrieves a single unified template object using its id.
 *
 * @param string $id Template unique identifier (example: theme_slug//template_slug).
 * @param array  $template_type wp_template or wp_template_part.
 *
 * @return Gutenberg_Block_Template|null Template.
 */
function gutenberg_get_block_template( $id, $template_type = 'wp_template' ) {
	/**
	 * Filters the block template object before the query takes place.
	 *
	 * Return a non-null value to bypass the WordPress queries.
	 *
	 * @since 10.8
	 *
	 * @param Gutenberg_Block_Template|null $block_template Return block template object to short-circuit the default query,
	 *                                               or null to allow WP to run it's normal queries.
	 * @param string $id Template unique identifier (example: theme_slug//template_slug).
	 * @param array  $template_type wp_template or wp_template_part.
	 */
	$block_template = apply_filters( 'pre_get_block_template', null, $id, $template_type );
	if ( ! is_null( $block_template ) ) {
		return $block_template;
	}

	$parts = explode( '//', $id, 2 );
	if ( count( $parts ) < 2 ) {
		return null;
	}
	list( , $slug ) = $parts;

	$templates      = gutenberg_get_template_slugs( $slug );
	$block_template = resolve_block_template( $slug, $templates, '' );
	if ( ! $block_template ) {
		$block_template = resolve_block_template( 'index', array(), '' );
	}
	// This might give us a fallback template with a different ID,
	// so we have to override it to make sure it's correct.
	$block_template->id   = $id;
	$block_template->slug = $slug;

	$template_type_info          = gutenberg_get_block_template_type_for_slug();
	$block_template->title       = $template_type_info['title'];
	$block_template->description = $template_type_info['description'];

	/**
	 * Filters the queried block template object after it's been fetched.
	 *
	 * @since 10.8
	 *
	 * @param Gutenberg_Block_Template|null $block_template The found block template, or null if there isn't one.
	 * @param string $id Template unique identifier (example: theme_slug//template_slug).
	 * @param array  $template_type wp_template or wp_template_part.
	 */
	return apply_filters( 'get_block_template', $block_template, $id, $template_type );
}

/**
 * Build a unified template object based a post Object.
 *
 * @param WP_Post $post Template post.
 *
 * @return Gutenberg_Block_Template|WP_Error Template.
 */
function gutenberg_build_block_template_result_from_post( $post ) {
	$default_template_types = get_default_block_template_types();
	$terms                  = get_the_terms( $post, 'wp_theme' );

	if ( is_wp_error( $terms ) ) {
		return $terms;
	}

	if ( ! $terms ) {
		return new WP_Error( 'template_missing_theme', __( 'No theme is defined for this template.', 'gutenberg' ) );
	}

	$origin           = get_post_meta( $post->ID, 'origin', true );
	$is_wp_suggestion = get_post_meta( $post->ID, 'is_wp_suggestion', true );

	$theme          = $terms[0]->name;
	$has_theme_file = wp_get_theme()->get_stylesheet() === $theme &&
		null !== _get_block_template_file( $post->post_type, $post->post_name );

	$template                 = new WP_Block_Template();
	$template->wp_id          = $post->ID;
	$template->id             = $theme . '//' . $post->post_name;
	$template->theme          = $theme;
	$template->content        = $post->post_content;
	$template->slug           = $post->post_name;
	$template->source         = 'custom';
	$template->origin         = ! empty( $origin ) ? $origin : null;
	$template->type           = $post->post_type;
	$template->description    = $post->post_excerpt;
	$template->title          = $post->post_title;
	$template->status         = $post->post_status;
	$template->has_theme_file = $has_theme_file;
	$template->is_custom      = empty( $is_wp_suggestion );
	$template->author         = $post->post_author;

	// We keep this check for existent templates that are part of the template hierarchy.
	if ( 'wp_template' === $post->post_type && isset( $default_template_types[ $template->slug ] ) ) {
		$template->is_custom = false;
	}

	if ( 'wp_template_part' === $post->post_type ) {
		$type_terms = get_the_terms( $post, 'wp_template_part_area' );
		if ( ! is_wp_error( $type_terms ) && false !== $type_terms ) {
			$template->area = $type_terms[0]->name;
		}
	}
	return $template;
}
