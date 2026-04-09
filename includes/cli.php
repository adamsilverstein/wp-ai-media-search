<?php
/**
 * WP-CLI commands for AI Media Search.
 *
 * @package AI_Media_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage AI-generated search metadata for media library images.
 */
class AI_Media_Search_CLI {

	/**
	 * Process images that don't yet have AI search metadata.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more attachment IDs to process.
	 *
	 * [--all]
	 * : Process all unprocessed images.
	 *
	 * [--reset]
	 * : Re-process images even if they already have metadata.
	 *
	 * [--batch-size=<number>]
	 * : Number of images to process. Default: all matching images.
	 *
	 * [--dry-run]
	 * : Show what would be processed without making AI calls.
	 *
	 * ## EXAMPLES
	 *
	 *     # Process all unprocessed images
	 *     $ wp ai-media-search process --all
	 *
	 *     # Process specific images
	 *     $ wp ai-media-search process 42 55 78
	 *
	 *     # Re-process all images from scratch
	 *     $ wp ai-media-search process --all --reset
	 *
	 *     # Process next 20 unprocessed images
	 *     $ wp ai-media-search process --all --batch-size=20
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function process( $args, $assoc_args ) {
		if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			WP_CLI::error( 'AI features are not available. Check your WordPress AI configuration.' );
		}

		$reset   = WP_CLI\Utils\get_flag_value( $assoc_args, 'reset', false );
		$dry_run = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$all     = WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );

		if ( ! empty( $args ) ) {
			$attachment_ids = array_map( 'intval', $args );
		} elseif ( $all ) {
			$attachment_ids = $this->get_unprocessed_ids( $assoc_args, $reset );
		} else {
			WP_CLI::error( 'Specify attachment IDs or use --all.' );
		}

		if ( empty( $attachment_ids ) ) {
			WP_CLI::success( 'No images to process.' );
			return;
		}

		$count   = count( $attachment_ids );
		$label   = $dry_run ? 'Would process' : 'Processing';
		WP_CLI::log( "{$label} {$count} image(s)..." );

		if ( $dry_run ) {
			foreach ( $attachment_ids as $id ) {
				$title = get_the_title( $id );
				WP_CLI::log( "  #{$id} — {$title}" );
			}
			return;
		}

		$progress  = WP_CLI\Utils\make_progress_bar( 'Processing images', $count );
		$success   = 0;
		$failed    = 0;

		foreach ( $attachment_ids as $attachment_id ) {
			if ( $reset ) {
				delete_post_meta( $attachment_id, '_wp_ai_media_search_status' );
				delete_post_meta( $attachment_id, '_wp_ai_media_search_data' );
				delete_post_meta( $attachment_id, '_wp_ai_media_search_text' );
				delete_post_meta( $attachment_id, '_wp_ai_media_search_error' );
			}

			ai_media_search_process_single( $attachment_id );

			$status = get_post_meta( $attachment_id, '_wp_ai_media_search_status', true );

			if ( 'complete' === $status ) {
				$success++;
			} else {
				$failed++;
				$error = get_post_meta( $attachment_id, '_wp_ai_media_search_error', true );
				$msg   = is_array( $error ) ? $error['message'] : 'Unknown error';
				WP_CLI::warning( "#{$attachment_id}: {$msg}" );
			}

			$progress->tick();
		}

		$progress->finish();
		WP_CLI::success( "Done. {$success} succeeded, {$failed} failed." );
	}

	/**
	 * Show processing status summary.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp ai-media-search status
	 *     +------------+-------+
	 *     | Status     | Count |
	 *     +------------+-------+
	 *     | complete   |   142 |
	 *     | pending    |    23 |
	 *     | failed     |     2 |
	 *     | skipped    |     1 |
	 *     | unprocessed|   332 |
	 *     | total      |   500 |
	 *     +------------+-------+
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		$counts = ai_media_search_get_status_counts();

		$rows = array();
		foreach ( $counts as $status => $count ) {
			$rows[] = array(
				'status' => $status,
				'count'  => $count,
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'count' ) );
	}

	/**
	 * Regenerate AI metadata for specific images or all images.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more attachment IDs to regenerate.
	 *
	 * [--all]
	 * : Regenerate all images. Alias for `process --all --reset`.
	 *
	 * ## EXAMPLES
	 *
	 *     # Regenerate a specific image
	 *     $ wp ai-media-search regenerate 42
	 *
	 *     # Regenerate all images
	 *     $ wp ai-media-search regenerate --all
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function regenerate( $args, $assoc_args ) {
		$assoc_args['reset'] = true;
		$this->process( $args, $assoc_args );
	}

	/**
	 * Get IDs of images needing processing.
	 *
	 * @param array $assoc_args Associative arguments.
	 * @param bool  $reset      Whether to include already-processed images.
	 * @return int[] Attachment IDs.
	 */
	private function get_unprocessed_ids( $assoc_args, $reset ) {
		$meta_query = $reset
			? array()
			: array(
				'relation' => 'OR',
				array(
					'key'     => '_wp_ai_media_search_status',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => '_wp_ai_media_search_status',
					'value' => array( 'pending', 'failed' ),
					'compare' => 'IN',
				),
			);

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'posts_per_page' => isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		$query = new WP_Query( $query_args );

		return $query->posts;
	}
}

WP_CLI::add_command( 'ai-media-search', 'AI_Media_Search_CLI' );
