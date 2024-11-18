<?php
/**
 * Plugin Name: WPDB Monolog
 * Plugin URI: https://github.com/bloom-ux/wpdb-monolog/
 * Description: Log messages to the WordPress database using Monolog
 * Version: 0.1.0
 * Author: bloom.lat
 * Author URI: https://www.bloom.lat/
 * License: GPL-3.0-or-later
 *
 * @package Bloom_UX\WPDB_Monolog
 */

namespace Bloom_UX\WPDB_Monolog;

use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

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
	$handler->setLevel( Level::Notice );
	$logger = new Logger( $channel );
	$logger->pushHandler( $handler );
	$logger->pushProcessor( new PsrLogMessageProcessor() );
	$logger->pushProcessor( new WP_Processor() );
	do_action_ref_array(
		'bloom_wpdb_monolog_logger_init',
		array(
			'logger' => $logger,
			'channel' => $channel,
		)
	);
	return $logger;
}

/**
 * Change the log level for the given channel or logger
 *
 * @param string|Logger $channel_or_logger The channel name or instantiated Logger.
 * @param Level         $level The log level to be set.
 * @return void
 */
function set_channel_level( $channel_or_logger, Level $level ) {
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

// add_action( 'shutdown', '\Bloom_UX\WPDB_Monolog\maybe_schedule_log_clean' );

// function maybe_schedule_log_clean() {
// 	if ( ! wp_next_scheduled( 'bloom_wpdb_monolog__clean_logs' ) ) {
// 		wp_schedule_event(
// 			time(),
// 			'twicedaily',
// 			'bloom_wpdb_monolog__clean_logs'
// 		);
// 	}
// }

// add_action( 'bloom_wpdb_monolog__clean_logs', '\Bloom_UX\WPDB_Monolog\maybe_clean_logs' );

// function maybe_clean_logs() {
// 	global $wpdb;
// 	// Clean records after hitting a certain limit or every "x" days
// 	$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->bsae_prefix}monolog" );
// 	$handler = new WPDB_Handler( $wpdb );
// 	$handler->clean_logs();
// }

// $logger = get_logger_for_channel( 'foo' );
// set_channel_level( $logger, Level::Info );
// add_action( 'shutdown', function() use ( $logger ) {
// 	$logger->info('hola {foo}', array(
// 		'foo' => wp_get_current_user()->display_name
// 	) );
// });