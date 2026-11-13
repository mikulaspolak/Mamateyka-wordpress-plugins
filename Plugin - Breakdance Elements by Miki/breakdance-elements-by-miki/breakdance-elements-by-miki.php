<?php

/**
 * Plugin Name: Breakdance Elements by Miki
 * Description: Custom Breakdance elements, macros, and presets by Miki.
 * Version: 1.30.0
 * Author: Mikulas Polak
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * License: Proprietary - All rights reserved
 */

namespace BreakdanceElementsByMiki;

if (!defined('ABSPATH')) {
    exit;
}

use function Breakdance\Util\getDirectoryPathRelativeToPluginFolder;

require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/elements/Featured_Image_Ilustracny_obrazok/featured-image.php';

const CATEGORY_SLUG = 'miki-elements';
const CATEGORY_LABEL = 'Elements by Miki';
// Last Breakdance major version this plugin was checked against; a newer major only triggers a notice.
const TESTED_BREAKDANCE_MAJOR = 2;

function is_breakdance_available(): bool
{
    return function_exists('Breakdance\Elements\registerCategory')
        && function_exists('Breakdance\ElementStudio\registerSaveLocation')
        && function_exists('Breakdance\Util\getDirectoryPathRelativeToPluginFolder');
}

// Priority 9 runs before Breakdance includes the element files, so the category exists when elements load.
add_action('breakdance_loaded', function () {
    if (!is_breakdance_available()) {
        return;
    }

    \Breakdance\Elements\registerCategory(CATEGORY_SLUG, CATEGORY_LABEL);

    $pluginPath = getDirectoryPathRelativeToPluginFolder(__DIR__);

    \Breakdance\ElementStudio\registerSaveLocation(
        $pluginPath . '/elements',
        'BreakdanceElementsByMiki',
        'element',
        'Miki Elements',
        false
    );

    \Breakdance\ElementStudio\registerSaveLocation(
        $pluginPath . '/macros',
        'BreakdanceElementsByMiki',
        'macro',
        'Miki Macros',
        false
    );

    \Breakdance\ElementStudio\registerSaveLocation(
        $pluginPath . '/presets',
        'BreakdanceElementsByMiki',
        'preset',
        'Miki Presets',
        false
    );
}, 9);

add_action('wp_enqueue_scripts', function () {
    $scriptPath = __DIR__ . '/elements/Memories_Spomienky/memories.js';
    wp_enqueue_script(
        'miki-memories',
        plugin_dir_url(__FILE__) . 'elements/Memories_Spomienky/memories.js',
        [],
        (string) filemtime($scriptPath),
        true
    );

    $galleryScriptPath = __DIR__ . '/elements/Post_Gallery_Galeria_clanku/post-gallery.js';
    wp_enqueue_script(
        'miki-post-gallery',
        plugin_dir_url(__FILE__) . 'elements/Post_Gallery_Galeria_clanku/post-gallery.js',
        [],
        (string) filemtime($galleryScriptPath),
        ['in_footer' => true, 'strategy' => 'defer']
    );
});

add_action('admin_notices', function () {
    if (!current_user_can('activate_plugins')) {
        return;
    }

    if (!is_breakdance_available()) {
        echo '<div class="notice notice-warning"><p>'
            . esc_html(t('Breakdance Elements by Miki requires an active Breakdance plugin.'))
            . '</p></div>';
        return;
    }

    $version = defined('__BREAKDANCE_VERSION') ? (string) \__BREAKDANCE_VERSION : '';
    if ($version !== '' && (int) $version > TESTED_BREAKDANCE_MAJOR) {
        echo '<div class="notice notice-warning"><p>'
            . esc_html(sprintf(
                t('Breakdance Elements by Miki was tested with Breakdance %1$d.x (installed: %2$s). After updating, check the elements in the editor and on the frontend.'),
                TESTED_BREAKDANCE_MAJOR,
                $version
            ))
            . '</p></div>';
    }
});
