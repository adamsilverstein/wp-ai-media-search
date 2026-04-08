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
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_wp_ai_media_search_%'" );
