<?php
/**
 * PHPUnit bootstrap for the AI Media Search test suite.
 *
 * @package AI_Media_Search
 */

$ai_media_search_plugin_dir = dirname( __DIR__ );
$ai_media_search_autoload   = $ai_media_search_plugin_dir . '/vendor/autoload.php';

if ( ! file_exists( $ai_media_search_autoload ) ) {
	echo 'Could not find vendor/autoload.php. Run `composer install` first.' . PHP_EOL;
	exit( 1 );
}

require_once $ai_media_search_autoload;

// The WordPress test library, from wp-phpunit/wp-phpunit unless pointed elsewhere.
$ai_media_search_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $ai_media_search_tests_dir ) {
	$ai_media_search_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

if ( ! $ai_media_search_tests_dir ) {
	$ai_media_search_tests_dir = $ai_media_search_plugin_dir . '/vendor/wp-phpunit/wp-phpunit';
}

$ai_media_search_tests_dir = rtrim( $ai_media_search_tests_dir, '/' );

if ( ! file_exists( $ai_media_search_tests_dir . '/includes/functions.php' ) ) {
	// WordPress is not loaded yet, so no escaping function exists to run this through.
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	printf( 'Could not find the WordPress test library in %s.' . PHP_EOL, $ai_media_search_tests_dir );
	exit( 1 );
}

// Use the repository's own config unless one is supplied by the environment.
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	$ai_media_search_config_path = getenv( 'WP_TESTS_CONFIG_FILE_PATH' );

	if ( ! $ai_media_search_config_path ) {
		$ai_media_search_config_path = __DIR__ . '/wp-tests-config.php';
	}

	define( 'WP_TESTS_CONFIG_FILE_PATH', $ai_media_search_config_path );
}

require_once $ai_media_search_tests_dir . '/includes/functions.php';

/**
 * Load the plugin once WordPress is far enough along to run it.
 */
function ai_media_search_manually_load_plugin() {
	require dirname( __DIR__ ) . '/ai-media-search.php';
}
tests_add_filter( 'muplugins_loaded', 'ai_media_search_manually_load_plugin' );

require $ai_media_search_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/includes/class-ai-media-search-testcase.php';
