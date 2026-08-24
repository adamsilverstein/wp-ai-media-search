<?php
/**
 * Search integration: filters WP_Query to include AI metadata in media library search.
 *
 * @package AI_Media_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query var used to mark the WP_Query behind a REST media search.
 *
 * `WP_REST_Posts_Controller::get_items()` passes the filtered arguments
 * straight to WP_Query, so flagging them there is what tells the search
 * filters below that this particular query is a media library search.
 *
 * @var string
 */
const AI_MEDIA_SEARCH_REST_QUERY_VAR = 'ai_media_search_rest';

/**
 * Mark a REST media query so the search filters recognize it.
 *
 * The block editor's media inserter searches `/wp/v2/media`, where `WP_ADMIN`
 * is never defined and `is_admin()` is therefore false. `rest_attachment_query`
 * fires only from the media controller, which makes it a narrower signal than
 * the `REST_REQUEST` constant: it says the request is a media library listing
 * rather than merely some REST request. It also covers an internal
 * `rest_do_request()` call, where `REST_REQUEST` is not defined at all.
 *
 * The capability is checked here rather than in the search filters because
 * `/wp/v2/media` answers unauthenticated visitors. AI metadata is private data
 * about the library, so it only steers results for someone who can manage it.
 *
 * @param array           $args    Query arguments for WP_Query.
 * @param WP_REST_Request $request The REST request.
 * @return array Query arguments, flagged when the user can manage media.
 */
function ai_media_search_filter_rest_attachment_query( $args, $request ) {
	unset( $request );

	if ( ! current_user_can( 'upload_files' ) ) {
		return $args;
	}

	$args[ AI_MEDIA_SEARCH_REST_QUERY_VAR ] = true;

	return $args;
}
add_filter( 'rest_attachment_query', 'ai_media_search_filter_rest_attachment_query', 10, 2 );

/**
 * Check if a WP_Query is a media library search that we should modify.
 *
 * Two request contexts qualify: an admin request, which covers the media
 * library list table and the classic modal over admin-ajax, and a REST media
 * query flagged by `ai_media_search_filter_rest_attachment_query()`, which
 * covers the block editor inserter. Front end queries are left alone.
 *
 * @param WP_Query $query The query to check.
 * @return bool
 */
function ai_media_search_is_attachment_search( $query ) {
	$post_type = $query->get( 'post_type' );

	$is_attachment_query = 'attachment' === $post_type
		|| ( is_array( $post_type ) && in_array( 'attachment', $post_type, true ) );

	$is_media_library = is_admin() || $query->get( AI_MEDIA_SEARCH_REST_QUERY_VAR );

	$is_attachment_search = $query->is_search() && $is_attachment_query && (bool) $is_media_library;

	/**
	 * Filters whether a query should search the AI metadata.
	 *
	 * Returning true opens the AI search text up to a query the plugin leaves
	 * alone by default, such as a front end media search. Returning false turns
	 * it off for a query it would otherwise handle.
	 *
	 * @param bool     $is_attachment_search Whether the query is a media library search.
	 * @param WP_Query $query                The query being checked.
	 */
	return (bool) apply_filters( 'ai_media_search_is_attachment_search', $is_attachment_search, $query );
}

/**
 * Add a LEFT JOIN on postmeta for AI search text.
 *
 * Mirrors the pattern in WP_Query::get_posts() for filename search.
 *
 * @param string   $join  The JOIN clause.
 * @param WP_Query $query The query object.
 * @return string Modified JOIN clause.
 */
function ai_media_search_filter_posts_join( $join, $query ) {
	if ( ! ai_media_search_is_attachment_search( $query ) ) {
		return $join;
	}

	global $wpdb;

	$join .= " LEFT JOIN {$wpdb->postmeta} AS ai_media_search_meta"
		. " ON ( {$wpdb->posts}.ID = ai_media_search_meta.post_id"
		. " AND ai_media_search_meta.meta_key = '_wp_ai_media_search_text' )";

	return $join;
}

/**
 * Add AI search meta to the search WHERE clause.
 *
 * For each search term, adds an OR condition matching against the AI-generated
 * search text. Mirrors the pattern in WP_Query::parse_search(). An excluded
 * term instead adds an AND condition ruling out images whose AI-generated text
 * contains it, leaving images that have no AI metadata in the results.
 *
 * @param string   $search The search WHERE clause.
 * @param WP_Query $query  The query object.
 * @return string Modified search clause.
 */
function ai_media_search_filter_posts_search( $search, $query ) {
	if ( ! ai_media_search_is_attachment_search( $query ) || empty( $search ) ) {
		return $search;
	}

	global $wpdb;

	$search_terms = $query->get( 'search_terms' );

	if ( empty( $search_terms ) ) {
		return $search;
	}

	// Detect exclusion prefix used by WP_Query::parse_search(). This is a core
	// filter, so it is intentionally read here without the plugin prefix.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	$exclusion_prefix = apply_filters( 'wp_query_search_exclusion_prefix', '-' );

	foreach ( $search_terms as $term ) {
		// Check if this is an excluded term (e.g., "-cat").
		$exclude = $exclusion_prefix && str_starts_with( $term, $exclusion_prefix );

		if ( $exclude ) {
			$term = substr( $term, strlen( $exclusion_prefix ) );
		}

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		// Mirror the operator core uses for this term, so the clause core built
		// can be found in the search string below.
		$like_op = $exclude ? 'NOT LIKE' : 'LIKE';

		if ( $exclude ) {
			// The joined column cannot answer an exclusion. It is NULL for an
			// attachment with no AI metadata, and `NULL NOT LIKE '%term%'` is
			// NULL rather than true, which would drop every unprocessed image
			// from the results. It also only carries one metadata row per
			// result row, so a second row that happens not to match would let
			// an excluded image back in once the GROUP BY collapsed them.
			// Asking whether any matching row exists avoids both.
			// Only the LIKE value varies, and that goes through a placeholder.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$meta_clause = $wpdb->prepare(
				" AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS ai_media_search_exclude"
					. " WHERE ai_media_search_exclude.post_id = {$wpdb->posts}.ID"
					. " AND ai_media_search_exclude.meta_key = '_wp_ai_media_search_text'"
					. ' AND ai_media_search_exclude.meta_value LIKE %s )',
				$like
			);
		} else {
			$meta_clause = $wpdb->prepare( ' OR (ai_media_search_meta.meta_value LIKE %s)', $like );
		}

		$escaped_like = $wpdb->prepare( '%s', $like );
		$needle       = "({$wpdb->posts}.post_content {$like_op} {$escaped_like})";
		$replacement  = $needle . $meta_clause;

		$search = str_replace( $needle, $replacement, $search );
	}

	return $search;
}

/**
 * Ensure GROUP BY includes post ID to prevent duplicates from the JOIN.
 *
 * @param string   $groupby The GROUP BY clause.
 * @param WP_Query $query   The query object.
 * @return string Modified GROUP BY clause.
 */
function ai_media_search_filter_posts_groupby( $groupby, $query ) {
	if ( ! ai_media_search_is_attachment_search( $query ) ) {
		return $groupby;
	}

	global $wpdb;

	$group_id = "{$wpdb->posts}.ID";

	if ( empty( $groupby ) ) {
		$groupby = $group_id;
	} elseif ( ! str_contains( $groupby, $group_id ) ) {
		$groupby .= ', ' . $group_id;
	}

	return $groupby;
}
