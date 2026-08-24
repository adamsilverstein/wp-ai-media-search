<?php
/**
 * Tests for includes/hooks.php.
 *
 * @package AI_Media_Search
 */

/**
 * Covers upload queueing, publish time queueing and image ID extraction.
 */
class Test_AI_Media_Search_Hooks extends AI_Media_Search_TestCase {

	/**
	 * A new image upload is marked pending and queued.
	 *
	 * @covers ::ai_media_search_on_new_attachment
	 */
	public function test_new_image_is_queued() {
		$attachment_id = $this->create_image_attachment();

		ai_media_search_on_new_attachment( $attachment_id );

		$this->assertSame( 'pending', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertNotFalse( wp_next_scheduled( 'ai_media_search_process_single', array( $attachment_id ) ) );
	}

	/**
	 * Uploading an image queues it without any explicit call.
	 *
	 * @covers ::ai_media_search_on_new_attachment
	 */
	public function test_add_attachment_hook_is_wired_up() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'kitten.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$this->assertSame( 'pending', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
	}

	/**
	 * Unsupported types are ignored.
	 *
	 * @covers ::ai_media_search_on_new_attachment
	 */
	public function test_unsupported_upload_is_not_queued() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'notes.pdf',
				'post_mime_type' => 'application/pdf',
			)
		);

		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertFalse( wp_next_scheduled( 'ai_media_search_process_single', array( $attachment_id ) ) );
	}

	/**
	 * Sites can opt individual attachments out.
	 *
	 * @covers ::ai_media_search_on_new_attachment
	 */
	public function test_should_process_filter_can_skip_an_upload() {
		add_filter( 'ai_media_search_should_process', '__return_false' );

		$attachment_id = $this->create_image_attachment();
		ai_media_search_on_new_attachment( $attachment_id );

		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertFalse( wp_next_scheduled( 'ai_media_search_process_single', array( $attachment_id ) ) );
	}

	/**
	 * Publishing a post queues the images in its content.
	 *
	 * @covers ::ai_media_search_on_publish
	 */
	public function test_publishing_queues_content_images() {
		$attachment_id = $this->create_image_attachment();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => '<!-- wp:image {"id":' . $attachment_id . '} --><figure class="wp-block-image"><img src="x.jpg"/></figure><!-- /wp:image -->',
			)
		);

		wp_publish_post( $post_id );

		$this->assertSame( 'pending', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertNotFalse( wp_next_scheduled( 'ai_media_search_process_single', array( $attachment_id ) ) );
	}

	/**
	 * Updating an already published post does not re-queue everything.
	 *
	 * @covers ::ai_media_search_on_publish
	 */
	public function test_republishing_does_not_requeue() {
		$attachment_id = $this->create_image_attachment();

		ai_media_search_on_publish( 'publish', 'publish', self::factory()->post->create_and_get() );

		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
	}

	/**
	 * A give-up decision survives a publish.
	 *
	 * @covers ::ai_media_search_on_publish
	 */
	public function test_publishing_does_not_revive_a_skipped_image() {
		$attachment_id = $this->create_image_attachment();
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'skipped' );

		$post = self::factory()->post->create_and_get(
			array(
				'post_content' => '<img class="wp-image-' . $attachment_id . '" src="x.jpg" />',
			)
		);

		ai_media_search_on_publish( 'publish', 'draft', $post );

		$this->assertSame( 'skipped', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertFalse( wp_next_scheduled( 'ai_media_search_process_single', array( $attachment_id ) ) );
	}

	/**
	 * A failed image keeps its status, so the retry backoff still applies.
	 *
	 * @covers ::ai_media_search_on_publish
	 */
	public function test_publishing_keeps_a_failed_status() {
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

		$post = self::factory()->post->create_and_get(
			array(
				'post_content' => '<img class="wp-image-' . $attachment_id . '" src="x.jpg" />',
			)
		);

		ai_media_search_on_publish( 'publish', 'draft', $post );

		$this->assertSame( 'failed', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertNotFalse( wp_next_scheduled( 'ai_media_search_process_single', array( $attachment_id ) ) );
	}

	/**
	 * Image IDs are pulled out of every markup shape the plugin claims to support.
	 *
	 * @dataProvider data_content_with_image_ids
	 * @covers ::ai_media_search_extract_image_ids
	 * @covers ::ai_media_search_collect_image_ids_from_blocks
	 *
	 * @param string $content  Post content.
	 * @param int[]  $expected Expected attachment IDs.
	 */
	public function test_extract_image_ids( $content, $expected ) {
		$actual = array_values( ai_media_search_extract_image_ids( $content ) );

		sort( $actual );
		sort( $expected );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Data provider of post content and the IDs it should yield.
	 *
	 * @return array[]
	 */
	public function data_content_with_image_ids() {
		return array(
			'empty content'       => array( '', array() ),
			'no images'           => array( '<p>Just words.</p>', array() ),
			'classic img class'   => array(
				'<img class="alignnone size-large wp-image-42" src="x.jpg" />',
				array( 42 ),
			),
			'two classic images'  => array(
				'<img class="wp-image-42" src="x.jpg" /><img class="wp-image-43" src="y.jpg" />',
				array( 42, 43 ),
			),
			'image block'         => array(
				'<!-- wp:image {"id":42,"sizeSlug":"large"} --><figure class="wp-block-image"><img src="x.jpg"/></figure><!-- /wp:image -->',
				array( 42 ),
			),
			'attributes reversed' => array(
				'<!-- wp:image {"sizeSlug":"large","id":42} --><figure class="wp-block-image"><img src="x.jpg"/></figure><!-- /wp:image -->',
				array( 42 ),
			),
			'cover block'         => array(
				'<!-- wp:cover {"url":"x.jpg","id":77} --><div class="wp-block-cover"></div><!-- /wp:cover -->',
				array( 77 ),
			),
			'media and text'      => array(
				'<!-- wp:media-text {"mediaId":88,"mediaType":"image"} --><div class="wp-block-media-text"></div><!-- /wp:media-text -->',
				array( 88 ),
			),
			'legacy gallery ids'  => array(
				'<!-- wp:gallery {"ids":[1,2,3]} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->',
				array( 1, 2, 3 ),
			),
			'nested inner blocks' => array(
				'<!-- wp:group --><div class="wp-block-group"><!-- wp:columns --><div class="wp-block-columns">'
					. '<!-- wp:column --><div class="wp-block-column">'
					. '<!-- wp:image {"id":55} --><figure class="wp-block-image"><img src="x.jpg"/></figure><!-- /wp:image -->'
					. '</div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->',
				array( 55 ),
			),
			'gallery of images'   => array(
				'<!-- wp:gallery --><figure class="wp-block-gallery">'
					. '<!-- wp:image {"id":11} --><figure class="wp-block-image"><img src="a.jpg"/></figure><!-- /wp:image -->'
					. '<!-- wp:image {"id":12} --><figure class="wp-block-image"><img src="b.jpg"/></figure><!-- /wp:image -->'
					. '</figure><!-- /wp:gallery -->',
				array( 11, 12 ),
			),
			'mixed markup'        => array(
				'<!-- wp:image {"id":42} --><figure class="wp-block-image"><img src="x.jpg"/></figure><!-- /wp:image -->'
					. '<img class="wp-image-43" src="y.jpg" />',
				array( 42, 43 ),
			),
		);
	}

	/**
	 * The same image referenced twice is only queued once.
	 *
	 * @covers ::ai_media_search_extract_image_ids
	 */
	public function test_extract_image_ids_deduplicates() {
		$content = '<!-- wp:image {"id":42} --><figure class="wp-block-image">'
			. '<img class="wp-image-42" src="x.jpg"/></figure><!-- /wp:image -->';

		$this->assertSame( array( 42 ), array_values( ai_media_search_extract_image_ids( $content ) ) );
	}

	/**
	 * A zero ID is not a real attachment and is dropped.
	 *
	 * @covers ::ai_media_search_extract_image_ids
	 */
	public function test_extract_image_ids_drops_zero() {
		$content = '<!-- wp:image {"id":0} --><figure class="wp-block-image"><img src="x.jpg"/></figure><!-- /wp:image -->';

		$this->assertSame( array(), array_values( ai_media_search_extract_image_ids( $content ) ) );
	}

	/**
	 * Publishing a post queues its featured image, even when the content has none.
	 *
	 * @covers ::ai_media_search_on_publish
	 */
	public function test_publishing_queues_the_featured_image() {
		$attachment_id = $this->create_image_attachment();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => '<p>Just words.</p>',
			)
		);
		set_post_thumbnail( $post_id, $attachment_id );

		wp_publish_post( $post_id );

		$this->assertSame( 'pending', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertNotFalse( wp_next_scheduled( 'ai_media_search_process_single', array( $attachment_id ) ) );
	}

	/**
	 * A featured image that is also in the content is only queued once.
	 *
	 * @covers ::ai_media_search_on_publish
	 */
	public function test_featured_image_in_content_is_queued_once() {
		$attachment_id = $this->create_image_attachment();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => '<img class="wp-image-' . $attachment_id . '" src="x.jpg" />',
			)
		);
		set_post_thumbnail( $post_id, $attachment_id );

		// `pre_schedule_event` runs before core's own duplicate check, so this
		// counts every attempt rather than only the ones that stick.
		$attempts = array();

		add_filter(
			'pre_schedule_event',
			static function ( $pre, $event ) use ( &$attempts ) {
				if ( 'ai_media_search_process_single' === $event->hook ) {
					$attempts[] = $event->args;
				}

				return $pre;
			},
			10,
			2
		);

		wp_publish_post( $post_id );

		$this->assertSame( array( array( $attachment_id ) ), $attempts );
	}

	/**
	 * A featured image gets the same eligibility checks as a content image.
	 *
	 * @covers ::ai_media_search_on_publish
	 */
	public function test_publishing_does_not_revive_a_skipped_featured_image() {
		$attachment_id = $this->create_image_attachment();
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'skipped' );

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => '<p>Just words.</p>',
			)
		);
		set_post_thumbnail( $post_id, $attachment_id );

		wp_publish_post( $post_id );

		$this->assertSame( 'skipped', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertFalse( wp_next_scheduled( 'ai_media_search_process_single', array( $attachment_id ) ) );
	}

	/**
	 * A post without a featured image still queues only its content images.
	 *
	 * @covers ::ai_media_search_on_publish
	 */
	public function test_publishing_without_a_featured_image_queues_content_only() {
		$attachment_id = $this->create_image_attachment();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => '<img class="wp-image-' . $attachment_id . '" src="x.jpg" />',
			)
		);

		$this->assertSame( '', get_post_meta( $post_id, '_thumbnail_id', true ) );

		wp_publish_post( $post_id );

		$this->assertSame( 'pending', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertNotFalse( wp_next_scheduled( 'ai_media_search_process_single', array( $attachment_id ) ) );
	}

	/**
	 * A post with no images at all queues nothing.
	 *
	 * @covers ::ai_media_search_on_publish
	 */
	public function test_publishing_a_post_without_images_queues_nothing() {
		$attachment_id = $this->create_image_attachment();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => '<p>Just words.</p>',
			)
		);

		wp_publish_post( $post_id );

		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertFalse( wp_next_scheduled( 'ai_media_search_process_single', array( $attachment_id ) ) );
	}

	/**
	 * Every image in a post gets its own slot rather than one shared tick.
	 *
	 * @covers ::ai_media_search_on_publish
	 * @covers ::ai_media_search_get_queue_stagger
	 * @link https://github.com/adamsilverstein/wp-ai-media-search/issues/12
	 */
	public function test_publishing_staggers_the_images_it_queues() {
		$ids = array(
			$this->create_image_attachment(),
			$this->create_image_attachment(),
			$this->create_image_attachment(),
		);

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => $this->content_for( $ids ),
			)
		);

		wp_publish_post( $post_id );

		$timestamps = $this->scheduled_times( $ids );

		$this->assertSame( $timestamps, array_unique( $timestamps ), 'Two images must never share a tick.' );
		$this->assertEqualsWithDelta( 30, $timestamps[1] - $timestamps[0], 1 );
		$this->assertEqualsWithDelta( 30, $timestamps[2] - $timestamps[1], 1 );
	}

	/**
	 * Sites can widen the spacing to suit a slow AI provider.
	 *
	 * @covers ::ai_media_search_on_publish
	 * @covers ::ai_media_search_get_queue_stagger
	 */
	public function test_queue_stagger_is_filterable() {
		add_filter( 'ai_media_search_queue_stagger', static fn () => 120 );

		$ids = array(
			$this->create_image_attachment(),
			$this->create_image_attachment(),
		);

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => $this->content_for( $ids ),
			)
		);

		wp_publish_post( $post_id );

		$timestamps = $this->scheduled_times( $ids );

		$this->assertEqualsWithDelta( 120, $timestamps[1] - $timestamps[0], 1 );
	}

	/**
	 * A spacing of zero restores the old all-at-once behaviour.
	 *
	 * @covers ::ai_media_search_on_publish
	 * @covers ::ai_media_search_get_queue_stagger
	 */
	public function test_queue_stagger_can_be_turned_off() {
		add_filter( 'ai_media_search_queue_stagger', static fn () => 0 );

		$ids = array(
			$this->create_image_attachment(),
			$this->create_image_attachment(),
		);

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => $this->content_for( $ids ),
			)
		);

		wp_publish_post( $post_id );

		$timestamps = $this->scheduled_times( $ids );

		$this->assertSame( $timestamps[0], $timestamps[1] );
	}

	/**
	 * A nonsense spacing is clamped rather than trusted.
	 *
	 * @covers ::ai_media_search_get_queue_stagger
	 */
	public function test_queue_stagger_is_never_negative() {
		add_filter( 'ai_media_search_queue_stagger', static fn () => -30 );

		$this->assertSame( 0, ai_media_search_get_queue_stagger() );
	}

	/**
	 * Images that are passed over do not leave gaps in the schedule.
	 *
	 * @covers ::ai_media_search_on_publish
	 */
	public function test_stagger_only_counts_the_images_it_queues() {
		$skipped = $this->create_image_attachment();
		update_post_meta( $skipped, '_wp_ai_media_search_status', 'skipped' );

		$ids = array(
			$this->create_image_attachment(),
			$this->create_image_attachment(),
		);

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => $this->content_for( array_merge( array( $skipped ), $ids ) ),
			)
		);

		$published_at = time();

		wp_publish_post( $post_id );

		$timestamps = $this->scheduled_times( $ids );

		$this->assertFalse( wp_next_scheduled( 'ai_media_search_process_single', array( $skipped ) ) );
		$this->assertEqualsWithDelta( $published_at + 5, $timestamps[0], 2, 'The first image queued keeps the short delay.' );
		$this->assertEqualsWithDelta( 30, $timestamps[1] - $timestamps[0], 1 );
	}

	/**
	 * Build post content referencing each of the given attachments.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return string Post content.
	 */
	private function content_for( $ids ) {
		$content = '';

		foreach ( $ids as $id ) {
			$content .= '<img class="wp-image-' . $id . '" src="x.jpg" />';
		}

		return $content;
	}

	/**
	 * Get the scheduled run time for each attachment, failing if one is missing.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return int[] Scheduled timestamps, in the order the IDs were given.
	 */
	private function scheduled_times( $ids ) {
		$timestamps = array();

		foreach ( $ids as $id ) {
			$next = wp_next_scheduled( 'ai_media_search_process_single', array( $id ) );

			$this->assertNotFalse( $next, "Attachment {$id} was never queued." );

			$timestamps[] = $next;
		}

		return $timestamps;
	}
}
