<?php
/**
 * Tests for includes/rest-api.php.
 *
 * @package AI_Media_Search
 */

/**
 * Covers the read-only status endpoint and the capability that guards it.
 */
class Test_AI_Media_Search_REST_API extends AI_Media_Search_TestCase {

	/**
	 * The route under test.
	 *
	 * @var string
	 */
	const ROUTE = '/ai-media-search/v1/status';

	/**
	 * Stand up a REST server for each test.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Tear the REST server back down.
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Dispatch a GET against the status route.
	 *
	 * @return WP_REST_Response
	 */
	protected function get_status() {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) );
	}

	/**
	 * The route is registered on rest_api_init.
	 *
	 * @covers ::ai_media_search_register_rest_routes
	 */
	public function test_route_is_registered() {
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
	}

	/**
	 * Logged out requests are rejected.
	 *
	 * @covers ::ai_media_search_register_rest_routes
	 */
	public function test_logged_out_request_is_rejected() {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->get_status()->get_status() );
	}

	/**
	 * A user without upload_files is rejected.
	 *
	 * @covers ::ai_media_search_register_rest_routes
	 */
	public function test_subscriber_is_rejected() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, $this->get_status()->get_status() );
	}

	/**
	 * A user who can upload files gets the counts.
	 *
	 * @covers ::ai_media_search_rest_status
	 */
	public function test_author_receives_the_counts() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$response = $this->get_status();

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		foreach ( array( 'complete', 'processing', 'pending', 'failed', 'skipped', 'unprocessed', 'total' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
			$this->assertIsInt( $data[ $key ] );
		}
	}

	/**
	 * The payload reflects the actual state of the library.
	 *
	 * @covers ::ai_media_search_rest_status
	 */
	public function test_counts_reflect_the_library() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		update_post_meta( $this->create_image_attachment(), '_wp_ai_media_search_status', 'complete' );
		update_post_meta( $this->create_image_attachment(), '_wp_ai_media_search_status', 'failed' );
		$this->create_image_attachment();

		$data = $this->get_status()->get_data();

		$this->assertSame( 1, $data['complete'] );
		$this->assertSame( 1, $data['failed'] );
		$this->assertSame( 1, $data['unprocessed'] );
		$this->assertSame( 3, $data['total'] );
	}

	/**
	 * The endpoint is read only.
	 *
	 * @covers ::ai_media_search_register_rest_routes
	 */
	public function test_route_only_accepts_get() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', self::ROUTE ) );

		$this->assertSame( 404, $response->get_status() );
	}
}
