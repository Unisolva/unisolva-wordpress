<?php
/**
 * Quote Requests
 *
 * Copyright (C) 2026 Unisolva for Information Technology and App Development L.L.C.
 *
 * This program is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software Foundation;
 * either version 2 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * @package Quote_Requests
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Quote records are business data and stay. Remove only scheduling, internal counters and the stored answer about old order statuses.
wp_clear_scheduled_hook( 'quote_requests_purge' );
delete_option( 'quote_requests_legacy_statuses' );
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'quote\_requests\_counter\_%' OR option_name LIKE 'quote\_requests\_rl\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
