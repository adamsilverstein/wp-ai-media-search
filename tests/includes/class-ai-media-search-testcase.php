<?php
/**
 * Shared test case for the AI Media Search suite.
 *
 * @package AI_Media_Search
 */

/**
 * Base test case: stubs the AI call and provides attachment helpers.
 *
 * The AI Client API is never reached from a test. The base class installs a
 * short-circuit on `ai_media_search_pre_prompt_image` that fails loudly, so a
 * test that forgets to stub a response gets a WP_Error instead of a live
 * request to a provider.
 */
abstract class AI_Media_Search_TestCase extends WP_UnitTestCase {

	/**
	 * Prompts passed to the AI wrapper during the current test.
	 *
	 * @var array[]
	 */
	protected $ai_calls = array();

	/**
	 * Block any real AI request for the duration of the test.
	 */
	public function set_up() {
		parent::set_up();

		$this->ai_calls = array();

		add_filter( 'ai_media_search_pre_prompt_image', array( $this, 'block_ai_request' ), 1, 5 );
	}

	/**
	 * Fail closed when a test has not stubbed the AI response.
	 *
	 * @param null|string|WP_Error $response      Raw response.
	 * @param string               $prompt        The prompt text.
	 * @param string               $file_path     Path to the file to analyze.
	 * @param string               $mime_type     MIME type of the file.
	 * @param int                  $attachment_id Attachment post ID.
	 * @return string|WP_Error
	 */
	public function block_ai_request( $response, $prompt, $file_path, $mime_type, $attachment_id ) {
		$this->ai_calls[] = compact( 'prompt', 'file_path', 'mime_type', 'attachment_id' );

		unset( $response );

		return new WP_Error(
			'ai_media_search_test_unstubbed',
			'The test did not stub an AI response.'
		);
	}

	/**
	 * Stub the AI response for the rest of the test.
	 *
	 * @param string|WP_Error|array $response Raw JSON string, WP_Error, or an
	 *                                        array that is encoded to JSON.
	 */
	protected function stub_ai_response( $response ) {
		if ( is_array( $response ) ) {
			$response = wp_json_encode( $response );
		}

		add_filter(
			'ai_media_search_pre_prompt_image',
			static function () use ( $response ) {
				return $response;
			},
			10,
			5
		);
	}

	/**
	 * Stub a successful AI response built from a description and tags.
	 *
	 * @param string $description Description text.
	 * @param string $tags        Comma-separated tags.
	 */
	protected function stub_ai_success( $description = 'A tabby cat asleep on a windowsill.', $tags = 'cat, tabby, window, sleeping' ) {
		$this->stub_ai_response(
			array(
				'description' => $description,
				'tags'        => $tags,
			)
		);
	}

	/**
	 * Create an image attachment backed by a real file on disk.
	 *
	 * `ai_media_search_generate_metadata()` requires the file to exist, so the
	 * factory alone is not enough.
	 *
	 * Creating an attachment fires `add_attachment`, which queues it. The queue
	 * state is cleared again here so each test starts from a blank attachment and
	 * sets up whatever state it is actually about. Queueing on upload is covered
	 * directly in the hooks tests.
	 *
	 * @param array $args Optional. Extra arguments for the attachment factory.
	 * @return int Attachment ID.
	 */
	protected function create_image_attachment( $args = array() ) {
		$upload_dir = wp_upload_dir();
		$file       = $upload_dir['path'] . '/ai-media-search-' . wp_generate_password( 8, false ) . '.jpg';

		wp_mkdir_p( dirname( $file ) );
		copy( DIR_TESTDATA . '/images/canola.jpg', $file );

		$attachment_id = self::factory()->attachment->create_object(
			array_merge(
				array(
					'file'           => $file,
					'post_mime_type' => 'image/jpeg',
					'post_title'     => 'Test image',
				),
				$args
			)
		);

		update_post_meta( $attachment_id, '_wp_attached_file', $file );

		ai_media_search_reset( $attachment_id );
		wp_clear_scheduled_hook( 'ai_media_search_process_single', array( $attachment_id ) );

		return $attachment_id;
	}

	/**
	 * Give an attachment the AI search text used by the search filters.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $search_text   Text to store.
	 */
	protected function set_search_text( $attachment_id, $search_text ) {
		update_post_meta( $attachment_id, '_wp_ai_media_search_text', $search_text );
		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'complete' );
	}
}
