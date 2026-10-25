<?php

/**
 * Plugin Name: Hero Carousel Mini Plugin
 * Description: Hero Carousel with automatic posts from Clanky and Galeria categories + separate templates (CSS/JS bundled in plugin)
 * Version: 3.9.0
 * Author: Mikulas Polak
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: Proprietary - All rights reserved
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HCM_PLUGIN_VERSION', '3.9.0');
define('HCM_PLUGIN_FILE', __FILE__);
define('HCM_PLUGIN_PATH', plugin_dir_path(__FILE__));

require_once HCM_PLUGIN_PATH . 'includes/post-type.php';
require_once HCM_PLUGIN_PATH . 'includes/acf-fields.php';
require_once HCM_PLUGIN_PATH . 'includes/rest-fields.php';
require_once HCM_PLUGIN_PATH . 'includes/admin-columns.php';
require_once HCM_PLUGIN_PATH . 'includes/settings.php';
require_once HCM_PLUGIN_PATH . 'includes/posts-rest-fields.php';
require_once HCM_PLUGIN_PATH . 'includes/posts-carousel.php';

function hcm_plugin_activate()
{
    hcm_register_post_type();
    update_option('hcm_plugin_version', HCM_PLUGIN_VERSION);
    flush_rewrite_rules();
}
register_activation_hook(HCM_PLUGIN_FILE, 'hcm_plugin_activate');

add_action('plugins_loaded', 'hcm_maybe_upgrade');
function hcm_maybe_upgrade()
{
    $installed_version = get_option('hcm_plugin_version', '0.0.0');

    if (version_compare((string) $installed_version, HCM_PLUGIN_VERSION, '>=')) {
        return;
    }

    if (version_compare((string) $installed_version, '1.1.0', '<')) {
        if (function_exists('hcm_backfill_empty_hero_titles')) {
            hcm_backfill_empty_hero_titles();
        }
    }

    if (version_compare((string) $installed_version, '2.1.0', '<')) {
        if (get_option('hcm_carousel_aspect_ratio', null) === null) {
            update_option('hcm_carousel_aspect_ratio', '3:4');
        }
    }

    if (version_compare((string) $installed_version, '2.9.0', '<')) {
        // Featured images now served at a larger size; drop the cached low-res URLs.
        if (function_exists('hcm_flush_universal_carousel_cache')) {
            hcm_flush_universal_carousel_cache();
        }
    }

    if (version_compare((string) $installed_version, '2.11.0', '<')) {
        // CSS/JS are now bundled in the plugin only; drop legacy editable options.
        delete_option('hcm_carousel_css');
        delete_option('hcm_carousel_js');
        delete_option('hcm_posts_count');

        if (function_exists('hcm_flush_universal_carousel_cache')) {
            hcm_flush_universal_carousel_cache();
        }
    }

    update_option('hcm_plugin_version', HCM_PLUGIN_VERSION);
}

function hcm_plugin_deactivate()
{
    flush_rewrite_rules();
}
register_deactivation_hook(HCM_PLUGIN_FILE, 'hcm_plugin_deactivate');
