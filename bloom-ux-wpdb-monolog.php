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

use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Get a preconfigured logger for the given channel
 *
 * @param string $channel The name of the channel for this log, such as your plugin name.
 * @return Logger An instantiated Logger object. You can modify the logger using the action hook 'bloom_wpdb_monolog_logger_init'
 */
function get_logger_for_channel( string $channel ): Logger {
	static $registry = array();
	if ( ! isset( $registry[ $channel ] ) ) {
		$registry[ $channel ] = init_logger_for_channel( $channel );
	}
	return $registry[ $channel ];
}

/**
 * Initialize a new logger for the given channel
 *
 * @param string $channel The channel name for this log.
 * @return Logger An instantiated Logger object.
 */
function init_logger_for_channel( string $channel ): Logger {
	global $wpdb;
	$handler = new WPDB_Handler( $wpdb );
	$handler->setLevel( 250 );
	$logger = new Logger( $channel );
	$logger->pushHandler( $handler );
	$logger->pushProcessor( new PsrLogMessageProcessor() );
	$logger->pushProcessor( new WP_Processor() );

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		$cli_handler  = new WP_CLI_Handler();
		$is_cli_debug = ! empty( $_SERVER['argv'] ) && in_array( '--debug', $_SERVER['argv'], true );
		if ( $is_cli_debug ) {
			$cli_handler->setLevel( Logger::DEBUG );
		} else {
			$cli_handler->setLevel( Logger::NOTICE );
		}
		$logger->pushHandler( $cli_handler );
	}

	do_action_ref_array(
		'bloom_wpdb_monolog_logger_init',
		array(
			'logger'  => $logger,
			'channel' => $channel,
		)
	);
	return $logger;
}

/**
 * Change the log level for the given channel or logger
 *
 * @param string|Logger $channel_or_logger The channel name or instantiated Logger.
 * @param int           $level The log level to be set.
 * @return void
 */
function set_channel_level( $channel_or_logger, $level ) {
	if ( is_string( $channel_or_logger ) ) {
		$logger = get_logger_for_channel( $channel_or_logger );
	} else {
		$logger = $channel_or_logger;
	}
	foreach ( $logger->getHandlers() as $handler ) {
		if ( $handler instanceof WPDB_Handler ) {
			$handler->setLevel( $level );
		}
	}
}