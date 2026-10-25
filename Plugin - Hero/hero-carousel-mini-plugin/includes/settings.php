<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize default CSS and JS on admin_init
 */
add_action('admin_init', 'hcm_initialize_defaults');
function hcm_initialize_defaults()
{
    // Set defaults only when option does not exist.
    if (get_option('hcm_carousel_aspect_ratio', null) === null) {
        update_option('hcm_carousel_aspect_ratio', '3:4');
    }
}

/**
 * Allowed aspect ratio options for carousel.
 */
function hcm_get_allowed_aspect_ratio_options()
{
    return array('3:4', '1:1', '4:3');
}

/**
 * Normalize aspect ratio option to one of allowed values.
 */
function hcm_normalize_aspect_ratio_option($ratio)
{
    if (!is_string($ratio)) {
        return '3:4';
    }

    $ratio = trim($ratio);
    $allowed = hcm_get_allowed_aspect_ratio_options();
    if (!in_array($ratio, $allowed, true)) {
        return '3:4';
    }

    return $ratio;
}

/**
 * Convert normalized W:H ratio into CSS aspect-ratio token (W / H).
 */
function hcm_aspect_ratio_to_css($ratio)
{
    $normalized_ratio = hcm_normalize_aspect_ratio_option($ratio);
    list($width, $height) = array_map('intval', explode(':', $normalized_ratio));

    return $width . ' / ' . $height;
}

/**
 * Convert normalized W:H ratio into decimal width/height value.
 */
function hcm_aspect_ratio_to_decimal($ratio)
{
    $normalized_ratio = hcm_normalize_aspect_ratio_option($ratio);
    list($width, $height) = array_map('intval', explode(':', $normalized_ratio));

    if ($height === 0) {
        return 0.75;
    }

    return $width / $height;
}

/**
 * Register admin page for Hero Carousel settings
 */
add_action('admin_menu', 'hcm_register_settings_page');
function hcm_register_settings_page()
{
    add_submenu_page(
        'edit.php?post_type=hero_carousel',
        'Hero Carousel Settings',
        'Settings',
        'manage_options',
        'hcm_settings',
        'hcm_render_settings_page'
    );
}

/**
 * Render the settings page
 */
function hcm_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    // Handle form submission
    if (isset($_POST['hcm_carousel_nonce'])) {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hcm_carousel_nonce'])), 'hcm_carousel_action')) {
            wp_die('Nonce verification failed');
        }

        if (isset($_POST['hcm_posts_clanky_count'])) {
            $count = (int) sanitize_text_field(wp_unslash($_POST['hcm_posts_clanky_count']));
            $count = max(0, min(20, $count)); // Clamp between 0 and 20
            update_option('hcm_posts_clanky_count', $count);
        }

        if (isset($_POST['hcm_posts_galeria_count'])) {
            $count = (int) sanitize_text_field(wp_unslash($_POST['hcm_posts_galeria_count']));
            $count = max(0, min(20, $count)); // Clamp between 0 and 20
            update_option('hcm_posts_galeria_count', $count);
        }

        if (isset($_POST['hcm_carousel_aspect_ratio'])) {
            $ratio = sanitize_text_field(wp_unslash($_POST['hcm_carousel_aspect_ratio']));
            update_option('hcm_carousel_aspect_ratio', hcm_normalize_aspect_ratio_option($ratio));
        }

        if (function_exists('hcm_flush_universal_carousel_cache')) {
            hcm_flush_universal_carousel_cache();
        }

        echo '<div class="notice notice-success is-dismissible"><p>Nastavenia boli uložené.</p></div>';
    }

    $clanky_count = (int) get_option('hcm_posts_clanky_count', 5);
    $galeria_count = (int) get_option('hcm_posts_galeria_count', 3);
    $aspect_ratio = hcm_normalize_aspect_ratio_option((string) get_option('hcm_carousel_aspect_ratio', '3:4'));
    $aspect_ratio_options = hcm_get_allowed_aspect_ratio_options();

    // Preview walk goes up to the selector maximum so JS can trim live for any chosen value.
    $clanky_preview = function_exists('hcm_evaluate_category_posts') ? hcm_evaluate_category_posts('clanky', 20) : array();
    $galeria_preview = function_exists('hcm_evaluate_category_posts') ? hcm_evaluate_category_posts('galeria', 20) : array();
?>
    <div class="wrap">
        <h1>Hero Carousel — Nastavenia</h1>
        <form method="post" action="">
            <?php wp_nonce_field('hcm_carousel_action', 'hcm_carousel_nonce'); ?>

            <h2 style="margin-top: 24px;">Počet príspevkov</h2>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="hcm_posts_clanky_count">Príspevky z "Články":</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="hcm_posts_clanky_count"
                                name="hcm_posts_clanky_count"
                                value="<?php echo esc_attr($clanky_count); ?>"
                                min="0"
                                max="20"
                                step="1"
                                data-hcm-preview-target="hcm-preview-clanky" />
                            <p class="description">
                                Koľko príspevkov z kategórie <strong>Články</strong> sa má <strong>pridať</strong> do carouselu (za manuálnymi položkami). (Min: 0, Max: 20)
                            </p>
                            <?php hcm_render_preview_table('hcm-preview-clanky', $clanky_preview); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hcm_posts_galeria_count">Príspevky z "Galéria":</label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="hcm_posts_galeria_count"
                                name="hcm_posts_galeria_count"
                                value="<?php echo esc_attr($galeria_count); ?>"
                                min="0"
                                max="20"
                                step="1"
                                data-hcm-preview-target="hcm-preview-galeria" />
                            <p class="description">
                                Koľko príspevkov z kategórie <strong>Galéria</strong> sa má <strong>pridať</strong> na koniec carouselu. (Min: 0, Max: 20)
                            </p>
                            <?php hcm_render_preview_table('hcm-preview-galeria', $galeria_preview); ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h2 style="margin-top: 32px;">CSS Štýly</h2>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="hcm_carousel_aspect_ratio">Pomer strán obrázkov:</label>
                        </th>
                        <td>
                            <select
                                id="hcm_carousel_aspect_ratio"
                                name="hcm_carousel_aspect_ratio"
                                style="width: 120px;">
                                <?php foreach ($aspect_ratio_options as $ratio_option) : ?>
                                    <option value="<?php echo esc_attr($ratio_option); ?>" <?php selected($aspect_ratio, $ratio_option); ?>>
                                        <?php echo esc_html($ratio_option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Jediný globálny pomer pre všetky obrázky v carouseli. Dostupné voľby: <strong>3:4</strong>, <strong>1:1</strong>, <strong>4:3</strong>.
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php submit_button('Uložiť všetky nastavenia'); ?>
        </form>
    </div>

    <style>
        .hcm-preview {
            margin-top: 10px;
            max-width: 520px;
        }

        .hcm-preview-table {
            border-collapse: collapse;
            width: 100%;
        }

        .hcm-preview-table th,
        .hcm-preview-table td {
            border: 1px solid #dcdcde;
            padding: 6px 10px;
            text-align: left;
            font-size: 13px;
            vertical-align: top;
        }

        .hcm-preview-table th {
            background: #f6f7f7;
            font-weight: 600;
        }

        .hcm-preview-table .hcm-status-show {
            color: #1a7f37;
            font-weight: 600;
        }

        .hcm-preview-table tr:not([data-status="show"]) td {
            color: #757575;
        }

        .hcm-preview-table .hcm-status-reason {
            color: #b32d2e;
        }

        .hcm-preview-empty {
            margin: 8px 0 0;
            font-style: italic;
            color: #757575;
        }
    </style>

    <script>
        (function() {
            function clamp(value, min, max) {
                if (isNaN(value)) {
                    return min;
                }
                return Math.max(min, Math.min(max, value));
            }

            document.querySelectorAll('input[data-hcm-preview-target]').forEach(function(input) {
                var wrapper = document.getElementById(input.getAttribute('data-hcm-preview-target'));
                if (!wrapper) {
                    return;
                }

                var rows = Array.prototype.slice.call(wrapper.querySelectorAll('tbody tr'));
                var table = wrapper.querySelector('.hcm-preview-table');
                var empty = wrapper.querySelector('.hcm-preview-empty');

                function update() {
                    var limit = clamp(parseInt(input.value, 10), 0, 20);
                    var validCount = 0;
                    var shownCount = 0;

                    rows.forEach(function(row) {
                        if (validCount >= limit) {
                            row.style.display = 'none';
                            return;
                        }

                        row.style.display = '';
                        shownCount++;

                        if (row.getAttribute('data-status') === 'show') {
                            validCount++;
                        }
                    });

                    if (table) {
                        table.style.display = shownCount === 0 ? 'none' : '';
                    }
                    if (empty) {
                        empty.style.display = shownCount === 0 ? '' : 'none';
                    }
                }

                input.addEventListener('input', update);
                update();
            });
        })();
    </script>
<?php
}

/**
 * Render a live preview table of the posts that would appear in the carousel.
 */
function hcm_render_preview_table($wrapper_id, $rows)
{
    $status_labels = array(
        'show' => 'Zobrazí sa',
        'no_image' => 'Nezobrazí sa – chýba náhľadový obrázok',
        'broken_image' => 'Nezobrazí sa – náhľadový obrázok je nedostupný',
    );
?>
    <div class="hcm-preview" id="<?php echo esc_attr($wrapper_id); ?>">
        <table class="hcm-preview-table">
            <thead>
                <tr>
                    <th>Názov</th>
                    <th>Stav / Dôvod</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) :
                    $status = isset($status_labels[$row['status']]) ? $row['status'] : 'no_image';
                    $title = $row['title'] !== '' ? $row['title'] : '(bez názvu)';
                    $status_class = $status === 'show' ? 'hcm-status-show' : 'hcm-status-reason';
                ?>
                    <tr data-status="<?php echo esc_attr($status); ?>">
                        <td><?php echo esc_html($title); ?></td>
                        <td class="<?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_labels[$status]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="hcm-preview-empty" style="display: none;">Žiadne príspevky sa nezobrazia.</p>
    </div>
<?php
}

/**
 * Get default CSS
 */
function hcm_get_default_css()
{
    $css_file = HCM_PLUGIN_PATH . 'templates/styles.css';
    if (!file_exists($css_file)) {
        return '';
    }

    $css = file_get_contents($css_file);
    if ($css === false) {
        return '';
    }

    return $css;
}

/**
 * Get default JavaScript
 */
function hcm_get_default_js()
{
    $js_file = HCM_PLUGIN_PATH . 'templates/carousel.js';
    if (!file_exists($js_file)) {
        return '';
    }

    $js = file_get_contents($js_file);
    if ($js === false) {
        return '';
    }

    return $js;
}
