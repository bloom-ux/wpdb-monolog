<?php

namespace Bloom_UX\WPDB_Monolog;

use DateTimeZone;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use wpdb;

if ( ! defined( 'Inpsyde\Wonolog\LOG' ) ) {
	return;
}

// $wpdb_handler = new WordPressHandler(
// 	null,
// 	'logs',
// 	array(
// 		'wp',
// 		'username',
// 		'userid',
// 		'file',
// 		'line',
// 		'context',
// 		'extra'
// 	)
// );
// $wpdb_handler->conf_table_size_limiter( 512000 );
// $record = [ 'extra' => [] ];
// 	$wpdb_handler->initialize( $record );

class WPDB_Handler extends AbstractProcessingHandler {

	private $wpdb;

	/**
	 * Local timezone
	 * @var \DateTimeZone
	 */
	private $timezone;

	const FALLBACK_TIMEZONE = 'Etc/UTC';

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;

		$this->set_timezone();
		$this->create_db_table();
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

	private function create_db_table() {
		$charset = $this->wpdb->get_charset_collate();
		$table_name = "{$this->wpdb->base_prefix}logs";
		$sql = "CREATE TABLE $table_name (
		    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            channel VARCHAR(255),
            message TEXT,
            level INT( 8 ) UNSIGNED,
			level_name VARCHAR(128),
			context JSON,
			extra JSON,
			formatted LONGTEXT,
			created_at DATETIME NOT NULL,
			time BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
			KEY channel (channel),
			KEY level (level, level_name),
			KEY created (created_at),
			KEY message (message(191))
		) $charset";
		// columnas virtuales: user_id, site_id, network_id(?)
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

    protected function write ( array $record ) : void {
		$columns = array(
			'channel'    => '%s',
			'message'    => '%s',
			'level'      => '%d',
			'level_name' => '%s',
			'context'    => '%s',
			'extra'      => '%s',
			'formatted'  => '%s',
			'created_at' => '%s',
			'time'       => '%d',
		);
		$row = [];
		foreach ( $record as $key => $val ) {
			if ( ! isset( $columns[ $key ] ) ) {
				continue;
			}
			if ( in_array( $key, array( 'context', 'extra' ) ) ) {
				if ( count( $val ) === 1 ) {
					$val = json_encode( current( $val ) );
				} else {
					$val = json_encode( $val );
				}
			}
			$row[$key] = $val;
		}

		$created_local = $record['datetime']->setTimezone( $this->timezone );
		$row['created_at'] = $created_local->format('Y-m-d H:i:s');
		$row['time'] = $record['datetime']->format('Uu');
		$formats = array_intersect_key(
			$columns,
			$row
		);
		$this->wpdb->insert(
			"{$this->wpdb->base_prefix}logs",
			$row,
			$formats
		);
	}
}

global $wpdb;
$handler = new WPDB_Handler( $wpdb );
$handler->setLevel( 250 );
$logger = new Logger('wp_monolog');
$logger->pushHandler( $handler );

// add_action(
// 	'wonolog.setup',
// 	function( Configurator $config ) {
// 		global $wpdb;
// 		$handler = new WPDB_Handler( $wpdb );
// 		// $handler->setLevel( 250 );
// 		$config->disableFallbackHandler();
// 		$config->pushHandler( $handler );
// 		$config->doNotLogPhpErrors();
// 		// $config->registerLogHook( 'wpmail_log' );
// 		$config->disableAllDefaultHookListeners();
// 	}
// );