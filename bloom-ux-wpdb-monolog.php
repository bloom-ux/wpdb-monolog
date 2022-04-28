<?php

namespace Bloom_UX\WPDB_Monolog;

use Inpsyde\Wonolog\Configurator;
use WordPressHandler\WordPressHandler;

if ( ! defined( 'Inpsyde\Wonolog\LOG' ) ) {
	return;
}

$wpdb_handler = new WordPressHandler(
	null,
	'logs',
	array(
		'wp',
		'username',
		'userid',
		'file',
		'line',
		'extra'
	)
);
$wpdb_handler->conf_table_size_limiter( 512000 );
$record = [ 'extra' => [] ];
$wpdb_handler->initialize( $record );

add_action(
	'wonolog.setup',
	function( Configurator $config ) use ( $wpdb_handler ) {
		$config->disableFallbackHandler();
		$config->pushHandler( $wpdb_handler );
	}
);