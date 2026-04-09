<?php
/**
 * Plugin Name: AI Media Search
 * Plugin URI:  https://github.com/adamsilverstein/wp-ai-media-search
 * Description: Uses WordPress AI to generate searchable descriptions for media library images.
 * Version:     0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Author:      Adam Silverstein
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-media-search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load plugin components.
require_once __DIR__ . '/includes/ai-generation.php';
require_once __DIR__ . '/includes/processing.php';
require_once __DIR__ . '/includes/hooks.php';
require_once __DIR__ . '/includes/search.php';
require_once __DIR__ . '/includes/rest-api.php';
require_once __DIR__ . '/includes/admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/cli.php';
}

/**
 * Get the cron recurrence interval for batch processing.
 *
 * @return string A registered cron schedule name (e.g., 'hourly', 'twicedaily').
 */
function ai_media_search_get_cron_interval() {
	/**
	 * Filters the cron recurrence interval for batch processing.
	 *
	 * Must be a schedule name registered with wp_get_schedules().
	 *
	 * @param string $interval Cron schedule name. Default 'hourly'.
	 */
	return apply_filters( 'ai_media_search_cron_interval', 'hourly' );
}

/**
 * Bootstrap the plugin. All hooks are registered here, gated on AI support.
 */
function ai_media_search_init() {
	if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
		return;
	}

	// New upload processing.
	add_action( 'add_attachment', 'ai_media_search_on_new_attachment' );

	// Post publish processing.
	add_action( 'transition_post_status', 'ai_media_search_on_publish', 10, 3 );

	// Cron handlers.
	add_action( 'ai_media_search_process_single', 'ai_media_search_process_single' );
	add_action( 'ai_media_search_batch_process', 'ai_media_search_batch_process' );

	// Search integration.
	add_filter( 'posts_join', 'ai_media_search_filter_posts_join', 10, 2 );
	add_filter( 'posts_search', 'ai_media_search_filter_posts_search', 10, 2 );
	add_filter( 'posts_groupby', 'ai_media_search_filter_posts_groupby', 10, 2 );

	// Self-healing: re-schedule batch cron if it was lost.
	if ( ! wp_next_scheduled( 'ai_media_search_batch_process' ) ) {
		wp_schedule_event( time(), ai_media_search_get_cron_interval(), 'ai_media_search_batch_process' );
	}
}
add_action( 'init', 'ai_media_search_init' );

/**
 * On activation, schedule the batch processing cron.
 */
function ai_media_search_activate() {
	if ( ! wp_next_scheduled( 'ai_media_search_batch_process' ) ) {
		wp_schedule_event( time(), ai_media_search_get_cron_interval(), 'ai_media_search_batch_process' );
	}
}
register_activation_hook( __FILE__, 'ai_media_search_activate' );

/**
 * On deactivation, clear the scheduled cron. Meta data is preserved.
 */
function ai_media_search_deactivate() {
	wp_clear_scheduled_hook( 'ai_media_search_batch_process' );

	// wp_unschedule_hook removes all events for a hook regardless of args,
	// clearing the per-attachment single events scheduled with attachment IDs.
	wp_unschedule_hook( 'ai_media_search_process_single' );
}
register_deactivation_hook( __FILE__, 'ai_media_search_deactivate' );
