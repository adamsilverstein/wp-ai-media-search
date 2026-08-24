<?php
/**
 * Configuration for the WordPress PHPUnit test library.
 *
 * Every value can be overridden with an environment variable so the same file
 * serves a local run and CI without being edited.
 *
 * @package AI_Media_Search
 */

/**
 * Read an environment variable, falling back to a default.
 *
 * @param string $name     Environment variable name.
 * @param string $fallback Value to use when the variable is not set.
 * @return string
 */
function ai_media_search_tests_env( $name, $fallback ) {
	$value = getenv( $name );

	return ( false === $value || '' === $value ) ? $fallback : $value;
}

// The WordPress install under test. Composer places one in vendor/.
define(
	'ABSPATH',
	rtrim(
		ai_media_search_tests_env( 'WP_TESTS_ABSPATH', dirname( __DIR__ ) . '/vendor/roots/wordpress-no-content' ),
		'/'
	) . '/'
);

define( 'DB_NAME', ai_media_search_tests_env( 'WP_TESTS_DB_NAME', 'wordpress_test' ) );
define( 'DB_USER', ai_media_search_tests_env( 'WP_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', ai_media_search_tests_env( 'WP_TESTS_DB_PASSWORD', 'root' ) );
define( 'DB_HOST', ai_media_search_tests_env( 'WP_TESTS_DB_HOST', 'localhost' ) );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'AI Media Search Tests' );

define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );

$table_prefix = ai_media_search_tests_env( 'WP_TESTS_TABLE_PREFIX', 'wptests_' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
