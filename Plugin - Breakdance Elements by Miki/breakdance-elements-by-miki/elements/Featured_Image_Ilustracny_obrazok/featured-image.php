<?php

namespace BreakdanceElementsByMiki\FeaturedImageOutput;

if (!defined('ABSPATH')) {
    exit;
}

// Helper of the Featured Image element. The element template cannot read WordPress data, so it prints a marker
// comment. On the front end an output filter swaps it for the featured image of the current post; in the
// Breakdance editor featured-image-editor.js asks the AJAX endpoint below for a preview image.

const MARKER_PATTERN = '/<!--miki-featured-image:([a-z0-9_-]{1,40})-->/';
const DEFAULT_SIZE = 'full';
const MIN_RATIO = 9 / 16;
const MAX_RATIO = 21 / 9;
const AJAX_ACTION = 'miki_featured_image_preview';

add_action('template_redirect', __NAMESPACE__ . '\\start_buffer', 0);
add_action('wp_ajax_' . AJAX_ACTION, __NAMESPACE__ . '\\ajax_preview');
add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_editor_script');

function start_buffer(): void
{
    if (is_admin() || wp_doing_ajax() || is_feed() || is_embed() || wp_is_json_request() || !is_singular()) {
        return;
    }

    ob_start(__NAMESPACE__ . '\\replace_markers');
}

function replace_markers(string $html): string
{
    if (strpos($html, '<!--miki-featured-image:') === false) {
        return $html;
    }

    $replaced = preg_replace_callback(
        MARKER_PATTERN,
        static fn(array $match): string => render_image($match[1], (int) get_queried_object_id()),
        $html
    );

    return is_string($replaced) ? $replaced : $html;
}

function resolve_size(string $size): string
{
    if ($size === 'full' || in_array($size, get_intermediate_image_sizes(), true)) {
        return $size;
    }

    return DEFAULT_SIZE;
}

function render_image(string $size, int $postId): string
{
    $attachmentId = $postId > 0 ? (int) get_post_thumbnail_id($postId) : 0;
    if ($attachmentId <= 0) {
        return '';
    }

    $size = resolve_size($size);
    $source = wp_get_attachment_image_src($attachmentId, $size);
    if (!is_array($source) || empty($source[0])) {
        return '';
    }

    $attributes = [
        'class' => 'miki-featured-image__img',
        'alt' => (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true),
        'loading' => 'eager',
        'decoding' => 'async',
        'fetchpriority' => 'high',
    ];

    // Extreme panoramas and tall portraits are cropped (object-fit: cover) to keep the shape decoration balanced.
    $width = (int) $source[1];
    $height = (int) $source[2];
    if ($width > 0 && $height > 0) {
        $ratio = $width / $height;
        $clamped = min(max($ratio, MIN_RATIO), MAX_RATIO);
        if (abs($clamped - $ratio) > 0.0001) {
            $attributes['style'] = 'aspect-ratio:' . round($clamped, 4);
        }
    }

    return wp_get_attachment_image($attachmentId, $size, false, $attributes);
}

function is_editor_request(): bool
{
    foreach (array_keys($_GET) as $key) {
        if (is_string($key) && strpos($key, 'breakdance') === 0) {
            return true;
        }
    }

    return false;
}

function enqueue_editor_script(): void
{
    if (!is_editor_request() || !current_user_can('edit_posts')) {
        return;
    }

    $path = __DIR__ . '/featured-image-editor.js';
    wp_enqueue_script(
        'miki-featured-image-editor',
        plugin_dir_url(__FILE__) . 'featured-image-editor.js',
        [],
        (string) filemtime($path),
        ['in_footer' => true, 'strategy' => 'defer']
    );
    wp_localize_script('miki-featured-image-editor', 'mikiFeaturedImage', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'action' => AJAX_ACTION,
        'nonce' => wp_create_nonce(AJAX_ACTION),
    ]);
}

// Editor preview: the post being edited, else the newest post that has a featured image.
function find_preview_post(int $requestedId): int
{
    if ($requestedId > 0 && current_user_can('edit_post', $requestedId) && has_post_thumbnail($requestedId)) {
        return $requestedId;
    }

    $latest = get_posts([
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_key' => '_thumbnail_id',
        'orderby' => 'date',
        'order' => 'DESC',
        'no_found_rows' => true,
    ]);

    return $latest ? (int) $latest[0] : 0;
}

function ajax_preview(): void
{
    check_ajax_referer(AJAX_ACTION, 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(null, 403);
    }

    $size = isset($_POST['size']) ? sanitize_key(wp_unslash($_POST['size'])) : DEFAULT_SIZE;
    $requestedId = isset($_POST['post']) ? absint($_POST['post']) : 0;

    wp_send_json_success(['html' => render_image($size, find_preview_post($requestedId))]);
}
