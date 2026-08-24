<?php
/**
 * Tests for includes/admin.php and the regenerate endpoint that backs it.
 *
 * @package AI_Media_Search
 */

/**
 * Covers the attachment description panel and the guards on regeneration.
 */
class Test_AI_Media_Search_Admin extends AI_Media_Search_TestCase {

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
	 * Build the regenerate route for an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string
	 */
	protected function regenerate_route( $attachment_id ) {
		return '/ai-media-search/v1/attachments/' . $attachment_id . '/regenerate';
	}

	/**
	 * Dispatch a regenerate request.
	 *
	 * @param int         $attachment_id Attachment post ID.
	 * @param string|null $nonce         Nonce to send, or null to send none.
	 * @return WP_REST_Response
	 */
	protected function regenerate( $attachment_id, $nonce ) {
		$request = new WP_REST_Request( 'POST', $this->regenerate_route( $attachment_id ) );

		if ( null !== $nonce ) {
			$request->set_param( 'nonce', $nonce );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Create a nonce for the current user against an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string
	 */
	protected function regenerate_nonce( $attachment_id ) {
		return wp_create_nonce( 'ai_media_search_regenerate_' . $attachment_id );
	}

	/**
	 * Create an attachment that has already been described.
	 *
	 * @param string $description Stored description.
	 * @param string $tags        Stored tags.
	 * @param array  $args        Extra attachment arguments.
	 * @return int Attachment ID.
	 */
	protected function create_described_attachment( $description = 'A tabby cat asleep on a windowsill.', $tags = 'cat, tabby, window', $args = array() ) {
		$attachment_id = $this->create_image_attachment( $args );

		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'complete' );
		update_post_meta(
			$attachment_id,
			'_wp_ai_media_search_data',
			array(
				'description'  => $description,
				'tags'         => $tags,
				'generated_at' => time() - HOUR_IN_SECONDS,
				'version'      => 1,
				'media_type'   => 'image',
			)
		);
		update_post_meta( $attachment_id, '_wp_ai_media_search_text', $description . ' ' . $tags );

		return $attachment_id;
	}

	/**
	 * Capture the rendered panel for an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string
	 */
	protected function render_panel( $attachment_id ) {
		ob_start();
		ai_media_search_render_attachment_panel( $attachment_id );

		return (string) ob_get_clean();
	}

	/**
	 * The stored description, tags and status all reach the screen.
	 *
	 * @covers ::ai_media_search_render_attachment_panel
	 */
	public function test_panel_shows_description_tags_and_status() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$attachment_id = $this->create_described_attachment();
		$html          = $this->render_panel( $attachment_id );

		$this->assertStringContainsString( 'A tabby cat asleep on a windowsill.', $html );
		$this->assertStringContainsString( 'cat, tabby, window', $html );
		$this->assertStringContainsString( 'Described', $html );
		$this->assertStringContainsString( 'ai-media-search-regenerate', $html );
	}

	/**
	 * An attachment nobody has described says so instead of rendering blank.
	 *
	 * @covers ::ai_media_search_render_attachment_panel
	 */
	public function test_panel_reports_an_undescribed_attachment() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->render_panel( $this->create_image_attachment() );

		$this->assertStringContainsString( 'Not described yet', $html );
	}

	/**
	 * A failure shows the stored error, so retrying is an informed choice.
	 *
	 * @covers ::ai_media_search_get_failure_message
	 */
	public function test_panel_shows_the_stored_error_for_a_failed_attachment() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$attachment_id = $this->create_image_attachment();

		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'failed' );
		update_post_meta(
			$attachment_id,
			'_wp_ai_media_search_error',
			array(
				'code'       => 'ai_media_search_invalid_response',
				'message'    => 'AI returned an invalid response structure.',
				'attempts'   => 2,
				'last_tried' => time(),
			)
		);

		$html = $this->render_panel( $attachment_id );

		$this->assertStringContainsString( 'Failed', $html );
		$this->assertStringContainsString( 'AI returned an invalid response structure.', $html );
	}

	/**
	 * A skipped attachment says it will not be retried on its own.
	 *
	 * @covers ::ai_media_search_get_failure_message
	 */
	public function test_panel_explains_a_skipped_attachment() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$attachment_id = $this->create_image_attachment();

		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'skipped' );
		update_post_meta(
			$attachment_id,
			'_wp_ai_media_search_error',
			array(
				'code'       => 'ai_media_search_file_missing',
				'message'    => 'Attachment file not found.',
				'attempts'   => 3,
				'last_tried' => time(),
			)
		);

		$html = $this->render_panel( $attachment_id );

		$this->assertStringContainsString( 'Skipped', $html );
		$this->assertStringContainsString( 'Attachment file not found.', $html );
		$this->assertStringContainsString( 'will not retry on its own', $html );
	}

	/**
	 * A user who cannot edit the attachment gets no Regenerate button.
	 *
	 * @covers ::ai_media_search_render_attachment_panel
	 */
	public function test_panel_hides_the_button_from_a_user_who_cannot_edit() {
		$owner         = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$attachment_id = $this->create_described_attachment(
			'A tabby cat asleep on a windowsill.',
			'cat, tabby, window',
			array( 'post_author' => $owner )
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$html = $this->render_panel( $attachment_id );

		$this->assertStringContainsString( 'A tabby cat asleep on a windowsill.', $html );
		$this->assertStringNotContainsString( 'ai-media-search-regenerate', $html );
	}

	/**
	 * The panel is attached to the media modal's attachment details.
	 *
	 * @covers ::ai_media_search_attachment_fields_to_edit
	 */
	public function test_attachment_fields_include_the_panel() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$attachment_id = $this->create_described_attachment();
		$fields        = apply_filters( 'attachment_fields_to_edit', array(), get_post( $attachment_id ) );

		$this->assertArrayHasKey( 'ai_media_search', $fields );
		$this->assertSame( 'html', $fields['ai_media_search']['input'] );
		$this->assertTrue( $fields['ai_media_search']['show_in_modal'] );
		$this->assertStringContainsString( 'A tabby cat asleep on a windowsill.', $fields['ai_media_search']['html'] );
	}

	/**
	 * Non-image attachments are left alone.
	 *
	 * @covers ::ai_media_search_attachment_fields_to_edit
	 */
	public function test_attachment_fields_skip_unsupported_types() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'doc.pdf',
				'post_mime_type' => 'application/pdf',
			)
		);

		$fields = apply_filters( 'attachment_fields_to_edit', array(), get_post( $attachment_id ) );

		$this->assertArrayNotHasKey( 'ai_media_search', $fields );
	}

	/**
	 * The Edit Media meta box is registered for attachments.
	 *
	 * @covers ::ai_media_search_add_meta_boxes
	 */
	public function test_meta_box_is_registered_for_attachments() {
		$this->assertSame( 10, has_action( 'add_meta_boxes_attachment', 'ai_media_search_add_meta_boxes' ) );
	}

	/**
	 * The meta box renders the same panel as the modal.
	 *
	 * @covers ::ai_media_search_render_meta_box
	 */
	public function test_meta_box_renders_the_panel() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$attachment_id = $this->create_described_attachment();

		ob_start();
		ai_media_search_render_meta_box( get_post( $attachment_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ai-media-search-panel', $html );
		$this->assertStringContainsString( 'A tabby cat asleep on a windowsill.', $html );
	}

	/**
	 * The regenerate route is registered.
	 *
	 * @covers ::ai_media_search_register_rest_routes
	 */
	public function test_regenerate_route_is_registered() {
		$this->assertArrayHasKey(
			'/ai-media-search/v1/attachments/(?P<id>[\d]+)/regenerate',
			rest_get_server()->get_routes()
		);
	}

	/**
	 * A capable user with a valid nonce gets a fresh description.
	 *
	 * @covers ::ai_media_search_rest_regenerate
	 */
	public function test_regenerate_succeeds_for_a_capable_user_with_a_valid_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$attachment_id = $this->create_described_attachment();

		$this->stub_ai_success( 'A golden retriever running on a beach.', 'dog, beach, running' );

		$response = $this->regenerate( $attachment_id, $this->regenerate_nonce( $attachment_id ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( 'complete', $data['status'] );
		$this->assertSame( 'A golden retriever running on a beach.', $data['description'] );
		$this->assertSame( 'dog, beach, running', $data['tags'] );
		$this->assertStringContainsString( 'A golden retriever running on a beach.', $data['html'] );

		$stored = get_post_meta( $attachment_id, '_wp_ai_media_search_data', true );

		$this->assertSame( 'A golden retriever running on a beach.', $stored['description'] );
	}

	/**
	 * A skipped attachment can be revived from the admin.
	 *
	 * @covers ::ai_media_search_regenerate_attachment
	 */
	public function test_regenerate_revives_a_skipped_attachment() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$attachment_id = $this->create_image_attachment();

		update_post_meta( $attachment_id, '_wp_ai_media_search_status', 'skipped' );
		update_post_meta(
			$attachment_id,
			'_wp_ai_media_search_error',
			array(
				'code'       => 'ai_media_search_invalid_response',
				'message'    => 'AI returned an invalid response structure.',
				'attempts'   => 3,
				'last_tried' => time(),
			)
		);

		$this->stub_ai_success( 'A bowl of ramen on a wooden table.', 'ramen, food, bowl' );

		$response = $this->regenerate( $attachment_id, $this->regenerate_nonce( $attachment_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'complete', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertSame( '', get_post_meta( $attachment_id, '_wp_ai_media_search_error', true ) );
	}

	/**
	 * An author can regenerate their own upload.
	 *
	 * @covers ::ai_media_search_rest_regenerate_permission_check
	 */
	public function test_regenerate_allows_the_author_of_the_attachment() {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );

		wp_set_current_user( $author_id );

		$attachment_id = $this->create_described_attachment(
			'A tabby cat asleep on a windowsill.',
			'cat, tabby, window',
			array( 'post_author' => $author_id )
		);

		$this->stub_ai_success( 'A golden retriever running on a beach.', 'dog, beach, running' );

		$response = $this->regenerate( $attachment_id, $this->regenerate_nonce( $attachment_id ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * A user without edit rights on the attachment is refused, nonce or not.
	 *
	 * @covers ::ai_media_search_rest_regenerate_permission_check
	 */
	public function test_regenerate_is_rejected_without_the_capability() {
		$owner         = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$attachment_id = $this->create_described_attachment(
			'A tabby cat asleep on a windowsill.',
			'cat, tabby, window',
			array( 'post_author' => $owner )
		);

		// An author can upload files, but not edit somebody else's attachment.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertTrue( current_user_can( 'upload_files' ) );
		$this->assertFalse( current_user_can( 'edit_post', $attachment_id ) );

		$this->stub_ai_success( 'Should never be generated.', 'nope' );

		$response = $this->regenerate( $attachment_id, $this->regenerate_nonce( $attachment_id ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'ai_media_search_cannot_edit', $response->get_data()['code'] );

		$stored = get_post_meta( $attachment_id, '_wp_ai_media_search_data', true );

		$this->assertSame( 'A tabby cat asleep on a windowsill.', $stored['description'] );
	}

	/**
	 * A request with no nonce is refused.
	 *
	 * @covers ::ai_media_search_rest_regenerate_permission_check
	 */
	public function test_regenerate_is_rejected_without_a_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$attachment_id = $this->create_described_attachment();

		$this->stub_ai_success( 'Should never be generated.', 'nope' );

		$response = $this->regenerate( $attachment_id, null );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'ai_media_search_invalid_nonce', $response->get_data()['code'] );

		$stored = get_post_meta( $attachment_id, '_wp_ai_media_search_data', true );

		$this->assertSame( 'A tabby cat asleep on a windowsill.', $stored['description'] );
	}

	/**
	 * A request with a junk nonce is refused.
	 *
	 * @covers ::ai_media_search_rest_regenerate_permission_check
	 */
	public function test_regenerate_is_rejected_with_an_invalid_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$attachment_id = $this->create_described_attachment();

		$this->stub_ai_success( 'Should never be generated.', 'nope' );

		$response = $this->regenerate( $attachment_id, 'not-a-real-nonce' );

		$this->assertSame( 403, $response->get_status() );

		$stored = get_post_meta( $attachment_id, '_wp_ai_media_search_data', true );

		$this->assertSame( 'A tabby cat asleep on a windowsill.', $stored['description'] );
	}

	/**
	 * A nonce minted for one attachment does not unlock another.
	 *
	 * @covers ::ai_media_search_rest_regenerate_permission_check
	 */
	public function test_regenerate_rejects_a_nonce_for_a_different_attachment() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$target = $this->create_described_attachment();
		$other  = $this->create_described_attachment();

		$this->stub_ai_success( 'Should never be generated.', 'nope' );

		$response = $this->regenerate( $target, $this->regenerate_nonce( $other ) );

		$this->assertSame( 403, $response->get_status() );

		$stored = get_post_meta( $target, '_wp_ai_media_search_data', true );

		$this->assertSame( 'A tabby cat asleep on a windowsill.', $stored['description'] );
	}

	/**
	 * Logged out requests never reach the endpoint.
	 *
	 * @covers ::ai_media_search_rest_regenerate_permission_check
	 */
	public function test_regenerate_is_rejected_when_logged_out() {
		$attachment_id = $this->create_described_attachment();

		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->regenerate( $attachment_id, 'not-a-real-nonce' )->get_status() );
	}

	/**
	 * The endpoint refuses anything that is not an attachment.
	 *
	 * @covers ::ai_media_search_rest_regenerate
	 */
	public function test_regenerate_returns_404_for_a_missing_attachment() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create();

		$response = $this->regenerate( $post_id, $this->regenerate_nonce( $post_id ) );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Nothing is thrown away when no AI provider is configured.
	 *
	 * @covers ::ai_media_search_rest_regenerate
	 */
	public function test_regenerate_keeps_the_stored_description_when_ai_is_unavailable() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$attachment_id = $this->create_described_attachment();

		add_filter( 'wp_supports_ai', '__return_false' );

		$response = $this->regenerate( $attachment_id, $this->regenerate_nonce( $attachment_id ) );

		$this->assertSame( 503, $response->get_status() );

		$stored = get_post_meta( $attachment_id, '_wp_ai_media_search_data', true );

		$this->assertSame( 'A tabby cat asleep on a windowsill.', $stored['description'] );
	}

	/**
	 * The regenerated action reports the resulting status.
	 *
	 * @covers ::ai_media_search_regenerate_attachment
	 */
	public function test_regenerated_action_fires_with_the_resulting_status() {
		$fired = array();

		add_action(
			'ai_media_search_regenerated',
			static function ( $attachment_id, $status ) use ( &$fired ) {
				$fired[] = array( $attachment_id, $status );
			},
			10,
			2
		);

		$attachment_id = $this->create_image_attachment();

		$this->stub_ai_success();

		ai_media_search_regenerate_attachment( $attachment_id );

		$this->assertSame( array( array( $attachment_id, 'complete' ) ), $fired );
	}
}
