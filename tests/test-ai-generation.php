<?php
/**
 * Tests for includes/ai-generation.php.
 *
 * @package AI_Media_Search
 */

/**
 * Covers ai_media_search_generate_metadata() and its AI wrapper.
 *
 * @covers ::ai_media_search_generate_metadata
 * @covers ::ai_media_search_prompt_image
 */
class Test_AI_Media_Search_AI_Generation extends AI_Media_Search_TestCase {

	/**
	 * A missing attachment produces an error rather than an AI request.
	 */
	public function test_missing_file_returns_error() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => '/nowhere/does-not-exist.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$result = ai_media_search_generate_metadata( $attachment_id );

		$this->assertWPError( $result );
		$this->assertSame( 'ai_media_search_file_missing', $result->get_error_code() );
		$this->assertSame( array(), $this->ai_calls, 'No AI request should be made for a missing file.' );
	}

	/**
	 * A well formed response is decoded into the stored metadata shape.
	 */
	public function test_successful_response_is_decoded() {
		$this->stub_ai_success( 'A tabby cat asleep on a windowsill.', 'cat, tabby, window' );

		$attachment_id = $this->create_image_attachment();
		$before        = time();
		$result        = ai_media_search_generate_metadata( $attachment_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'A tabby cat asleep on a windowsill.', $result['description'] );
		$this->assertSame( 'cat, tabby, window', $result['tags'] );
		$this->assertSame( 1, $result['version'] );
		$this->assertSame( 'image', $result['media_type'] );
		$this->assertGreaterThanOrEqual( $before, $result['generated_at'] );
	}

	/**
	 * The media type is derived from the attachment MIME type, not hard coded.
	 */
	public function test_media_type_comes_from_the_mime_type() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment( array( 'post_mime_type' => 'video/mp4' ) );
		$result        = ai_media_search_generate_metadata( $attachment_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'video', $result['media_type'] );
	}

	/**
	 * Description and tags are sanitized before they are returned.
	 */
	public function test_response_is_sanitized() {
		$this->stub_ai_success( 'A cat <script>alert(1)</script> napping', 'cat, <b>tabby</b>' );

		$attachment_id = $this->create_image_attachment();
		$result        = ai_media_search_generate_metadata( $attachment_id );

		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<script>', $result['description'] );
		$this->assertStringNotContainsString( '<b>', $result['tags'] );
	}

	/**
	 * An error from the AI client is passed straight back to the caller.
	 */
	public function test_ai_error_is_returned() {
		$this->stub_ai_response( new WP_Error( 'provider_down', 'Provider unavailable.' ) );

		$attachment_id = $this->create_image_attachment();
		$result        = ai_media_search_generate_metadata( $attachment_id );

		$this->assertWPError( $result );
		$this->assertSame( 'provider_down', $result->get_error_code() );
	}

	/**
	 * Malformed responses are rejected instead of being stored.
	 *
	 * @dataProvider data_invalid_responses
	 *
	 * @param string $response Raw response body.
	 */
	public function test_invalid_response_returns_error( $response ) {
		$this->stub_ai_response( $response );

		$attachment_id = $this->create_image_attachment();
		$result        = ai_media_search_generate_metadata( $attachment_id );

		$this->assertWPError( $result );
		$this->assertSame( 'ai_media_search_invalid_response', $result->get_error_code() );
	}

	/**
	 * Data provider for malformed AI responses.
	 *
	 * @return array[]
	 */
	public function data_invalid_responses() {
		return array(
			'not JSON'           => array( 'I am not JSON at all.' ),
			'empty string'       => array( '' ),
			'JSON scalar'        => array( '"just a string"' ),
			'missing tags'       => array( '{"description":"A cat."}' ),
			'empty description'  => array( '{"description":"","tags":"cat"}' ),
			'missing everything' => array( '{}' ),
		);
	}

	/**
	 * An empty tags string is valid: some images have nothing worth tagging.
	 */
	public function test_empty_tags_are_accepted() {
		$this->stub_ai_response( '{"description":"A plain grey square.","tags":""}' );

		$attachment_id = $this->create_image_attachment();
		$result        = ai_media_search_generate_metadata( $attachment_id );

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['tags'] );
	}

	/**
	 * The prompt filter reaches the AI wrapper.
	 */
	public function test_prompt_filter_is_applied() {
		$this->stub_ai_success();

		add_filter(
			'ai_media_search_prompt',
			static function ( $prompt, $attachment_id ) {
				return 'Describe attachment ' . $attachment_id;
			},
			10,
			2
		);

		$attachment_id = $this->create_image_attachment();
		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );
		$this->assertSame( 'Describe attachment ' . $attachment_id, $this->ai_calls[0]['prompt'] );
	}

	/**
	 * The wrapper hands the AI the attachment file and its MIME type.
	 */
	public function test_wrapper_receives_the_attachment_file() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment();
		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );
		$this->assertSame( get_attached_file( $attachment_id ), $this->ai_calls[0]['file_path'] );
		$this->assertSame( 'image/jpeg', $this->ai_calls[0]['mime_type'] );
		$this->assertSame( $attachment_id, $this->ai_calls[0]['attachment_id'] );
	}

	/**
	 * The short-circuit filter keeps the AI Client API out of the request.
	 */
	public function test_pre_prompt_filter_short_circuits_the_request() {
		$response = ai_media_search_prompt_image( 'A prompt', '/tmp/file.jpg', 'image/jpeg', 0 );

		// The base test case installs a failing stub, proving nothing reached a provider.
		$this->assertWPError( $response );
		$this->assertSame( 'ai_media_search_test_unstubbed', $response->get_error_code() );
	}
}
