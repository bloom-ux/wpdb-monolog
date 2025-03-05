<?php
/**
 * WP CLI handler for monolog
 *
 * @package bloom\WPDB_Monolog
 */

namespace bloom\WPDB_Monolog;

use WP_CLI;
use Monolog\Handler\AbstractProcessingHandler;

/**
 * Outputs log records to WordPress CLI
 */
class WP_CLI_Handler extends AbstractProcessingHandler {

	/**
	 * Write a record to WordPress CLI
	 *
	 * @param array $record The log record.
	 * @return void
	 */
	protected function write( array $record ): void {
		$level = (int) $record['level'];
		if ( $level >= 400 ) {
			WP_CLI::error( $record['formatted'], false );
		} elseif ( $level >= 300 ) {
			WP_CLI::warning( $record['formatted'] );
		} elseif ( $level >= 250 ) {
			WP_CLI::log( $record['formatted'] );
		} else {
			WP_CLI::debug( $record['formatted'], $record['context'] );
		}
	}
}
