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
	 * @return void
	 */
	public function install() {
		$repository = Repository::get_instance();
		$repository->install();
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
		if ( ! is_multisite() || ( ! empty( $assoc_args['network'] ) && $assoc_args['network'] ) ) {
			unset( $assoc_args['url'] );
		} else {
			$requested_domain = wp_parse_url( $assoc_args['url'], PHP_URL_HOST );
			$requested_path   = wp_parse_url( $assoc_args['url'], PHP_URL_PATH );
			$site_from_url    = get_blog_id_from_url( $requested_domain, $requested_path );
			if ( $site_from_url ) {
				$assoc_args['blog_id'] = $site_from_url;
			}
		}
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
		if ( ! is_multisite() || ( ! empty( $assoc_args['network'] ) && $assoc_args['network'] ) ) {
			unset( $assoc_args['url'] );
		} else {
			$requested_domain = wp_parse_url( $assoc_args['url'], PHP_URL_HOST );
			$requested_path   = wp_parse_url( $assoc_args['url'], PHP_URL_PATH );
			$site_from_url    = get_blog_id_from_url( $requested_domain, $requested_path );
			if ( $site_from_url ) {
				$assoc_args['blog_id'] = $site_from_url;
			}
		}
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
		if ( ! empty( $assoc_args['network'] ) && $assoc_args['network'] ) {
			unset( $assoc_args['url'] );
		} else {
			$requested_domain = wp_parse_url( $assoc_args['url'], PHP_URL_HOST );
			$requested_path   = wp_parse_url( $assoc_args['url'], PHP_URL_PATH );
			$site_from_url    = get_blog_id_from_url( $requested_domain, $requested_path );
			if ( $site_from_url ) {
				$assoc_args['blog_id'] = $site_from_url;
			}
		}

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
