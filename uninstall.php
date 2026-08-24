<?php
/**
 * AI Media Search uninstall script.
 *
 * Removes all plugin metadata from the database on full plugin deletion.
 *
 * @package AI_Media_Search
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$ai_media_search_like = $wpdb->esc_like( '_wp_ai_media_search_' ) . '%';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup of plugin meta on delete; there is no core API for a LIKE-matched meta_key delete, and caching is irrelevant here.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$ai_media_search_like
	)
);
