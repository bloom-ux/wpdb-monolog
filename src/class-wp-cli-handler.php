<?php

namespace bloom\WPDB_Monolog;

use WP_CLI;
use Monolog\Handler\AbstractProcessingHandler;

class WP_CLI_Handler extends AbstractProcessingHandler {
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
		if ( $this->level <= 250 ) {
			WP_CLI::error_multi_line( explode( "\n", json_encode( is_string( $record['context'] ) ? json_decode( $record['context'] ) : $record['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) );
		}
	}

}