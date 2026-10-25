<?php

/**
 * Uninstall routine for Farské oznamy.
 *
 * This file is executed ONLY when the plugin is deleted via Plugins → Delete
 * in the WordPress admin. It is NOT called on plugin deactivation or update.
 *
 * On every plugin update (uploading a new zip), WordPress simply replaces the
 * plugin files — it never touches the database, so all saved settings, cached
 * content, and sync history are automatically preserved.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin settings.
delete_option('oznamy_settings');

// Remove DB version flag.
delete_option('oznamy_db_version');

// Remove stale-content fallback copies.
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE 'oznamy_stale_%'"
);

// Remove all cached transients (doc HTML + Google OAuth token).
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_oznamy_%'
        OR option_name LIKE '_transient_timeout_oznamy_%'"
);

// Remove sync history.
delete_option('oznamy_sync_history');
delete_option('oznamy_last_publication');
delete_option('oznamy_version_states');
delete_option('oznamy_load_issues');
