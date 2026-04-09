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

	// Gutenberg image blocks: use parse_blocks() for reliable extraction
	// regardless of attribute order in the block comment.
	if ( function_exists( 'parse_blocks' ) ) {
		$blocks = parse_blocks( $content );
		ai_media_search_collect_image_ids_from_blocks( $blocks, $ids );
	}

	// Classic editor: class="wp-image-123"
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
