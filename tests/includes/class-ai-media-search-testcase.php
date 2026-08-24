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
	 * The request start time PHP recorded, put back on tear down.
	 *
	 * @var mixed
	 */
	private $request_time_float;

	/**
	 * Block any real AI request for the duration of the test.
	 *
	 * The batch time budget is measured from the start of the request, which
	 * for the suite is whenever PHPUnit started. Each test is given a fresh
	 * request start so it behaves like the single cron request it stands in
	 * for, rather than inheriting however long the suite has been running.
	 */
	public function set_up() {
		parent::set_up();

		$this->ai_calls = array();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Stashed verbatim so tear down can put back exactly what PHP recorded.
		$this->request_time_float      = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );

		add_filter( 'ai_media_search_pre_prompt_image', array( $this, 'block_ai_request' ), 1, 5 );
	}

	/**
	 * Put the real request start time back for anything outside the suite.
	 */
	public function tear_down() {
		if ( null === $this->request_time_float ) {
			unset( $_SERVER['REQUEST_TIME_FLOAT'] );
		} else {
			$_SERVER['REQUEST_TIME_FLOAT'] = $this->request_time_float;
		}

		parent::tear_down();
	}

	/**
	 * Run the rest of the test as a site in another language.
	 *
	 * The `locale` filter is used rather than `switch_to_locale()`, which
	 * refuses any locale with no translation files installed and so would
	 * quietly leave most of these tests running in English. Filtering `locale`
	 * is what `get_locale()` reads either way, and it works for the deliberately
	 * unrecognized locales too. `WP_UnitTestCase` restores the hooks on tear
	 * down, so the next test starts in English again.
	 *
	 * @param string $locale WordPress locale, such as `de_DE`.
	 */
	protected function switch_locale( $locale ) {
		add_filter(
			'locale',
			static function () use ( $locale ) {
				return $locale;
			}
		);
	}

	/**
	 * Fail closed when a test has not stubbed the AI response.
	 *
	 * This runs at priority 1, so a stub added later still wins. Recording the
	 * arguments here means every test can see what the plugin would have sent.
	 *
	 * @param null|string|WP_Error $response      Raw response. Always null here.
	 * @param string               $prompt        The prompt text.
	 * @param string               $file_path     Path to the file to analyze.
	 * @param string               $mime_type     MIME type of the file.
	 * @param int                  $attachment_id Attachment post ID.
	 * @return string|WP_Error
	 */
	public function block_ai_request( $response, $prompt, $file_path, $mime_type, $attachment_id ) {
		unset( $response );

		$this->ai_calls[] = compact( 'prompt', 'file_path', 'mime_type', 'attachment_id' );

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
	 * @param array  $args   Optional. Extra arguments for the attachment factory.
	 * @param string $source Optional. File name inside the test data images
	 *                       directory. Default 'canola.jpg', which at 640x480
	 *                       is too small for an intermediate `large` size.
	 * @return int Attachment ID.
	 */
	protected function create_image_attachment( $args = array(), $source = 'canola.jpg' ) {
		$upload_dir = wp_upload_dir();
		$file       = $upload_dir['path'] . '/ai-media-search-' . wp_generate_password( 8, false ) . '.jpg';

		wp_mkdir_p( dirname( $file ) );
		copy( DIR_TESTDATA . '/images/' . $source, $file );

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
	 * Create an image attachment and generate its intermediate sizes.
	 *
	 * The plain factory stores no attachment metadata, so no intermediate size
	 * exists. Anything covering which file is sent to the AI needs the real
	 * sub-sizes on disk, which means running them through WordPress.
	 *
	 * @param string $source Optional. File name inside the test data images
	 *                       directory. Default 'test-image-large.jpg', at 3000x2250.
	 * @param array  $args   Optional. Extra arguments for the attachment factory.
	 * @return int Attachment ID.
	 */
	protected function create_image_attachment_with_sizes( $source = 'test-image-large.jpg', $args = array() ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = $this->create_image_attachment( $args, $source );

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, get_attached_file( $attachment_id ) )
		);

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
