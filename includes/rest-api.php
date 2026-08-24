<?php
/**
 * REST API: processing status, and the regenerate endpoint behind the admin
 * Regenerate button.
 *
 * @package AI_Media_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the plugin's REST API routes.
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

	register_rest_route(
		'ai-media-search/v1',
		'/attachments/(?P<id>[\d]+)/regenerate',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'ai_media_search_rest_regenerate',
			'permission_callback' => 'ai_media_search_rest_regenerate_permission_check',
			'args'                => array(
				'id'    => array(
					'description' => __( 'Attachment post ID to regenerate.', 'ai-media-search' ),
					'type'        => 'integer',
					'required'    => true,
				),
				// Deliberately not marked required: the schema check runs ahead
				// of the permission callback, and a missing nonce should be
				// answered by the security check rather than by validation.
				'nonce' => array(
					'description' => __( 'Nonce for the ai_media_search_regenerate_<id> action.', 'ai-media-search' ),
					'type'        => 'string',
				),
			),
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

/**
 * Permission callback for the regenerate endpoint.
 *
 * Regenerating spends money at an AI provider and overwrites what is stored
 * for the attachment, so it is guarded twice. The nonce ties the request to
 * this user, this session and this specific attachment, which keeps another
 * site from firing it off in the background of a logged-in editor's browser.
 * The capability check is `edit_post` against the attachment itself, so an
 * author can regenerate their own uploads and nobody else's - `upload_files`
 * would have let any contributor rewrite the whole library.
 *
 * Both failures report through `rest_authorization_required_code()`, which
 * answers 401 to a logged-out request and 403 to a logged-in one.
 *
 * @param WP_REST_Request $request The request.
 * @return true|WP_Error True when allowed, WP_Error otherwise.
 */
function ai_media_search_rest_regenerate_permission_check( $request ) {
	// Permission callbacks run before the route's own argument sanitizing, so
	// nothing here can assume the parameters have been through the schema.
	$attachment_id = (int) $request['id'];
	$nonce         = $request['nonce'];

	if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'ai_media_search_regenerate_' . $attachment_id ) ) {
		return new WP_Error(
			'ai_media_search_invalid_nonce',
			__( 'The security check failed. Reload the page and try again.', 'ai-media-search' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
		return new WP_Error(
			'ai_media_search_cannot_edit',
			__( 'Sorry, you are not allowed to regenerate the AI description for this attachment.', 'ai-media-search' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	return true;
}

/**
 * REST callback: regenerate the AI description for one attachment.
 *
 * The AI call runs inline. It is a single request for a single image that the
 * user just asked for, and the browser is waiting on `fetch()` rather than on
 * a page load, so the admin screen stays usable while it runs and the answer
 * comes back in the response instead of appearing minutes later after cron.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function ai_media_search_rest_regenerate( $request ) {
	$attachment_id = (int) $request['id'];
	$post          = get_post( $attachment_id );

	if ( ! $post || 'attachment' !== $post->post_type ) {
		return new WP_Error(
			'ai_media_search_not_found',
			__( 'No attachment was found with that ID.', 'ai-media-search' ),
			array( 'status' => 404 )
		);
	}

	if ( ! ai_media_search_is_supported_attachment( $attachment_id ) ) {
		return new WP_Error(
			'ai_media_search_unsupported_type',
			__( 'This attachment is not a type the plugin describes.', 'ai-media-search' ),
			array( 'status' => 400 )
		);
	}

	// Regenerating clears the stored description first, so bail before that
	// happens rather than trading a real description for an empty one.
	if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
		return new WP_Error(
			'ai_media_search_ai_unavailable',
			__( 'No AI provider is configured in WordPress, so nothing can be generated right now.', 'ai-media-search' ),
			array( 'status' => 503 )
		);
	}

	ai_media_search_regenerate_attachment( $attachment_id );

	$state = ai_media_search_get_attachment_state( $attachment_id );

	return new WP_REST_Response(
		array(
			'id'          => $attachment_id,
			'status'      => $state['status'],
			'description' => $state['description'],
			'tags'        => $state['tags'],
			'error'       => $state['error_message'],
			'html'        => ai_media_search_get_attachment_panel( $attachment_id ),
		),
		200
	);
}
