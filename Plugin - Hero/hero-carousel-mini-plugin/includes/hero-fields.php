<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('add_meta_boxes_hero_carousel', 'hcm_add_hero_fields_meta_box');
function hcm_add_hero_fields_meta_box()
{
    add_meta_box(
        'hcm_hero_fields',
        'Hero Carousel Fields',
        'hcm_render_hero_fields_meta_box',
        'hero_carousel',
        'normal',
        'high'
    );
}

function hcm_get_hero_image_id($post_id)
{
    $image = get_post_meta($post_id, 'carousel_image', true);
    if (is_array($image)) {
        $image = $image['ID'] ?? ($image['id'] ?? 0);
    }

    $image_id = absint($image);
    if ($image_id > 0) {
        return $image_id;
    }

    return absint(get_post_thumbnail_id($post_id));
}

function hcm_render_hero_fields_meta_box($post)
{
    wp_nonce_field('hcm_save_hero_fields', '_hcm_hero_fields_nonce');

    $image_id = hcm_get_hero_image_id($post->ID);
    $title = (string) get_post_meta($post->ID, 'carousel_title', true);
    $link = (string) get_post_meta($post->ID, 'carousel_link', true);
    $enabled = get_post_meta($post->ID, 'carousel_enabled', true);
    $show_button = get_post_meta($post->ID, 'carousel_show_button', true);

    if ($enabled === '') {
        $enabled = '1';
    }
    if ($show_button === '') {
        $show_button = '1';
    }
    ?>
    <p>
        <label for="hcm_carousel_image"><strong>Obrázok</strong></label><br>
        <input type="hidden" id="hcm_carousel_image" name="carousel_image" value="<?php echo esc_attr($image_id); ?>">
        <span id="hcm_carousel_image_preview">
            <?php
            if ($image_id) {
                echo wp_get_attachment_image($image_id, 'thumbnail');
            }
            ?>
        </span><br>
        <button type="button" class="button" id="hcm_select_carousel_image">Vybrať obrázok</button>
        <button type="button" class="button" id="hcm_remove_carousel_image">Odstrániť obrázok</button>
    </p>
    <p>
        <label for="hcm_carousel_title"><strong>Nadpis v carouseli</strong></label><br>
        <input type="text" class="widefat" id="hcm_carousel_title" name="carousel_title" maxlength="120" value="<?php echo esc_attr($title); ?>">
        <span class="description">Voliteľné. Ak zostane prázdny, nadpis sa nezobrazí.</span>
    </p>
    <p>
        <label for="hcm_carousel_link"><strong>Odkaz</strong></label><br>
        <input type="text" class="widefat" id="hcm_carousel_link" name="carousel_link" value="<?php echo esc_attr($link); ?>" placeholder="/blogy">
        <span class="description">Zadajte celú URL alebo cestu, napr. /blogy.</span>
    </p>
    <p>
        <label>
            <input type="checkbox" name="carousel_show_button" value="1" <?php checked($show_button, '1'); ?>>
            Zobraziť tlačidlo (inak bude klikateľný celý obrázok)
        </label>
    </p>
    <p>
        <label>
            <input type="checkbox" name="carousel_enabled" value="1" <?php checked($enabled, '1'); ?>>
            Zobraziť túto položku v carouseli
        </label>
    </p>
    <?php
}

add_action('admin_enqueue_scripts', 'hcm_enqueue_hero_fields_assets');
function hcm_enqueue_hero_fields_assets()
{
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'hero_carousel') {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_script('jquery');
    wp_add_inline_script('jquery', <<<'JS'
jQuery(function ($) {
    var frame;
    $('#hcm_select_carousel_image').on('click', function (event) {
        event.preventDefault();
        if (frame) {
            frame.open();
            return;
        }

        frame = wp.media({
            title: 'Vybrať obrázok pre carousel',
            button: { text: 'Použiť obrázok' },
            library: { type: 'image' },
            multiple: false
        });
        frame.on('select', function () {
            var image = frame.state().get('selection').first().toJSON();
            $('#hcm_carousel_image').val(image.id);
            $('#hcm_carousel_image_preview').html(
                $('<img>', {
                    src: image.sizes && image.sizes.thumbnail ? image.sizes.thumbnail.url : image.url,
                    alt: '',
                    css: { maxWidth: '150px', height: 'auto' }
                })
            );
        });
        frame.open();
    });

    $('#hcm_remove_carousel_image').on('click', function (event) {
        event.preventDefault();
        $('#hcm_carousel_image').val('');
        $('#hcm_carousel_image_preview').empty();
    });
});
JS
    );
}

add_action('save_post_hero_carousel', 'hcm_save_hero_fields', 10, 2);
function hcm_save_hero_fields($post_id, $post)
{
    if (
        !isset($_POST['_hcm_hero_fields_nonce'])
        || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_hcm_hero_fields_nonce'])), 'hcm_save_hero_fields')
        || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        || wp_is_post_revision($post_id)
        || !current_user_can('edit_post', $post_id)
    ) {
        return;
    }

    $image_id = isset($_POST['carousel_image']) ? absint($_POST['carousel_image']) : 0;
    if ($image_id && wp_attachment_is_image($image_id)) {
        update_post_meta($post_id, 'carousel_image', $image_id);
    } else {
        delete_post_meta($post_id, 'carousel_image');
    }

    $title = isset($_POST['carousel_title'])
        ? sanitize_text_field(wp_unslash($_POST['carousel_title']))
        : '';
    update_post_meta($post_id, 'carousel_title', $title);

    $link = isset($_POST['carousel_link'])
        ? sanitize_text_field(wp_unslash($_POST['carousel_link']))
        : '';
    update_post_meta($post_id, 'carousel_link', $link);

    update_post_meta($post_id, 'carousel_show_button', isset($_POST['carousel_show_button']) ? '1' : '0');
    update_post_meta($post_id, 'carousel_enabled', isset($_POST['carousel_enabled']) ? '1' : '0');

    $title = trim($title);
    if ($title !== '' && $post instanceof WP_Post && $post->post_title !== $title) {
        remove_action('save_post_hero_carousel', 'hcm_save_hero_fields', 10);
        wp_update_post(array(
            'ID' => $post_id,
            'post_title' => $title,
            'post_name' => sanitize_title($title),
        ));
        add_action('save_post_hero_carousel', 'hcm_save_hero_fields', 10, 2);
    }
}
