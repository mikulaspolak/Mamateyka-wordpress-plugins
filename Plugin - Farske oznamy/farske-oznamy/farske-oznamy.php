<?php

/**
 * Plugin Name: Farské oznamy
 * Description: Automatically fetches parish announcements and Mass intentions from Google Docs.
 * Version:     1.125.0
 * Requires at least: 5.9
 * Requires PHP: 7.0
 * Author:      Mikulas Polak
 * License:     Proprietary - All rights reserved
 * Text Domain: oznamy-gdocs
 *
 * Changelog:
 *   1.125.0 - Release package generated.
 *   1.124.0 - Release package generated.
 *   1.123.0 - Release package generated.
 *   1.122.0 - Release package generated.
 *   1.121.0 - Release package generated.
 *   1.120.0 - Release package generated.
 *   1.119.0 - Release package generated.
 *   1.118.0 - Release package generated.
 *   1.117.0 - Release package generated.
 *   1.116.0 - Added an optional fixed two-column layout for the image gallery shortcode.
 *   1.115.0 - Release package generated.
 *   1.114.0 - Release package generated.
 *   1.113.0 - Release package generated.
 *   1.112.0 - Release package generated.
 *   1.111.0 - Release package generated.
 *   1.110.0 - Release package generated.
 *   1.109.0 - Removed the calendar icon from the single-month heading.
 *   1.108.0 - Removed the separate Mass intentions filter-clear button.
 *   1.107.0 - Release package generated.
 *   1.106.0 - Added a themed search-field clear button.
 *   1.105.0 - Release package generated.
 *   1.104.0 - Added immediate diacritic-insensitive search for Mass intentions.
 *   1.103.0 - Release package generated.
 *   1.102.0 - Release package generated.
 *   1.101.0 - Release package generated.
 *   1.100.0 - Release package generated.
 *   1.99.0 - Release package generated.
 *   1.98.0 - Mass intentions mark today using the visitor's local date.
 *   1.97.0 - Release package generated.
 *   1.96.0 - Release package generated.
 *   1.95.0 - Release package generated.
 *   1.94.0 - Release package generated.
 *   1.93.0 - Release package generated.
 *   1.92.0 - Added [umysly-svatych-omsi] shortcode for Mass intentions.
 *   1.91.0 - Release package generated.
 *   1.90.0 - Release package generated.
 *   1.89.0 - Release package generated.
 *   1.88.0 - Release package generated.
 *   1.87.0 - Release package generated.
 *   1.86.0 - Release package generated.
 *   1.85.0 - Release package generated.
 *   1.84.0 - Release package generated.
 *   1.83.0 - Release package generated.
 *   1.82.0 - Release package generated.
 *   1.81.0 - Release package generated.
 *   1.80.0 - Release package generated.
 *   1.79.0 - Release package generated.
 *   1.78.0 - Release package generated.
 *   1.77.0 - Release package generated.
 *   1.76.0 - Release package generated.
 *   1.75.0 - Release package generated.
 *   1.74.0 - Release package generated.
 *   1.73.0 - Added standalone historical announcement banner shortcode.
 *   1.72.0 - Release package generated.
 *   1.71.0 - Release package generated.
 *   1.70.0 - Release package generated.
 *   1.69.0 - Release package generated.
 *   1.68.0 - Release package generated.
 *   1.67.0 - Release package generated.
 *   1.66.0 - Added [farske_oznamy_date_picker] archive navigation shortcode.
 *   1.65.0 - Added date-based public archive links for historical notices.
 *   1.64.0 - Release package generated.
 *   1.63.0 - Release package generated.
 *   1.62.0 - Release package generated.
 *   1.61.0 - Release package generated.
 *   1.60.0 - Release package generated.
 *   1.59.0 - Release package generated.
 *   1.58.0 - Release package generated.
 *   1.57.0 - Release package generated.
 *   1.56.0 - Release package generated.
 *   1.55.0 - Release package generated.
 *   1.54.0 - Added [farske_oznamy_moderne] shortcode with separate panels for numbered announcements.
 *   1.53.0 - Release package generated.
 *   1.52.0 - Release package generated.
 *   1.51.0 - Release package generated.
 *   1.50.0 - Release package generated.
 *   1.49.0 - Release package generated.
 *   1.48.0 - Release package generated.
 *   1.47.0 - Frontend refresh button now shows an inline summary (what was refreshed + which pages had cache purged) instead of reloading; parish page (/farnost) cache is always purged on refresh.
 *   1.46.0 - Release package generated.
 *   1.45.0 - Refresh (admin + frontend button) now also purges WP Super Cache for pages containing the plugin's shortcodes; nicer loading spinner on the frontend refresh button.
 *   1.44.0 - Release package generated.
 *   1.43.0 - Release package generated.
 *   1.42.0 - Release package generated.
 *   1.41.0 - Release package generated.
 *   1.40.0 - Release package generated.
 *   1.39.0 - Release package generated.
 *   1.38.0 - Release package generated.
 *   1.37.0 - Release package generated.
 *   1.36.0 - Release package generated.
 *   1.35.0 - Release package generated.
 *   1.34.0 - Release package generated.
 *   1.33.0 - Release package generated.
 *   1.32.0 - Release package generated.
 *   1.31.0 - Release package generated.
 *   1.30.0 - Release package generated.
 *   1.29.0 - Release package generated.
 *   1.28.0 - Release package generated.
 *   1.27.0 - Release package generated.
 *   1.26.0 - Release package generated.
 *   1.25.0 - Added [farske_oznamy_buduce_obrazky] shortcode for future image preview with countdown.
 *   1.24.0 - Added [farske_oznamy_buduce] shortcode for preview of nearest future announcements with countdown until auto-publication.
 *   1.23.0 – Subfolder structure: plugin now reads from Oznamy_YYYY.MM.DD subfolders in the root folder; new [farske_oznamy_obrazky] shortcode for image gallery with masonry layout and lightbox; images cached to WP uploads; date attribute format changed to DD.MM.YYYY.
 *   1.22.0 - Release package generated.
 *   1.21.0 - Release package generated.
 *   1.20.0 - Release package generated.
 *   1.19.0 - Release package generated.
 *   1.18.0 - Release package generated.
 *   1.17.0 - Release package generated.
 *   1.16.0 - Release package generated.
 *   1.15.0 - Release package generated.
 *   1.14.0 - Release package generated.
 *   1.13.0 - Release package generated.
 *   1.12.0 - Release package generated.
 *   1.11.0 - Release package generated.
 *   1.10.0 - Release package generated.
 *   1.9.0 - Release package generated.
 *   1.8.0 - Release package generated.
 *   1.7.0 - Release package generated.
 *   1.6.0 - Release package generated.
 *   1.5.0 – Ready for production release.
 *   1.4.0 – Upgrade-safe bootstrapping: version changes now clear cached content on update and preserve all saved settings.
 *   1.3.0 – Enhanced "Test Connection" button: displays detailed diagnostic info including folder URL, document count, 
 *           regex pattern matching status for each file, and visual indicators for compatible documents.
 *   1.2.0 – Improved UI text for Google Drive setup and error messages.
 *   1.1.0 – Renamed shortcode to [farske_oznamy]; moved to main WP menu; added uninstall cleanup.
 *   1.0.0 – Initial release.
 */

if (! defined('ABSPATH')) {
    exit;
}

define('OZNAMY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OZNAMY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OZNAMY_VERSION', '1.125.0');
define('OZNAMY_DB_VERSION', '1.125.0');
define('OZNAMY_OPTION_KEY', 'oznamy_settings');
define('OZNAMY_CACHE_KEY_PREFIX', 'oznamy_cache_');
define('OZNAMY_HISTORY_KEY', 'oznamy_sync_history');

// Activation: store DB version for future upgrade routines.
register_activation_hook(__FILE__, 'oznamy_activate');
function oznamy_activate()
{
    oznamy_run_upgrade('0.0.0');
}

// On every load: run upgrade routines if DB version is outdated.
add_action('plugins_loaded', 'oznamy_check_version');
function oznamy_check_version()
{
    $installed_version = (string) get_option('oznamy_db_version', '0.0.0');

    if (version_compare($installed_version, OZNAMY_DB_VERSION, '<')) {
        oznamy_run_upgrade($installed_version);
    }
}

function oznamy_run_upgrade($installed_version)
{
    $purge_shortcode_pages_cache = version_compare((string) $installed_version, '1.92.0', '<');
    $purge_legacy_image_cache = version_compare((string) $installed_version, '1.66.0', '<');

    if (version_compare((string) $installed_version, '1.4.0', '<')) {
        global $wpdb;

        // Clear runtime caches so the updated code is visible immediately after a ZIP replacement.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_oznamy_%'
                OR option_name LIKE '_transient_timeout_oznamy_%'
                OR option_name LIKE 'oznamy_stale_%'"
        );
    }

    if (version_compare((string) $installed_version, '1.23.0', '<')) {
        // Clear all caches (incl. image filesystem cache) when upgrading to subfolder structure.
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                '_transient_oznamy_%',
                '_transient_timeout_oznamy_%',
                'oznamy_stale_%'
            )
        );
        // Image cache will be cleared once Oznamy_Shortcode class is loaded.
        if (class_exists('Oznamy_Shortcode')) {
            Oznamy_Shortcode::clear_image_cache();
        }
    }

    if ($purge_legacy_image_cache) {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                '_transient_oznamy_cache_images_%',
                '_transient_timeout_oznamy_cache_images_%',
                '_transient_oznamy_cache_future_images_%',
                '_transient_timeout_oznamy_cache_future_images_%'
            )
        );
    }

    update_option('oznamy_db_version', OZNAMY_DB_VERSION, false);

    if ($purge_shortcode_pages_cache && class_exists('Oznamy_Shortcode')) {
        if (did_action('wp_loaded')) {
            Oznamy_Shortcode::purge_shortcode_pages_cache();
        } else {
            add_action('wp_loaded', array('Oznamy_Shortcode', 'purge_shortcode_pages_cache'), 20);
        }
    }
}

require_once OZNAMY_PLUGIN_DIR . 'includes/class-gdrive-client.php';
require_once OZNAMY_PLUGIN_DIR . 'includes/class-gdocs-fetcher.php';
require_once OZNAMY_PLUGIN_DIR . 'includes/class-gdocs-renderer.php';
require_once OZNAMY_PLUGIN_DIR . 'includes/class-load-health.php';
require_once OZNAMY_PLUGIN_DIR . 'includes/class-mass-intentions.php';
require_once OZNAMY_PLUGIN_DIR . 'includes/class-shortcode.php';

add_action('oznamy_sunday_release_cache_purge', array('Oznamy_Shortcode', 'purge_shortcode_pages_cache'));
add_action('update_option_' . OZNAMY_OPTION_KEY, array('Oznamy_Shortcode', 'handle_settings_updated'), 10, 0);
add_action('add_option_' . OZNAMY_OPTION_KEY, array('Oznamy_Shortcode', 'handle_settings_updated'), 10, 0);

if (is_admin()) {
    require_once OZNAMY_PLUGIN_DIR . 'admin/settings-page.php';
    add_action('admin_menu', 'oznamy_register_admin_menu');
    add_action('admin_init', 'oznamy_register_settings');
    add_action('admin_notices', array('Oznamy_Load_Health', 'render_admin_notice'));
    add_action('admin_post_oznamy_clear_cache', 'oznamy_handle_clear_cache');
    add_action('admin_post_oznamy_test_connection', 'oznamy_handle_test_connection');
    add_action('admin_post_oznamy_refresh', 'oznamy_handle_refresh');
    add_action('admin_post_oznamy_version_action', array('Oznamy_Shortcode', 'handle_version_action'));
    add_action('admin_post_nopriv_oznamy_version_action', array('Oznamy_Shortcode', 'handle_version_action'));
}

add_shortcode('farske_oznamy', array('Oznamy_Shortcode', 'render_modern'));
add_shortcode('farske_oznamy_banner_history', array('Oznamy_Shortcode', 'render_history_banner'));
add_shortcode('farske_oznamy_date_picker', array('Oznamy_Shortcode', 'render_date_picker'));
add_shortcode('farske_oznamy_obrazky', array('Oznamy_Shortcode', 'render_images'));
add_shortcode('farske_oznamy_buduce', array('Oznamy_Shortcode', 'render_future_modern'));
add_shortcode('farske_oznamy_buduce_obrazky', array('Oznamy_Shortcode', 'render_future_images'));
add_shortcode('farske_oznamy_admin', array('Oznamy_Shortcode', 'render_publication_admin'));
add_shortcode('nacitat-farske-oznamy', array('Oznamy_Shortcode', 'render_refresh_button'));
add_shortcode('umysly-svatych-omsi', array('Oznamy_Mass_Intentions', 'render_shortcode'));
add_action('rest_api_init', array('Oznamy_Shortcode', 'register_embed_route'));
add_action('wp_ajax_oznamy_frontend_refresh', array('Oznamy_Shortcode', 'handle_ajax_refresh'));
add_action('wp_ajax_nopriv_oznamy_frontend_refresh', array('Oznamy_Shortcode', 'handle_ajax_refresh'));
add_action('wp_ajax_oznamy_archive_fetch', array('Oznamy_Shortcode', 'handle_archive_ajax'));
add_action('wp_ajax_nopriv_oznamy_archive_fetch', array('Oznamy_Shortcode', 'handle_archive_ajax'));
add_action('wp_enqueue_scripts', 'oznamy_enqueue_styles');

function oznamy_enqueue_styles()
{
    wp_enqueue_style(
        'oznamy-gdocs',
        OZNAMY_PLUGIN_URL . 'assets/style.css',
        array(),
        OZNAMY_VERSION
    );
}
