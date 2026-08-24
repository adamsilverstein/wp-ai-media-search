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

	$analysis_file = ai_media_search_get_analysis_file( $attachment_id, $file_path, $mime_type );
	$file_path     = $analysis_file['path'];
	$mime_type     = $analysis_file['mime_type'];

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
 * Resolve which file to send to the AI for an attachment.
 *
 * The original upload is the wrong thing to send. Providers bill by image
 * tokens and cap the size of a single request, and a phone or camera original
 * routinely runs to several megabytes and 4000 pixels or more, none of which
 * buys a better description or a better tag list. A registered intermediate
 * size carries the same subject at a fraction of the payload.
 *
 * The original is used whenever a suitable intermediate is not available: a
 * non-image attachment, an upload too small for the size to have been
 * generated, or a size that was never registered on this site.
 *
 * @param int          $attachment_id Attachment post ID.
 * @param string       $file_path     Absolute path to the original file.
 * @param string|false $mime_type     MIME type of the original file.
 * @return array {
 *     The file to analyze.
 *
 *     @type string       $path      Absolute path to the file.
 *     @type string|false $mime_type MIME type of that file.
 * }
 */
function ai_media_search_get_analysis_file( $attachment_id, $file_path, $mime_type ) {
	$original = array(
		'path'      => $file_path,
		'mime_type' => $mime_type,
	);

	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		return $original;
	}

	/**
	 * Filters the image size sent to the AI for analysis.
	 *
	 * Defaults to `large`, which core caps at 1024 pixels on the long edge.
	 * That is at or above what current vision models resize an image to before
	 * they read it, so it keeps the detail that affects the description while
	 * dropping the payload by an order of magnitude on a typical upload.
	 *
	 * Accepts any registered size name, an array of `array( $width, $height )`
	 * to match the closest generated size, or `full` to send the original.
	 *
	 * @param string|int[] $size          Registered image size name or dimensions.
	 * @param int          $attachment_id Attachment post ID.
	 */
	$size = apply_filters( 'ai_media_search_image_size', 'large', $attachment_id );

	if ( empty( $size ) || 'full' === $size ) {
		return $original;
	}

	$intermediate = image_get_intermediate_size( $attachment_id, $size );

	if ( ! is_array( $intermediate ) || empty( $intermediate['path'] ) ) {
		return $original;
	}

	$uploads = wp_get_upload_dir();

	if ( ! empty( $uploads['error'] ) ) {
		return $original;
	}

	// image_get_intermediate_size() reports the path relative to the uploads directory.
	$path = path_join( $uploads['basedir'], $intermediate['path'] );

	if ( ! file_exists( $path ) ) {
		return $original;
	}

	// WordPress can write an intermediate size in a different format than the
	// original, so the type is read off the file that is actually being sent.
	$filetype = wp_check_filetype( $path );

	return array(
		'path'      => $path,
		'mime_type' => empty( $filetype['type'] ) ? $mime_type : $filetype['type'],
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
