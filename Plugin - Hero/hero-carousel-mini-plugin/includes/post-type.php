<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'hcm_register_post_type');
function hcm_register_post_type()
{
    $labels = array(
        'name' => 'Hero Carousel',
        'singular_name' => 'Hero Item',
        'menu_name' => 'Hero Carousel',
        'add_new_item' => 'Add New Hero Item',
        'edit_item' => 'Edit Hero Item',
    );

    $args = array(
        'labels' => $labels,
        'public' => true,
        'publicly_queryable' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_nav_menus' => false,
        'show_in_rest' => true,
        'rest_base' => 'hero_carousel',
        'capability_type' => 'post',
        'hierarchical' => false,
        'supports' => array('title', 'thumbnail', 'page-attributes'),
        'menu_icon' => 'dashicons-images-alt',
        'has_archive' => false,
    );

    register_post_type('hero_carousel', $args);
}
