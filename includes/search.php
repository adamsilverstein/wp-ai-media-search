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
 * Table alias the AI search text is joined under.
 *
 * Naming it once keeps the JOIN, the condition that reads it and the check for
 * a clause that already carries that condition from drifting apart.
 *
 * @var string
 */
const AI_MEDIA_SEARCH_META_ALIAS = 'ai_media_search_meta';

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

	$alias = AI_MEDIA_SEARCH_META_ALIAS;

	$join .= " LEFT JOIN {$wpdb->postmeta} AS {$alias}"
		. " ON ( {$wpdb->posts}.ID = {$alias}.post_id"
		. " AND {$alias}.meta_key = '_wp_ai_media_search_text' )";

	return $join;
}

/**
 * Work out which post columns core is searching for this query.
 *
 * `WP_Query::parse_search()` resolves the column list and then discards it: the
 * result is never written back to the query vars, so the only way to know what
 * core searched is to resolve it the same way. Doing that keeps the AI
 * condition in step with a site that narrows the columns through
 * `post_search_columns`.
 *
 * @param WP_Query $query The query object.
 * @return string[] Column names, always a subset of the three columns core supports.
 */
function ai_media_search_get_search_columns( $query ) {
	$default_columns = array( 'post_title', 'post_excerpt', 'post_content' );

	$columns = $query->get( 'search_columns' );

	if ( empty( $columns ) ) {
		$columns = $default_columns;
	}

	if ( ! is_array( $columns ) ) {
		$columns = array( $columns );
	}

	/*
	 * This filter is documented in wp-includes/class-wp-query.php. It is a core
	 * filter, so it is intentionally applied here without the plugin prefix.
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	$columns = (array) apply_filters( 'post_search_columns', $columns, $query->get( 's' ), $query );

	$columns = array_values( array_intersect( $columns, $default_columns ) );

	if ( empty( $columns ) ) {
		$columns = $default_columns;
	}

	return $columns;
}

/**
 * Add AI search meta to the search WHERE clause.
 *
 * The AI condition is composed from the query's own parsed search terms and
 * then combined with whatever clause came in, rather than spliced into it by
 * matching a rebuilt copy of core's SQL. Reconstructing that needle only worked
 * for the exact SQL core happens to emit today: an `exact` query, a
 * `post_search_columns` filter that drops a column, or another plugin rewriting
 * the clause first all left nothing to match, and the AI text quietly stopped
 * being searched.
 *
 * The incoming clause is a run of ` AND (…)` conjunctions, so it is wrapped
 * whole and offered as one alternative to a condition built here from
 * `search_terms`, which mirrors what core searches and adds the AI text to each
 * term. Because that alternative is a superset of core's own condition, an
 * untouched clause comes out with exactly the per-term OR semantics the string
 * replacement used to produce, and a clause another plugin has rewritten keeps
 * matching what it matched before.
 *
 * An excluded term is different: it has to narrow, so it is left out of the
 * alternative and appended as its own top level condition instead.
 *
 * @param string   $search The search WHERE clause.
 * @param WP_Query $query  The query object.
 * @return string Modified search clause.
 */
function ai_media_search_filter_posts_search( $search, $query ) {
	if ( ! ai_media_search_is_attachment_search( $query ) || empty( $search ) ) {
		return $search;
	}

	$alias = AI_MEDIA_SEARCH_META_ALIAS;

	// The clause already reads the AI text, so this is a second pass over a
	// clause that has been handled. Wrapping it again would only nest the same
	// condition inside itself.
	if ( str_contains( $search, $alias . '.meta_value' ) ) {
		return $search;
	}

	$search_terms = $query->get( 'search_terms' );

	if ( ! is_array( $search_terms ) || array() === $search_terms ) {
		return $search;
	}

	global $wpdb;

	$columns = ai_media_search_get_search_columns( $query );

	// Core drops the wildcards for an `exact` query, matching the whole column.
	$exact    = $query->get( 'exact' );
	$wildcard = empty( $exact ) ? '%' : '';

	// Detect exclusion prefix used by WP_Query::parse_search(). This is a core
	// filter, so it is intentionally read here without the plugin prefix.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	$exclusion_prefix = apply_filters( 'wp_query_search_exclusion_prefix', '-' );

	$term_conditions = array();
	$exclusions      = array();

	foreach ( $search_terms as $term ) {
		$term = (string) $term;

		// Check if this is an excluded term (e.g., "-cat").
		$exclude = $exclusion_prefix && str_starts_with( $term, $exclusion_prefix );

		if ( $exclude ) {
			$term = substr( $term, strlen( $exclusion_prefix ) );
		}

		$like     = $wildcard . $wpdb->esc_like( $term ) . $wildcard;
		$like_op  = $exclude ? 'NOT LIKE' : 'LIKE';
		$andor_op = $exclude ? 'AND' : 'OR';

		$parts = array();

		foreach ( $columns as $column ) {
			// The column name comes from a fixed list and the operator from the
			// two literals above. Only the value varies, through a placeholder.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$parts[] = $wpdb->prepare( "({$wpdb->posts}.{$column} {$like_op} %s)", $like );
		}

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
			$exclusions[] = $wpdb->prepare(
				"AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS ai_media_search_exclude"
					. " WHERE ai_media_search_exclude.post_id = {$wpdb->posts}.ID"
					. " AND ai_media_search_exclude.meta_key = '_wp_ai_media_search_text'"
					. ' AND ai_media_search_exclude.meta_value LIKE %s )',
				$like
			);
		} else {
			// The alias is a constant and the value goes through a placeholder.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$parts[] = $wpdb->prepare( "({$alias}.meta_value LIKE %s)", $like );
		}

		$term_conditions[] = '(' . implode( " {$andor_op} ", $parts ) . ')';
	}

	$ai_condition = implode( ' AND ', $term_conditions );

	// Core appends this to its own clause for a logged out visitor. The
	// alternative built here has to carry it as well, or the OR below would be
	// a way around it.
	if ( ! is_user_logged_in() ) {
		$ai_condition .= " AND ({$wpdb->posts}.post_password = '')";
	}

	// `1=1` opens the group so the incoming clause, a run of ` AND (…)`
	// conjunctions, reads correctly inside it. Nothing else is assumed about
	// what it contains, which is what leaves another plugin's work intact.
	return " AND ( ( 1=1 {$search} ) OR ( {$ai_condition} ) ) " . implode( ' ', $exclusions );
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
