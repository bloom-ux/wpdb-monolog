# monolog with WordPress

Use monolog with WordPress and log errors to your database.

## Installation

Install using composer: `composer require bloom-ux/wpdb-monolog:dev-main`.

It will install as a mu-plugin.

If you're using composer's autoloader, you're done.

## Components

- `\bloom\WPDB_Monolog\WPDB_Handler` → monolog handler that writes logs to your WordPress database.
- `\bloom\WPDB_Monolog\WP_Processor` → monolog processor that adds "extra" data to a monolog record.

## Usage

### Basic

There are some helper functions to ease the integration:

```php
<?php
use function bloom\WPDB_Monolog\get_logger_for_channel;
use function bloom\WPDB_Monolog\set_channel_level;

$logger = get_logger_for_channel( 'MyCustomChannel' );
set_channel_level( $logger, 'production' === wp_get_environment_type() ? 400 : 100 );
$logger->info("Lorem ipsum dolor sit amet", array( 'foo' => 'bar' ) );
```

### Custom

You can integrate the handler and/or processor as you see fit with your monolog integration.