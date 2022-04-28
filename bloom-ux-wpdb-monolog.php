<?php

namespace Bloom_UX\WPDB_Monolog;

use WordPressHandler\WordPressHandler;

if ( ! defined( 'Inpsyde\Wonolog\LOG' ) ) {
	return;
}

$wpdb_handler = new WordPressHandler(
	null,
	'log',
	[ 'username', 'userid' ],
	\Monolog\Logger::DEBUG
);
$wpdb_handler->conf_table_size_limiter( 256000 );
$record = [ 'extra' => [] ];
$wpdb_handler->initialize( $record );

\Inpsyde\Wonolog\bootstrap(
	$wpdb_handler
);
