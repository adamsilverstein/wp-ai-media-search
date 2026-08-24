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
 * @covers ::ai_media_search_get_language_instruction
 * @covers ::ai_media_search_get_language
 * @covers ::ai_media_search_language_from_locale
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
		$this->assertStringStartsWith( 'Describe attachment ' . $attachment_id, $this->ai_calls[0]['prompt'] );
	}

	/**
	 * A filtered prompt still gets the language instruction appended.
	 *
	 * The whole point of issue #18 is that a non-English site gets non-English
	 * metadata. A site that customizes the prompt should not lose that.
	 */
	public function test_prompt_filter_still_gets_the_language_instruction() {
		$this->stub_ai_success();

		add_filter(
			'ai_media_search_prompt',
			static function () {
				return 'Describe this image.';
			}
		);

		$this->switch_locale( 'de_DE' );

		$attachment_id = $this->create_image_attachment();
		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );
		$this->assertStringStartsWith( 'Describe this image.', $this->ai_calls[0]['prompt'] );
		$this->assertStringContainsString( 'in German', $this->ai_calls[0]['prompt'] );
	}

	/**
	 * An English site is told to answer in English rather than left to guess.
	 */
	public function test_prompt_asks_for_english_on_an_english_site() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment();
		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );
		$this->assertSame( 'en_US', get_locale(), 'The test suite should be running in English.' );
		$this->assertStringContainsString(
			'Write the description and the tags in English.',
			$this->ai_calls[0]['prompt']
		);
	}

	/**
	 * A non-English site asks for the description and tags in its own language.
	 */
	public function test_prompt_asks_for_the_site_language() {
		$this->stub_ai_success();

		$this->switch_locale( 'fr_FR' );

		$attachment_id = $this->create_image_attachment();
		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );
		$this->assertStringContainsString(
			'Write the description and the tags in French.',
			$this->ai_calls[0]['prompt']
		);
	}

	/**
	 * The JSON keys are protected, so parsing survives a localized response.
	 */
	public function test_prompt_keeps_the_json_keys_in_english() {
		$this->stub_ai_success();

		$this->switch_locale( 'ja_JP' );

		$attachment_id = $this->create_image_attachment();
		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );

		$prompt = $this->ai_calls[0]['prompt'];

		$this->assertStringContainsString( 'in Japanese', $prompt );
		$this->assertStringContainsString( 'Return JSON with keys "description" and "tags"', $prompt );
		$this->assertStringContainsString(
			'keys "description" and "tags" in English exactly as written here',
			$prompt
		);
	}

	/**
	 * A locale with no entry in the table falls back to English.
	 */
	public function test_unknown_locale_falls_back_to_english() {
		$this->stub_ai_success();

		$this->switch_locale( 'xx_YY' );

		$attachment_id = $this->create_image_attachment();
		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );
		$this->assertStringContainsString(
			'Write the description and the tags in English.',
			$this->ai_calls[0]['prompt']
		);
		$this->assertStringNotContainsString( 'xx_YY', $this->ai_calls[0]['prompt'] );
	}

	/**
	 * The language filter overrides the site locale.
	 */
	public function test_language_filter_overrides_the_locale() {
		$this->stub_ai_success();

		$this->switch_locale( 'de_DE' );

		add_filter(
			'ai_media_search_language',
			static function () {
				return 'Welsh';
			}
		);

		$attachment_id = $this->create_image_attachment();
		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );
		$this->assertStringContainsString(
			'Write the description and the tags in Welsh.',
			$this->ai_calls[0]['prompt']
		);
	}

	/**
	 * The language filter receives the locale it is overriding.
	 */
	public function test_language_filter_receives_the_locale() {
		$this->stub_ai_success();

		$this->switch_locale( 'pt_BR' );

		$received = array();

		add_filter(
			'ai_media_search_language',
			static function ( $language, $locale, $attachment_id ) use ( &$received ) {
				$received = array(
					'language'      => $language,
					'locale'        => $locale,
					'attachment_id' => $attachment_id,
				);

				return $language;
			},
			10,
			3
		);

		$attachment_id = $this->create_image_attachment();
		ai_media_search_generate_metadata( $attachment_id );

		$this->assertSame( 'Brazilian Portuguese', $received['language'] );
		$this->assertSame( 'pt_BR', $received['locale'] );
		$this->assertSame( $attachment_id, $received['attachment_id'] );
	}

	/**
	 * A filter that returns nothing usable falls back to English.
	 *
	 * @dataProvider data_unusable_language_values
	 *
	 * @param mixed $value Value returned by the language filter.
	 */
	public function test_unusable_language_filter_value_falls_back_to_english( $value ) {
		add_filter(
			'ai_media_search_language',
			static function () use ( $value ) {
				return $value;
			}
		);

		$this->assertSame( 'English', ai_media_search_get_language( 0 ) );
	}

	/**
	 * Data provider for language filter values that cannot be used in a prompt.
	 *
	 * @return array[]
	 */
	public function data_unusable_language_values() {
		return array(
			'empty string' => array( '' ),
			'whitespace'   => array( "  \n" ),
			'null'         => array( null ),
			'false'        => array( false ),
		);
	}

	/**
	 * Locales map to language names a model can act on.
	 *
	 * @dataProvider data_locale_language_names
	 *
	 * @param string $locale   WordPress locale.
	 * @param string $expected Expected language name.
	 */
	public function test_locale_maps_to_a_language_name( $locale, $expected ) {
		$this->assertSame( $expected, ai_media_search_language_from_locale( $locale ) );
	}

	/**
	 * Data provider for locale to language name mapping.
	 *
	 * @return array[]
	 */
	public function data_locale_language_names() {
		return array(
			'plain locale'         => array( 'de_DE', 'German' ),
			'language only'        => array( 'fr', 'French' ),
			'formal variant'       => array( 'nl_NL_formal', 'Dutch' ),
			'informal variant'     => array( 'de_CH_informal', 'German' ),
			'orthography variant'  => array( 'pt_PT_ao90', 'Portuguese' ),
			'Brazilian Portuguese' => array( 'pt_BR', 'Brazilian Portuguese' ),
			'simplified Chinese'   => array( 'zh_CN', 'Simplified Chinese' ),
			'traditional Chinese'  => array( 'zh_TW', 'Traditional Chinese' ),
			'three letter code'    => array( 'bel', 'Belarusian' ),
			'BCP 47 style hyphen'  => array( 'ja-JP', 'Japanese' ),
			'unrecognized locale'  => array( 'xx_YY', 'English' ),
			'empty locale'         => array( '', 'English' ),
		);
	}

	/**
	 * A large upload is sent as an intermediate size, not as the original.
	 */
	public function test_large_attachment_sends_an_intermediate_size() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment_with_sizes();
		$original      = get_attached_file( $attachment_id );

		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );

		$sent = $this->ai_calls[0]['file_path'];

		$this->assertNotSame( $original, $sent, 'The original should not be sent when a large size exists.' );
		$this->assertFileExists( $sent );
		$this->assertStringContainsString( '-1024x', wp_basename( $sent ) );
		$this->assertLessThan( filesize( $original ), filesize( $sent ), 'The intermediate should be the smaller payload.' );
	}

	/**
	 * An upload too small for an intermediate size falls back to the original.
	 */
	public function test_small_attachment_falls_back_to_the_original() {
		$this->stub_ai_success();

		// canola.jpg is 640x480, so WordPress generates no `large` size for it.
		$attachment_id = $this->create_image_attachment_with_sizes( 'canola.jpg' );

		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );
		$this->assertSame( get_attached_file( $attachment_id ), $this->ai_calls[0]['file_path'] );
	}

	/**
	 * The size filter picks which intermediate size is sent.
	 */
	public function test_image_size_filter_is_respected() {
		$this->stub_ai_success();

		add_filter(
			'ai_media_search_image_size',
			static function () {
				return 'thumbnail';
			}
		);

		$attachment_id = $this->create_image_attachment_with_sizes();

		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );
		$this->assertStringContainsString( '-150x150', wp_basename( $this->ai_calls[0]['file_path'] ) );
	}

	/**
	 * Filtering the size to `full` opts back in to sending the original.
	 */
	public function test_full_size_filter_sends_the_original() {
		$this->stub_ai_success();

		add_filter(
			'ai_media_search_image_size',
			static function () {
				return 'full';
			}
		);

		$attachment_id = $this->create_image_attachment_with_sizes();

		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );
		$this->assertSame( get_attached_file( $attachment_id ), $this->ai_calls[0]['file_path'] );
	}

	/**
	 * A size that was never generated falls back to the original.
	 */
	public function test_unknown_size_falls_back_to_the_original() {
		$this->stub_ai_success();

		add_filter(
			'ai_media_search_image_size',
			static function () {
				return 'ai-media-search-never-registered';
			}
		);

		$attachment_id = $this->create_image_attachment_with_sizes();

		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );
		$this->assertSame( get_attached_file( $attachment_id ), $this->ai_calls[0]['file_path'] );
	}

	/**
	 * Non-image attachments have no intermediate sizes, so the original is sent.
	 */
	public function test_non_image_attachment_sends_the_original() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment( array( 'post_mime_type' => 'video/mp4' ) );

		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );
		$this->assertSame( get_attached_file( $attachment_id ), $this->ai_calls[0]['file_path'] );
		$this->assertSame( 'video/mp4', $this->ai_calls[0]['mime_type'] );
	}

	/**
	 * The MIME type sent describes the file sent, not the original.
	 *
	 * WordPress can be told to write sub-sizes in a different format than the
	 * upload, and the provider is handed whichever format actually went out.
	 */
	public function test_mime_type_matches_the_file_sent() {
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$this->markTestSkipped( 'The image editor cannot write WebP.' );
		}

		$this->stub_ai_success();

		add_filter(
			'image_editor_output_format',
			static function () {
				return array( 'image/jpeg' => 'image/webp' );
			}
		);

		$attachment_id = $this->create_image_attachment_with_sizes();

		ai_media_search_generate_metadata( $attachment_id );

		$this->assertCount( 1, $this->ai_calls );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $attachment_id ), 'The original is still a JPEG.' );
		$this->assertSame( 'image/webp', $this->ai_calls[0]['mime_type'] );
		$this->assertStringEndsWith( '.webp', $this->ai_calls[0]['file_path'] );
	}

	/**
	 * The wrapper hands the AI the attachment file and its MIME type.
	 *
	 * The factory stores no attachment metadata, so there is no intermediate
	 * size to prefer and the original is what goes out.
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
