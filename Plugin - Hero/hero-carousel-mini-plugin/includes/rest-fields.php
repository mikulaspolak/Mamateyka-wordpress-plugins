<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', 'hcm_register_rest_fields');
function hcm_register_rest_fields()
{
    register_rest_field('hero_carousel', 'image_url', array(
        'get_callback' => 'hcm_get_image_url',
        'schema' => array(
            'type' => 'string',
            'description' => 'Hero image URL',
            'context' => array('view', 'edit'),
        ),
    ));

    register_rest_field('hero_carousel', 'image_alt', array(
        'get_callback' => 'hcm_get_image_alt',
        'schema' => array(
            'type' => 'string',
            'description' => 'Hero image alt text',
            'context' => array('view', 'edit'),
        ),
    ));

    register_rest_field('hero_carousel', 'carousel_title', array(
        'get_callback' => 'hcm_get_carousel_title',
        'schema' => array(
            'type' => 'string',
            'description' => 'Carousel title',
            'context' => array('view', 'edit'),
        ),
    ));

    register_rest_field('hero_carousel', 'carousel_link', array(
        'get_callback' => 'hcm_get_carousel_link',
        'schema' => array(
            'type' => 'string',
            'description' => 'Carousel target URL',
            'context' => array('view', 'edit'),
        ),
    ));

    register_rest_field('hero_carousel', 'carousel_enabled', array(
        'get_callback' => 'hcm_get_carousel_enabled',
        'schema' => array(
            'type' => 'boolean',
            'description' => 'Whether the carousel item is enabled',
            'context' => array('view', 'edit'),
        ),
    ));

    register_rest_field('hero_carousel', 'carousel_show_button', array(
        'get_callback' => 'hcm_get_carousel_show_button',
        'schema' => array(
            'type' => 'boolean',
            'description' => 'Whether the CTA button is shown instead of full-image clickable mode',
            'context' => array('view', 'edit'),
        ),
    ));
}

add_filter('rest_hero_carousel_query', 'hcm_filter_rest_query_to_enabled_items', 10, 2);
function hcm_filter_rest_query_to_enabled_items($args, $request)
{
    if ($request->get_param('context') === 'edit') {
        return $args;
    }

    $enabled_meta_query = array(
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
    );

    if (!empty($args['meta_query']) && is_array($args['meta_query'])) {
        $args['meta_query'] = array(
            'relation' => 'AND',
            $args['meta_query'],
            $enabled_meta_query,
        );
    } else {
        $args['meta_query'] = $enabled_meta_query;
    }

    return $args;
}

function hcm_get_post_id_from_rest_item($post)
{
    return isset($post['id']) ? (int) $post['id'] : 0;
}

function hcm_get_image_url($post)
{
    $post_id = hcm_get_post_id_from_rest_item($post);

    if (function_exists('get_field')) {
        $image = get_field('carousel_image', $post_id);
        if (!empty($image['url'])) {
            return esc_url_raw($image['url']);
        }
    }

    if (has_post_thumbnail($post_id)) {
        $thumb = wp_get_attachment_image_src(get_post_thumbnail_id($post_id), 'large');
        return !empty($thumb[0]) ? esc_url_raw($thumb[0]) : null;
    }

    return null;
}

function hcm_get_image_alt($post)
{
    $post_id = hcm_get_post_id_from_rest_item($post);

    if (function_exists('get_field')) {
        $image = get_field('carousel_image', $post_id);
        if (!empty($image['alt'])) {
            return sanitize_text_field($image['alt']);
        }
    }

    return get_the_title($post_id);
}

function hcm_get_carousel_title($post)
{
    $post_id = hcm_get_post_id_from_rest_item($post);

    if (function_exists('get_field')) {
        $value = trim((string) get_field('carousel_title', $post_id));
        return $value !== '' ? sanitize_text_field($value) : '';
    }

    return get_the_title($post_id);
}

function hcm_resolve_carousel_link($raw_link)
{
    if (!is_string($raw_link)) {
        return '';
    }

    $link = trim($raw_link);
    if ($link === '') {
        return '';
    }

    $scheme = wp_parse_url($link, PHP_URL_SCHEME);
    if (!empty($scheme) || strpos($link, '//') === 0) {
        return esc_url_raw($link);
    }

    if ($link[0] !== '/') {
        $link = '/' . $link;
    }

    return esc_url_raw(home_url($link));
}

function hcm_get_carousel_link($post)
{
    $post_id = hcm_get_post_id_from_rest_item($post);

    if (function_exists('get_field')) {
        $value = get_field('carousel_link', $post_id);
        if (!empty($value)) {
            return hcm_resolve_carousel_link($value);
        }
    }

    return get_permalink($post_id);
}

function hcm_get_carousel_enabled($post)
{
    $post_id = hcm_get_post_id_from_rest_item($post);
    $raw_value = get_post_meta($post_id, 'carousel_enabled', true);

    if ($raw_value === '') {
        return true;
    }

    return (bool) $raw_value;
}

function hcm_get_carousel_show_button($post)
{
    $post_id = hcm_get_post_id_from_rest_item($post);
    $raw_value = get_post_meta($post_id, 'carousel_show_button', true);

    if ($raw_value === '') {
        return true;
    }

    return (bool) $raw_value;
}
