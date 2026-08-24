<?php
/**
 * Tests for includes/processing.php.
 *
 * @package AI_Media_Search
 */

/**
 * Covers the eligibility rules, the lock, the retry state machine and the batch cron.
 */
class Test_AI_Media_Search_Processing extends AI_Media_Search_TestCase {

	/**
	 * Images are the only supported type out of the box.
	 *
	 * @covers ::ai_media_search_get_supported_mime_types
	 */
	public function test_supported_mime_types_default_to_images() {
		$this->assertSame( array( 'image' ), ai_media_search_get_supported_mime_types() );
	}

	/**
	 * The supported types filter widens what gets processed.
	 *
	 * @covers ::ai_media_search_is_supported_attachment
	 */
	public function test_supported_mime_types_can_be_extended() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'clip.mp4',
				'post_mime_type' => 'video/mp4',
			)
		);

		$this->assertFalse( ai_media_search_is_supported_attachment( $attachment_id ) );

		add_filter(
			'ai_media_search_supported_mime_types',
			static function ( $types ) {
				$types[] = 'video';
				return $types;
			}
		);

		$this->assertTrue( ai_media_search_is_supported_attachment( $attachment_id ) );
	}

	/**
	 * Only supported MIME types are eligible.
	 *
	 * @covers ::ai_media_search_is_supported_attachment
	 */
	public function test_unsupported_mime_type_is_not_supported() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'doc.pdf',
				'post_mime_type' => 'application/pdf',
			)
		);

		$this->assertFalse( ai_media_search_is_supported_attachment( $attachment_id ) );
	}

	/**
	 * A fresh image with no status is eligible.
	 *
	 * @covers ::ai_media_search_can_process_attachment
	 */
	public function test_unprocessed_image_can_be_processed() {
		$attachment_id = $this->create_image_attachment();

		$this->assertTrue( ai_media_search_can_process_attachment( $attachment_id ) );
	}

	/**
	 * Non-attachments and missing posts are never eligible.
	 *
	 * @covers ::ai_media_search_can_process_attachment
	 */
	public function test_non_attachments_cannot_be_processed() {
		$post_id = self::factory()->post->create();

		$this->assertFalse( ai_media_search_can_process_attachment( $post_id ) );
		$this->assertFalse( ai_media_search_can_process_attachment( 999999 ) );
	}

	/**
	 * Terminal and in-flight statuses block a second run.
	 *
	 * @dataProvider data_blocking_statuses
	 * @covers ::ai_media_search_can_process_attachment
	 *
	 * @param string $status Stored status value.
	 */
	public function test_blocking_statuses_prevent_processing( $status ) {
		$attachment_id = $this->create_image_attachment();
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', $status );

		$this->assertFalse( ai_media_search_can_process_attachment( $attachment_id ) );
	}

	/**
	 * Data provider for statuses that block processing.
	 *
	 * @return array[]
	 */
	public function data_blocking_statuses() {
		return array(
			'complete'   => array( 'complete' ),
			'processing' => array( 'processing' ),
			'skipped'    => array( 'skipped' ),
		);
	}

	/**
	 * A pending item is still eligible: it is queued, not done.
	 *
	 * @covers ::ai_media_search_can_process_attachment
	 */
	public function test_pending_status_allows_processing() {
		$attachment_id = $this->create_image_attachment();
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'pending' );

		$this->assertTrue( ai_media_search_can_process_attachment( $attachment_id ) );
	}

	/**
	 * A recent failure is held back by the one hour cooldown.
	 *
	 * @covers ::ai_media_search_can_process_attachment
	 */
	public function test_failure_cooldown_blocks_an_immediate_retry() {
		$attachment_id = $this->create_image_attachment();
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'failed' );
		update_post_meta(
			$attachment_id,
			'_wp_ai_media_search_error',
			array(
				'attempts'   => 1,
				'last_tried' => time() - ( 5 * MINUTE_IN_SECONDS ),
			)
		);

		$this->assertFalse( ai_media_search_can_process_attachment( $attachment_id ) );
	}

	/**
	 * Once the cooldown has passed a failed item is retried.
	 *
	 * @covers ::ai_media_search_can_process_attachment
	 */
	public function test_failure_is_retried_after_the_cooldown() {
		$attachment_id = $this->create_image_attachment();
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'failed' );
		update_post_meta(
			$attachment_id,
			'_wp_ai_media_search_error',
			array(
				'attempts'   => 1,
				'last_tried' => time() - ( 2 * HOUR_IN_SECONDS ),
			)
		);

		$this->assertTrue( ai_media_search_can_process_attachment( $attachment_id ) );
	}

	/**
	 * The attempt ceiling wins even when the cooldown has passed.
	 *
	 * @covers ::ai_media_search_can_process_attachment
	 */
	public function test_max_retries_stops_further_attempts() {
		$attachment_id = $this->create_image_attachment();
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'failed' );
		update_post_meta(
			$attachment_id,
			'_wp_ai_media_search_error',
			array(
				'attempts'   => 3,
				'last_tried' => time() - ( 2 * HOUR_IN_SECONDS ),
			)
		);

		$this->assertFalse( ai_media_search_can_process_attachment( $attachment_id ) );

		add_filter( 'ai_media_search_max_retries', static fn () => 5 );

		$this->assertTrue( ai_media_search_can_process_attachment( $attachment_id ) );
	}

	/**
	 * Only one caller can hold an attachment lock at a time.
	 *
	 * @covers ::ai_media_search_acquire_lock
	 * @covers ::ai_media_search_release_lock
	 */
	public function test_lock_is_exclusive() {
		$attachment_id = $this->create_image_attachment();

		$this->assertNotFalse( ai_media_search_acquire_lock( $attachment_id ) );
		$this->assertFalse( ai_media_search_acquire_lock( $attachment_id ), 'A second caller must not get the lock.' );

		ai_media_search_release_lock( $attachment_id );

		$this->assertNotFalse( ai_media_search_acquire_lock( $attachment_id ), 'The lock must be reusable once released.' );
	}

	/**
	 * Locks are per attachment, not global.
	 *
	 * @covers ::ai_media_search_acquire_lock
	 */
	public function test_locks_are_scoped_to_one_attachment() {
		$first  = $this->create_image_attachment();
		$second = $this->create_image_attachment();

		$this->assertNotFalse( ai_media_search_acquire_lock( $first ) );
		$this->assertNotFalse( ai_media_search_acquire_lock( $second ) );
	}

	/**
	 * A successful run stores both meta shapes and marks the item complete.
	 *
	 * @covers ::ai_media_search_process_single
	 */
	public function test_successful_run_stores_metadata() {
		$this->stub_ai_success( 'A tabby cat asleep on a windowsill.', 'cat, tabby, window' );

		$attachment_id = $this->create_image_attachment();

		$processed = array();
		add_action(
			'ai_media_search_processed',
			static function ( $id, $metadata ) use ( &$processed ) {
				$processed[] = array( $id, $metadata );
			},
			10,
			2
		);

		ai_media_search_process_single( $attachment_id );

		$this->assertSame( 'complete', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );

		$data = get_post_meta( $attachment_id, '_wp_ai_media_search_data', true );
		$this->assertIsArray( $data );
		$this->assertSame( 'A tabby cat asleep on a windowsill.', $data['description'] );

		$this->assertSame(
			'A tabby cat asleep on a windowsill. cat, tabby, window',
			get_post_meta( $attachment_id, '_wp_ai_media_search_text', true )
		);

		$this->assertCount( 1, $processed, 'ai_media_search_processed should fire once.' );
		$this->assertSame( $attachment_id, $processed[0][0] );
	}

	/**
	 * The lock is released so the attachment is not wedged after a success.
	 *
	 * @covers ::ai_media_search_process_single
	 */
	public function test_successful_run_releases_the_lock() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment();
		ai_media_search_process_single( $attachment_id );

		$this->assertFalse( get_option( "ai_media_search_lock_{$attachment_id}" ) );
	}

	/**
	 * A previous failure record is cleared once the item finally succeeds.
	 *
	 * @covers ::ai_media_search_process_single
	 */
	public function test_successful_run_clears_previous_error() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment();
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'failed' );
		update_post_meta(
			$attachment_id,
			'_wp_ai_media_search_error',
			array(
				'attempts'   => 1,
				'last_tried' => time() - ( 2 * HOUR_IN_SECONDS ),
			)
		);

		ai_media_search_process_single( $attachment_id );

		$this->assertSame( 'complete', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_error', true ) );
	}

	/**
	 * The metadata filter runs before anything is stored.
	 *
	 * @covers ::ai_media_search_process_single
	 */
	public function test_metadata_filter_changes_what_is_stored() {
		$this->stub_ai_success( 'Original description.', 'one, two' );

		add_filter(
			'ai_media_search_metadata',
			static function ( $metadata ) {
				$metadata['description'] = 'Replaced description.';
				return $metadata;
			}
		);

		$attachment_id = $this->create_image_attachment();
		ai_media_search_process_single( $attachment_id );

		$data = get_post_meta( $attachment_id, '_wp_ai_media_search_data', true );
		$this->assertSame( 'Replaced description.', $data['description'] );
		$this->assertStringStartsWith( 'Replaced description.', get_post_meta( $attachment_id, '_wp_ai_media_search_text', true ) );
	}

	/**
	 * The search text filter can append extra keywords.
	 *
	 * @covers ::ai_media_search_process_single
	 */
	public function test_search_text_filter_changes_the_stored_text() {
		$this->stub_ai_success( 'A cat.', 'cat' );

		add_filter(
			'ai_media_search_search_text',
			static function ( $text ) {
				return $text . ' extra-keyword';
			}
		);

		$attachment_id = $this->create_image_attachment();
		ai_media_search_process_single( $attachment_id );

		$this->assertSame( 'A cat. cat extra-keyword', get_post_meta( $attachment_id, '_wp_ai_media_search_text', true ) );
	}

	/**
	 * Alt text is left alone unless a site opts in.
	 *
	 * @covers ::ai_media_search_process_single
	 */
	public function test_alt_text_is_not_written_by_default() {
		$this->stub_ai_success( 'A cat.', 'cat' );

		$attachment_id = $this->create_image_attachment();
		ai_media_search_process_single( $attachment_id );

		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * With the filter on, empty alt text is filled from the description.
	 *
	 * @covers ::ai_media_search_process_single
	 */
	public function test_alt_text_is_written_when_enabled_and_empty() {
		$this->stub_ai_success( 'A cat.', 'cat' );
		add_filter( 'ai_media_search_update_alt_text', '__return_true' );

		$attachment_id = $this->create_image_attachment();
		ai_media_search_process_single( $attachment_id );

		$this->assertSame( 'A cat.', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Author written alt text is never overwritten.
	 *
	 * @covers ::ai_media_search_process_single
	 */
	public function test_existing_alt_text_is_preserved() {
		$this->stub_ai_success( 'A cat.', 'cat' );
		add_filter( 'ai_media_search_update_alt_text', '__return_true' );

		$attachment_id = $this->create_image_attachment();
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Hand written alt text' );

		ai_media_search_process_single( $attachment_id );

		$this->assertSame( 'Hand written alt text', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * An ineligible attachment never reaches the AI.
	 *
	 * @covers ::ai_media_search_process_single
	 */
	public function test_complete_attachment_is_not_reprocessed() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment();
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'complete' );

		ai_media_search_process_single( $attachment_id );

		$this->assertSame( array(), $this->ai_calls );
	}

	/**
	 * A held lock keeps a second worker out.
	 *
	 * @covers ::ai_media_search_process_single
	 */
	public function test_held_lock_prevents_a_duplicate_run() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment();
		ai_media_search_acquire_lock( $attachment_id );

		ai_media_search_process_single( $attachment_id );

		$this->assertSame( array(), $this->ai_calls, 'The AI must not be called while the lock is held.' );
		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
	}

	/**
	 * A failed run records the error, marks the item failed and frees the lock.
	 *
	 * @covers ::ai_media_search_process_single
	 */
	public function test_failed_run_records_the_error_and_releases_the_lock() {
		$this->stub_ai_response( new WP_Error( 'provider_down', 'Provider unavailable.' ) );

		$attachment_id = $this->create_image_attachment();

		$failures = array();
		add_action(
			'ai_media_search_failed',
			static function ( $id, $error, $error_data ) use ( &$failures ) {
				$failures[] = array( $id, $error, $error_data );
			},
			10,
			3
		);

		ai_media_search_process_single( $attachment_id );

		$this->assertSame( 'failed', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );

		$error = get_post_meta( $attachment_id, '_wp_ai_media_search_error', true );
		$this->assertIsArray( $error );
		$this->assertSame( 'provider_down', $error['code'] );
		$this->assertSame( 'Provider unavailable.', $error['message'] );
		$this->assertSame( 1, $error['attempts'] );

		$this->assertFalse( get_option( "ai_media_search_lock_{$attachment_id}" ) );
		$this->assertCount( 1, $failures );
	}

	/**
	 * Attempts accumulate across failures.
	 *
	 * @covers ::ai_media_search_handle_failure
	 */
	public function test_failures_increment_the_attempt_count() {
		$attachment_id = $this->create_image_attachment();
		$error         = new WP_Error( 'provider_down', 'Provider unavailable.' );

		ai_media_search_handle_failure( $attachment_id, $error );
		$this->assertSame( 1, get_post_meta( $attachment_id, '_wp_ai_media_search_error', true )['attempts'] );

		ai_media_search_handle_failure( $attachment_id, $error );
		$this->assertSame( 2, get_post_meta( $attachment_id, '_wp_ai_media_search_error', true )['attempts'] );
		$this->assertSame( 'failed', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
	}

	/**
	 * The third failure gives up for good.
	 *
	 * @covers ::ai_media_search_handle_failure
	 */
	public function test_max_retries_transitions_failed_to_skipped() {
		$attachment_id = $this->create_image_attachment();
		$error         = new WP_Error( 'provider_down', 'Provider unavailable.' );

		ai_media_search_handle_failure( $attachment_id, $error );
		ai_media_search_handle_failure( $attachment_id, $error );

		$this->assertSame( 'failed', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );

		ai_media_search_handle_failure( $attachment_id, $error );

		$this->assertSame( 'skipped', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertSame( 3, get_post_meta( $attachment_id, '_wp_ai_media_search_error', true )['attempts'] );
		$this->assertFalse( ai_media_search_can_process_attachment( $attachment_id ) );
	}

	/**
	 * The retry ceiling is filterable.
	 *
	 * @covers ::ai_media_search_handle_failure
	 */
	public function test_max_retries_filter_moves_the_give_up_point() {
		add_filter( 'ai_media_search_max_retries', static fn () => 1 );

		$attachment_id = $this->create_image_attachment();
		ai_media_search_handle_failure( $attachment_id, new WP_Error( 'provider_down', 'Provider unavailable.' ) );

		$this->assertSame( 'skipped', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
	}

	/**
	 * Reset clears every trace so the item is picked up again.
	 *
	 * @covers ::ai_media_search_reset
	 */
	public function test_reset_clears_all_state() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment();
		ai_media_search_process_single( $attachment_id );
		ai_media_search_acquire_lock( $attachment_id );

		ai_media_search_reset( $attachment_id );

		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_data', true ) );
		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_text', true ) );
		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_error', true ) );
		$this->assertFalse( get_option( "ai_media_search_lock_{$attachment_id}" ) );
		$this->assertTrue( ai_media_search_can_process_attachment( $attachment_id ) );
	}

	/**
	 * A batch run picks up unprocessed images and reports how many it handled.
	 *
	 * @covers ::ai_media_search_batch_process
	 */
	public function test_batch_processes_unprocessed_images() {
		$this->stub_ai_success();
		add_filter( 'ai_media_search_batch_size', static fn () => 10 );

		$ids = array(
			$this->create_image_attachment(),
			$this->create_image_attachment(),
			$this->create_image_attachment(),
		);

		$batches = array();
		add_action(
			'ai_media_search_batch_complete',
			static function ( $processed ) use ( &$batches ) {
				$batches[] = $processed;
			}
		);

		ai_media_search_batch_process();

		foreach ( $ids as $id ) {
			$this->assertSame( 'complete', get_post_meta( $id, '_wp_ai_media_search_status', true ) );
		}

		$this->assertSame( array( 3 ), $batches );
	}

	/**
	 * The batch size caps how much work one cron run takes on.
	 *
	 * @covers ::ai_media_search_batch_process
	 */
	public function test_batch_size_is_respected() {
		$this->stub_ai_success();
		add_filter( 'ai_media_search_batch_size', static fn () => 2 );

		$this->create_image_attachment();
		$this->create_image_attachment();
		$this->create_image_attachment();

		ai_media_search_batch_process();

		$this->assertCount( 2, $this->ai_calls );
	}

	/**
	 * Batch size is clamped rather than trusted.
	 *
	 * @covers ::ai_media_search_batch_process
	 */
	public function test_batch_size_is_clamped_to_at_least_one() {
		$this->stub_ai_success();
		add_filter( 'ai_media_search_batch_size', static fn () => 0 );

		$this->create_image_attachment();
		$this->create_image_attachment();

		ai_media_search_batch_process();

		$this->assertCount( 1, $this->ai_calls );
	}

	/**
	 * Completed images are not picked up again by the batch query.
	 *
	 * @covers ::ai_media_search_batch_process
	 */
	public function test_batch_skips_completed_images() {
		$this->stub_ai_success();

		$done = $this->create_image_attachment();
		update_post_meta( $done, '_wp_ai_media_search_status', 'complete' );

		ai_media_search_batch_process();

		$this->assertSame( array(), $this->ai_calls );
	}

	/**
	 * A failed image inside its cooldown is fetched but not retried.
	 *
	 * @covers ::ai_media_search_batch_process
	 */
	public function test_batch_honours_the_retry_cooldown() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment();
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'failed' );
		update_post_meta(
			$attachment_id,
			'_wp_ai_media_search_error',
			array(
				'attempts'   => 1,
				'last_tried' => time(),
			)
		);

		ai_media_search_batch_process();

		$this->assertSame( array(), $this->ai_calls );
		$this->assertSame( 'failed', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
	}

	/**
	 * Counts add up, and anything untracked lands in `unprocessed`.
	 *
	 * @covers ::ai_media_search_get_status_counts
	 */
	public function test_status_counts_add_up() {
		$statuses = array( 'complete', 'complete', 'processing', 'pending', 'failed', 'skipped' );

		foreach ( $statuses as $status ) {
			update_post_meta( $this->create_image_attachment(), '_wp_ai_media_search_status', $status );
		}

		// Two images the plugin has never seen.
		$this->create_image_attachment();
		$this->create_image_attachment();

		$counts = ai_media_search_get_status_counts();

		$this->assertSame( 2, $counts['complete'] );
		$this->assertSame( 1, $counts['processing'] );
		$this->assertSame( 1, $counts['pending'] );
		$this->assertSame( 1, $counts['failed'] );
		$this->assertSame( 1, $counts['skipped'] );
		$this->assertSame( 2, $counts['unprocessed'] );
		$this->assertSame( 8, $counts['total'] );
	}

	/**
	 * Only supported MIME types are counted.
	 *
	 * @covers ::ai_media_search_get_status_counts
	 */
	public function test_status_counts_ignore_unsupported_mime_types() {
		$this->create_image_attachment();

		self::factory()->attachment->create_object(
			array(
				'file'           => 'doc.pdf',
				'post_mime_type' => 'application/pdf',
			)
		);

		$counts = ai_media_search_get_status_counts();

		$this->assertSame( 1, $counts['total'] );
	}

	/**
	 * An empty library reports zeroes rather than tripping over the maths.
	 *
	 * @covers ::ai_media_search_get_status_counts
	 */
	public function test_status_counts_on_an_empty_library() {
		$counts = ai_media_search_get_status_counts();

		$this->assertSame( 0, $counts['total'] );
		$this->assertSame( 0, $counts['unprocessed'] );
		$this->assertSame( 0, $counts['complete'] );
	}

	/**
	 * Leave an attachment in the state a run that died mid-flight leaves behind.
	 *
	 * A worker takes the lock, writes `processing`, and never comes back. Both
	 * timestamps are backdated so the row looks as old as $age seconds.
	 *
	 * @param int  $attachment_id Attachment post ID.
	 * @param int  $age           Optional. How long ago the run started, in seconds.
	 * @param bool $track_start   Optional. Whether to record a start time. Rows
	 *                            stranded before start times were tracked have
	 *                            nothing but the leaked lock to go on.
	 */
	private function strand_in_processing( $attachment_id, $age = 7200, $track_start = true ) {
		ai_media_search_acquire_lock( $attachment_id );
		update_option( "ai_media_search_lock_{$attachment_id}", time() - $age );

		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'processing' );

		if ( $track_start ) {
			update_post_meta( $attachment_id, '_wp_ai_media_search_started', time() - $age );
		}
	}

	/**
	 * A run that dies mid-flight should not strand the attachment.
	 *
	 * @covers ::ai_media_search_can_process_attachment
	 * @covers ::ai_media_search_is_stale_processing
	 * @covers ::ai_media_search_process_single
	 * @link https://github.com/adamsilverstein/wp-ai-media-search/issues/8
	 */
	public function test_stale_processing_state_is_recovered() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment();
		$this->strand_in_processing( $attachment_id );

		$this->assertTrue( ai_media_search_is_stale_processing( $attachment_id ) );
		$this->assertTrue( ai_media_search_can_process_attachment( $attachment_id ), 'A stranded run must become eligible again.' );

		ai_media_search_process_single( $attachment_id );

		$this->assertCount( 1, $this->ai_calls, 'The recovered attachment must reach the AI.' );
		$this->assertSame( 'complete', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_started', true ), 'A finished run must clear its start time.' );
		$this->assertFalse( get_option( "ai_media_search_lock_{$attachment_id}" ) );
	}

	/**
	 * A run still inside the timeout is left alone.
	 *
	 * @covers ::ai_media_search_can_process_attachment
	 * @covers ::ai_media_search_is_stale_processing
	 * @covers ::ai_media_search_process_single
	 */
	public function test_fresh_processing_state_is_not_reclaimed() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment();
		$this->strand_in_processing( $attachment_id, 30 );

		$this->assertFalse( ai_media_search_is_stale_processing( $attachment_id ) );
		$this->assertFalse( ai_media_search_can_process_attachment( $attachment_id ) );

		ai_media_search_process_single( $attachment_id );

		$this->assertSame( array(), $this->ai_calls, 'A live run must not be duplicated.' );
		$this->assertSame( 'processing', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
	}

	/**
	 * Rows stranded before start times were recorded fall back to the lock.
	 *
	 * @covers ::ai_media_search_is_stale_processing
	 */
	public function test_stale_processing_falls_back_to_the_lock_timestamp() {
		$attachment_id = $this->create_image_attachment();
		$this->strand_in_processing( $attachment_id, 7200, false );

		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_started', true ) );
		$this->assertTrue( ai_media_search_is_stale_processing( $attachment_id ) );
		$this->assertTrue( ai_media_search_can_process_attachment( $attachment_id ) );
	}

	/**
	 * With no timestamp anywhere there is nothing to judge age by, so the row
	 * is left as it is rather than risking a duplicate AI call.
	 *
	 * @covers ::ai_media_search_is_stale_processing
	 */
	public function test_processing_without_any_timestamp_is_left_alone() {
		$attachment_id = $this->create_image_attachment();
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'processing' );

		$this->assertFalse( ai_media_search_is_stale_processing( $attachment_id ) );
		$this->assertFalse( ai_media_search_can_process_attachment( $attachment_id ) );
	}

	/**
	 * Statuses other than `processing` are never stale.
	 *
	 * @covers ::ai_media_search_is_stale_processing
	 */
	public function test_only_processing_rows_can_go_stale() {
		$attachment_id = $this->create_image_attachment();
		$this->strand_in_processing( $attachment_id );
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'complete' );

		$this->assertFalse( ai_media_search_is_stale_processing( $attachment_id ) );
	}

	/**
	 * Sites with slower AI calls can widen the window, or narrow it.
	 *
	 * @covers ::ai_media_search_get_processing_timeout
	 * @covers ::ai_media_search_is_stale_processing
	 */
	public function test_processing_timeout_is_filterable() {
		$attachment_id = $this->create_image_attachment();
		$this->strand_in_processing( $attachment_id, 5 * MINUTE_IN_SECONDS );

		$this->assertFalse( ai_media_search_is_stale_processing( $attachment_id ), 'Five minutes is inside the default window.' );

		add_filter(
			'ai_media_search_processing_timeout',
			static function () {
				return MINUTE_IN_SECONDS;
			}
		);

		$this->assertSame( MINUTE_IN_SECONDS, ai_media_search_get_processing_timeout() );
		$this->assertTrue( ai_media_search_is_stale_processing( $attachment_id ) );
	}

	/**
	 * The timeout never drops below a minute, however low a filter sets it.
	 *
	 * @covers ::ai_media_search_get_processing_timeout
	 */
	public function test_processing_timeout_has_a_floor() {
		add_filter( 'ai_media_search_processing_timeout', '__return_zero' );

		$this->assertSame( MINUTE_IN_SECONDS, ai_media_search_get_processing_timeout() );
	}

	/**
	 * An abandoned lock can be taken over once it ages out.
	 *
	 * @covers ::ai_media_search_acquire_lock
	 */
	public function test_a_stale_lock_can_be_broken() {
		$attachment_id = $this->create_image_attachment();

		$this->assertNotFalse( ai_media_search_acquire_lock( $attachment_id ) );
		$this->assertFalse( ai_media_search_acquire_lock( $attachment_id ), 'A live lock must hold.' );

		update_option( "ai_media_search_lock_{$attachment_id}", time() - 7200 );

		$this->assertNotFalse( ai_media_search_acquire_lock( $attachment_id ), 'An abandoned lock must be breakable.' );
		$this->assertGreaterThan( time() - 60, (int) get_option( "ai_media_search_lock_{$attachment_id}" ), 'Breaking the lock must stamp it afresh.' );
		$this->assertFalse( ai_media_search_acquire_lock( $attachment_id ), 'The re-taken lock must hold in turn.' );
	}

	/**
	 * A lock with no usable timestamp is treated as abandoned.
	 *
	 * @covers ::ai_media_search_acquire_lock
	 */
	public function test_a_lock_with_no_timestamp_can_be_broken() {
		$attachment_id = $this->create_image_attachment();

		add_option( "ai_media_search_lock_{$attachment_id}", '', '', false );

		$this->assertNotFalse( ai_media_search_acquire_lock( $attachment_id ) );
	}

	/**
	 * Two workers finding the same expired lock must not both take it over.
	 *
	 * The interleaving is driven from the `query` filter: the second worker
	 * runs its whole acquisition inside the first worker's write, which is the
	 * window between reading the expired value and claiming it. Checking the
	 * value and then writing it in two separate statements lets both workers
	 * through and starts two AI calls on one attachment.
	 *
	 * @covers ::ai_media_search_acquire_lock
	 */
	public function test_two_workers_cannot_both_take_over_one_expired_lock() {
		$attachment_id = $this->create_image_attachment();
		$lock_key      = "ai_media_search_lock_{$attachment_id}";

		add_option( $lock_key, time() - 7200, '', false );

		$second = false;

		$interleave = function ( $query ) use ( $lock_key, $attachment_id, &$second, &$interleave ) {
			// Only interrupt the first write aimed at this lock. Reads still
			// have to run untouched, or the worker never gets that far.
			if ( false === strpos( $query, $lock_key ) || 1 === preg_match( '/^\s*SELECT/i', $query ) ) {
				return $query;
			}

			remove_filter( 'query', $interleave );
			$second = ai_media_search_acquire_lock( $attachment_id );

			return $query;
		};

		add_filter( 'query', $interleave );

		$first = ai_media_search_acquire_lock( $attachment_id );

		remove_filter( 'query', $interleave );

		$holders = array_filter( array( $first, $second ) );

		$this->assertCount( 1, $holders, 'Exactly one worker may come away holding the lock.' );
		$this->assertSame( (string) reset( $holders ), (string) get_option( $lock_key ), 'The stored lock must belong to the worker that was told it won.' );
	}

	/**
	 * A run whose lock was taken over must not release the new holder's lock.
	 *
	 * @covers ::ai_media_search_release_lock
	 */
	public function test_releasing_a_lock_that_was_taken_over_is_a_no_op() {
		$attachment_id = $this->create_image_attachment();
		$lock_key      = "ai_media_search_lock_{$attachment_id}";

		// The overrunning run holds this lock, and its token is the value it
		// wrote when it took it.
		$abandoned = time() - 7200;
		add_option( $lock_key, $abandoned, '', false );

		$taken_over = ai_media_search_acquire_lock( $attachment_id );

		$this->assertNotFalse( $taken_over );

		// The original run finally finishes and lets go of what it thinks is
		// still its lock.
		ai_media_search_release_lock( $attachment_id, $abandoned );

		$this->assertSame( (string) $taken_over, (string) get_option( $lock_key ), 'A late release must leave the new holder alone.' );

		ai_media_search_release_lock( $attachment_id, $taken_over );

		$this->assertFalse( get_option( $lock_key ), 'The holder itself must still be able to release.' );
	}

	/**
	 * The batch query has to find stranded rows or nothing ever retries them.
	 *
	 * @covers ::ai_media_search_batch_process
	 * @covers ::ai_media_search_get_unprocessed_meta_query
	 */
	public function test_batch_picks_up_a_stale_processing_row() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment();
		$this->strand_in_processing( $attachment_id );

		ai_media_search_batch_process();

		$this->assertCount( 1, $this->ai_calls );
		$this->assertSame( 'complete', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
	}

	/**
	 * The same query finds rows stranded without a recorded start time.
	 *
	 * @covers ::ai_media_search_batch_process
	 * @covers ::ai_media_search_get_unprocessed_meta_query
	 */
	public function test_batch_picks_up_a_stale_processing_row_with_no_start_time() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment();
		$this->strand_in_processing( $attachment_id, 7200, false );

		ai_media_search_batch_process();

		$this->assertSame( 'complete', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
	}

	/**
	 * A batch run must not trample a worker that is still going.
	 *
	 * @covers ::ai_media_search_batch_process
	 */
	public function test_batch_leaves_a_live_processing_row_alone() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment();
		$this->strand_in_processing( $attachment_id, 30 );

		ai_media_search_batch_process();

		$this->assertSame( array(), $this->ai_calls );
		$this->assertSame( 'processing', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
	}

	/**
	 * A failed run clears its start time along with the lock.
	 *
	 * @covers ::ai_media_search_handle_failure
	 */
	public function test_a_failed_run_clears_the_start_time() {
		$this->stub_ai_response( new WP_Error( 'ai_media_search_test', 'Nope.' ) );

		$attachment_id = $this->create_image_attachment();

		ai_media_search_process_single( $attachment_id );

		$this->assertSame( 'failed', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_started', true ) );
	}

	/**
	 * Resetting an attachment wipes the start time too.
	 *
	 * @covers ::ai_media_search_reset
	 */
	public function test_reset_clears_the_start_time() {
		$attachment_id = $this->create_image_attachment();
		$this->strand_in_processing( $attachment_id );

		ai_media_search_reset( $attachment_id );

		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_started', true ) );
		$this->assertFalse( get_option( "ai_media_search_lock_{$attachment_id}" ) );
	}
}
