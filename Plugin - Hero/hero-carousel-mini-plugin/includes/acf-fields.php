<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('acf/init', 'hcm_register_acf_fields');
function hcm_register_acf_fields()
{
    if (!function_exists('acf_add_local_field_group')) {
        return;
    }

    acf_add_local_field_group(array(
        'key' => 'group_hero_carousel',
        'title' => 'Hero Carousel Fields',
        'fields' => array(
            array(
                'key' => 'field_carousel_image',
                'label' => 'Image',
                'name' => 'carousel_image',
                'type' => 'image',
                'instructions' => 'Pouzi obrazok, ktorý má vhodné rozmery pre carousel.',
                'required' => 1,
                'return_format' => 'array',
                'preview_size' => 'thumbnail',
                'library' => 'all',
            ),
            array(
                'key' => 'field_carousel_title',
                'label' => 'Carousel Title',
                'name' => 'carousel_title',
                'type' => 'text',
                'instructions' => 'Volitelne. Ak nieje vyplnene, nazov sa v carouseli nezobrazi.',
                'required' => 0,
                'maxlength' => 120,
            ),
            array(
                'key' => 'field_carousel_link',
                'label' => 'Carousel Link URL',
                'name' => 'carousel_link',
                'type' => 'text',
                'instructions' => 'Volitelne. Mozes zadat celu URL alebo iba podstranku, napr. /blogy. Ak nieje vyplnene, tlacidlo "Zobrazit viac" sa nezobrazi.',
                'placeholder' => '/blogy',
                'required' => 0,
            ),
            array(
                'key' => 'field_carousel_show_button',
                'label' => 'Zobrazit button / obrazok je klikatelny',
                'name' => 'carousel_show_button',
                'type' => 'true_false',
                'instructions' => 'Ak je zapnute, zobrazi sa button. Ak je vypnute, button sa skryje a klikatelny bude cely obrazok (ak je zadany link).',
                'default_value' => 1,
                'ui' => 1,
                'ui_on_text' => 'Button',
                'ui_off_text' => 'Klikatelny obrazok',
            ),
            array(
                'key' => 'field_carousel_enabled',
                'label' => 'Enabled',
                'name' => 'carousel_enabled',
                'type' => 'true_false',
                'instructions' => 'Ak je vypnute, item sa v carouseli nezobrazi.',
                'default_value' => 1,
                'ui' => 1,
                'ui_on_text' => 'Enabled',
                'ui_off_text' => 'Disabled',
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'post_type',
                    'operator' => '==',
                    'value' => 'hero_carousel',
                ),
            ),
        ),
    ));
}

add_action('acf/save_post', 'hcm_sync_post_title_from_acf', 20);
function hcm_sync_post_title_from_acf($post_id)
{
    if (!is_numeric($post_id)) {
        return;
    }

    if (get_post_type($post_id) !== 'hero_carousel') {
        return;
    }

    $carousel_title = function_exists('get_field') ? (string) get_field('carousel_title', $post_id) : '';
    $carousel_title = trim($carousel_title);

    if ($carousel_title === '') {
        // Ak nieje title, pouzivame default alebo necham to
        return;
    }

    $current_post = get_post((int) $post_id);
    if (!$current_post instanceof WP_Post) {
        return;
    }

    if ($current_post->post_title === $carousel_title) {
        return;
    }

    wp_update_post(array(
        'ID' => (int) $post_id,
        'post_title' => $carousel_title,
        'post_name' => sanitize_title($carousel_title),
    ));
}
