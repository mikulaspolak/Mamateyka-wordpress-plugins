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

        // The checkbox list is always rendered, so a missing field means nothing is selected.
        $submitted_category_ids = isset($_POST['hcm_posts_category_ids']) ? (array) wp_unslash($_POST['hcm_posts_category_ids']) : array();
        $submitted_category_ids = array_values(array_unique(array_filter(array_map('absint', $submitted_category_ids))));
        $valid_category_ids = get_terms(array('taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids'));
        $valid_category_ids = is_wp_error($valid_category_ids) ? array() : array_map('intval', $valid_category_ids);
        update_option('hcm_posts_category_ids', array_values(array_intersect($submitted_category_ids, $valid_category_ids)), false);

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
    $selected_category_ids = hcm_get_selected_category_ids();
    $aspect_ratio = hcm_normalize_aspect_ratio_option((string) get_option('hcm_carousel_aspect_ratio', '3:4'));
    $aspect_ratio_options = hcm_get_allowed_aspect_ratio_options();

    $categories = get_categories(array('taxonomy' => 'category', 'hide_empty' => false, 'orderby' => 'name'));
    $category_tree = hcm_build_category_tree($categories);

    // Preview walk goes up to the selector maximum so JS can trim live for any chosen value.
    $preview_rows = hcm_collect_preview_rows($categories);
?>
    <div class="wrap">
        <h1>Hero Carousel — Nastavenia</h1>
        <form method="post" action="">
            <?php wp_nonce_field('hcm_carousel_action', 'hcm_carousel_nonce'); ?>

            <h2 style="margin-top: 24px;">Príspevky v carouseli</h2>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="hcm_posts_clanky_count">Počet príspevkov:</label>
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
                                data-hcm-preview-target="hcm-preview-posts" />
                            <p class="description">
                                Koľko príspevkov z vybraných kategórií sa má <strong>pridať</strong> do carouselu (za manuálnymi položkami). (Min: 0, Max: 20)
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Kategórie:</th>
                        <td>
                            <fieldset class="hcm-category-list" id="hcm-category-list">
                                <?php hcm_render_category_checkboxes($category_tree, 0, $selected_category_ids); ?>
                            </fieldset>
                            <p class="description">
                                Príspevky sa vyberajú z <strong>zaškrtnutých kategórií</strong> (podkategórie sa nezahŕňajú automaticky). Príspevok vo viacerých kategóriách sa zobrazí raz. Ak nie je zaškrtnutá žiadna, nepridá sa žiadny príspevok.
                            </p>
                            <?php hcm_render_preview_table('hcm-preview-posts', $preview_rows); ?>
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
        .hcm-category-list {
            max-height: 260px;
            max-width: 520px;
            overflow-y: auto;
            padding: 8px 12px;
            border: 1px solid #dcdcde;
            background: #fff;
        }

        .hcm-category-list label {
            display: block;
            margin: 3px 0;
        }

        .hcm-category-count {
            color: #757575;
        }

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

                var rows = Array.prototype.slice.call(wrapper.querySelectorAll('.hcm-preview-table > tbody > tr'));
                var table = wrapper.querySelector('.hcm-preview-table');
                var empty = wrapper.querySelector('.hcm-preview-empty');
                var checkboxes = Array.prototype.slice.call(document.querySelectorAll('input[name="hcm_posts_category_ids[]"]'));

                function update() {
                    var limit = clamp(parseInt(input.value, 10), 0, 20);
                    var checked = {};
                    checkboxes.forEach(function(box) {
                        if (box.checked) {
                            checked[box.value] = true;
                        }
                    });

                    var validCount = 0;
                    var shownCount = 0;

                    rows.forEach(function(row) {
                        var inSelection = row.getAttribute('data-terms').split(',').some(function(id) {
                            return checked[id];
                        });

                        if (!inSelection || validCount >= limit) {
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
                checkboxes.forEach(function(box) {
                    box.addEventListener('change', update);
                });
                update();
            });
        })();
    </script>
<?php
}

/**
 * Arrange categories into a parent => children map.
 */
function hcm_build_category_tree($categories)
{
    $ids = array();
    foreach ($categories as $category) {
        $ids[(int) $category->term_id] = true;
    }

    $tree = array();
    foreach ($categories as $category) {
        $parent = (int) $category->parent;
        // Treat categories with a missing parent as top-level.
        if (!isset($ids[$parent])) {
            $parent = 0;
        }
        $tree[$parent][] = $category;
    }

    return $tree;
}

/**
 * Render nested category checkboxes with post counts.
 */
function hcm_render_category_checkboxes($tree, $parent, $selected_ids, $depth = 0)
{
    if (empty($tree[$parent]) || $depth > 10) {
        return;
    }

    foreach ($tree[$parent] as $category) {
        $term_id = (int) $category->term_id;
        ?>
        <label style="margin-left: <?php echo (int) ($depth * 18); ?>px;">
            <input
                type="checkbox"
                name="hcm_posts_category_ids[]"
                value="<?php echo esc_attr($term_id); ?>"
                <?php checked(in_array($term_id, $selected_ids, true)); ?> />
            <?php echo esc_html($category->name); ?>
            <span class="hcm-category-count">(<?php echo (int) $category->count; ?>)</span>
        </label>
        <?php
        hcm_render_category_checkboxes($tree, $term_id, $selected_ids, $depth + 1);
    }
}

/**
 * Merge per-category preview walks into one list (newest first, each post once).
 * Each category contributes its first 20 displayable posts, which is enough for any
 * selection and count up to the maximum.
 */
function hcm_collect_preview_rows($categories)
{
    if (!function_exists('hcm_evaluate_category_posts')) {
        return array();
    }

    $rows = array();
    foreach ($categories as $category) {
        if ((int) $category->count <= 0) {
            continue;
        }

        foreach (hcm_evaluate_category_posts(array((int) $category->term_id), 20) as $row) {
            $rows[$row['post_id']] = $row;
        }
    }

    uasort($rows, static function ($a, $b) {
        $by_date = strcmp($b['date'], $a['date']);
        return $by_date !== 0 ? $by_date : $b['post_id'] <=> $a['post_id'];
    });

    return array_values($rows);
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
                    <tr data-status="<?php echo esc_attr($status); ?>" data-terms="<?php echo esc_attr(implode(',', array_map('intval', $row['term_ids']))); ?>">
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
