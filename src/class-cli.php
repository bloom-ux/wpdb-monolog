<?php

namespace bloom\WPDB_Monolog;

class CLI {
	public function purge_records( $args ) {

	}
	public function install() {
		$repository = Repository::get_instance();
		$repository->install();
	}
}
