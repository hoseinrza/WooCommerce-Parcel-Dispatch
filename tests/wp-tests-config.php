<?php
/**
 * WordPress integration test configuration.
 *
 * PHP is run from C:/xampp/php/php.exe (8.2) because it has mysqli;
 * the database is a dedicated throwaway schema (wcpd_test).
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/wordpress/' );
define( 'WP_CONTENT_DIR', __DIR__ . '/wp-content' );
define( 'WP_CONTENT_URL', 'http://example.org/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WP_PLUGIN_URL', WP_CONTENT_URL . '/plugins' );

define( 'DB_NAME', 'wcpd_test' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'WCPD Test Suite' );
define( 'WP_PHP_BINARY', 'C:/xampp/php/php.exe' );
define( 'WPLANG', '' );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', true );

$table_prefix = 'wptests_';
