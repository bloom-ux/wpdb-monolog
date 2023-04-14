<?php

namespace Bloom_UX\WPDB_Monolog;

use wpdb;
use DateTimeZone;
use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;

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
		$table = 'logs',
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
			context JSON,
			extra JSON,
			created_at DATETIME( 3 ) NOT NULL,
			created_at_gmt DATETIME( 3 ) NOT NULL,
			formatted LONGTEXT,
            PRIMARY KEY  ( id ),
			KEY channel ( channel ),
			KEY level ( level, level_name),
			KEY created ( created_at ),
			KEY message ( message( 191 ) )
		) $charset";
		// columnas virtuales: user_id, site_id, network_id(?)
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
		update_option( static::INSTALLED_VERSION_OPT_NAME, static::VERSION );
	}

    protected function write ( array $record ) : void {
		$columns = array(
			'channel'        => '%s',
			'message'        => '%s',
			'level'          => '%d',
			'level_name'     => '%s',
			'context'        => '%s',
			'extra'          => '%s',
			'formatted'      => '%s',
			'created_at'     => '%s',
			'created_at_gmt' => '%s',
		);
		$row = [];
		foreach ( $record as $key => $val ) {
			if ( ! isset( $columns[ $key ] ) ) {
				continue;
			}
			if ( in_array( $key, array( 'context', 'extra' ) ) ) {
				$val = json_encode( $val );
				// if ( count( $val ) === 1 ) {
				// 	$val = json_encode( current( $val ) );
				// } else {
				// 	$val = json_encode( $val );
				// }
			}
			$row[ $key ] = $val;
		}

		$created_local = $record['datetime']->setTimezone( $this->timezone );
		$row['created_at'] = $created_local->format('Y-m-d H:i:s.u');
		$row['created_at_gmt'] = $record['datetime']->format('Y-m-d H:i:s.u');
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
