<?php

if (!defined('ABSPATH')) {
    exit;
}

add_filter('manage_hero_carousel_posts_columns', 'hcm_admin_columns');
function hcm_admin_columns($columns) {
    $new_columns = array();

    if (isset($columns['cb'])) {
        $new_columns['cb'] = $columns['cb'];
    }

    $new_columns['title'] = isset($columns['title']) ? $columns['title'] : 'Title';
    $new_columns['hero_order'] = 'Order';
    $new_columns['thumbnail'] = 'Image';
    $new_columns['hero_link'] = 'Link';
    $new_columns['hero_enabled'] = 'Status';
    $new_columns['date'] = isset($columns['date']) ? $columns['date'] : 'Date';

    return $new_columns;
}

add_action('manage_hero_carousel_posts_custom_column', 'hcm_admin_custom_column', 10, 2);
function hcm_admin_custom_column($column, $post_id) {
    if ($column === 'thumbnail') {
        $image = function_exists('get_field') ? get_field('carousel_image', $post_id) : null;

        if (is_array($image) && !empty($image['ID'])) {
            echo wp_get_attachment_image((int) $image['ID'], array(50, 50));

            $image_label = '';
            if (!empty($image['title'])) {
                $image_label = $image['title'];
            } elseif (!empty($image['filename'])) {
                $image_label = $image['filename'];
            }

            if ($image_label !== '') {
                echo '<br><small>' . esc_html($image_label) . '</small>';
            }
            return;
        }

        echo '<span aria-hidden="true">-</span>';
        return;
    }

    if ($column === 'hero_order') {
        $post = get_post($post_id);
        $menu_order = $post instanceof WP_Post ? (int) $post->menu_order : 0;
        echo esc_html((string) $menu_order);
        return;
    }

    if ($column === 'hero_link') {
        $link = function_exists('get_field') ? get_field('carousel_link', $post_id) : '';
        if (!empty($link)) {
            $resolved_link = function_exists('hcm_resolve_carousel_link') ? hcm_resolve_carousel_link($link) : esc_url_raw($link);
            echo '<a href="' . esc_url($resolved_link) . '" target="_blank" rel="noopener noreferrer">' . esc_html($link) . '</a>';
        }
        return;
    }

    if ($column === 'hero_enabled') {
        $enabled = get_post_meta($post_id, 'carousel_enabled', true);
        $is_enabled = ($enabled === '') ? true : (bool) $enabled;
        echo $is_enabled ? 'Enabled' : 'Disabled';
    }
}

add_filter('manage_edit_hero_carousel_sortable_columns', 'hcm_admin_sortable_columns');
function hcm_admin_sortable_columns($columns) {
    $columns['title'] = 'carousel_title';
    $columns['hero_order'] = 'menu_order';
    return $columns;
}

add_action('pre_get_posts', 'hcm_admin_apply_sorting');
function hcm_admin_apply_sorting($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    if ($query->get('post_type') !== 'hero_carousel') {
        return;
    }

    if ($query->get('orderby') === 'carousel_title') {
        $query->set('meta_key', 'carousel_title');
        $query->set('orderby', 'meta_value');
        return;
    }

    if ($query->get('orderby') === 'menu_order') {
        $query->set('orderby', array(
            'menu_order' => 'ASC',
            'date' => 'DESC',
        ));
    }
}
