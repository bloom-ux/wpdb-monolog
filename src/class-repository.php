<?php
/**
 * Repository for the monolog tables
 *
 * Handles the interactions with the database such as installation, schema migrations, CRUD.
 *
 * @package bloom\WPDB_Monolog
 */

namespace bloom\WPDB_Monolog;

use DateTimeImmutable;
use DateTimeZone;
use WP_CLI;
use wpdb;

/**
 * Handles the interactions with the database
 */
class Repository {
	const VERSION = '0.2.0';

	const INSTALLED_VERSION_OPT_NAME = 'wpdb_monolog_handler_version';

	const FALLBACK_TIMEZONE = 'Etc/UTC';

	/**
	 * Instance of the repository class
	 *
	 * @var ?static
	 */
	private static $instance = null;

	/**
	 * WordPress database handler instance
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Full name of the table used to save log records ($wpdb->base_prefix . 'monolog').
	 *
	 * @var string
	 */
	private $table = '';

	/**
	 * Timezone used for created_at timestamps on records saved to database.
	 *
	 * @var ?DateTimeZone
	 */
	private $timezone = null;

	/**
	 * Construct a new Repositry
	 *
	 * @param wpdb $wpdb WordPress database class instance.
	 */
	private function __construct( wpdb $wpdb ) {
		// Set a default timezone.
		$this->set_timezone();
		$this->wpdb  = $wpdb;
		$this->table = $this->wpdb->base_prefix . 'monolog';
	}

	/**
	 * Get the singleton instance
	 *
	 * @param wpdb $wpdb A wpdb object or null to use the default one.
	 * @return $this
	 */
	public static function get_instance( ?wpdb $wpdb = null ) {
		if ( is_null( static::$instance ) ) {
			$called_class = get_called_class();
			if ( is_null( $wpdb ) ) {
				global $wpdb;
			}
			static::$instance = new $called_class( $wpdb );
		}
		return static::$instance;
	}

	/**
	 * Check installed version and updates database schema if needed
	 */
	public function install() {
		$installed_version = get_option( static::INSTALLED_VERSION_OPT_NAME, '0.0.0' );
		if ( $installed_version >= static::VERSION ) {
			return;
		}
		$charset    = $this->wpdb->get_charset_collate();
		$table_name = "{$this->wpdb->base_prefix}{$this->table}";
		$sql        = "CREATE TABLE $table_name (
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
	public function get_columns_formats(): array {
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
	 * Set a local timezone
	 *
	 * @param null|DateTimeZone $timezone Timezone object or null to use site default.
	 * @return $this
	 */
	public function set_timezone( ?DateTimeZone $timezone = null ) {
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
	 * Save a monolog record to database using $wpdb
	 *
	 * @param array|\Monolog\LogRecord $record Debug record as array or monolog record.
	 * @return bool True on success.
	 */
	public function save( $record ) {
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
		return (bool) $this->wpdb->insert(
			"{$this->wpdb->base_prefix}{$this->table}",
			$row,
			$formats
		);
	}

	/**
	 * Find records according to the given parameters.
	 *
	 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	 *
	 * @param array $params {
	 *     Query parameters.
	 *     @type string $channel    Channel name.
	 *     @type ?int   $level      Log record level.
	 *     @type string $level_name Log record level name.
	 *     @type string $message    Log message (does partial matches by default).
	 *     @type ?int   $blog_id    Blog id where the message was originated. Default null.
	 *     @type int    $paged      Page of results. Default 1.
	 *     @type int    $per_page   Number of results at once. Default 10.
	 *     @type string $order_by   How to sort results. Can be any table column. Default 'id';
	 *     @type string $order      Whether to sort ascending or descending. Default 'DESC'
	 *     @type string $since      Lower bound for created_at (inclusive).
	 *     @type string $before     Upper bound for created_at (exclusive).
	 * }
	 * @return array|Record[] Array of found records or empty array if none found.
	 */
	public function find_by_query( array $params = array() ) {
		$args         = wp_parse_args(
			$params,
			array(
				'channel'    => '',
				'level'      => null,
				'level_name' => '',
				'message'    => '',
				'blog_id'    => null,
				'paged'      => 1,
				'per_page'   => 10,
				'order_by'   => 'id',
				'order'      => 'DESC',
				'after'      => null,
				'before'     => null,
			)
		);
		$query_params = array();
		$query        = "SELECT * FROM {$this->table} WHERE 1 = 1 ";
		if ( ! empty( $args['channel'] ) ) {
			$query         .= ' AND channel = %s ';
			$query_params[] = $args['channel'];
		}
		if ( ! empty( $args['message'] ) ) {
			$query         .= ' AND message LIKE %s ';
			$query_params[] = '%' . $this->wpdb->esc_like( $args['message'] ) . '%';
		}
		if ( ! empty( $args['level'] ) ) {
			$query         .= ' AND level = %d ';
			$query_params[] = $args['level'];
		}
		if ( ! empty( $args['level_name'] ) ) {
			$query         .= ' AND level_name = %s ';
			$query_params[] = $args['level_name'];
		}
		if ( ! empty( $args['blog_id'] ) ) {
			$query         .= " AND JSON_VALUE( extra, '$.current_blog_id' ) = %d ";
			$query_params[] = $args['blog_id'];
		}
		if ( ! empty( $args['after'] ) ) {
			$after_dt = new DateTimeImmutable( $args['after'], $this->timezone );
			$query         .= ' AND created_at >= %s ';
			$query_params[] = $after_dt->format( 'Y-m-d H:i:s' );
		}
		if ( ! empty( $args['before'] ) ) {
			$before_dt = new DateTimeImmutable( $args['before'], $this->timezone );
			$query         .= ' AND created_at <= %s ';
			$query_params[] = $before_dt->format( 'Y-m-d H:i:s' );
		}

		$order_by       = array_key_exists( $args['order_by'], $this->get_columns_formats() ) ? $args['order_by'] : 'id';
		$order          = in_array( strtoupper( $args['order'] ), array( 'ASC', 'DESC' ) ) ? $args['order'] : 'DESC';
		$query         .= " ORDER BY $order_by $order ";
		if ( ! empty( $args['per_page'] ) && $args['per_page'] > 0 ) {
			$query .= ' LIMIT %d, %d';
			$query_params[] = ( (int) $args['paged'] - 1 ) * (int) $args['per_page'];
			$query_params[] = (int) $args['per_page'];
		}
		$prepared       = $this->wpdb->prepare( $query, $query_params );
		if ( is_callable( array( 'WP_CLI', 'debug' ) ) ) {
			WP_CLI::debug( $prepared );
		}
		$rows = $this->wpdb->get_results( $prepared );
		if ( ! $rows ) {
			return array();
		}
		$items = array_map(
			function ( $item ) {
				return new Record( (array) $item );
			},
			$rows
		);
		return $items;
	}

	/**
	 * Find log channels
	 *
	 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	 *
	 * @param array $params {
	 *     Query parameters.
	 *     @type ?int $blog_id Filter by blog id where the record was originated. Default null.
	 * }
	 * @return array|object|null
	 */
	public function find_channels( array $params = array() ): array {
		$args         = wp_parse_args(
			$params,
			array(
				'blog_id' => null,
			)
		);
		$query        = "SELECT channel, id as count, created_at as last_record FROM {$this->table} WHERE 1 = 1";
		$query_params = array();
		if ( ! empty( $args['blog_id'] ) ) {
			$query         .= " AND JSON_VALUE( extra, '$.current_blog_id' ) = %d ";
			$query_params[] = $args['blog_id'];
		}
		$query   .= ' GROUP BY channel ORDER BY last_record DESC ';
		$prepared = $this->wpdb->prepare( $query, $query_params );
		if ( is_callable( array( 'WP_CLI', 'debug' ) ) ) {
			WP_CLI::debug( $prepared );
		}
		$results = $this->wpdb->get_results( $prepared, ARRAY_A );
		return $results;
	}

	/**
	 * Get a record by id from the database
	 *
	 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	 *
	 * @param int $id ID of the record on database.
	 * @return null|Record The requested record of null if not found
	 */
	public function get( int $id ): ?Record {
		$query = "SELECT * FROM {$this->table} WHERE id = %d";
		$row   = $this->wpdb->get_row(
			$this->wpdb->prepare( $query, $id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		return new Record( $row );
	}

	/**
	 * Delete records from the database by query parameters.
	 *
	 * @param array $params Query parameters. See $this->find_by_query() for details.
	 * @return ?int Number of deleted rows or null if something fails.
	 * @see $this->find_by_query()
	 */
	public function delete_by_query( array $params = array() ): ?int {
		$records = $this->find_by_query( $params );
		if ( empty( $records ) ) {
			return null;
		}
		$where_clauses = array();
		$query_params = array();
		$ids = array_map(
			function ( $record ) {
				return $record->id;
			},
			$records
		);
		if ( empty( $ids ) ) {
			return null;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$where_clauses[] = "id IN ($placeholders)";
		$query_params = $ids;

		$table = "{$this->table}";
		$where_sql = $where_clauses ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';
		$sql = "DELETE FROM $table $where_sql";
		$sql = $this->wpdb->prepare( $sql, $query_params );
		$result = $this->wpdb->query( $sql );
		$return = is_bool( $result ) ? null : (int) $result;
		return $return;
	}
}
