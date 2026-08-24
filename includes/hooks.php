<?php
/**
 * WordPress hooks: new upload queueing, post publish processing, image extraction.
 *
 * @package AI_Media_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue a newly uploaded image for AI processing.
 *
 * @param int $post_id Attachment post ID.
 */
function ai_media_search_on_new_attachment( $post_id ) {
	if ( ! ai_media_search_is_supported_attachment( $post_id ) ) {
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

/**
 * Get the spacing between the jobs queued together for one post.
 *
 * WP-Cron runs every event that is due in a single request, so scheduling a
 * whole gallery for the same moment hands one request every AI call in the
 * post, back to back, against one `max_execution_time`. Spreading the jobs out
 * gives each one its own cron request instead.
 *
 * @return int Seconds between successive jobs. Never negative.
 */
function ai_media_search_get_queue_stagger() {
	/**
	 * Filters the spacing between the per-image jobs queued for one post.
	 *
	 * Raise this on hosts where AI requests are slow, so a post's images are
	 * spread over more cron requests. Return `0` to queue them all for the same
	 * moment, which is how the plugin behaved before the spacing was added.
	 *
	 * @param int $stagger Seconds between successive jobs. Default 30.
	 */
	$stagger = (int) apply_filters( 'ai_media_search_queue_stagger', 30 );

	return max( 0, $stagger );
}

/**
 * When a post is published, queue any unprocessed images attached to it.
 *
 * Covers both the images found in the post content and the featured image,
 * which lives in post meta rather than the content.
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

	// The featured image is stored in _thumbnail_id, so content parsing never
	// sees it. Without this a post whose only image is the featured image
	// queues nothing and waits for the hourly batch instead.
	$thumbnail_id = (int) get_post_thumbnail_id( $post );

	if ( $thumbnail_id ) {
		$attachment_ids[] = $thumbnail_id;

		// The featured image is often used in the content as well; queueing it
		// twice would schedule the same job twice.
		$attachment_ids = array_unique( $attachment_ids );
	}

	$stagger = ai_media_search_get_queue_stagger();
	$queued  = 0;

	foreach ( $attachment_ids as $attachment_id ) {
		// Use the shared eligibility check so retry backoff and skipped state
		// are respected — we never rewrite a failed/skipped item back to pending.
		if ( ! ai_media_search_can_process_attachment( $attachment_id ) ) {
			continue;
		}

		if ( ! apply_filters( 'ai_media_search_should_process', true, $attachment_id ) ) {
			continue;
		}

		// Only set pending for truly unprocessed items. Failed items keep their
		// existing status so retry backoff stays in effect.
		$status = get_post_meta( $attachment_id, '_wp_ai_media_search_status', true );
		if ( empty( $status ) ) {
			update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'pending' );
		}

		// Each image goes further out than the one before it, so a gallery
		// post spreads across cron requests rather than filling one.
		wp_schedule_single_event( time() + 5 + ( $queued * $stagger ), 'ai_media_search_process_single', array( $attachment_id ) );
		++$queued;
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

	// Gutenberg image blocks: use parse_blocks() for reliable extraction
	// regardless of attribute order in the block comment.
	if ( function_exists( 'parse_blocks' ) ) {
		$blocks = parse_blocks( $content );
		ai_media_search_collect_image_ids_from_blocks( $blocks, $ids );
	}

	// Classic editor: match the wp-image-123 class core adds to inserted images.
	if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
		$ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
	}

	return array_unique( array_filter( $ids ) );
}

/**
 * Recursively collect image attachment IDs from parsed blocks.
 *
 * @param array $blocks Parsed blocks array.
 * @param int[] $ids    Collected IDs (passed by reference).
 */
function ai_media_search_collect_image_ids_from_blocks( $blocks, &$ids ) {
	foreach ( $blocks as $block ) {
		$name  = $block['blockName'] ?? '';
		$attrs = $block['attrs'] ?? array();

		switch ( $name ) {
			case 'core/image':
			case 'core/cover':
				if ( ! empty( $attrs['id'] ) ) {
					$ids[] = (int) $attrs['id'];
				}
				break;

			case 'core/media-text':
				if ( ! empty( $attrs['mediaId'] ) ) {
					$ids[] = (int) $attrs['mediaId'];
				}
				break;

			case 'core/gallery':
				// Gallery stores image IDs in its inner image blocks,
				// but legacy galleries use an 'ids' attribute.
				if ( ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
					$ids = array_merge( $ids, array_map( 'intval', $attrs['ids'] ) );
				}
				break;
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			ai_media_search_collect_image_ids_from_blocks( $block['innerBlocks'], $ids );
		}
	}
}
