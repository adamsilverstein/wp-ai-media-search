<?php
/**
 * Tests for includes/search.php.
 *
 * @package AI_Media_Search
 */

/**
 * Covers the JOIN, the search WHERE rewrite and the GROUP BY.
 */
class Test_AI_Media_Search_Search extends AI_Media_Search_TestCase {

	/**
	 * A user who can manage the media library.
	 *
	 * @var int
	 */
	protected static $author_id;

	/**
	 * A user who cannot.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * Create the users the REST tests dispatch as.
	 *
	 * @param WP_UnitTest_Factory $factory Shared fixture factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$author_id     = $factory->user->create( array( 'role' => 'author' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Stand up a REST server so the media route can be dispatched.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Leave the admin screen and the REST server behind between tests.
	 */
	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;

		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Search the media library over the REST route the block editor uses.
	 *
	 * @param string $search Search string.
	 * @return int[] Matched attachment IDs.
	 */
	protected function search_media_rest( $search ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/media' );
		$request->set_param( 'search', $search );
		$request->set_param( 'per_page', 100 );
		$request->set_param( 'orderby', 'id' );
		$request->set_param( 'order', 'asc' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		return array_map(
			static function ( $item ) {
				return (int) $item['id'];
			},
			$response->get_data()
		);
	}

	/**
	 * Pretend the request is a media library screen.
	 */
	protected function go_to_admin() {
		set_current_screen( 'upload.php' );
	}

	/**
	 * Run a media library search and return the matched attachment IDs.
	 *
	 * @param string $search Search string.
	 * @param array  $args   Optional. Extra query arguments.
	 * @return int[] Matched attachment IDs.
	 */
	protected function search_media( $search, $args = array() ) {
		$query = new WP_Query(
			array_merge(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					's'              => $search,
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				),
				$args
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Sort a list of IDs so a comparison does not depend on result order.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return int[] Sorted IDs.
	 */
	protected function sorted( $ids ) {
		sort( $ids );

		return $ids;
	}

	/**
	 * A plain front end query is not a media library search.
	 *
	 * @covers ::ai_media_search_is_attachment_search
	 */
	public function test_is_attachment_search_requires_a_media_library_request() {
		$query = new WP_Query();
		$query->parse_query( array( 'post_type' => 'attachment' ) );
		$query->is_search = true;

		$this->assertFalse( ai_media_search_is_attachment_search( $query ) );

		$this->go_to_admin();

		$this->assertTrue( ai_media_search_is_attachment_search( $query ) );
	}

	/**
	 * A non-search query is left alone.
	 *
	 * @covers ::ai_media_search_is_attachment_search
	 */
	public function test_is_attachment_search_requires_a_search() {
		$this->go_to_admin();

		$query = new WP_Query();
		$query->parse_query( array( 'post_type' => 'attachment' ) );

		$this->assertFalse( ai_media_search_is_attachment_search( $query ) );
	}

	/**
	 * Only attachment queries are touched.
	 *
	 * @covers ::ai_media_search_is_attachment_search
	 */
	public function test_is_attachment_search_requires_the_attachment_post_type() {
		$this->go_to_admin();

		$query = new WP_Query();
		$query->parse_query( array( 'post_type' => 'post' ) );
		$query->is_search = true;

		$this->assertFalse( ai_media_search_is_attachment_search( $query ) );
	}

	/**
	 * A query for several post types including attachments counts.
	 *
	 * @covers ::ai_media_search_is_attachment_search
	 */
	public function test_is_attachment_search_accepts_a_post_type_array() {
		$this->go_to_admin();

		$query = new WP_Query();
		$query->parse_query( array( 'post_type' => array( 'post', 'attachment' ) ) );
		$query->is_search = true;

		$this->assertTrue( ai_media_search_is_attachment_search( $query ) );
	}

	/**
	 * The JOIN is added for the queries we handle and nothing else.
	 *
	 * @covers ::ai_media_search_filter_posts_join
	 */
	public function test_join_is_added_only_for_attachment_searches() {
		global $wpdb;

		$this->go_to_admin();

		$query = new WP_Query();
		$query->parse_query( array( 'post_type' => 'attachment' ) );
		$query->is_search = true;

		$join = ai_media_search_filter_posts_join( '', $query );

		$this->assertStringContainsString( "LEFT JOIN {$wpdb->postmeta} AS ai_media_search_meta", $join );
		$this->assertStringContainsString( '_wp_ai_media_search_text', $join );

		$query->is_search = false;

		$this->assertSame( 'ORIGINAL', ai_media_search_filter_posts_join( 'ORIGINAL', $query ) );
	}

	/**
	 * GROUP BY gets the post ID so the JOIN cannot duplicate rows.
	 *
	 * @covers ::ai_media_search_filter_posts_groupby
	 */
	public function test_groupby_adds_the_post_id() {
		global $wpdb;

		$this->go_to_admin();

		$query = new WP_Query();
		$query->parse_query( array( 'post_type' => 'attachment' ) );
		$query->is_search = true;

		$this->assertSame( "{$wpdb->posts}.ID", ai_media_search_filter_posts_groupby( '', $query ) );

		$this->assertSame(
			"{$wpdb->posts}.post_date, {$wpdb->posts}.ID",
			ai_media_search_filter_posts_groupby( "{$wpdb->posts}.post_date", $query )
		);

		// Already grouped by ID: leave it alone.
		$this->assertSame(
			"{$wpdb->posts}.ID",
			ai_media_search_filter_posts_groupby( "{$wpdb->posts}.ID", $query )
		);

		$query->is_search = false;

		$this->assertSame( '', ai_media_search_filter_posts_groupby( '', $query ) );
	}

	/**
	 * The whole point: a word that only appears in the AI metadata finds the image.
	 *
	 * @covers ::ai_media_search_filter_posts_search
	 */
	public function test_ai_metadata_is_searchable() {
		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'IMG_4523' ) );
		$this->set_search_text( $attachment_id, 'A tabby cat asleep on a windowsill. cat, tabby, sleeping' );

		$this->go_to_admin();

		$this->assertSame( array( $attachment_id ), $this->search_media( 'cat' ) );
	}

	/**
	 * A term that matches nothing returns nothing.
	 *
	 * @covers ::ai_media_search_filter_posts_search
	 */
	public function test_unrelated_term_finds_nothing() {
		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'IMG_4523' ) );
		$this->set_search_text( $attachment_id, 'A tabby cat asleep on a windowsill.' );

		$this->go_to_admin();

		$this->assertSame( array(), $this->search_media( 'dinosaur' ) );
	}

	/**
	 * Every term has to match somewhere, as core does it.
	 *
	 * @covers ::ai_media_search_filter_posts_search
	 */
	public function test_multiple_terms_must_all_match() {
		$both = $this->create_image_attachment( array( 'post_title' => 'IMG_0001' ) );
		$this->set_search_text( $both, 'A tabby cat asleep on a windowsill. cat, window' );

		$one = $this->create_image_attachment( array( 'post_title' => 'IMG_0002' ) );
		$this->set_search_text( $one, 'A tabby cat on a rug. cat, rug' );

		$this->go_to_admin();

		$this->assertSame( array( $both ), $this->search_media( 'cat windowsill' ) );
	}

	/**
	 * One term can come from the title and another from the AI metadata.
	 *
	 * @covers ::ai_media_search_filter_posts_search
	 */
	public function test_terms_can_span_the_title_and_the_ai_metadata() {
		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'Sunset shot' ) );
		$this->set_search_text( $attachment_id, 'Waves breaking on a beach at dusk. beach, ocean' );

		$this->go_to_admin();

		$this->assertSame( array( $attachment_id ), $this->search_media( 'sunset beach' ) );
	}

	/**
	 * The LEFT JOIN must not drop images the plugin has not processed yet.
	 *
	 * @covers ::ai_media_search_filter_posts_join
	 */
	public function test_unprocessed_images_are_still_found_by_title() {
		$processed = $this->create_image_attachment( array( 'post_title' => 'IMG_0001' ) );
		$this->set_search_text( $processed, 'A tabby cat asleep on a windowsill.' );

		$unprocessed = $this->create_image_attachment( array( 'post_title' => 'A cat photo' ) );

		$this->go_to_admin();

		$results = $this->search_media( 'cat' );

		sort( $results );
		$expected = array( $processed, $unprocessed );
		sort( $expected );

		$this->assertSame( $expected, $results );
	}

	/**
	 * The GROUP BY keeps a multi-row meta key from duplicating results.
	 *
	 * @covers ::ai_media_search_filter_posts_groupby
	 */
	public function test_results_are_not_duplicated_by_the_join() {
		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'IMG_4523' ) );

		// Two rows under the same meta key: the LEFT JOIN would return the post twice.
		add_post_meta( $attachment_id, '_wp_ai_media_search_text', 'A tabby cat asleep.' );
		add_post_meta( $attachment_id, '_wp_ai_media_search_text', 'A tabby cat awake.' );

		$this->go_to_admin();

		$this->assertSame( array( $attachment_id ), $this->search_media( 'tabby' ) );
	}

	/**
	 * Front end searches are left as they were.
	 *
	 * @covers ::ai_media_search_filter_posts_search
	 */
	public function test_front_end_search_is_untouched() {
		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'IMG_4523' ) );
		$this->set_search_text( $attachment_id, 'A tabby cat asleep on a windowsill.' );

		$this->assertSame( array(), $this->search_media( 'cat' ) );
	}

	/**
	 * Post searches do not pick up attachment metadata.
	 *
	 * @covers ::ai_media_search_filter_posts_join
	 */
	public function test_post_searches_are_untouched() {
		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'IMG_4523' ) );
		$this->set_search_text( $attachment_id, 'A tabby cat asleep on a windowsill.' );

		$this->go_to_admin();

		$query = new WP_Query(
			array(
				'post_type' => 'post',
				's'         => 'cat',
				'fields'    => 'ids',
			)
		);

		$this->assertSame( array(), $query->posts );
	}

	/**
	 * An empty search clause is returned untouched.
	 *
	 * @covers ::ai_media_search_filter_posts_search
	 */
	public function test_empty_search_clause_is_returned_unchanged() {
		$this->go_to_admin();

		$query = new WP_Query();
		$query->parse_query( array( 'post_type' => 'attachment' ) );
		$query->is_search = true;

		$this->assertSame( '', ai_media_search_filter_posts_search( '', $query ) );
	}

	/**
	 * Excluding a term must not hide images that have no AI metadata.
	 *
	 * An unprocessed image is not a cat, so `-cat` has to return it.
	 *
	 * @link https://github.com/adamsilverstein/wp-ai-media-search/issues/6
	 *
	 * @covers ::ai_media_search_filter_posts_search
	 */
	public function test_exclusion_prefix_keeps_unprocessed_images() {
		$cat = $this->create_image_attachment( array( 'post_title' => 'IMG_0001' ) );
		$this->set_search_text( $cat, 'A tabby cat asleep on a windowsill. cat, tabby' );

		$dog = $this->create_image_attachment( array( 'post_title' => 'IMG_0002' ) );
		$this->set_search_text( $dog, 'A dog running on a path. dog, running' );

		$unprocessed = $this->create_image_attachment( array( 'post_title' => 'IMG_0003' ) );

		$this->go_to_admin();

		$this->assertSame(
			$this->sorted( array( $dog, $unprocessed ) ),
			$this->sorted( $this->search_media( '-cat' ) )
		);
	}

	/**
	 * A positive term and an excluded term in the same search.
	 *
	 * The unprocessed image matches the positive term on its title, and nothing
	 * about it says "cat", so it belongs in the results.
	 *
	 * @link https://github.com/adamsilverstein/wp-ai-media-search/issues/6
	 *
	 * @covers ::ai_media_search_filter_posts_search
	 */
	public function test_exclusion_prefix_combines_with_a_positive_term() {
		$beach_cat = $this->create_image_attachment( array( 'post_title' => 'IMG_0001' ) );
		$this->set_search_text( $beach_cat, 'A cat sitting on the beach. cat, beach' );

		$beach_dog = $this->create_image_attachment( array( 'post_title' => 'IMG_0002' ) );
		$this->set_search_text( $beach_dog, 'A dog splashing at the beach. dog, beach' );

		$forest_dog = $this->create_image_attachment( array( 'post_title' => 'IMG_0003' ) );
		$this->set_search_text( $forest_dog, 'A dog in a forest. dog, forest' );

		$unprocessed = $this->create_image_attachment( array( 'post_title' => 'Beach at dawn' ) );

		$this->go_to_admin();

		$this->assertSame(
			$this->sorted( array( $beach_dog, $unprocessed ) ),
			$this->sorted( $this->search_media( 'beach -cat' ) )
		);
	}

	/**
	 * Several excluded terms in one search.
	 *
	 * Every exclusion has to hold, and none of them may cost the unprocessed
	 * image its place in the results.
	 *
	 * @link https://github.com/adamsilverstein/wp-ai-media-search/issues/6
	 *
	 * @covers ::ai_media_search_filter_posts_search
	 */
	public function test_multiple_exclusion_terms_all_apply() {
		$cat = $this->create_image_attachment( array( 'post_title' => 'IMG_0001' ) );
		$this->set_search_text( $cat, 'A tabby cat asleep on a windowsill. cat, tabby' );

		$dog = $this->create_image_attachment( array( 'post_title' => 'IMG_0002' ) );
		$this->set_search_text( $dog, 'A dog running on a path. dog, running' );

		$bird = $this->create_image_attachment( array( 'post_title' => 'IMG_0003' ) );
		$this->set_search_text( $bird, 'A bird on a fence post. bird, fence' );

		$unprocessed = $this->create_image_attachment( array( 'post_title' => 'IMG_0004' ) );

		$this->go_to_admin();

		$this->assertSame(
			$this->sorted( array( $bird, $unprocessed ) ),
			$this->sorted( $this->search_media( '-cat -dog' ) )
		);
	}

	/**
	 * An excluded term is matched against every AI metadata row for the image.
	 *
	 * The joined column only carries one row at a time, so an image with a
	 * second, non-matching row would otherwise slip past the exclusion.
	 *
	 * @link https://github.com/adamsilverstein/wp-ai-media-search/issues/6
	 *
	 * @covers ::ai_media_search_filter_posts_search
	 */
	public function test_exclusion_applies_to_every_metadata_row() {
		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'IMG_0001' ) );

		add_post_meta( $attachment_id, '_wp_ai_media_search_text', 'A tabby cat asleep.' );
		add_post_meta( $attachment_id, '_wp_ai_media_search_text', 'A dog running on a path.' );

		$this->go_to_admin();

		$this->assertSame( array(), $this->search_media( '-cat' ) );
	}

	/**
	 * An `exact` search should still consult the AI metadata.
	 *
	 * Skipped: the clause is spliced in by rebuilding core's SQL string, which
	 * does not match what core emits for an exact query, so the replace no-ops.
	 * That is issue #10.
	 *
	 * @link https://github.com/adamsilverstein/wp-ai-media-search/issues/10
	 */
	public function test_exact_search_uses_ai_metadata() {
		$this->markTestSkipped( 'Exact queries bypass the AI clause. See issue #10.' );
	}

	/**
	 * The block editor media inserter finds images by AI metadata.
	 *
	 * @link https://github.com/adamsilverstein/wp-ai-media-search/issues/7
	 *
	 * @covers ::ai_media_search_filter_posts_search
	 */
	public function test_rest_media_search_uses_ai_metadata() {
		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'IMG_4523' ) );
		$this->set_search_text( $attachment_id, 'A tabby cat asleep on a windowsill. cat, tabby, sleeping' );

		wp_set_current_user( self::$author_id );

		$this->assertSame( array( $attachment_id ), $this->search_media_rest( 'cat' ) );
	}

	/**
	 * A visitor with no upload rights gets the stock media search.
	 *
	 * `/wp/v2/media` answers unauthenticated requests, and the AI text is not
	 * public data, so it must not steer results for someone who cannot manage
	 * the media library.
	 *
	 * @link https://github.com/adamsilverstein/wp-ai-media-search/issues/7
	 *
	 * @covers ::ai_media_search_filter_rest_attachment_query
	 */
	public function test_rest_media_search_ignores_ai_metadata_for_visitors() {
		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'IMG_4523' ) );
		$this->set_search_text( $attachment_id, 'A tabby cat asleep on a windowsill. cat, tabby, sleeping' );

		wp_set_current_user( 0 );

		$this->assertSame( array(), $this->search_media_rest( 'cat' ) );
	}

	/**
	 * A subscriber cannot manage media either, so the AI text stays out of it.
	 *
	 * @link https://github.com/adamsilverstein/wp-ai-media-search/issues/7
	 *
	 * @covers ::ai_media_search_filter_rest_attachment_query
	 */
	public function test_rest_media_search_ignores_ai_metadata_for_subscribers() {
		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'IMG_4523' ) );
		$this->set_search_text( $attachment_id, 'A tabby cat asleep on a windowsill. cat, tabby, sleeping' );

		wp_set_current_user( self::$subscriber_id );

		$this->assertSame( array(), $this->search_media_rest( 'cat' ) );
	}

	/**
	 * Titles still work over REST, and unprocessed images are not dropped.
	 *
	 * @link https://github.com/adamsilverstein/wp-ai-media-search/issues/7
	 *
	 * @covers ::ai_media_search_filter_posts_join
	 */
	public function test_rest_media_search_keeps_unprocessed_images() {
		$processed = $this->create_image_attachment( array( 'post_title' => 'IMG_0001' ) );
		$this->set_search_text( $processed, 'A tabby cat asleep on a windowsill.' );

		$unprocessed = $this->create_image_attachment( array( 'post_title' => 'A cat photo' ) );

		wp_set_current_user( self::$author_id );

		$this->assertSame(
			$this->sorted( array( $processed, $unprocessed ) ),
			$this->sorted( $this->search_media_rest( 'cat' ) )
		);
	}

	/**
	 * A REST search for posts is not an attachment search.
	 *
	 * @link https://github.com/adamsilverstein/wp-ai-media-search/issues/7
	 *
	 * @covers ::ai_media_search_filter_rest_attachment_query
	 */
	public function test_rest_post_search_is_untouched() {
		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'IMG_4523' ) );
		$this->set_search_text( $attachment_id, 'A tabby cat asleep on a windowsill.' );

		wp_set_current_user( self::$author_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'search', 'cat' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}

	/**
	 * A front end query is left alone even for a user who can manage media.
	 *
	 * The REST flag is what opens the gate, not the capability on its own.
	 *
	 * @link https://github.com/adamsilverstein/wp-ai-media-search/issues/7
	 *
	 * @covers ::ai_media_search_is_attachment_search
	 */
	public function test_front_end_search_is_untouched_for_media_managers() {
		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'IMG_4523' ) );
		$this->set_search_text( $attachment_id, 'A tabby cat asleep on a windowsill.' );

		wp_set_current_user( self::$author_id );

		$this->assertSame( array(), $this->search_media( 'cat' ) );
	}

	/**
	 * A front end post search still runs core's plain search.
	 *
	 * @covers ::ai_media_search_is_attachment_search
	 */
	public function test_front_end_post_search_is_unaffected() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'A cat story',
				'post_content' => 'All about a cat.',
				'post_status'  => 'publish',
			)
		);

		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'IMG_4523' ) );
		$this->set_search_text( $attachment_id, 'A tabby cat asleep on a windowsill.' );

		wp_set_current_user( self::$author_id );

		$query = new WP_Query(
			array(
				'post_type' => 'post',
				's'         => 'cat',
				'fields'    => 'ids',
			)
		);

		$this->assertSame( array( $post_id ), array_map( 'intval', $query->posts ) );
	}

	/**
	 * The gate is filterable, so a site can opt the front end in.
	 *
	 * @covers ::ai_media_search_is_attachment_search
	 */
	public function test_is_attachment_search_is_filterable() {
		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'IMG_4523' ) );
		$this->set_search_text( $attachment_id, 'A tabby cat asleep on a windowsill.' );

		$this->assertSame( array(), $this->search_media( 'cat' ) );

		add_filter( 'ai_media_search_is_attachment_search', '__return_true' );

		$this->assertSame( array( $attachment_id ), $this->search_media( 'cat' ) );
	}

	/**
	 * The filter can also turn the integration off where it would have run.
	 *
	 * @covers ::ai_media_search_is_attachment_search
	 */
	public function test_is_attachment_search_filter_can_opt_out() {
		$attachment_id = $this->create_image_attachment( array( 'post_title' => 'IMG_4523' ) );
		$this->set_search_text( $attachment_id, 'A tabby cat asleep on a windowsill.' );

		$this->go_to_admin();

		add_filter( 'ai_media_search_is_attachment_search', '__return_false' );

		$this->assertSame( array(), $this->search_media( 'cat' ) );
	}

	/**
	 * The REST flag is only added for a user who can manage media.
	 *
	 * @covers ::ai_media_search_filter_rest_attachment_query
	 */
	public function test_rest_attachment_query_is_flagged_by_capability() {
		$request = new WP_REST_Request( 'GET', '/wp/v2/media' );

		wp_set_current_user( self::$author_id );

		$args = ai_media_search_filter_rest_attachment_query( array( 'post_type' => 'attachment' ), $request );

		$this->assertArrayHasKey( 'ai_media_search_rest', $args );
		$this->assertTrue( $args['ai_media_search_rest'] );

		wp_set_current_user( self::$subscriber_id );

		$this->assertSame(
			array( 'post_type' => 'attachment' ),
			ai_media_search_filter_rest_attachment_query( array( 'post_type' => 'attachment' ), $request )
		);
	}
}
