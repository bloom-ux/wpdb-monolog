<?php
/**
 * WordPress database handler for monolog records
 *
 * @package bloom\WPDB_Monolog
 */

namespace bloom\WPDB_Monolog;

use wpdb;
use DateTimeZone;
use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;

/**
 * Log records to the WordPress database
 */
class WPDB_Handler extends AbstractProcessingHandler {

	/**
	 * WordPress database object
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Local timezone
	 *
	 * @var \DateTimeZone
	 */
	private $timezone;

	/**
	 * Table name
	 *
	 * @var string
	 */
	private $table;

	const FALLBACK_TIMEZONE = 'Etc/UTC';

	const VERSION = '0.1.0';

	const INSTALLED_VERSION_OPT_NAME = 'wpdb_monolog_handler_version';

	/**
	 * Handler constructor
	 *
	 * @param wpdb|null $wpdb "wpdb" driver instance.
	 * @param string    $table The name of the table that will store the logs.
	 * @param int       $level The log level.
	 * @param bool      $bubble Whether the messages that are handled can bubble up the stack or not.
	 */
	public function __construct(
		wpdb $wpdb = null,
		$table = 'monolog',
		$level = Logger::DEBUG,
		$bubble = true
	) {
		$this->wpdb = $wpdb;
		$this->table = $table;

		$this->set_timezone();
		$this->maybe_create_db_table();

		parent::__construct( $level, $bubble );
	}

	/**
	 * Set a local timezone
	 *
	 * @param null|DateTimeZone $timezone Timezone object or null to use site default.
	 * @return $this
	 */
	private function set_timezone( ?DateTimeZone $timezone = null ) {
		if ( $timezone instanceof DateTimeZone ) {
			$this->timezone = $timezone;
			return $this;
		}
		if ( function_exists( 'wp_timezone' ) ) {
			$this->timezone = wp_timezone();
			return $this;
		}
		$timezone_string = get_option( 'timezone_string' );
		if ( ! $timezone_string ) {
			$timezone_string = static::FALLBACK_TIMEZONE;
		}
		$this->timezone = new DateTimeZone( $timezone_string );
		return $this;
	}

	/**
	 * Create the table if it doesn't exist
	 *
	 * @return void
	 */
	private function maybe_create_db_table() {
		$installed_version = get_option( static::INSTALLED_VERSION_OPT_NAME, '0.0.0' );
		if ( $installed_version >= static::VERSION ) {
			return;
		}
		$charset = $this->wpdb->get_charset_collate();
		$table_name = "{$this->wpdb->base_prefix}{$this->table}";
		$sql = "CREATE TABLE $table_name (
		    id BIGINT( 20 ) UNSIGNED NOT NULL AUTO_INCREMENT,
            channel VARCHAR( 255 ) NOT NULL,
            message TEXT NOT NULL,
            level INT( 8 ) UNSIGNED NOT NULL,
			level_name VARCHAR( 16 ) NOT NULL,
			extra JSON,
			context JSON,
			created_at DATETIME( 3 ) NOT NULL,
			created_at_gmt DATETIME( 3 ) NOT NULL,
            PRIMARY KEY  ( id ),
			KEY channel ( channel ),
			KEY level ( level, level_name),
			KEY created ( created_at ),
			KEY message ( message( 191 ) )
		) $charset";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( static::INSTALLED_VERSION_OPT_NAME, static::VERSION );
	}

	/**
	 * Get columns data format for query sanitization
	 *
	 * @return array Map of columns to sanitization format
	 */
	private function get_columns_formats(): array {
		return array(
			'channel'        => '%s',
			'message'        => '%s',
			'level'          => '%d',
			'level_name'     => '%s',
			'context'        => '%s',
			'extra'          => '%s',
			'created_at'     => '%s',
			'created_at_gmt' => '%s',
		);
	}

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
		$row = array();
		foreach ( $record as $key => $val ) {
			// Use only allowed formats.
			if ( ! isset( $this->get_columns_formats()[ $key ] ) ) {
				continue;
			}
			// "context" and "extra" are stored as JSON.
			if ( in_array( $key, array( 'context', 'extra' ) ) ) {
				if ( is_array( $val ) ) {
					foreach ( $val as $k => $v ) {
						if ( is_wp_error( $v ) ) {
							$error_data = array();
							foreach ( (array) $v->get_error_data() as $data_key => $error_value ) {
								$error_data[ $data_key ] = wp_check_invalid_utf8( $error_value );
							}
							$val[ $k ] = array(
								'codes'    => $v->get_error_codes(),
								'messages' => $v->get_error_messages(),
								'data'     => $error_data,
							);
						} else {
							$val[ $k ] = $v;
						}
					}
				} else {
					$val = wp_check_invalid_utf8( $val );
				}
				$val = json_encode( $val );
			}
			$row[ $key ] = $val;
		}

		$created_local         = $record['datetime']->setTimezone( $this->timezone );
		$row['created_at']     = $created_local->format( 'Y-m-d H:i:s.u' );
		$row['created_at_gmt'] = $record['datetime']->format( 'Y-m-d H:i:s.u' );
		$formats               = array_intersect_key(
			$this->get_columns_formats(),
			$row
		);
		$this->wpdb->insert(
			"{$this->wpdb->base_prefix}{$this->table}",
			$row,
			$formats
		);
	}
}
