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
 * Check if a WP_Query is an admin attachment search that we should modify.
 *
 * @param WP_Query $query The query to check.
 * @return bool
 */
function ai_media_search_is_attachment_search( $query ) {
	if ( ! is_admin() ) {
		return false;
	}

	if ( ! $query->is_search() ) {
		return false;
	}

	$post_type = $query->get( 'post_type' );

	if ( 'attachment' === $post_type ) {
		return true;
	}

	if ( is_array( $post_type ) && in_array( 'attachment', $post_type, true ) ) {
		return true;
	}

	return false;
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
 * search text. Mirrors the pattern in WP_Query::parse_search().
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

	foreach ( $search_terms as $term ) {
		$like = '%' . $wpdb->esc_like( $term ) . '%';
		$or_clause = $wpdb->prepare( ' OR (ai_media_search_meta.meta_value LIKE %s)', $like );

		// Insert the OR clause before the closing parenthesis of each term's group.
		// The search clause structure is: AND ((col1 LIKE %s) OR (col2 LIKE %s))
		// We find the term's LIKE pattern and append our OR before the group closes.
		$escaped_like = $wpdb->prepare( '%s', $like );
		$needle       = "({$wpdb->posts}.post_content LIKE {$escaped_like})";
		$replacement  = $needle . $or_clause;

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
