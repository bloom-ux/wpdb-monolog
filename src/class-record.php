<?php
/**
 * Log record retrieved from database
 *
 * @package bloom\WPDB_Monolog
 */

namespace bloom\WPDB_Monolog;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use ReturnTypeWillChange;

/**
 * Log record retrieved from database
 */
class Record implements JsonSerializable {

	/**
	 * Record ID on database
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Name of the channel that produced the record
	 *
	 * @var string
	 */
	private $channel;

	/**
	 * Record human-readable message
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Record level
	 *
	 * @var int
	 */
	private $level;

	/**
	 * Record level name
	 *
	 * @var string
	 */
	private $level_name;

	/**
	 * Extra data associated with the record
	 *
	 * @var stdClass
	 */
	private $extra;

	/**
	 * Context data associated with the record
	 *
	 * @var stdClass
	 */
	private $context;

	/**
	 * Local date and time the record was created
	 *
	 * @var DateTimeImmutable
	 */
	private $created_at;

	/**
	 * UTC date and time the record was created
	 *
	 * @var DateTimeImmutable
	 */
	private $created_at_gmt;

	/**
	 * Create a new Record object from database data.
	 *
	 * @param array $data {
	 *    Log record data.
	 *    @type string $message The log message.
	 *    @type mixed[] $context The log context.
	 *    @type int $level The severity level of the log.
	 *    @type string $level_name The severity level name of the log.
	 *    @type string $channel The channel name of the log.
	 *    @type \DateTimeImmutable $datetime The timestamp of the log.
	 *    @type mixed[] $extra The extra data.
	 *    @type mixed $formatted The formatted message.
	 * }
	 */
	public function __construct( array $data = array() ) {
		foreach ( $data as $key => $val ) {
			if ( in_array( $key, array( 'id', 'level' ), true ) ) {
				$this->{$key} = (int) $val;
			} elseif ( in_array( $key, array( 'extra', 'context' ), true ) ) {
				$decoded = json_decode( $val, false );
				if ( json_last_error() ) {
					continue;
				}
				$this->{$key} = $decoded;
			} elseif ( 'created_at' === $key ) {
				$this->{$key} = new DateTimeImmutable( $val, wp_timezone() );
			} elseif ( 'created_at_gmt' === $key ) {
				$this->{$key} = new DateTimeImmutable( $val, new DateTimeZone( 'Etc/UTC' ) );
			} elseif ( 'message' === $key ) {
				$this->{$key} = sanitize_textarea_field( $val );
			} elseif ( property_exists( $this, $key ) ) {
				$this->{$key} = sanitize_text_field( $val );
			}
		}
	}

	/**
	 * Get a property from the record
	 *
	 * @param string $key Name of the property.
	 * @return mixed Property value or null if property doesn't exist.
	 */
	public function __get( $key ) {
		return isset( $this->{$key} ) ? $this->{$key} : null;
	}

	/**
	 * Generate a JSON representation of the log record
	 *
	 * @return array Associative array with the log record info
	 */
	#[ReturnTypeWillChange]
	public function jsonSerialize() {
		return array(
			'id'             => $this->id,
			'channel'        => $this->channel,
			'message'        => $this->message,
			'level'          => $this->level,
			'level_name'     => $this->level_name,
			'extra'          => $this->extra,
			'context'        => $this->context,
			'created_at'     => $this->created_at->format( 'c' ),
			'created_at_gmt' => $this->created_at_gmt->format( 'c' ),
		);
	}
}
