<?php
/**
 * REST API: read-only endpoint for processing status.
 *
 * @package AI_Media_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the REST API route for processing status.
 */
function ai_media_search_register_rest_routes() {
	register_rest_route(
		'ai-media-search/v1',
		'/status',
		array(
			'methods'             => 'GET',
			'callback'            => 'ai_media_search_rest_status',
			'permission_callback' => function () {
				return current_user_can( 'upload_files' );
			},
		)
	);
}
add_action( 'rest_api_init', 'ai_media_search_register_rest_routes' );

/**
 * REST callback: return processing status counts.
 *
 * @return WP_REST_Response
 */
function ai_media_search_rest_status() {
	$counts = ai_media_search_get_status_counts();

	return new WP_REST_Response( $counts, 200 );
}
