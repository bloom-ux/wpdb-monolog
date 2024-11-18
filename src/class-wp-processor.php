<?php
/**
 * Monolog processor to add WordPress-specific information to the "extra" key of log records
 *
 * @package Bloom_UX\WPDB_Monolog
 */

namespace Bloom_UX\WPDB_Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Add extra information for the log records
 *
 * @package Bloom_UX\WPDB_Monolog
 */
class WP_Processor implements ProcessorInterface {

	/**
	 * Add extra information to the log record
	 *
	 * @param LogRecord $record The record to process.
	 * @return LogRecord The processed record with Wordpress info
	 */
	public function __invoke( LogRecord $record ) {
		if ( ! $record['extra'] ) {
			$record['extra'] = array();
		}
		$record['extra'] = array_merge(
			$record['extra'],
			array(
				'request_uri'        => filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL ),
				'doing_cron'         => defined( 'DOING_CRON' ) ? (bool) DOING_CRON : null,
				'doing_ajax'         => defined( 'DOING_AJAX' ) ? (bool) DOING_AJAX : null,
				'doing_autosave'     => defined( 'DOING_AUTOSAVE' ) ? (bool) DOING_AUTOSAVE : null,
				'is_admin'           => is_callable( 'is_admin' ) ? is_admin() : null,
				'doing_rest'         => null,
				'user_id'            => is_callable( 'wp_get_current_user' ) ? wp_get_current_user()->ID : null,
				'ms_switched'        => is_callable( 'ms_is_switched' ) ? ms_is_switched() : null,
				'current_blog_id'    => is_callable( 'get_current_blog_id' ) ? get_current_blog_id() : null,
				'current_network_id' => is_callable( 'get_current_network_id' ) ? get_current_network_id() : null,
				'is_ssl'             => is_callable( 'is_ssl' ) ? is_ssl() : null,
				'environment'        => is_callable( 'wp_get_environment_type' ) ? wp_get_environment_type() : null,
			)
		);
		return $record;
	}
}
