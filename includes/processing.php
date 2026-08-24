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

	$type_prefix     = strtok( $mime, '/' );
	$supported_types = ai_media_search_get_supported_mime_types();

	return in_array( $type_prefix, $supported_types, true );
}

/**
 * Get the number of seconds after which an in-flight run is presumed dead.
 *
 * A PHP timeout, a fatal error or a restarted worker can end a run between the
 * `processing` status being written and the AI call returning. Nothing gets a
 * chance to clean up in that case, so the plugin waits this long before it
 * treats the run as abandoned and lets the attachment be picked up again.
 *
 * @return int Timeout in seconds. Never less than one minute.
 */
function ai_media_search_get_processing_timeout() {
	/**
	 * Filters how long an attachment may sit in `processing` before the run
	 * that owns it is presumed dead and the attachment is reprocessed.
	 *
	 * Raise this on hosts where AI requests are slow; the value is clamped to a
	 * minimum of one minute so an in-flight run is never reclaimed instantly.
	 *
	 * @param int $timeout Timeout in seconds. Default 15 minutes.
	 */
	$timeout = (int) apply_filters( 'ai_media_search_processing_timeout', 15 * MINUTE_IN_SECONDS );

	return max( MINUTE_IN_SECONDS, $timeout );
}

/**
 * Determine whether an attachment left in `processing` has been abandoned.
 *
 * Age comes from the start time recorded when the run began, falling back to
 * the timestamp stored in the lock for rows stranded before start times were
 * tracked. With neither available there is no way to tell a dead run from a
 * live one, so the attachment is left alone rather than risking a duplicate
 * AI call.
 *
 * @phpstan-impure
 *
 * @param int $attachment_id Attachment post ID.
 * @return bool Whether the run that owns the attachment has timed out.
 */
function ai_media_search_is_stale_processing( $attachment_id ) {
	if ( 'processing' !== get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) ) {
		return false;
	}

	$started = (int) get_post_meta( $attachment_id, '_wp_ai_media_search_started', true );

	if ( ! $started ) {
		$started = (int) get_option( "ai_media_search_lock_{$attachment_id}", 0 );
	}

	if ( ! $started ) {
		return false;
	}

	return $started < ( time() - ai_media_search_get_processing_timeout() );
}

/**
 * Determine whether an attachment is eligible for processing.
 *
 * Enforces the same rules for all callers:
 * - Must be an existing attachment with a supported MIME type
 * - Must not already be complete
 * - Must not be skipped (permanent give-up)
 * - If processing, the run that owns it must have timed out
 * - If failed, must be past the retry cooldown and under max retries
 *
 * Reads live post meta, so the answer can change between calls - callers
 * deliberately re-check after taking the processing lock.
 *
 * @phpstan-impure
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

	if ( in_array( $status, array( 'complete', 'skipped' ), true ) ) {
		return false;
	}

	// An in-flight run blocks a second one, unless it has been abandoned.
	if ( 'processing' === $status && ! ai_media_search_is_stale_processing( $attachment_id ) ) {
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
 * The lock stores the time it was taken and expires: a holder that dies without
 * releasing it would otherwise wedge the attachment forever, so a lock older
 * than the processing timeout is taken over.
 *
 * The returned value is the token the lock now holds. Pass it back to
 * ai_media_search_release_lock() so a run whose lock was taken over cannot
 * release the lock that replaced it.
 *
 * @param int $attachment_id Attachment post ID.
 * @return int|false The lock token, or false when another run holds the lock.
 */
function ai_media_search_acquire_lock( $attachment_id ) {
	global $wpdb;

	$lock_key = "ai_media_search_lock_{$attachment_id}";
	$token    = time();

	// add_option returns false if the option already exists, making this atomic.
	// Suppress errors from duplicate-key failures. Passing false for $autoload
	// keeps the lock out of the autoloaded options; the 'no' string form is
	// deprecated as of WordPress 6.7.
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( @add_option( $lock_key, $token, '', false ) ) {
		return $token;
	}

	$held_since = get_option( $lock_key );

	// The holder released the lock in the moment between the failed insert and
	// this read. Bowing out keeps the function to the single atomic insert
	// above; the attachment comes round again on the next pass.
	if ( false === $held_since ) {
		return false;
	}

	// A lock inside the timeout belongs to a run that is still going.
	if ( (int) $held_since > ( time() - ai_media_search_get_processing_timeout() ) ) {
		return false;
	}

	// Take the expired lock over with a compare-and-swap. Reading the value and
	// then writing it in two statements is not enough: two workers can both see
	// the same expired lock, both replace it, and both start an AI call on the
	// same attachment. Conditioning the write on the exact value read means the
	// first writer wins and every other one matches zero rows.
	//
	// A stale cached read is safe here too - the WHERE clause simply matches
	// nothing and the caller backs off.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The options API has no compare-and-swap, and only a conditional UPDATE makes the claim atomic. The option cache is cleared immediately below.
	$claimed = $wpdb->update(
		$wpdb->options,
		array( 'option_value' => (string) $token ),
		array(
			'option_name'  => $lock_key,
			'option_value' => maybe_serialize( $held_since ),
		)
	);

	// The row was written behind get_option()'s back, so drop the cached copy
	// the way the options API would have.
	wp_cache_delete( $lock_key, 'options' );

	return ( 1 === $claimed ) ? $token : false;
}

/**
 * Release a per-attachment lock.
 *
 * Pass the token returned by ai_media_search_acquire_lock() to release only
 * that lock. A run that overshoots the timeout can have its lock taken over
 * while it is still going, and an unconditional delete would then remove the
 * new holder's lock on the way out, letting a third run start alongside it.
 * Conditioning the delete on the token means such a run can only ever remove
 * the lock it actually took.
 *
 * @param int      $attachment_id Attachment post ID.
 * @param int|null $token         Optional. Release only while the lock still
 *                                holds this token. Default null, which drops
 *                                the lock whoever holds it.
 */
function ai_media_search_release_lock( $attachment_id, $token = null ) {
	global $wpdb;

	$lock_key = "ai_media_search_lock_{$attachment_id}";

	if ( null === $token ) {
		delete_option( $lock_key );
		return;
	}

	// Conditional delete, atomic for the same reason the takeover above is.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- delete_option() cannot be made conditional on the current value. The option cache is cleared immediately below.
	$wpdb->delete(
		$wpdb->options,
		array(
			'option_name'  => $lock_key,
			'option_value' => (string) $token,
		)
	);

	wp_cache_delete( $lock_key, 'options' );
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

	// Acquire atomic lock. If a live run holds it, another worker has it. The
	// token identifies this run's claim, so the lock is only released again if
	// it is still the one this run took.
	$lock_token = ai_media_search_acquire_lock( $attachment_id );

	if ( false === $lock_token ) {
		return;
	}

	// Re-check eligibility after acquiring the lock in case state changed.
	//
	// A row still marked `processing` here was abandoned: the check above only
	// let it through because it had timed out, and holding the lock means no
	// other worker is on it. It is deliberately not re-tested for staleness,
	// because taking over the lock stamps it afresh.
	$status = get_post_meta( $attachment_id, '_wp_ai_media_search_status', true );

	if ( 'processing' !== $status && ! ai_media_search_can_process_attachment( $attachment_id ) ) {
		ai_media_search_release_lock( $attachment_id, $lock_token );
		return;
	}

	update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'processing' );

	// Stamp the run so a later one can tell whether this one is still alive.
	update_post_meta( $attachment_id, '_wp_ai_media_search_started', time() );

	$metadata = ai_media_search_generate_metadata( $attachment_id );

	if ( is_wp_error( $metadata ) ) {
		ai_media_search_handle_failure( $attachment_id, $metadata );
		ai_media_search_release_lock( $attachment_id, $lock_token );
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
	delete_post_meta( $attachment_id, '_wp_ai_media_search_started' );
	ai_media_search_release_lock( $attachment_id, $lock_token );

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
	delete_post_meta( $attachment_id, '_wp_ai_media_search_started' );
	ai_media_search_release_lock( $attachment_id );
}

/**
 * Clear an attachment's AI metadata and process it again immediately.
 *
 * Regeneration is the one path where the stored state is deliberately thrown
 * away: `ai_media_search_process_single()` refuses anything already complete or
 * skipped, so the reset is what makes the attachment eligible again. The two
 * steps live together here so every caller resets and reprocesses in the same
 * order, and so a caller cannot leave an attachment wiped but unprocessed.
 *
 * @param int $attachment_id Attachment post ID.
 */
function ai_media_search_regenerate_attachment( $attachment_id ) {
	ai_media_search_reset( $attachment_id );
	ai_media_search_process_single( $attachment_id );

	/**
	 * Fires after an attachment has been regenerated on request.
	 *
	 * Unlike `ai_media_search_processed`, this fires whether the run succeeded
	 * or failed, so it is the hook to use for auditing manual retries.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $status        Resulting status meta value.
	 */
	do_action(
		'ai_media_search_regenerated',
		$attachment_id,
		(string) get_post_meta( $attachment_id, '_wp_ai_media_search_status', true )
	);
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
	++$attempts;

	/** This filter is documented in ai_media_search_can_process_attachment */
	$max_retries = (int) apply_filters( 'ai_media_search_max_retries', 3 );

	$error_data = array(
		'code'       => $error->get_error_code(),
		'message'    => $error->get_error_message(),
		'attempts'   => $attempts,
		'last_tried' => time(),
	);

	update_post_meta( $attachment_id, '_wp_ai_media_search_error', $error_data );

	// The run is over, so its start time no longer says anything.
	delete_post_meta( $attachment_id, '_wp_ai_media_search_started' );

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
 * Build the meta query that selects attachments still waiting for processing.
 *
 * Covers attachments the plugin has never seen, those queued or failed, and
 * those left in `processing` by a run that died before it could finish. A
 * stranded row is recognised by a start time past the timeout, or by having no
 * start time at all - which is how rows stranded before start times were
 * tracked look. Eligibility is re-checked per attachment before any AI call, so
 * a row that is merely in-flight is fetched and then passed over.
 *
 * @return array<int|string, mixed> Meta query for WP_Query.
 */
function ai_media_search_get_unprocessed_meta_query() {
	return array(
		'relation' => 'OR',
		array(
			'key'     => '_wp_ai_media_search_status',
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'     => '_wp_ai_media_search_status',
			'value'   => array( 'pending', 'failed' ),
			'compare' => 'IN',
		),
		array(
			'relation' => 'AND',
			array(
				'key'   => '_wp_ai_media_search_status',
				'value' => 'processing',
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => '_wp_ai_media_search_started',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_wp_ai_media_search_started',
					'value'   => time() - ai_media_search_get_processing_timeout(),
					'compare' => '<',
					'type'    => 'NUMERIC',
				),
			),
		),
	);
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
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Finding unprocessed attachments requires a meta comparison; the result set is capped by the batch size.
			'meta_query'     => ai_media_search_get_unprocessed_meta_query(),
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
		++$processed;
	}

	/**
	 * Fires after a batch cron run completes.
	 *
	 * @param int $processed Number of attachments processed in this batch.
	 */
	do_action( 'ai_media_search_batch_complete', $processed );
}

/**
 * Name of the transient holding the cached status counts.
 *
 * A transient rather than a plain object cache entry: the counts are worth
 * keeping between requests, and where a persistent object cache is installed
 * the transient API already stores them there instead of in the options table.
 */
const AI_MEDIA_SEARCH_STATUS_COUNTS_TRANSIENT = 'ai_media_search_status_counts';

/**
 * The statuses the plugin tracks, in the order they are reported.
 *
 * @return string[] Status names.
 */
function ai_media_search_get_tracked_statuses() {
	return array( 'complete', 'processing', 'pending', 'failed', 'skipped' );
}

/**
 * Get how long cached status counts are served before being recounted.
 *
 * Writes made through the plugin drop the cache as they happen, so this is a
 * backstop for the changes it cannot see: attachments deleted, statuses written
 * by something other than the post meta API, rows edited straight in the
 * database. Short enough that such a figure is never misleading for long.
 *
 * @return int Lifetime in seconds. Zero or less disables the cache entirely.
 */
function ai_media_search_get_status_counts_ttl() {
	/**
	 * Filters how long the processing status counts are cached.
	 *
	 * Return zero or less to recount on every call, at the cost of two queries
	 * per admin page load and per hit to the status endpoint.
	 *
	 * @param int $ttl Cache lifetime in seconds. Default 5 minutes.
	 */
	return (int) apply_filters( 'ai_media_search_status_counts_ttl', 5 * MINUTE_IN_SECONDS );
}

/**
 * Discard the cached status counts.
 */
function ai_media_search_flush_status_counts() {
	delete_transient( AI_MEDIA_SEARCH_STATUS_COUNTS_TRANSIENT );
}

/**
 * Discard the cached counts whenever a processing status is written.
 *
 * Hooking the meta API rather than each individual write catches every caller
 * at once - processing runs, the upload and publish hooks, WP-CLI, and anything
 * a site adds of its own - so the admin never reports a figure that a status
 * change has already made wrong.
 *
 * @param int|int[] $meta_id   Meta ID, or IDs on delete. Unused.
 * @param int       $object_id Attachment post ID. Unused.
 * @param string    $meta_key  Meta key that changed.
 */
function ai_media_search_flush_status_counts_on_meta_change( $meta_id, $object_id, $meta_key ) {
	unset( $meta_id, $object_id );

	if ( '_wp_ai_media_search_status' === $meta_key ) {
		ai_media_search_flush_status_counts();
	}
}
add_action( 'added_post_meta', 'ai_media_search_flush_status_counts_on_meta_change', 10, 3 );
add_action( 'updated_post_meta', 'ai_media_search_flush_status_counts_on_meta_change', 10, 3 );
add_action( 'deleted_post_meta', 'ai_media_search_flush_status_counts_on_meta_change', 10, 3 );

/**
 * Get processing status counts for all images.
 *
 * Two queries: one for the library total, one grouped count covering every
 * status at once. The result is cached, since both the settings screen and the
 * status endpoint ask for it repeatedly and it changes only as slowly as
 * processing runs.
 *
 * @return array<string, int> Associative array of status => count. Always
 *                            carries every tracked status, plus `unprocessed`
 *                            and `total`.
 */
function ai_media_search_get_status_counts() {
	global $wpdb;

	$mime_types = ai_media_search_get_supported_mime_types();
	$ttl        = ai_media_search_get_status_counts_ttl();

	if ( $ttl > 0 ) {
		$cached = get_transient( AI_MEDIA_SEARCH_STATUS_COUNTS_TRANSIENT );

		// The supported types are filterable, and they decide what gets counted.
		// A payload built under a different set of types answers a different
		// question, so it is recounted rather than returned.
		if ( isset( $cached['mime_types'], $cached['counts'] ) && $cached['mime_types'] === $mime_types ) {
			return $cached['counts'];
		}
	}

	// Build MIME type WHERE clause from supported types.
	$mime_wheres = array();

	foreach ( $mime_types as $type ) {
		$mime_wheres[] = $wpdb->prepare( 'post_mime_type LIKE %s', $wpdb->esc_like( $type ) . '/%' );
	}

	$mime_clause = '(' . implode( ' OR ', $mime_wheres ) . ')';

	// $mime_clause is assembled above entirely from $wpdb->prepare() output, so
	// it is already safe to interpolate. Both results are cached in the
	// transient written at the end of this function.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit' AND {$mime_clause}" );

	$rows = $wpdb->get_results(
		"SELECT pm.meta_value AS status, COUNT(*) AS num FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_ai_media_search_status'
		WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND {$mime_clause}
		GROUP BY pm.meta_value",
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// GROUP BY returns nothing at all for a status no attachment is in, and
	// every caller indexes these keys directly. Starting from a full set of
	// zeroes is what keeps a quiet status present rather than missing.
	$counts = array_fill_keys( ai_media_search_get_tracked_statuses(), 0 );

	foreach ( (array) $rows as $row ) {
		$status = (string) $row['status'];

		// A status the plugin does not track is left out of the tally, so it
		// falls through into `unprocessed` the way it always has.
		if ( isset( $counts[ $status ] ) ) {
			$counts[ $status ] = (int) $row['num'];
		}
	}

	$counts['unprocessed'] = $total - array_sum( $counts );
	$counts['total']       = $total;

	if ( $ttl > 0 ) {
		set_transient(
			AI_MEDIA_SEARCH_STATUS_COUNTS_TRANSIENT,
			array(
				'mime_types' => $mime_types,
				'counts'     => $counts,
			),
			$ttl
		);
	}

	return $counts;
}
