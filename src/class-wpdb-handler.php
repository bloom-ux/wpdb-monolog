<?php
/**
 * WordPress database handler for monolog records
 *
 * @package bloom\WPDB_Monolog
 */

namespace bloom\WPDB_Monolog;

use Monolog\Handler\AbstractProcessingHandler;

/**
 * Log records to the WordPress database
 */
class WPDB_Handler extends AbstractProcessingHandler {

	/**
	 * Write the record to db
	 *
	 * @param array $record {
	 *    The log record.
	 *    @type string $message The log message.
	 *    @type mixed[] $context The log context.
	 *    @type int $level The severity level of the log.
	 *    @type string $level_name The severity level name of the log.
	 *    @type string $channel The channel name of the log.
	 *    @type \DateTimeImmutable $datetime The timestamp of the log.
	 *    @type mixed[] $extra The extra data.
	 *    @type mixed $formatted The formatted message.
	 * }
	 * @return void
	 */
	protected function write( $record ): void {
		$repository = Repository::get_instance();
		$repository->save( $record );
	}
}
