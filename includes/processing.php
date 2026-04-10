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
 * Determine whether an attachment is eligible for processing.
 *
 * Enforces the same rules for all callers:
 * - Must be an existing attachment with a supported MIME type
 * - Must not already be complete or currently processing
 * - Must not be skipped (permanent give-up)
 * - If failed, must be past the retry cooldown and under max retries
 *
 * @param int $attachment_id Attachment post ID.
 * @return bool Whether the attachment can be processed now.
 */
function ai_media_search_can_process_attachment( $attachment_id ) {
	$post = get_post( $attachment_id );

	if ( ! $post || 'attachment' !== $post->post_type || ! ai_media_search_is_supported_attachment( $attachment_id ) ) {
		return false;
	}

	$status = get_post_meta( $attachment_id, '_wp_ai_media_search_status', true );

	if ( in_array( $status, array( 'complete', 'processing', 'skipped' ), true ) ) {
		return false;
	}

	if ( 'failed' === $status ) {
		/**
		 * Filters the maximum number of retry attempts before an attachment
		 * is marked as skipped.
		 *
		 * @param int $max_retries Max retry attempts. Default 3.
		 */
		$max_retries = (int) apply_filters( 'ai_media_search_max_retries', 3 );
		$error       = get_post_meta( $attachment_id, '_wp_ai_media_search_error', true );

		if ( is_array( $error ) ) {
			if ( ( $error['attempts'] ?? 0 ) >= $max_retries ) {
				return false;
			}

			// Enforce 1-hour cooldown between retries.
			if ( ( $error['last_tried'] ?? 0 ) > ( time() - HOUR_IN_SECONDS ) ) {
				return false;
			}
		}
	}

	return true;
}

/**
 * Attempt to acquire an atomic per-attachment lock.
 *
 * Uses add_option() which is atomic at the database level: only one concurrent
 * worker will succeed in inserting the row. The option is non-autoloaded so it
 * does not bloat the options cache.
 *
 * @param int $attachment_id Attachment post ID.
 * @return bool True if the lock was acquired, false if already held.
 */
function ai_media_search_acquire_lock( $attachment_id ) {
	$lock_key = "ai_media_search_lock_{$attachment_id}";

	// add_option returns false if the option already exists, making this atomic.
	// Suppress errors from duplicate-key failures.
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	return @add_option( $lock_key, time(), '', 'no' );
}

/**
 * Release a per-attachment lock.
 *
 * @param int $attachment_id Attachment post ID.
 */
function ai_media_search_release_lock( $attachment_id ) {
	delete_option( "ai_media_search_lock_{$attachment_id}" );
}

/**
 * Process a single image: generate AI metadata and store it.
 *
 * Uses an atomic option-based lock to prevent duplicate AI calls under
 * concurrent execution. Enforces retry/backoff rules via the shared
 * eligibility helper.
 *
 * @param int $attachment_id Attachment post ID.
 */
function ai_media_search_process_single( $attachment_id ) {
	// Check eligibility BEFORE acquiring the lock to avoid leaving stale locks.
	if ( ! ai_media_search_can_process_attachment( $attachment_id ) ) {
		return;
	}

	// Acquire atomic lock. If it's already held, another worker has it.
	if ( ! ai_media_search_acquire_lock( $attachment_id ) ) {
		return;
	}

	// Re-check eligibility after acquiring the lock in case state changed.
	if ( ! ai_media_search_can_process_attachment( $attachment_id ) ) {
		ai_media_search_release_lock( $attachment_id );
		return;
	}

	update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'processing' );

	$metadata = ai_media_search_generate_metadata( $attachment_id );

	if ( is_wp_error( $metadata ) ) {
		ai_media_search_handle_failure( $attachment_id, $metadata );
		ai_media_search_release_lock( $attachment_id );
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
	ai_media_search_release_lock( $attachment_id );

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
	ai_media_search_release_lock( $attachment_id );
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

	/** This filter is documented in ai_media_search_can_process_attachment */
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

	$processed = 0;

	foreach ( $query->posts as $attachment_id ) {
		// Eligibility (including retry cooldown) is enforced inside
		// ai_media_search_process_single() via the shared helper.
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
