<?php
/**
 * AI Media Search uninstall script.
 *
 * Removes all plugin metadata from the database on full plugin deletion.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$like = $wpdb->esc_like( '_wp_ai_media_search_' ) . '%';
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$like
	)
);
