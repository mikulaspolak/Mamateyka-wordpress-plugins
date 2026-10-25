<?php
if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;
$prefix = $wpdb->prefix . 'km_';
foreach (array('reservations', 'book_history', 'book_secondary_categories', 'categories_v2', 'book_categories', 'book_tags', 'books', 'tags', 'languages', 'categories', 'category_groups') as $table) {
	$wpdb->query("DROP TABLE IF EXISTS {$prefix}{$table}");
}

remove_role('library_editor');

delete_option('km_settings');
delete_option('km_db_version');
