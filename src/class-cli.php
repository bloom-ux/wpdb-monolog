<?php
/**
 * Command line interface for retrieving log records
 *
 * @package bloom\WPDB_Monolog
 */

namespace bloom\WPDB_Monolog;

use DateTimeImmutable;
use WP_CLI;

use function WP_CLI\Utils\format_items;

/**
 * Command line interface for log records on database
 */
class CLI {

	/**
	 * Performs the plugin installation
	 *
	 * Creates or updates the log table and reports the resulting schema version.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpdb-monolog install
	 *
	 * @return void
	 */
	public function install() {
		$repository        = Repository::get_instance();
		$table_name        = $repository->get_table_name();
		$installed_version = $repository->get_installed_version();
		if ( $repository->is_up_to_date() && $repository->table_exists() ) {
			WP_CLI::success(
				sprintf(
					'Nothing to do: the log table %s is already up to date (version %s).',
					$table_name,
					$installed_version
				)
			);
			return;
		}
		$table_existed = $repository->table_exists();
		$repository->install();
		// Verification: the table must exist and the current schema version must be registered.
		if ( ! $repository->table_exists() ) {
			WP_CLI::error(
				sprintf( 'Installation failed: the log table %s was not created on database.', $table_name )
			);
		}
		if ( ! $repository->is_up_to_date() ) {
			WP_CLI::error(
				sprintf(
					'Installation failed: table %s is on version %s but version %s was expected.',
					$table_name,
					$repository->get_installed_version(),
					Repository::VERSION
				)
			);
		}
		if ( ! $table_existed ) {
			WP_CLI::success(
				sprintf( 'Log table %s created with version %s.', $table_name, Repository::VERSION )
			);
			return;
		}
		WP_CLI::success(
			sprintf(
				'Log table %s updated from version %s to version %s.',
				$table_name,
				$installed_version,
				Repository::VERSION
			)
		);
	}

	/**
	 * Show the current state of the log integration and how it is being used
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Get records from all sites on the network. Default false (records from the current site).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpdb-monolog status
	 *     wp wpdb-monolog status --network
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function status( $args = array(), $assoc_args = array() ) {
		$repository = Repository::get_instance();
		$table_name = $repository->get_table_name();
		if ( ! $repository->table_exists() ) {
			WP_CLI::error( sprintf( 'The log table %s does not exist. Run "wp wpdb-monolog install" first.', $table_name ) );
		}
		$assoc_args = $this->resolve_blog_id(
			wp_parse_args(
				$assoc_args,
				array(
					'url' => empty( $_SERVER['HTTP_HOST'] ) ? null : esc_url( wp_unslash( $_SERVER['HTTP_HOST'] ) ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				)
			)
		);
		$summary  = $repository->get_usage_summary( $assoc_args );
		$by_level = $repository->get_records_by_level( $assoc_args );
		WP_CLI::log( sprintf( '%-10s %s', 'Table:', $table_name ) );
		WP_CLI::log(
			sprintf(
				'%-10s %s',
				'Version:',
				$repository->is_up_to_date()
					? sprintf( '%s (up to date)', Repository::VERSION )
					: sprintf( '%s (outdated, expected %s)', $repository->get_installed_version(), Repository::VERSION )
			)
		);
		WP_CLI::log( sprintf( '%-10s %d', 'Records:', $summary['total_records'] ) );
		WP_CLI::log( sprintf( '%-10s %d', 'Channels:', $summary['total_channels'] ) );
		WP_CLI::log(
			sprintf(
				'%-10s %s',
				'Oldest:',
				$summary['oldest_record']
					? sprintf( '%s (%d days ago)', $summary['oldest_record'], $summary['oldest_days'] )
					: 'no records yet'
			)
		);
		if ( empty( $by_level ) ) {
			return;
		}
		WP_CLI::log( 'Records by level:' );
		format_items( 'table', $by_level, array( 'level_name', 'level', 'total' ) );
	}

	/**
	 * Deletes old records to keep the database on a manageable size
	 *
	 * ## OPTIONS
	 *
	 * [<max-age>]
	 * : Number of days to keep (older records will be deleted). Default: 90.
	 *
	 * [--dry-run]
	 * : Query the database to check how many records would be deleted but don't do the deletion.
	 *
	 * [--network]
	 * : Delete records from all sites on the network. Default false, will only work for current site.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 * @subcommand purge-records
	 */
	public function purge_records( $args = array(), $assoc_args = array() ) {
		$max_age_days = isset( $args[0] ) && is_numeric( $args[0] ) ? (int) $args[0] : 90;
		$date_since   = new DateTimeImmutable( "{$max_age_days} days ago", wp_timezone() );
		$assoc_args   = wp_parse_args(
			$assoc_args,
			array(
				'before' => $date_since->format( 'Y-m-d' ),
				'per_page' => -1,
				'url' => empty( $_SERVER['HTTP_HOST'] ) ? null : esc_url( wp_unslash( $_SERVER['HTTP_HOST'] ) ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			)
		);
		$assoc_args   = $this->resolve_blog_id( $assoc_args );
		if ( isset( $assoc_args['dry-run'] ) && $assoc_args['dry-run'] ) {
			$records = Repository::get_instance()->find_by_query( $assoc_args );
			\WP_CLI::success( sprintf( "Using --dry-run: %d old log record(s) older than %d days would've been deleted.", count( $records ), $max_age_days ) );
			return;
		}
		$deleted = Repository::get_instance()->delete_by_query( $assoc_args );
		\WP_CLI::success( sprintf( 'Deleted %d old log record(s) older than %d days.', $deleted, $max_age_days ) );
	}

	/**
	 * Get a collection of log records from database.
	 *
	 * ## OPTIONS
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific fields.
	 *
	 * [--<field>=<value>]
	 * : Arguments to pass to Repository::find_by_query()
	 *
	 * [--network]
	 * : Fetch records from any site.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - ids
	 *   - json
	 *   - count
	 *   - yaml
	 * ---
	 *
	 * ## AVAILABLE FIELDS
	 *
	 *   These fields will be displayed for each record:
	 *
	 *   * id
	 *   * channel
	 *   * message
	 *   * level_name
	 *   * created_at
	 *
	 *   These fields are optionally available:
	 *
	 *   * level
	 *   * extra
	 *   * context
	 *   * created_at_gmt
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpdb-monolog list --url=sitedomain.com
	 *     wp wpdb-monolog list --network
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative args.
	 */
	public function list( $args = array(), $assoc_args = array() ) {
		$assoc_args = wp_parse_args(
			$assoc_args,
			array(
				'format'     => 'table',
				'fields'     => 'id,channel,level_name,message,created_at',
				'paged'      => 1,
				'per_page'   => 10,
				'channel'    => '',
				'order_by'   => 'id',
				'order'      => 'DESC',
				'level'      => null,
				'level_name' => '',
				'url'        => empty( $_SERVER['HTTP_HOST'] ) ? null : esc_url( wp_unslash( $_SERVER['HTTP_HOST'] ) ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			)
		);
		$fields     = 'ids' === $assoc_args['format'] ? 'id' : $assoc_args['fields'];
		$assoc_args = $this->resolve_blog_id( $assoc_args );
		$entries  = Repository::get_instance()->find_by_query( $assoc_args );
		$filtered = array_map(
			function ( Record $record ) use ( $fields ) {
				return $this->filter_record_fields( $record, $fields );
			},
			$entries
		);
		format_items( $assoc_args['format'], $filtered, $fields );
	}

	/**
	 * Get a list of log channels, record count and last used.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Get channels from all sites. Default false (use --url to restrict to a single site).
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @subcommand list-channels
	 */
	public function list_channels( $args, $assoc_args ) {
		$assoc_args = wp_parse_args(
			$assoc_args,
			array(
				'format' => 'table',
				'url'    => empty( $_SERVER['HTTP_HOST'] ) ? null : esc_url( wp_unslash( $_SERVER['HTTP_HOST'] ) ), //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			)
		);
		$assoc_args = $this->resolve_blog_id( $assoc_args );

		$channels = Repository::get_instance()->find_channels( $assoc_args );
		format_items( 'table', $channels, array( 'channel', 'count', 'last_record' ) );
	}

	/**
	 * Fetch a single record from database and show as JSON
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The record ID
	 *
	 * @param array $args Positional arguments.
	 */
	public function get( $args ) {
		list( $id ) = $args;
		$record     = Repository::get_instance()->get( $id );
		if ( ! $record ) {
			WP_CLI::error( "Record with ID $record not found." );
		}
		echo json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Resolve the blog id used to filter records from the --url or --network arguments
	 *
	 * @param array $assoc_args Associative arguments, may include url and network keys.
	 * @return array Associative arguments, including blog_id when it can be resolved from the url.
	 */
	private function resolve_blog_id( array $assoc_args ): array {
		if ( ! is_multisite() || ! empty( $assoc_args['network'] ) ) {
			unset( $assoc_args['url'] );
			return $assoc_args;
		}
		$requested_url    = (string) ( $assoc_args['url'] ?? '' );
		$requested_domain = (string) wp_parse_url( $requested_url, PHP_URL_HOST );
		$requested_path   = (string) wp_parse_url( $requested_url, PHP_URL_PATH );
		$site_from_url    = get_blog_id_from_url( $requested_domain, $requested_path );
		if ( ! $site_from_url ) {
			return $assoc_args;
		}
		$assoc_args['blog_id'] = $site_from_url;
		return $assoc_args;
	}

	/**
	 * Filter record fields
	 *
	 * @param Record       $record A log record retrieved from database.
	 * @param array|string $fields Comma separated or array of desired fields.
	 * @return array Associative array with the desired fields as keys.
	 */
	private function filter_record_fields( Record $record, $fields = '' ): array {
		$fields      = is_string( $fields ) ? array_map( 'trim', explode( ',', $fields ) ) : $fields;
		$record_data = $record->jsonSerialize();
		return array_intersect_key( (array) $record_data, array_flip( $fields ) );
	}
}
