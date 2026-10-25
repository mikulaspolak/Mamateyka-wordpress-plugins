<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register universal carousel shortcode
 */
add_shortcode('hero_carousel', 'hcm_render_universal_carousel_shortcode');

function hcm_render_universal_carousel_shortcode($atts)
{

    ob_start();
    $custom_css = function_exists('hcm_get_default_css') ? hcm_get_default_css() : '';
    $custom_js = function_exists('hcm_get_default_js') ? hcm_get_default_js() : '';

    $stored_ratio = (string) get_option('hcm_carousel_aspect_ratio', '3:4');
    $ratio_css = function_exists('hcm_aspect_ratio_to_css') ? hcm_aspect_ratio_to_css($stored_ratio) : '3 / 4';
    $ratio_decimal = function_exists('hcm_aspect_ratio_to_decimal') ? hcm_aspect_ratio_to_decimal($stored_ratio) : 0.75;
    $wrapper_style = sprintf(
        '--hcm-carousel-ratio: %s; --hcm-carousel-ratio-decimal: %.6F;',
        $ratio_css,
        (float) $ratio_decimal
    );
?>
    <style>
        <?php echo $custom_css; ?>
    </style>
    <style>
        .hero-carousel-wrapper .carousel-container {
            aspect-ratio: var(--hcm-carousel-ratio, 3 / 4);
        }

        .hero-carousel-wrapper .carousel-thumbnail {
            aspect-ratio: var(--hcm-carousel-ratio, 3 / 4);
        }

        @media (max-width: 768px) {
            .hero-carousel-wrapper .carousel-container {
                aspect-ratio: var(--hcm-carousel-ratio, 3 / 4);
            }
        }
    </style>

    <?php
    // Load HTML template
    $template_file = HCM_PLUGIN_PATH . 'templates/carousel-template.html';
    if (file_exists($template_file)) {
        echo '<div class="hero-carousel-host" style="' . esc_attr($wrapper_style) . '">';
        $carousel_items = function_exists('hcm_get_universal_carousel_items')
            ? hcm_get_universal_carousel_items()
            : array();
        $carousel_json = wp_json_encode(
            $carousel_items,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        echo '<script type="application/json" class="hcm-carousel-data">'
            . ($carousel_json ?: 'null')
            . '</script>';
        include $template_file;
        echo '</div>';
    }
    ?>

    <script>
        <?php echo $custom_js; ?>
    </script>
<?php
    return ob_get_clean();
}
