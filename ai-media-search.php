<?php
/**
 * Plugin Name: AI Media Search
 * Plugin URI:  https://github.com/adamsilverstein/wp-ai-media-search
 * Description: Uses WordPress AI to generate searchable descriptions for media library images.
 * Version:     0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Author:      Adam Silverstein
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap the plugin. All hooks are registered here, gated on AI support.
 */
function ai_media_search_init() {
	if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
		return;
	}

	// New upload processing.
	add_action( 'add_attachment', 'ai_media_search_on_new_attachment' );

	// Post publish processing.
	add_action( 'transition_post_status', 'ai_media_search_on_publish', 10, 3 );

	// Cron handlers.
	add_action( 'ai_media_search_process_single', 'ai_media_search_process_single' );
	add_action( 'ai_media_search_batch_process', 'ai_media_search_batch_process' );

	// Search integration.
	add_filter( 'posts_join', 'ai_media_search_filter_posts_join', 10, 2 );
	add_filter( 'posts_search', 'ai_media_search_filter_posts_search', 10, 2 );
	add_filter( 'posts_groupby', 'ai_media_search_filter_posts_groupby', 10, 2 );

	// Self-healing: re-schedule batch cron if it was lost.
	if ( ! wp_next_scheduled( 'ai_media_search_batch_process' ) ) {
		wp_schedule_event( time(), 'hourly', 'ai_media_search_batch_process' );
	}
}
add_action( 'init', 'ai_media_search_init' );

/**
 * On activation, schedule the batch processing cron.
 */
function ai_media_search_activate() {
	if ( ! wp_next_scheduled( 'ai_media_search_batch_process' ) ) {
		wp_schedule_event( time(), 'hourly', 'ai_media_search_batch_process' );
	}
}
register_activation_hook( __FILE__, 'ai_media_search_activate' );

/**
 * On deactivation, clear the scheduled cron. Meta data is preserved.
 */
function ai_media_search_deactivate() {
	wp_clear_scheduled_hook( 'ai_media_search_batch_process' );
}
register_deactivation_hook( __FILE__, 'ai_media_search_deactivate' );

// ─── New Upload Processing ───────────────────────────────────────────────────

/**
 * Queue a newly uploaded image for AI processing.
 *
 * @param int $post_id Attachment post ID.
 */
function ai_media_search_on_new_attachment( $post_id ) {
	if ( ! wp_attachment_is_image( $post_id ) ) {
		return;
	}

	/**
	 * Filters whether a specific attachment should be processed.
	 *
	 * @param bool $should_process Whether to process this attachment. Default true.
	 * @param int  $post_id        Attachment post ID.
	 */
	if ( ! apply_filters( 'ai_media_search_should_process', true, $post_id ) ) {
		return;
	}

	update_post_meta( $post_id, '_wp_ai_media_search_status', 'pending' );
	wp_schedule_single_event( time() + 5, 'ai_media_search_process_single', array( $post_id ) );
}

// ─── Post Publish Processing ─────────────────────────────────────────────────

/**
 * When a post is published, queue any unprocessed images found in its content.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       Post object.
 */
function ai_media_search_on_publish( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}

	$attachment_ids = ai_media_search_extract_image_ids( $post->post_content );

	foreach ( $attachment_ids as $attachment_id ) {
		$status = get_post_meta( $attachment_id, '_wp_ai_media_search_status', true );

		if ( in_array( $status, array( 'complete', 'processing' ), true ) ) {
			continue;
		}

		if ( ! apply_filters( 'ai_media_search_should_process', true, $attachment_id ) ) {
			continue;
		}

		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'pending' );
		wp_schedule_single_event( time() + 5, 'ai_media_search_process_single', array( $attachment_id ) );
	}
}

/**
 * Extract image attachment IDs from post content.
 *
 * Supports Gutenberg image blocks and classic editor img classes.
 *
 * @param string $content Post content.
 * @return int[] Array of unique attachment IDs.
 */
function ai_media_search_extract_image_ids( $content ) {
	$ids = array();

	// Gutenberg image blocks: <!-- wp:image {"id":123} -->
	if ( preg_match_all( '/wp:image\s+\{"id":(\d+)/', $content, $matches ) ) {
		$ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
	}

	// Classic editor: class="wp-image-123"
	if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
		$ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
	}

	return array_unique( array_filter( $ids ) );
}

// ─── AI Metadata Generation ─────────────────────────────────────────────────

/**
 * Generate AI search metadata for an image attachment.
 *
 * @param int $attachment_id Attachment post ID.
 * @return array|WP_Error Structured metadata array on success, WP_Error on failure.
 */
function ai_media_search_generate_metadata( $attachment_id ) {
	$file_path = get_attached_file( $attachment_id );

	if ( ! $file_path || ! file_exists( $file_path ) ) {
		return new WP_Error(
			'ai_media_search_file_missing',
			__( 'Attachment file not found.', 'ai-media-search' )
		);
	}

	$mime_type = get_post_mime_type( $attachment_id );

	$default_prompt = 'Analyze this image and provide: '
		. '1) A detailed description suitable for search (2-3 sentences covering the main subject, setting, colors, mood, and any actions or text visible). '
		. '2) A comma-separated list of 15-25 search tags covering objects, people, animals, colors, concepts, emotions, settings, and styles present in the image. '
		. 'Return JSON with keys "description" and "tags".';

	/**
	 * Filters the AI prompt used for image analysis.
	 *
	 * @param string $prompt        The prompt text.
	 * @param int    $attachment_id Attachment post ID.
	 */
	$prompt = apply_filters( 'ai_media_search_prompt', $default_prompt, $attachment_id );

	$result = wp_ai_client_prompt( $prompt )
		->with_file( $file_path, $mime_type )
		->as_json_response(
			array(
				'type'       => 'object',
				'properties' => array(
					'description' => array( 'type' => 'string' ),
					'tags'        => array( 'type' => 'string' ),
				),
				'required'   => array( 'description', 'tags' ),
			)
		)
		->generate_text();

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$decoded = json_decode( $result, true );

	if ( ! is_array( $decoded ) || empty( $decoded['description'] ) || ! isset( $decoded['tags'] ) ) {
		return new WP_Error(
			'ai_media_search_invalid_response',
			__( 'AI returned an invalid response structure.', 'ai-media-search' )
		);
	}

	return array(
		'description'  => sanitize_text_field( $decoded['description'] ),
		'tags'         => sanitize_text_field( $decoded['tags'] ),
		'generated_at' => time(),
		'version'      => 1,
		'media_type'   => 'image',
	);
}

// ─── Single Image Processing ─────────────────────────────────────────────────

/**
 * Process a single image: generate AI metadata and store it.
 *
 * @param int $attachment_id Attachment post ID.
 */
function ai_media_search_process_single( $attachment_id ) {
	$post = get_post( $attachment_id );

	if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $attachment_id ) ) {
		return;
	}

	$status = get_post_meta( $attachment_id, '_wp_ai_media_search_status', true );

	if ( in_array( $status, array( 'complete', 'processing' ), true ) ) {
		return;
	}

	update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'processing' );

	$metadata = ai_media_search_generate_metadata( $attachment_id );

	if ( is_wp_error( $metadata ) ) {
		ai_media_search_handle_failure( $attachment_id, $metadata );
		return;
	}

	// Store structured data.
	update_post_meta( $attachment_id, '_wp_ai_media_search_data', $metadata );

	// Store plain searchable text.
	$search_text = $metadata['description'] . ' ' . $metadata['tags'];
	update_post_meta( $attachment_id, '_wp_ai_media_search_text', $search_text );

	// Mark complete.
	update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'complete' );
	delete_post_meta( $attachment_id, '_wp_ai_media_search_error' );
}

// ─── Error Handling ──────────────────────────────────────────────────────────

/**
 * Handle a processing failure with retry tracking.
 *
 * @param int      $attachment_id Attachment post ID.
 * @param WP_Error $error         The error that occurred.
 */
function ai_media_search_handle_failure( $attachment_id, $error ) {
	$existing = get_post_meta( $attachment_id, '_wp_ai_media_search_error', true );
	$attempts = is_array( $existing ) ? (int) ( $existing['attempts'] ?? 0 ) : 0;
	$attempts++;

	/** This filter is documented in ai_media_search_batch_process */
	$max_retries = (int) apply_filters( 'ai_media_search_max_retries', 3 );

	$error_data = array(
		'code'       => $error->get_error_code(),
		'message'    => $error->get_error_message(),
		'attempts'   => $attempts,
		'last_tried' => time(),
	);

	update_post_meta( $attachment_id, '_wp_ai_media_search_error', $error_data );

	if ( $attempts >= $max_retries ) {
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'skipped' );
	} else {
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'failed' );
	}
}

// ─── Batch Cron Processing ───────────────────────────────────────────────────

/**
 * Process a batch of unprocessed images. Runs on WP Cron hourly.
 */
function ai_media_search_batch_process() {
	if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
		return;
	}

	/**
	 * Filters the number of images to process per batch.
	 *
	 * @param int $batch_size Number of images per batch. Default 5.
	 */
	$batch_size = (int) apply_filters( 'ai_media_search_batch_size', 5 );

	$query = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_wp_ai_media_search_status',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => '_wp_ai_media_search_status',
					'value' => 'pending',
				),
				array(
					'key'   => '_wp_ai_media_search_status',
					'value' => 'failed',
				),
			),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'posts_per_page' => $batch_size,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	if ( empty( $query->posts ) ) {
		return;
	}

	/** This filter is documented in ai_media_search_handle_failure */
	$max_retries = (int) apply_filters( 'ai_media_search_max_retries', 3 );

	foreach ( $query->posts as $attachment_id ) {
		// For failed items, check retry eligibility.
		$status = get_post_meta( $attachment_id, '_wp_ai_media_search_status', true );

		if ( 'failed' === $status ) {
			$error = get_post_meta( $attachment_id, '_wp_ai_media_search_error', true );

			if ( is_array( $error ) ) {
				if ( ( $error['attempts'] ?? 0 ) >= $max_retries ) {
					continue;
				}

				// Skip if last attempt was less than 1 hour ago.
				if ( ( $error['last_tried'] ?? 0 ) > ( time() - HOUR_IN_SECONDS ) ) {
					continue;
				}
			}
		}

		ai_media_search_process_single( $attachment_id );
	}
}

// ─── Search Integration ──────────────────────────────────────────────────────

/**
 * Check if a WP_Query is an attachment search that we should modify.
 *
 * @param WP_Query $query The query to check.
 * @return bool
 */
function ai_media_search_is_attachment_search( $query ) {
	if ( ! $query->is_search() ) {
		return false;
	}

	$post_type = $query->get( 'post_type' );

	if ( 'attachment' === $post_type ) {
		return true;
	}

	if ( is_array( $post_type ) && in_array( 'attachment', $post_type, true ) ) {
		return true;
	}

	return false;
}

/**
 * Add a LEFT JOIN on postmeta for AI search text.
 *
 * Mirrors the pattern in WP_Query::get_posts() for filename search.
 *
 * @param string   $join  The JOIN clause.
 * @param WP_Query $query The query object.
 * @return string Modified JOIN clause.
 */
function ai_media_search_filter_posts_join( $join, $query ) {
	if ( ! ai_media_search_is_attachment_search( $query ) ) {
		return $join;
	}

	global $wpdb;

	$join .= " LEFT JOIN {$wpdb->postmeta} AS ai_media_search_meta"
		. " ON ( {$wpdb->posts}.ID = ai_media_search_meta.post_id"
		. " AND ai_media_search_meta.meta_key = '_wp_ai_media_search_text' )";

	return $join;
}

/**
 * Add AI search meta to the search WHERE clause.
 *
 * For each search term, adds an OR condition matching against the AI-generated
 * search text. Mirrors the pattern in WP_Query::parse_search().
 *
 * @param string   $search The search WHERE clause.
 * @param WP_Query $query  The query object.
 * @return string Modified search clause.
 */
function ai_media_search_filter_posts_search( $search, $query ) {
	if ( ! ai_media_search_is_attachment_search( $query ) || empty( $search ) ) {
		return $search;
	}

	global $wpdb;

	$search_terms = $query->get( 'search_terms' );

	if ( empty( $search_terms ) ) {
		return $search;
	}

	foreach ( $search_terms as $term ) {
		$like = '%' . $wpdb->esc_like( $term ) . '%';
		$or_clause = $wpdb->prepare( ' OR (ai_media_search_meta.meta_value LIKE %s)', $like );

		// Insert the OR clause before the closing parenthesis of each term's group.
		// The search clause structure is: AND ((col1 LIKE %s) OR (col2 LIKE %s))
		// We find the term's LIKE pattern and append our OR before the group closes.
		$escaped_like = $wpdb->prepare( '%s', $like );
		$needle       = "({$wpdb->posts}.post_content LIKE {$escaped_like})";
		$replacement  = $needle . $or_clause;

		$search = str_replace( $needle, $replacement, $search );
	}

	return $search;
}

/**
 * Ensure GROUP BY includes post ID to prevent duplicates from the JOIN.
 *
 * @param string   $groupby The GROUP BY clause.
 * @param WP_Query $query   The query object.
 * @return string Modified GROUP BY clause.
 */
function ai_media_search_filter_posts_groupby( $groupby, $query ) {
	if ( ! ai_media_search_is_attachment_search( $query ) ) {
		return $groupby;
	}

	global $wpdb;

	$group_id = "{$wpdb->posts}.ID";

	if ( empty( $groupby ) || ! str_contains( $groupby, $group_id ) ) {
		$groupby = $group_id;
	}

	return $groupby;
}
