<?php
/**
 * Tests for the plugin bootstrap in ai-media-search.php.
 *
 * @package AI_Media_Search
 */

/**
 * Covers the hook wiring, the cron schedule and the activation handlers.
 */
class Test_AI_Media_Search_Plugin extends AI_Media_Search_TestCase {

	/**
	 * Every hook the plugin relies on is registered on init.
	 *
	 * @dataProvider data_registered_hooks
	 * @covers ::ai_media_search_init
	 *
	 * @param string $hook     Hook name.
	 * @param string $callback Callback name.
	 */
	public function test_hooks_are_registered( $hook, $callback ) {
		$this->assertSame( 10, has_filter( $hook, $callback ) );
	}

	/**
	 * Data provider of hook and callback pairs.
	 *
	 * @return array[]
	 */
	public function data_registered_hooks() {
		return array(
			array( 'add_attachment', 'ai_media_search_on_new_attachment' ),
			array( 'transition_post_status', 'ai_media_search_on_publish' ),
			array( 'ai_media_search_process_single', 'ai_media_search_process_single' ),
			array( 'ai_media_search_batch_process', 'ai_media_search_batch_process' ),
			array( 'posts_join', 'ai_media_search_filter_posts_join' ),
			array( 'posts_search', 'ai_media_search_filter_posts_search' ),
			array( 'posts_groupby', 'ai_media_search_filter_posts_groupby' ),
			array( 'rest_api_init', 'ai_media_search_register_rest_routes' ),
		);
	}

	/**
	 * The batch cron is scheduled.
	 *
	 * @covers ::ai_media_search_init
	 */
	public function test_batch_cron_is_scheduled() {
		$this->assertNotFalse( wp_next_scheduled( 'ai_media_search_batch_process' ) );
	}

	/**
	 * The recurrence is hourly and filterable.
	 *
	 * @covers ::ai_media_search_get_cron_interval
	 */
	public function test_cron_interval_is_filterable() {
		$this->assertSame( 'hourly', ai_media_search_get_cron_interval() );

		add_filter( 'ai_media_search_cron_interval', static fn () => 'twicedaily' );

		$this->assertSame( 'twicedaily', ai_media_search_get_cron_interval() );
	}

	/**
	 * Activation schedules the batch cron.
	 *
	 * @covers ::ai_media_search_activate
	 */
	public function test_activation_schedules_the_batch_cron() {
		wp_clear_scheduled_hook( 'ai_media_search_batch_process' );
		$this->assertFalse( wp_next_scheduled( 'ai_media_search_batch_process' ) );

		ai_media_search_activate();

		$this->assertNotFalse( wp_next_scheduled( 'ai_media_search_batch_process' ) );
	}

	/**
	 * Deactivation clears both the batch cron and any queued single events.
	 *
	 * @covers ::ai_media_search_deactivate
	 */
	public function test_deactivation_clears_scheduled_work() {
		$attachment_id = $this->create_image_attachment();
		ai_media_search_on_new_attachment( $attachment_id );

		$this->assertNotFalse( wp_next_scheduled( 'ai_media_search_process_single', array( $attachment_id ) ) );

		ai_media_search_deactivate();

		$this->assertFalse( wp_next_scheduled( 'ai_media_search_batch_process' ) );
		$this->assertFalse( wp_next_scheduled( 'ai_media_search_process_single', array( $attachment_id ) ) );
	}

	/**
	 * Deactivation leaves generated metadata in place.
	 *
	 * @covers ::ai_media_search_deactivate
	 */
	public function test_deactivation_preserves_metadata() {
		$this->stub_ai_success();

		$attachment_id = $this->create_image_attachment();
		ai_media_search_process_single( $attachment_id );

		ai_media_search_deactivate();

		$this->assertSame( 'complete', get_post_meta( $attachment_id, '_wp_ai_media_search_status', true ) );
		$this->assertNotEmpty( get_post_meta( $attachment_id, '_wp_ai_media_search_text', true ) );
	}
}
