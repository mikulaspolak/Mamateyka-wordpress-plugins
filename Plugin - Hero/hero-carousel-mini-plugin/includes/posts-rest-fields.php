<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Transform a post into carousel item format
 */
function hcm_transform_post_to_carousel_item($post)
{
    if (!$post instanceof WP_Post) {
        return null;
    }

    $post_id = $post->ID;

    // Get featured image
    $image_data = array(
        'image_url' => '',
        'thumbnail_url' => '',
        'image_srcset' => '',
        'image_sizes' => '',
        'image_alt' => '',
    );

    if (has_post_thumbnail($post_id)) {
        $thumb_id = get_post_thumbnail_id($post_id);
        $image_data = hcm_get_attachment_image_data($thumb_id);
    }

    // If no featured image, return null to skip this post
    if (!$image_data['image_url']) {
        return null;
    }

    return array(
        'id' => $post_id,
        'title' => array(
            'rendered' => wp_kses_post($post->post_title),
        ),
        'carousel_title' => wp_kses_post($post->post_title),
        'image_url' => $image_data['image_url'],
        'thumbnail_url' => $image_data['thumbnail_url'],
        'image_srcset' => $image_data['image_srcset'],
        'image_sizes' => $image_data['image_sizes'],
        'image_alt' => esc_attr($image_data['image_alt'] ?: $post->post_title),
        'carousel_link' => esc_url_raw(get_permalink($post_id)),
        'carousel_show_button' => true,
        'link' => esc_url_raw(get_permalink($post_id)),
        'date' => $post->post_date_gmt,
    );
}

/**
 * Return the image variants used by the carousel for an attachment.
 */
function hcm_get_attachment_image_data($attachment_id)
{
    $attachment_id = absint($attachment_id);
    if ($attachment_id <= 0) {
        return array(
            'image_url' => '',
            'thumbnail_url' => '',
            'image_srcset' => '',
            'image_sizes' => '',
            'image_alt' => '',
        );
    }

    $image = wp_get_attachment_image_src($attachment_id, 'large');
    $thumbnail = wp_get_attachment_image_src($attachment_id, 'medium');
    $image_srcset = (string) (wp_get_attachment_image_srcset($attachment_id, 'large') ?: '');
    $image_srcset_candidates = array_filter(
        preg_split('/,\s*/', $image_srcset),
        static function ($candidate) {
            return preg_match('/\s(\d+)w$/', trim($candidate), $matches) && (int) $matches[1] <= 1024;
        }
    );

    return array(
        'image_url' => esc_url_raw($image[0] ?? ''),
        'thumbnail_url' => esc_url_raw($thumbnail[0] ?? ($image[0] ?? '')),
        'image_srcset' => implode(', ', $image_srcset_candidates),
        'image_sizes' => (string) (wp_get_attachment_image_sizes($attachment_id, 'large') ?: ''),
        'image_alt' => (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
    );
}

/**
 * Register universal REST endpoint (manual hero items + posts from both categories)
 */
add_action('rest_api_init', 'hcm_register_universal_rest_endpoint');
function hcm_register_universal_rest_endpoint()
{
    register_rest_route('hcm/v1', '/carousel', array(
        'methods' => 'GET',
        'callback' => 'hcm_get_universal_carousel',
        'permission_callback' => '__return_true',
    ));
}

/**
 * Cache key for the combined universal carousel payload.
 */
function hcm_universal_carousel_cache_key()
{
    return 'hcm_universal_carousel';
}

/**
 * Clear the cached universal carousel payload.
 */
function hcm_flush_universal_carousel_cache()
{
    delete_transient(hcm_universal_carousel_cache_key());
}
add_action('save_post', 'hcm_flush_universal_carousel_cache');
add_action('deleted_post', 'hcm_flush_universal_carousel_cache');
add_action('trashed_post', 'hcm_flush_universal_carousel_cache');

// Covers scheduled posts published by WP-Cron, which do not fire save_post.
add_action('transition_post_status', 'hcm_flush_universal_carousel_cache_on_transition', 10, 3);
function hcm_flush_universal_carousel_cache_on_transition($new_status, $old_status, $post)
{
    if ($new_status === 'publish' || $old_status === 'publish') {
        hcm_flush_universal_carousel_cache();
    }
}

/**
 * Determine whether a post's featured image would render in the carousel.
 * Returns 'show' | 'no_image' | 'broken_image'.
 */
function hcm_get_post_image_status($post_id)
{
    if (!has_post_thumbnail($post_id)) {
        return 'no_image';
    }

    $thumb = wp_get_attachment_image_src(get_post_thumbnail_id($post_id), 'large');
    if (empty($thumb[0])) {
        return 'broken_image';
    }

    return 'show';
}

/**
 * Walk the newest published posts of a category (newest first) until $limit posts
 * with a valid featured image are collected, backfilling past ones without an image.
 * Returns ordered rows (including skipped ones) as ['post_id', 'title', 'status'].
 */
function hcm_evaluate_category_posts($slug, $limit)
{
    $limit = (int) $limit;
    if ($limit <= 0) {
        return array();
    }

    $term = get_term_by('slug', $slug, 'category');
    if (!$term) {
        return array();
    }

    $query = new WP_Query(array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
        'fields' => 'ids',
        'no_found_rows' => true,
        'tax_query' => array(
            array(
                'taxonomy' => 'category',
                'field' => 'term_id',
                'terms' => $term->term_id,
            ),
        ),
    ));

    $results = array();
    $valid_count = 0;

    foreach ($query->posts as $post_id) {
        $status = hcm_get_post_image_status($post_id);

        $results[] = array(
            'post_id' => (int) $post_id,
            'title' => get_the_title($post_id),
            'status' => $status,
        );

        if ($status === 'show') {
            $valid_count++;
            if ($valid_count >= $limit) {
                break;
            }
        }
    }

    return $results;
}

/**
 * Append carousel items for the newest displayable posts of a category (backfilled).
 */
function hcm_append_category_carousel_items(&$combined_items, $slug, $count)
{
    if ((int) $count <= 0) {
        return;
    }

    $post_ids = hcm_get_frontend_category_post_ids($slug, $count);
    foreach ($post_ids as $post_id) {
        $item = hcm_transform_post_to_carousel_item(get_post($post_id));
        if ($item) {
            $combined_items[] = $item;
        }
    }
}

/**
 * Find enough frontend posts in small batches instead of loading a whole category.
 */
function hcm_get_frontend_category_post_ids($slug, $limit)
{
    $limit = (int) $limit;
    if ($limit <= 0) {
        return array();
    }

    $term = get_term_by('slug', $slug, 'category');
    if (!$term) {
        return array();
    }

    $post_ids = array();
    $offset = 0;
    $batch_size = max(10, min(30, $limit * 2));

    do {
        $query = new WP_Query(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => array(
                array(
                    'key' => '_thumbnail_id',
                    'compare' => 'EXISTS',
                ),
            ),
            'tax_query' => array(
                array(
                    'taxonomy' => 'category',
                    'field' => 'term_id',
                    'terms' => $term->term_id,
                ),
            ),
        ));

        foreach ($query->posts as $post_id) {
            if (hcm_get_post_image_status($post_id) !== 'show') {
                continue;
            }

            $post_ids[] = (int) $post_id;
            if (count($post_ids) >= $limit) {
                break 2;
            }
        }

        $batch_count = count($query->posts);
        $offset += $batch_count;
    } while ($batch_count > 0);

    return $post_ids;
}

/**
 * Get universal carousel (manual items + Clanky posts + Galeria posts)
 */
function hcm_get_universal_carousel_items()
{
    $cache_key = hcm_universal_carousel_cache_key();
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $clanky_count = (int) get_option('hcm_posts_clanky_count', 5);
    $galeria_count = (int) get_option('hcm_posts_galeria_count', 3);
    $combined_items = array();

    // 1. Get all enabled manual hero_carousel items
    $hero_items_args = array(
        'post_type' => 'hero_carousel',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => array(
            'menu_order' => 'ASC',
            'date' => 'ASC',
            'ID' => 'ASC',
        ),
        'meta_query' => array(
            'relation' => 'OR',
            array(
                'key' => 'carousel_enabled',
                'value' => '1',
                'compare' => '=',
            ),
            array(
                'key' => 'carousel_enabled',
                'compare' => 'NOT EXISTS',
            ),
        ),
    );

    $hero_query = new WP_Query($hero_items_args);
    $hero_posts = $hero_query->posts;

    foreach ($hero_posts as $post) {
        $item = hcm_transform_hero_item_to_carousel($post);
        if ($item) {
            $combined_items[] = $item;
        }
    }

    // 2. Add posts from Clanky category (backfilled to full count when possible)
    hcm_append_category_carousel_items($combined_items, 'clanky', $clanky_count);

    // 3. Add posts from Galeria category (backfilled to full count when possible)
    hcm_append_category_carousel_items($combined_items, 'galeria', $galeria_count);

    set_transient($cache_key, $combined_items, 10 * MINUTE_IN_SECONDS);

    return $combined_items;
}

/**
 * Get universal carousel as a REST response.
 */
function hcm_get_universal_carousel($request)
{
    return new WP_REST_Response(hcm_get_universal_carousel_items(), 200);
}

/**
 * Transform manual hero_carousel item to carousel format
 */
function hcm_transform_hero_item_to_carousel($post)
{
    if (!$post instanceof WP_Post) {
        return null;
    }

    $post_id = $post->ID;

    // Get image from ACF or featured image
    $image_url = '';
    $image_alt = '';
    $image_data = array(
        'thumbnail_url' => '',
        'image_srcset' => '',
        'image_sizes' => '',
    );

    if (function_exists('get_field')) {
        $image = get_field('carousel_image', $post_id);
        if (!empty($image['url'])) {
            $image_url = $image['url'];
            $image_alt = $image['alt'] ?? '';

            $attachment_id = absint($image['ID'] ?? ($image['id'] ?? 0));
            if ($attachment_id > 0) {
                $attachment_data = hcm_get_attachment_image_data($attachment_id);
                $image_url = $attachment_data['image_url'] ?: $image_url;
                $image_alt = $image_alt ?: $attachment_data['image_alt'];
                $image_data = $attachment_data;
            }
        }
    }

    // Fallback to featured image
    if (!$image_url && has_post_thumbnail($post_id)) {
        $thumb_id = get_post_thumbnail_id($post_id);
        $attachment_data = hcm_get_attachment_image_data($thumb_id);
        $image_url = $attachment_data['image_url'];
        $image_alt = $attachment_data['image_alt'];
        $image_data = $attachment_data;
    }

    if (!$image_url) {
        return null;
    }

    // Get title and link from ACF or post fields
    $carousel_title = function_exists('get_field') ? (string) get_field('carousel_title', $post_id) : (string) $post->post_title;
    $carousel_title = trim($carousel_title);
    $raw_link = function_exists('get_field') ? get_field('carousel_link', $post_id) : '';
    $carousel_link = function_exists('hcm_resolve_carousel_link') ? hcm_resolve_carousel_link($raw_link) : esc_url_raw((string) $raw_link);
    $carousel_show_button = function_exists('get_field') ? get_field('carousel_show_button', $post_id) : true;

    return array(
        'id' => $post_id,
        'type' => 'hero_carousel',
        'title' => array(
            'rendered' => wp_kses_post($carousel_title),
        ),
        'carousel_title' => wp_kses_post($carousel_title),
        'image_url' => esc_url_raw($image_url),
        'thumbnail_url' => $image_data['thumbnail_url'] ?: esc_url_raw($image_url),
        'image_srcset' => $image_data['image_srcset'],
        'image_sizes' => $image_data['image_sizes'],
        'image_alt' => esc_attr($image_alt ?: ($carousel_title ?: $post->post_title)),
        'carousel_link' => $carousel_link,
        'carousel_show_button' => $carousel_show_button === null ? true : (bool) $carousel_show_button,
        'link' => $carousel_link,
        'date' => $post->post_date_gmt,
    );
}
