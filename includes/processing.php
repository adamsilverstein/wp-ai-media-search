<?php
/**
 * Image processing: single-item processing, error handling, and batch cron.
 *
 * @package AI_Media_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the supported MIME type prefixes for processing.
 *
 * @return string[] Array of MIME type prefixes (e.g., 'image', 'video', 'audio').
 */
function ai_media_search_get_supported_mime_types() {
	/**
	 * Filters the supported MIME type prefixes.
	 *
	 * Add 'video' or 'audio' to extend processing beyond images.
	 *
	 * @param string[] $mime_types Array of MIME type prefixes. Default array( 'image' ).
	 */
	return apply_filters( 'ai_media_search_supported_mime_types', array( 'image' ) );
}

/**
 * Check whether an attachment's MIME type is supported for processing.
 *
 * @param int $attachment_id Attachment post ID.
 * @return bool
 */
function ai_media_search_is_supported_attachment( $attachment_id ) {
	$mime = get_post_mime_type( $attachment_id );

	if ( ! $mime ) {
		return false;
	}

	$type_prefix      = strtok( $mime, '/' );
	$supported_types  = ai_media_search_get_supported_mime_types();

	return in_array( $type_prefix, $supported_types, true );
}

/**
 * Process a single image: generate AI metadata and store it.
 *
 * Uses a transient-based lock to prevent duplicate AI calls under concurrent
 * cron execution.
 *
 * @param int $attachment_id Attachment post ID.
 */
function ai_media_search_process_single( $attachment_id ) {
	// Acquire a per-attachment lock to prevent duplicate AI calls under concurrent cron execution.
	$lock_key = "ai_media_search_lock_{$attachment_id}";
	if ( get_transient( $lock_key ) ) {
		return;
	}
	set_transient( $lock_key, 1, 10 * MINUTE_IN_SECONDS );

	$post = get_post( $attachment_id );

	if ( ! $post || 'attachment' !== $post->post_type || ! ai_media_search_is_supported_attachment( $attachment_id ) ) {
		delete_transient( $lock_key );
		return;
	}

	$status = get_post_meta( $attachment_id, '_wp_ai_media_search_status', true );

	if ( in_array( $status, array( 'complete', 'processing' ), true ) ) {
		delete_transient( $lock_key );
		return;
	}

	update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'processing' );

	$metadata = ai_media_search_generate_metadata( $attachment_id );

	if ( is_wp_error( $metadata ) ) {
		ai_media_search_handle_failure( $attachment_id, $metadata );
		delete_transient( $lock_key );
		return;
	}

	/**
	 * Filters the AI-generated metadata before it is stored.
	 *
	 * Allows plugins to modify, enrich, or translate the description and tags.
	 *
	 * @param array $metadata      The metadata array (description, tags, generated_at, version, media_type).
	 * @param int   $attachment_id Attachment post ID.
	 */
	$metadata = apply_filters( 'ai_media_search_metadata', $metadata, $attachment_id );

	// Store structured data.
	update_post_meta( $attachment_id, '_wp_ai_media_search_data', $metadata );

	// Store plain searchable text.
	$search_text = $metadata['description'] . ' ' . $metadata['tags'];

	/**
	 * Filters the concatenated search text before it is stored.
	 *
	 * Allows plugins to append extra keywords (e.g., EXIF data, taxonomy terms).
	 *
	 * @param string $search_text   The search text (description + tags).
	 * @param array  $metadata      The full metadata array.
	 * @param int    $attachment_id Attachment post ID.
	 */
	$search_text = apply_filters( 'ai_media_search_search_text', $search_text, $metadata, $attachment_id );

	update_post_meta( $attachment_id, '_wp_ai_media_search_text', $search_text );

	// Optionally populate empty alt text for accessibility.
	/**
	 * Filters whether to set alt text from the AI description when it is empty.
	 *
	 * @param bool $update_alt    Whether to update empty alt text. Default false.
	 * @param int  $attachment_id Attachment post ID.
	 */
	if ( apply_filters( 'ai_media_search_update_alt_text', false, $attachment_id ) ) {
		$existing_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		if ( empty( $existing_alt ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $metadata['description'] );
		}
	}

	// Mark complete.
	update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'complete' );
	delete_post_meta( $attachment_id, '_wp_ai_media_search_error' );
	delete_transient( $lock_key );

	/**
	 * Fires after an image has been successfully processed.
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $metadata      The generated metadata (description, tags, etc.).
	 */
	do_action( 'ai_media_search_processed', $attachment_id, $metadata );
}

/**
 * Reset AI search metadata for an attachment so it can be re-processed.
 *
 * @param int $attachment_id Attachment post ID.
 */
function ai_media_search_reset( $attachment_id ) {
	delete_post_meta( $attachment_id, '_wp_ai_media_search_status' );
	delete_post_meta( $attachment_id, '_wp_ai_media_search_data' );
	delete_post_meta( $attachment_id, '_wp_ai_media_search_text' );
	delete_post_meta( $attachment_id, '_wp_ai_media_search_error' );
	delete_transient( "ai_media_search_lock_{$attachment_id}" );
}

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

	/**
	 * Fires when processing an attachment fails.
	 *
	 * @param int      $attachment_id Attachment post ID.
	 * @param WP_Error $error         The error that occurred.
	 * @param array    $error_data    Error tracking data (code, message, attempts, last_tried).
	 */
	do_action( 'ai_media_search_failed', $attachment_id, $error, $error_data );
}

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
	$batch_size = max( 1, min( 50, (int) apply_filters( 'ai_media_search_batch_size', 5 ) ) );

	$query = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => ai_media_search_get_supported_mime_types(),
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
	$processed   = 0;

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
		$processed++;
	}

	/**
	 * Fires after a batch cron run completes.
	 *
	 * @param int $processed Number of attachments processed in this batch.
	 */
	do_action( 'ai_media_search_batch_complete', $processed );
}

/**
 * Get processing status counts for all images.
 *
 * @return array Associative array of status => count.
 */
function ai_media_search_get_status_counts() {
	global $wpdb;

	// Build MIME type WHERE clause from supported types.
	$mime_types  = ai_media_search_get_supported_mime_types();
	$mime_wheres = array();

	foreach ( $mime_types as $type ) {
		$mime_wheres[] = $wpdb->prepare( 'post_mime_type LIKE %s', $wpdb->esc_like( $type ) . '/%' );
	}

	$mime_clause = '(' . implode( ' OR ', $mime_wheres ) . ')';

	$total = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit' AND {$mime_clause}"
	);

	$statuses = array( 'complete', 'processing', 'pending', 'failed', 'skipped' );
	$counts   = array();

	foreach ( $statuses as $status ) {
		$counts[ $status ] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_ai_media_search_status'
				WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND {$mime_clause} AND pm.meta_value = %s",
				$status
			)
		);
	}

	$tracked                = array_sum( $counts );
	$counts['unprocessed']  = $total - $tracked;
	$counts['total']        = $total;

	return $counts;
}
