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
 * Get the wall clock budget a single batch run may spend, in seconds.
 *
 * Every item in a batch is a blocking AI request that can take anything from a
 * couple of seconds to most of a minute, so a batch of five can easily outlive
 * the request that started it. PHP then kills the run mid-call, which is
 * exactly what strands an attachment in `processing`.
 *
 * The budget is derived from `max_execution_time` and deliberately stops short
 * of it. Elapsed time is only checked between items, so whichever AI call is
 * running when the budget runs out still has to come back: the fifth of the
 * limit held in reserve is what that call gets to finish in. A limit of `0`
 * means PHP will not stop the script at all, which is the norm under WP-CLI and
 * on some hosts, so a few minutes is used instead of running indefinitely.
 *
 * The clock starts when the batch does. PHP measures its limit across the whole
 * request, so a cron request that has already spent time on other events has
 * less room left than this assumes - the reserved headroom absorbs the
 * difference, and a run that is killed anyway is recovered by the stale
 * processing timeout.
 *
 * @return int Budget in seconds. Never negative. Zero means each run processes
 *             a single attachment, since a run always makes progress.
 */
function ai_media_search_get_batch_time_budget() {
	$max_execution_time = (int) ini_get( 'max_execution_time' );

	if ( $max_execution_time > 0 ) {
		$budget = (int) floor( $max_execution_time * 0.8 );
	} else {
		$budget = 3 * MINUTE_IN_SECONDS;
	}

	/**
	 * Filters the wall clock budget for a single batch cron run.
	 *
	 * Raise this on hosts that allow long-running requests, or lower it to keep
	 * cron requests short. The budget is checked between items, so a run can
	 * overrun it by however long the last AI call takes; leave room for that.
	 *
	 * A run always processes at least one attachment, so no value here can stop
	 * the queue from draining.
	 *
	 * @param int $budget             Budget in seconds. Default 80% of
	 *                                `max_execution_time`, or 3 minutes when
	 *                                PHP imposes no limit.
	 * @param int $max_execution_time The `max_execution_time` the default was
	 *                                derived from. `0` means no limit.
	 */
	$budget = (int) apply_filters( 'ai_media_search_batch_time_budget', $budget, $max_execution_time );

	return max( 0, $budget );
}

/**
 * Schedule an extra batch run for work the current run could not reach.
 *
 * A run that stops on its time budget leaves the rest of its batch untouched,
 * and the recurring cron event is an hour away. Queueing a follow-up run lets a
 * backlog keep draining at whatever pace the site can manage, rather than
 * clearing one budget's worth of images an hour.
 *
 * Relies on the duplicate check in wp_schedule_single_event(), so follow-ups
 * never stack up behind each other or alongside a batch that is already due.
 */
function ai_media_search_schedule_batch_followup() {
	/**
	 * Filters the delay before a follow-up batch run.
	 *
	 * The follow-up only runs when a batch stops early with work left over.
	 * Return zero or less to turn follow-up runs off and wait for the next
	 * recurring batch instead.
	 *
	 * @param int $delay Delay in seconds. Default 2 minutes.
	 */
	$delay = (int) apply_filters( 'ai_media_search_batch_followup_delay', 2 * MINUTE_IN_SECONDS );

	if ( $delay <= 0 ) {
		return;
	}

	wp_schedule_single_event( time() + $delay, 'ai_media_search_batch_process' );
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

	$budget    = ai_media_search_get_batch_time_budget();
	$started   = microtime( true );
	$processed = 0;

	foreach ( $query->posts as $attachment_id ) {
		// Stop between items, never inside one. An item abandoned part way
		// through would still hold its lock and still read as `processing`,
		// and nothing would touch it again until the stale run timeout expired.
		// Anything this run does not reach is simply left as it was found, so
		// the next run picks it up from the same query.
		//
		// The first item always runs: a budget small enough to be spent before
		// any work happens would otherwise leave the queue stuck forever.
		if ( $processed > 0 && ( microtime( true ) - $started ) >= $budget ) {
			break;
		}

		// Eligibility (including retry cooldown) is enforced inside
		// ai_media_search_process_single() via the shared helper.
		ai_media_search_process_single( $attachment_id );
		++$processed;
	}

	$remaining = count( $query->posts ) - $processed;

	if ( $remaining > 0 ) {
		ai_media_search_schedule_batch_followup();
	}

	/**
	 * Fires after a batch cron run completes.
	 *
	 * @param int $processed Number of attachments processed in this batch.
	 * @param int $remaining Number of attachments the run left for the next one
	 *                       because its time budget was spent.
	 */
	do_action( 'ai_media_search_batch_complete', $processed, $remaining );
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

	// $mime_clause is assembled above entirely from $wpdb->prepare() output, so
	// it is already safe to interpolate. Counts are live figures shown in the
	// admin and over REST, so they are deliberately not cached.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit' AND {$mime_clause}" );

	$statuses = array( 'complete', 'processing', 'pending', 'failed', 'skipped' );
	$counts   = array();

	foreach ( $statuses as $status ) {
		// See the note above; $mime_clause is prepared and the counts are
		// intentionally uncached. The disable/enable pair is needed because the
		// interpolation falls on a continuation line of the SQL string.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$counts[ $status ] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_ai_media_search_status'
				WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND {$mime_clause} AND pm.meta_value = %s",
				$status
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	$tracked               = array_sum( $counts );
	$counts['unprocessed'] = $total - $tracked;
	$counts['total']       = $total;

	return $counts;
}
