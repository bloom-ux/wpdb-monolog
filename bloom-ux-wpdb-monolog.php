<?php

namespace Bloom_UX\WPDB_Monolog;

use wpdb;
use DateTimeZone;
use Monolog\Logger;
use Monolog\Processor\ProcessorInterface;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Handler\AbstractProcessingHandler;
use Psr\Log\InvalidArgumentException;

class WPDB_Handler extends AbstractProcessingHandler {

	private $wpdb;

	/**
	 * Local timezone
	 * @var \DateTimeZone
	 */
	private $timezone;

	private $table;

	const FALLBACK_TIMEZONE = 'Etc/UTC';

	const VERSION = '0.1.0';

	const INSTALLED_VERSION_OPT_NAME = 'wpdb_monolog_handler_version';

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

	private function maybe_create_db_table() {
		$installed_version = get_option( static::INSTALLED_VERSION_OPT_NAME, '0.0.0' );
		if ( $installed_version >= static::VERSION ) {
			return;
		}
		$charset = $this->wpdb->get_charset_collate();
		$table_name = "{$this->wpdb->base_prefix}logs";
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
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
		update_option( static::INSTALLED_VERSION_OPT_NAME, static::VERSION );
	}

	/**
	 * Get columns data format for query sanitization
	 *
	 * @return array Map of columns to sanitization format
	 */
	private function get_columns_formats( ) : array {
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
	 * @param array $record array{message: string, context: mixed[], level: Level, level_name: LevelName, channel: string, datetime: \DateTimeImmutable, extra: mixed[], formatted: mixed}
	 * @return void
	 */
    protected function write ( $record ) : void {
		$row = [];
		foreach ( $record as $key => $val ) {
			// Use only allowed formats.
			if ( ! isset( $this->get_columns_formats()[ $key ] ) ) {
				continue;
			}
			// "context" and "extra" are stored as JSON.
			if ( in_array( $key, array( 'context', 'extra' ) ) ) {
				$val = json_encode( $val );
			}
			$row[ $key ] = $val;
		}

		$created_local = $record['datetime']->setTimezone( $this->timezone );
		$row['created_at'] = $created_local->format('Y-m-d H:i:s.u');
		$row['created_at_gmt'] = $record['datetime']->format('Y-m-d H:i:s.u');
		$formats = array_intersect_key(
			$this->get_columns_formats(),
			$row
		);
		$this->wpdb->insert(
			"{$this->wpdb->base_prefix}logs",
			$row,
			$formats
		);
	}
}

/**
 * Add extra information for the log records
 *
 * @package Bloom_UX\WPDB_Monolog
 */
class WP_Processor implements ProcessorInterface {

    public function __invoke( array $record ) {
		if ( ! $record['extra'] ) {
			$record['extra'] = array();
		}
		$record['extra'] = array_merge( $record['extra'], array(
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
			'environment'        => is_callable( 'wp_get_environment_type' ) ? wp_get_environment_type() : null
		) );
		return $record;
	}

}

/**
 * Get a preconfigured logger for the given channel
 *
 * @param string $channel The name of the channel for this log, such as your plugin name.
 * @return Logger An instantiated Logger object. You can modify the logger using the action hook 'bloom_wpdb_monolog_logger_init'
 * @throws InvalidArgumentException
 */
function get_logger_for_channel( string $channel ) : Logger {
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
 * @throws InvalidArgumentException
 */
function init_logger_for_channel( string $channel ) : Logger {
	global $wpdb;
	$handler = new WPDB_Handler( $wpdb );
	$handler->setLevel( 250 );
	$logger = new Logger( $channel );
	$logger->pushHandler( $handler );
	$logger->pushProcessor( new PsrLogMessageProcessor );
	$logger->pushProcessor( new WP_Processor );
	do_action_ref_array( 'bloom_wpdb_monolog_logger_init', array(
		'logger' => $logger,
		'channel' => $channel
	) );
	return $logger;
}

/**
 * Change the log level for the given channel or logger
 *
 * @param string|Logger $channel_or_logger The channel name or instantiated Logger.
 * @param int $level The log level to be set.
 * @return void
 * @throws InvalidArgumentException
 */
function set_level( $channel_or_logger, $level ) {
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

$logger = get_logger_for_channel( 'foo' );
set_level( $logger, Logger::INFO );

add_action( 'shutdown', function() use ( $logger ) {
	$logger->info('hola {foo}', array(
		'foo' => wp_get_current_user()->display_name
	) );
});