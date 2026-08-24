<?php
/**
 * AI metadata generation: prompts the AI Client API to analyze images.
 *
 * @package AI_Media_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate AI search metadata for an image attachment.
 *
 * @param int $attachment_id Attachment post ID.
 * @return array|WP_Error Structured metadata array on success, WP_Error on failure.
 */
function ai_media_search_generate_metadata( $attachment_id ) {
	$file_path = get_attached_file( $attachment_id );

	if ( ! $file_path || ! file_exists( $file_path ) ) {
		return new WP_Error(
			'ai_media_search_file_missing',
			__( 'Attachment file not found.', 'ai-media-search' )
		);
	}

	$mime_type = get_post_mime_type( $attachment_id );

	$default_prompt = 'Analyze this image and provide: '
		. '1) A detailed description suitable for search (2-3 sentences covering the main subject, setting, colors, mood, and any actions or text visible). '
		. '2) A comma-separated list of 15-25 search tags covering objects, people, animals, colors, concepts, emotions, settings, and styles present in the image. '
		. 'Return JSON with keys "description" and "tags".';

	/**
	 * Filters the AI prompt used for image analysis.
	 *
	 * @param string $prompt        The prompt text.
	 * @param int    $attachment_id Attachment post ID.
	 */
	$prompt = apply_filters( 'ai_media_search_prompt', $default_prompt, $attachment_id );

	$result = ai_media_search_prompt_image( $prompt, $file_path, $mime_type, $attachment_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$decoded = json_decode( $result, true );

	if ( ! is_array( $decoded ) || empty( $decoded['description'] ) || ! isset( $decoded['tags'] ) ) {
		return new WP_Error(
			'ai_media_search_invalid_response',
			__( 'AI returned an invalid response structure.', 'ai-media-search' )
		);
	}

	$mime       = get_post_mime_type( $attachment_id );
	$media_type = $mime ? strtok( $mime, '/' ) : 'image';

	return array(
		'description'  => sanitize_text_field( $decoded['description'] ),
		'tags'         => sanitize_text_field( $decoded['tags'] ),
		'generated_at' => time(),
		'version'      => 1,
		'media_type'   => $media_type,
	);
}

/**
 * Send an image to the AI Client API and return the raw JSON response.
 *
 * This wraps the single call into the AI Client API so the response can be
 * replaced without a network request, which is what the test suite does.
 *
 * @param string $prompt        The prompt text.
 * @param string $file_path     Absolute path to the file to analyze.
 * @param string $mime_type     MIME type of the file.
 * @param int    $attachment_id Attachment post ID.
 * @return string|WP_Error Raw JSON response on success, WP_Error on failure.
 */
function ai_media_search_prompt_image( $prompt, $file_path, $mime_type, $attachment_id ) {
	/**
	 * Filters the raw AI response, short-circuiting the request when non-null.
	 *
	 * Returning anything other than null skips the AI Client API entirely. Return
	 * a JSON string in the shape the prompt asks for, or a WP_Error to simulate a
	 * failure.
	 *
	 * @param null|string|WP_Error $response      Raw response. Default null.
	 * @param string               $prompt        The prompt text.
	 * @param string               $file_path     Absolute path to the file to analyze.
	 * @param string               $mime_type     MIME type of the file.
	 * @param int                  $attachment_id Attachment post ID.
	 */
	$response = apply_filters( 'ai_media_search_pre_prompt_image', null, $prompt, $file_path, $mime_type, $attachment_id );

	if ( null !== $response ) {
		return $response;
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return new WP_Error(
			'ai_media_search_client_missing',
			__( 'The WordPress AI Client API is not available.', 'ai-media-search' )
		);
	}

	return wp_ai_client_prompt( $prompt )
		->with_file( $file_path, $mime_type )
		->as_json_response(
			array(
				'type'       => 'object',
				'properties' => array(
					'description' => array( 'type' => 'string' ),
					'tags'        => array( 'type' => 'string' ),
				),
				'required'   => array( 'description', 'tags' ),
			)
		)
		->generate_text();
}
