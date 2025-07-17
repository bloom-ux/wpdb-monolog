<?php
/**
 * Plugin Name: Bloom UX WPDB Monolog
 * Description: A simple logger for WordPress that writes to the database and WP CLI.
 * Version: 0.1.0
 * Author: bloom.lat
 * Author URI: https://www.bloom.lat
 * License: GPL-2.0+
 *
 * @package bloom\WPDB_Monolog
 *
 * Example usage:
 * $logger = get_logger_for_channel( 'foo' );
 * set_channel_level( $logger, Logger::INFO );
 * add_action( 'shutdown', function() use ( $logger ) {
 *   $logger->info(
 *     'hola {foo}',
 *     array(
 *       'foo' => wp_get_current_user()->display_name
 *     )
 *   );
 * });
 */

namespace bloom\WPDB_Monolog;
