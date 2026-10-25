<?php

/**
 * Admin stránka nastavení – Farské oznamy.
 */

if (! defined('ABSPATH')) {
    exit;
}

if (function_exists('oznamy_register_admin_menu')) {
    return;
}

// -------------------------------------------------------------------------
// Registration hooks (called from farske-oznamy.php)
// -------------------------------------------------------------------------

function oznamy_register_admin_menu()
{
    add_menu_page(
        __('Farské oznamy', 'oznamy-gdocs'),
        __('Farské oznamy', 'oznamy-gdocs'),
        'manage_options',
        'oznamy-gdocs',
        'oznamy_settings_page',
        'dashicons-megaphone',
        26
    );
}

function oznamy_register_settings()
{
    register_setting(
        'oznamy_settings_group',
        OZNAMY_OPTION_KEY,
        array(
            'sanitize_callback' => 'oznamy_sanitize_settings',
            'default'           => array(),
        )
    );
}

// -------------------------------------------------------------------------
// Action handlers
// -------------------------------------------------------------------------

function oznamy_handle_clear_cache()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized', 'oznamy-gdocs'), 403);
    }
    check_admin_referer('oznamy_clear_cache');

    global $wpdb;
    // Remove all Oznamy transients (cached doc HTML + Google token) and stale fallbacks.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            '_transient_oznamy_%',
            '_transient_timeout_oznamy_%',
            'oznamy_stale_%'
        )
    );

    // Also clear locally cached images from WP uploads.
    if (class_exists('Oznamy_Shortcode')) {
        Oznamy_Shortcode::clear_image_cache();
    }

    wp_safe_redirect(
        add_query_arg(
            array('page' => 'oznamy-gdocs', 'msg' => 'cache_cleared'),
            admin_url('admin.php')
        )
    );
    exit;
}

function oznamy_handle_refresh()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized', 'oznamy-gdocs'), 403);
    }
    check_admin_referer('oznamy_refresh');

    $sync = Oznamy_Shortcode::refresh_from_drive();
    $doc_name = (string) ($sync['doc_name'] ?? '');
    $cleared_pages = implode(', ', array_map('sanitize_text_field', $sync['supercache_pages'] ?? array()));

    if (! empty($sync['error'])) {
        $result = 'refresh_error';
    } else {
        $has_content = ! empty($sync['latest_doc'])
            || ! empty($sync['future_doc'])
            || ! empty($sync['latest_images'])
            || ! empty($sync['future_images'])
            || ! empty($sync['mass_intentions']);

        $result = $has_content ? 'refreshed' : 'refreshed_empty';
    }

    wp_safe_redirect(
        add_query_arg(
            array(
                'page'          => 'oznamy-gdocs',
                'msg'           => $result,
                'doc'           => rawurlencode($doc_name),
                'intentions'    => ! empty($sync['mass_intentions']) ? 'ok' : 'error',
                'cleared'       => absint($sync['supercache_cleared'] ?? 0),
                'cleared_pages' => rawurlencode($cleared_pages),
            ),
            admin_url('admin.php')
        )
    );
    exit;
}

function oznamy_handle_test_connection()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized', 'oznamy-gdocs'), 403);
    }
    check_admin_referer('oznamy_test_connection');

    $settings  = get_option(OZNAMY_OPTION_KEY, array());
    $folder_id = sanitize_text_field($settings['folder_id'] ?? '');
    $sa_json   = $settings['sa_json'] ?? '';
    $result    = 'error';
    $diagnostic = array();

    if ($folder_id && $sa_json) {
        $sa_data = json_decode($sa_json, true);
        if (is_array($sa_data)) {
            $client = new Oznamy_GDrive_Client($sa_data);

            // 1. Folder metadata (Drive API reachability check).
            $folder_meta = $client->get_folder_metadata($folder_id);
            $debug_errors = array();
            $debug_hints  = array();

            $diagnostic['folder_id']    = $folder_id;
            $diagnostic['folder_url']   = 'https://drive.google.com/drive/folders/' . rawurlencode($folder_id);
            $diagnostic['service_account'] = $sa_data['client_email'] ?? 'unknown';
            $diagnostic['folder_meta']  = isset($folder_meta['error']) ? array() : $folder_meta;
            $diagnostic['oauth_scopes'] = array(
                'https://www.googleapis.com/auth/drive.readonly',
                'https://www.googleapis.com/auth/documents.readonly',
            );
            $diagnostic['mass_intentions'] = Oznamy_Mass_Intentions::inspect_document($client, $folder_id);

            if (isset($folder_meta['error'])) {
                $debug_errors[] = 'Folder metadata check failed: ' . $folder_meta['error'];
            }

            // 2. Drive API status.
            if (! empty($diagnostic['folder_meta'])) {
                $diagnostic['api_status']['drive'] = array(
                    'enabled' => true,
                    'status'  => 'enabled',
                    'message' => 'Drive API request succeeded.',
                );
            } else {
                $drive_error = $debug_errors[0] ?? '';
                $drive_is_disabled = oznamy_is_google_api_disabled_error($drive_error);
                $diagnostic['api_status']['drive'] = array(
                    'enabled' => $drive_is_disabled ? false : null,
                    'status'  => $drive_is_disabled ? 'disabled' : 'unknown',
                    'message' => $drive_error ?: 'Could not verify Drive API status.',
                );
            }

            // 3. List all Oznamy_YYYY.MM.DD subfolders in root folder.
            $subfolders = $client->list_subfolders_sorted($folder_id);
            $publication = Oznamy_Shortcode::get_current_publication_for_admin();
            $best = is_wp_error($publication) ? null : $publication['folder'];
            $diagnostic['folder_tree'] = $client->list_folder_tree($folder_id);

            try {
                $tz       = wp_timezone();
                $today_ts = (new DateTimeImmutable('today midnight', $tz))->getTimestamp();
            } catch (Exception $e) {
                $tz       = new DateTimeZone('UTC');
                $today_ts = strtotime('today midnight');
            }

            $diagnostic['total_subfolders'] = count($subfolders);
            $diagnostic['subfolders']       = array();

            $first_doc_id = null;

            foreach ($subfolders as $folder) {
                $dt = (new DateTimeImmutable('@' . $folder['date']))->setTimezone($tz);
                $date_str  = $dt->format('d.m.Y');
                $is_best   = ($best && $folder['id'] === $best['id']);
                $is_future = ($folder['date'] > $today_ts && ! $is_best);

                $doc_name    = '—';
                $image_count = 0;

                if ($is_best) {
                    $doc_id = $client->find_doc_in_subfolder($folder['id']);
                    if ($doc_id) {
                        $doc_name     = 'Oznamy (ID: ' . $doc_id . ')';
                        $first_doc_id = $doc_id;
                    } else {
                        $doc_name = '(nenájdený)';
                    }
                    $imgs = $client->list_images_in_folder($folder['id']);
                    $image_count = count($imgs);
                }

                $diagnostic['subfolders'][] = array(
                    'name'        => $folder['name'],
                    'date_str'    => $date_str,
                    'is_best'     => $is_best,
                    'is_future'   => $is_future,
                    'doc_name'    => $doc_name,
                    'image_count' => $image_count,
                );
            }

            if (0 === count($subfolders)) {
                $debug_hints[] = 'V koreňovom priečinku sa nenašli podpriečinky s názvom Oznamy_YYYY.MM.DD. Skontrolujte formát názvu (napr. Oznamy_2026.07.19) a oprávnenia Service Account-u.';
            } elseif (! $best) {
                $debug_hints[] = 'Nenašiel sa použiteľný aktuálny nedeľný oznam. Skontrolujte, či dokument Google Docs existuje a obsahuje text.';
            }

            // 4. Probe Docs API using first doc from the selected subfolder.
            if ($first_doc_id) {
                $docs_structure = $client->get_doc_structure($first_doc_id);
                if (! is_wp_error($docs_structure)) {
                    $diagnostic['api_status']['docs'] = array(
                        'enabled' => true,
                        'status'  => 'enabled',
                        'message' => 'Docs API request succeeded.',
                    );
                } else {
                    $docs_error = $docs_structure->get_error_message();
                    $docs_is_disabled = oznamy_is_google_api_disabled_error($docs_error);
                    $diagnostic['api_status']['docs'] = array(
                        'enabled' => $docs_is_disabled ? false : null,
                        'status'  => $docs_is_disabled ? 'disabled' : 'unknown',
                        'message' => $docs_error,
                    );
                    $debug_errors[] = 'Docs API probe: ' . $docs_error;
                }
            } else {
                $diagnostic['api_status']['docs'] = array(
                    'enabled' => null,
                    'status'  => 'unknown',
                    'message' => 'Docs API nebol otestovaný – nebolo čo testovať.',
                );
            }
            if ('found' === ($diagnostic['mass_intentions']['status'] ?? '')) {
                $diagnostic['api_status']['docs'] = array(
                    'enabled' => true,
                    'status'  => 'enabled',
                    'message' => 'Docs API request for the Mass intentions document succeeded.',
                );
            }

            // 5. Permissions.
            $permissions = $client->get_folder_permissions($folder_id);
            $diagnostic['permissions'] = array();
            if (! empty($permissions) && ! isset($permissions['error'])) {
                foreach ($permissions as $perm) {
                    $diagnostic['permissions'][] = array(
                        'type'    => $perm['type'] ?? 'unknown',
                        'role'    => $perm['role'] ?? 'unknown',
                        'email'   => $perm['emailAddress'] ?? '',
                        'display' => $perm['displayName'] ?? '',
                    );
                }
            }

            $diagnostic['debug_errors'] = $debug_errors;
            $diagnostic['debug_hints']  = $debug_hints;
            $result = 'ok';
        }
    }

    // Store diagnostic results in transient for 5 minutes
    set_transient('oznamy_test_result', $diagnostic, 5 * MINUTE_IN_SECONDS);

    wp_safe_redirect(
        add_query_arg(
            array('page' => 'oznamy-gdocs', 'msg' => $result),
            admin_url('admin.php')
        )
    );
    exit;
}

function oznamy_render_tree_node($node)
{
    if (! is_array($node)) {
        return;
    }

    $type = sanitize_key($node['type'] ?? 'file');
    $name = sanitize_text_field($node['name'] ?? '');

    echo '<li style="margin:4px 0;">';

    if ('folder' === $type) {
        echo '<span style="font-weight:bold;">[Priečinok]</span> <code>' . esc_html($name) . '</code>';

        if (! empty($node['date'])) {
            echo ' <span style="color:#666;">(' . esc_html(wp_date('Y.m.d', (int) $node['date'])) . ')</span>';
        }

        if (! empty($node['loop'])) {
            echo ' <span style="color:#c00;">(cyklus)</span>';
        }

        if (! empty($node['children']) && is_array($node['children'])) {
            echo '<ul style="margin:6px 0 0 22px; padding-left:18px; border-left:1px dotted #d0d0d0;">';
            foreach ($node['children'] as $child) {
                oznamy_render_tree_node($child);
            }
            echo '</ul>';
        }
    } else {
        echo '<span style="font-weight:bold;">[Súbor]</span> <code>' . esc_html($name) . '</code>';

        if (! empty($node['mimeType'])) {
            echo ' <span style="color:#666;">' . esc_html($node['mimeType']) . '</span>';
        }
    }

    echo '</li>';
}

function oznamy_render_folder_tree($tree)
{
    if (! is_array($tree) || empty($tree['name'])) {
        return;
    }

    echo '<div style="background:#fff; border:1px solid #e0e0e0; border-radius:4px; padding:12px; margin-bottom:16px;">';
    echo '<strong>' . esc_html__('Strom priečinkov a súborov:', 'oznamy-gdocs') . '</strong>';
    echo '<div style="margin-top:8px; font-size:0.95em;">';
    echo '<div style="margin-bottom:6px;"><strong>' . esc_html__('Koreň:', 'oznamy-gdocs') . '</strong> <code>' . esc_html($tree['name']) . '</code></div>';

    if (! empty($tree['children']) && is_array($tree['children'])) {
        echo '<ul style="margin:0; padding-left:18px;">';
        foreach ($tree['children'] as $child) {
            oznamy_render_tree_node($child);
        }
        echo '</ul>';
    } else {
        echo '<em>' . esc_html__('Žiadne položky.', 'oznamy-gdocs') . '</em>';
    }

    echo '</div>';
    echo '</div>';
}

function oznamy_is_google_api_disabled_error($message)
{
    $text = strtolower((string) $message);
    if ('' === $text) {
        return false;
    }

    return false !== strpos($text, 'accessnotconfigured')
        || false !== strpos($text, 'has not been used in project')
        || false !== strpos($text, 'api is disabled')
        || false !== strpos($text, 'is disabled')
        || false !== strpos($text, 'enable it by visiting');
}

// -------------------------------------------------------------------------
// Sanitization
// -------------------------------------------------------------------------

function oznamy_sanitize_settings($input)
{
    $clean = array();

    // Folder ID: only alphanumeric, hyphens, underscores.
    $raw_folder = sanitize_text_field($input['folder_id'] ?? '');
    if (preg_match('/^[a-zA-Z0-9_\-]*$/', $raw_folder)) {
        $clean['folder_id'] = $raw_folder;
    } else {
        add_settings_error('oznamy_settings', 'bad_folder_id', __('ID priečinka obsahuje neplatné znaky.', 'oznamy-gdocs'));
        $clean['folder_id'] = '';
    }

    // Service Account JSON: must be valid JSON object.
    $raw_json = trim($input['sa_json'] ?? '');
    $decoded  = json_decode($raw_json, true);
    if (is_array($decoded)) {
        $clean['sa_json'] = $raw_json;
    } else {
        add_settings_error('oznamy_settings', 'bad_sa_json', __('Service Account JSON nie je platný JSON.', 'oznamy-gdocs'));
        $clean['sa_json'] = get_option(OZNAMY_OPTION_KEY)['sa_json'] ?? '';
    }

    // Cache TTL: integer clamped to 60–86400 seconds.
    $raw_ttl           = absint($input['cache_ttl'] ?? 3600);
    $clean['cache_ttl'] = max(60, min(86400, $raw_ttl));

    $release_time = sanitize_text_field($input['sunday_release_time'] ?? '16:00');
    if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $release_time)) {
        add_settings_error('oznamy_settings', 'bad_sunday_release_time', __('Čas zverejnenia musí byť platný čas.', 'oznamy-gdocs'));
        $release_time = '16:00';
    }
    $clean['sunday_release_time'] = $release_time;

    // Number of archive dates available in the date picker: 0–999.
    $raw_archive_count = filter_var($input['archive_count'] ?? 4, FILTER_VALIDATE_INT);
    if (false === $raw_archive_count) {
        $raw_archive_count = 4;
    }
    $clean['archive_count'] = max(0, min(999, $raw_archive_count));

    // Render mode: auto | docs_api | legacy_html_export.
    $render_mode = sanitize_key($input['render_mode'] ?? 'auto');
    if (! in_array($render_mode, array('auto', 'docs_api', 'legacy_html_export'), true)) {
        $render_mode = 'auto';
    }
    $clean['render_mode'] = $render_mode;

    $show_title = sanitize_key($input['show_title'] ?? 'enabled');
    if (! in_array($show_title, array('enabled', 'disabled'), true)) {
        $show_title = 'enabled';
    }
    $clean['show_title'] = $show_title;

    $show_date = sanitize_key($input['show_date'] ?? 'enabled');
    if (! in_array($show_date, array('enabled', 'disabled'), true)) {
        $show_date = 'enabled';
    }
    $clean['show_date'] = $show_date;

    return $clean;
}

// -------------------------------------------------------------------------
// Settings page HTML
// -------------------------------------------------------------------------

function oznamy_settings_page()
{
    if (! current_user_can('manage_options')) {
        return;
    }

    $settings = get_option(OZNAMY_OPTION_KEY, array());
    $archive_count = max(0, min(999, (int) ($settings['archive_count'] ?? 4)));
    $release_time = $settings['sunday_release_time'] ?? '16:00';
    if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $release_time)) {
        $release_time = '16:00';
    }
    $msg      = sanitize_key($_GET['msg'] ?? '');
    $doc_name = sanitize_text_field(urldecode($_GET['doc'] ?? ''));
    $cleared  = absint($_GET['cleared'] ?? 0);
    $cleared_pages = sanitize_text_field(urldecode($_GET['cleared_pages'] ?? ''));
    $diagnostic = get_transient('oznamy_test_result');
?>
    <div class="wrap">
        <?php if ('cache_cleared' === $msg) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Medzipamäť bola vymazaná. Pri ďalšom načítaní stránky sa stiahne nový obsah z Google Drive.', 'oznamy-gdocs'); ?></p>
            </div>
        <?php elseif ('ok' === $msg && is_array($diagnostic)) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php esc_html_e('Pripojenie úspešné!', 'oznamy-gdocs'); ?></strong><br>
                    <?php
                    $sf_count = absint($diagnostic['total_subfolders'] ?? 0);
                    printf(
                        /* translators: %d: počet podpriečinkov Oznamy_YYYY.MM.DD */
                        esc_html__('Nájdených podpriečinkov Oznamy_YYYY.MM.DD: %d', 'oznamy-gdocs'),
                        $sf_count
                    );
                    ?>
                </p>
            </div>

            <!-- Diagnostic Details -->
            <div style="background:#f0f6fc; border:1px solid #cfe6f1; border-radius:4px; padding:16px; margin:16px 0;">
                <h3 style="margin-top:0"><?php esc_html_e('Detailná diagnostika pripojenia', 'oznamy-gdocs'); ?></h3>

                <!-- Basic Info Section -->
                <div style="background:#fff; border:1px solid #e0e0e0; border-radius:4px; padding:12px; margin-bottom:16px;">
                    <p style="margin:0 0 8px 0">
                        <strong><?php esc_html_e('Priečinok na Google Drive:', 'oznamy-gdocs'); ?></strong><br>
                        <code style="background:#f5f5f5; padding:4px 8px; border-radius:2px; display:inline-block;"><?php echo esc_html($diagnostic['folder_id']); ?></code><br>
                        <a href="<?php echo esc_url($diagnostic['folder_url']); ?>" target="_blank" rel="noopener noreferrer" style="font-size:0.9em;">
                            [Otvoriť v Google Drive]
                        </a>
                    </p>
                    <p style="margin:8px 0 0 0">
                        <strong><?php esc_html_e('Service Account email:', 'oznamy-gdocs'); ?></strong><br>
                        <code style="background:#f5f5f5; padding:4px 8px; border-radius:2px; display:inline-block; font-size:0.9em;"><?php echo esc_html($diagnostic['service_account'] ?? 'unknown'); ?></code>
                    </p>
                    <?php if (! empty($diagnostic['api_status']) && is_array($diagnostic['api_status'])) : ?>
                        <?php
                        $drive_api = $diagnostic['api_status']['drive'] ?? array();
                        $docs_api  = $diagnostic['api_status']['docs'] ?? array();
                        $drive_enabled = $drive_api['enabled'] ?? null;
                        $docs_enabled = $docs_api['enabled'] ?? null;
                        ?>
                        <p style="margin:8px 0 0 0">
                            <strong><?php esc_html_e('Stav API (diagnostika):', 'oznamy-gdocs'); ?></strong><br>
                            <span style="font-size:0.92em;">
                                <?php esc_html_e('Google Drive API:', 'oznamy-gdocs'); ?>
                                <?php if (true === $drive_enabled) : ?>
                                    <span style="color:green;font-weight:bold;"><?php esc_html_e('Zapnuté', 'oznamy-gdocs'); ?></span>
                                <?php elseif (false === $drive_enabled) : ?>
                                    <span style="color:#c00;font-weight:bold;"><?php esc_html_e('Vypnuté', 'oznamy-gdocs'); ?></span>
                                <?php else : ?>
                                    <span style="color:#666;font-weight:bold;">Unknown</span>
                                <?php endif; ?>
                                &nbsp;|&nbsp;
                                <?php esc_html_e('Google Docs API:', 'oznamy-gdocs'); ?>
                                <?php if (true === $docs_enabled) : ?>
                                    <span style="color:green;font-weight:bold;"><?php esc_html_e('Zapnuté', 'oznamy-gdocs'); ?></span>
                                <?php elseif (false === $docs_enabled) : ?>
                                    <span style="color:#c00;font-weight:bold;"><?php esc_html_e('Vypnuté', 'oznamy-gdocs'); ?></span>
                                <?php else : ?>
                                    <span style="color:#666;font-weight:bold;">Unknown</span>
                                <?php endif; ?>
                            </span>
                            <?php if (! empty($drive_api['message']) || ! empty($docs_api['message'])) : ?>
                                <br>
                                <span style="font-size:0.85em;color:#555;">
                                    <?php echo esc_html__('Drive:', 'oznamy-gdocs') . ' ' . esc_html((string) ($drive_api['message'] ?? '')); ?>
                                </span>
                                <br>
                                <span style="font-size:0.85em;color:#555;">
                                    <?php echo esc_html__('Docs:', 'oznamy-gdocs') . ' ' . esc_html((string) ($docs_api['message'] ?? '')); ?>
                                </span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <?php if (! empty($diagnostic['oauth_scopes']) && is_array($diagnostic['oauth_scopes'])) : ?>
                        <p style="margin:8px 0 0 0">
                            <strong><?php esc_html_e('OAuth scopes (read-only):', 'oznamy-gdocs'); ?></strong><br>
                            <code style="background:#f5f5f5; padding:4px 8px; border-radius:2px; display:inline-block; font-size:0.85em; line-height:1.7;">
                                <?php echo esc_html(implode("\n", array_map('sanitize_text_field', $diagnostic['oauth_scopes']))); ?>
                            </code>
                        </p>
                    <?php endif; ?>
                    <?php if (! empty($diagnostic['folder_meta']) && is_array($diagnostic['folder_meta'])) : ?>
                        <p style="margin:8px 0 0 0">
                            <strong><?php esc_html_e('Meta priečinka (Drive API):', 'oznamy-gdocs'); ?></strong><br>
                            <?php
                            $meta_name = sanitize_text_field($diagnostic['folder_meta']['name'] ?? '');
                            $meta_mime = sanitize_text_field($diagnostic['folder_meta']['mimeType'] ?? '');
                            $meta_drive = sanitize_text_field($diagnostic['folder_meta']['driveId'] ?? '');
                            $can_list = (bool) ($diagnostic['folder_meta']['capabilities']['canListChildren'] ?? false);
                            ?>
                            <span style="font-size:0.92em;">
                                <?php esc_html_e('Názov:', 'oznamy-gdocs'); ?> <code><?php echo esc_html($meta_name ?: '(unknown)'); ?></code>
                                &nbsp;|&nbsp;
                                <?php esc_html_e('Typ:', 'oznamy-gdocs'); ?> <code><?php echo esc_html($meta_mime ?: '(unknown)'); ?></code>
                                <?php if (! empty($meta_drive)) : ?>
                                    &nbsp;|&nbsp;
                                    <?php esc_html_e('Shared Drive ID:', 'oznamy-gdocs'); ?> <code><?php echo esc_html($meta_drive); ?></code>
                                <?php endif; ?>
                                &nbsp;|&nbsp;
                                <?php esc_html_e('canListChildren:', 'oznamy-gdocs'); ?>
                                <?php if ($can_list) : ?>
                                    <span style="color:green;font-weight:bold;">true</span>
                                <?php else : ?>
                                    <span style="color:#c00;font-weight:bold;">false</span>
                                <?php endif; ?>
                            </span>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Permissions Section -->
                <?php if (! empty($diagnostic['permissions'])) : ?>
                    <div style="background:#fff; border:1px solid #e0e0e0; border-radius:4px; padding:12px; margin-bottom:16px;">
                        <strong><?php esc_html_e('Prístupové práva (Permissions):', 'oznamy-gdocs'); ?></strong>
                        <table class="widefat" style="margin-top:8px; font-size:0.95em;">
                            <thead>
                                <tr style="background:#f5f5f5;">
                                    <th style="padding:8px;"><?php esc_html_e('Typ', 'oznamy-gdocs'); ?></th>
                                    <th style="padding:8px;"><?php esc_html_e('Rola', 'oznamy-gdocs'); ?></th>
                                    <th style="padding:8px;"><?php esc_html_e('Email / Účet', 'oznamy-gdocs'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($diagnostic['permissions'] as $perm) : ?>
                                    <tr>
                                        <td style="padding:8px;"><code><?php echo esc_html($perm['type']); ?></code></td>
                                        <td style="padding:8px;"><strong><?php echo esc_html($perm['role']); ?></strong></td>
                                        <td style="padding:8px;">
                                            <?php
                                            if (! empty($perm['email'])) {
                                                echo '<code style="background:#f5f5f5; padding:2px 4px; border-radius:2px;">' . esc_html($perm['email']) . '</code>';
                                            }
                                            if (! empty($perm['display'])) {
                                                echo ' <em>' . esc_html($perm['display']) . '</em>';
                                            }
                                            if (empty($perm['email']) && empty($perm['display'])) {
                                                echo '—';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Subfolders Table -->
                <?php if (! empty($diagnostic['subfolders'])) : ?>
                    <div style="background:#fff; border:1px solid #e0e0e0; border-radius:4px; padding:12px; margin-bottom:16px;">
                        <strong><?php esc_html_e('Podpriečinky Oznamy_YYYY.MM.DD v koreňovom priečinku:', 'oznamy-gdocs'); ?></strong>
                        <table class="widefat striped" style="margin-top:8px; font-size:0.95em;">
                            <thead>
                                <tr style="background:#f5f5f5;">
                                    <th style="padding:8px;"><?php esc_html_e('Podpriečinok', 'oznamy-gdocs'); ?></th>
                                    <th style="padding:8px;"><?php esc_html_e('Dátum', 'oznamy-gdocs'); ?></th>
                                    <th style="padding:8px;"><?php esc_html_e('Stav', 'oznamy-gdocs'); ?></th>
                                    <th style="padding:8px;"><?php esc_html_e('Google Doc (Oznamy)', 'oznamy-gdocs'); ?></th>
                                    <th style="padding:8px;"><?php esc_html_e('Obrázky', 'oznamy-gdocs'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($diagnostic['subfolders'] as $sf) : ?>
                                    <tr>
                                        <td style="padding:8px;"><code><?php echo esc_html($sf['name']); ?></code></td>
                                        <td style="padding:8px;"><?php echo esc_html($sf['date_str']); ?></td>
                                        <td style="padding:8px;">
                                            <?php
                                            if ($sf['is_best']) {
                                                echo '<span style="color:green;font-weight:bold;">&#10003; ' . esc_html__('Aktuálny (zobrazuje sa)', 'oznamy-gdocs') . '</span>';
                                            } elseif ($sf['is_future']) {
                                                echo '<span style="color:#888;">' . esc_html__('Budúci', 'oznamy-gdocs') . '</span>';
                                            } else {
                                                echo '<span style="color:#666;">' . esc_html__('Starší', 'oznamy-gdocs') . '</span>';
                                            }
                                            ?>
                                        </td>
                                        <td style="padding:8px;">
                                            <?php
                                            if ($sf['is_best']) {
                                                if ('(nenájdený)' === $sf['doc_name']) {
                                                    echo '<span style="color:#c00;">' . esc_html__('⚠ Nenájdený!', 'oznamy-gdocs') . '</span>';
                                                } else {
                                                    echo '<span style="color:green;">&#10003; ' . esc_html__('Nájdený', 'oznamy-gdocs') . '</span>';
                                                }
                                            } else {
                                                echo '<span style="color:#ccc;">—</span>';
                                            }
                                            ?>
                                        </td>
                                        <td style="padding:8px;">
                                            <?php
                                            if ($sf['is_best']) {
                                                echo absint($sf['image_count']);
                                            } else {
                                                echo '<span style="color:#ccc;">—</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if (! empty($diagnostic['mass_intentions'])) : ?>
                    <?php $intentions_diagnostic = $diagnostic['mass_intentions']; ?>
                    <div style="background:#fff; border:1px solid #e0e0e0; border-radius:4px; padding:12px; margin-bottom:16px;">
                        <strong><?php esc_html_e('Dokument úmyslov svätých omší:', 'oznamy-gdocs'); ?></strong>
                        <?php if ('found' === ($intentions_diagnostic['status'] ?? '')) : ?>
                            <?php $intentions_schedule = $intentions_diagnostic['schedule']; ?>
                            <span style="color:green;font-weight:bold;">&#10003; <?php esc_html_e('Nájdený a čitateľný', 'oznamy-gdocs'); ?></span>
                            <p style="margin:8px 0 0;">
                                <?php esc_html_e('Google Docs ID:', 'oznamy-gdocs'); ?>
                                <code><?php echo esc_html($intentions_diagnostic['doc_id']); ?></code>
                                &nbsp;|&nbsp;
                                <?php
                                printf(
                                    /* translators: %1$d: počet dátumov, %2$d: počet úmyslov */
                                    esc_html__('%1$d dátumov, %2$d úmyslov', 'oznamy-gdocs'),
                                    count($intentions_schedule['days']),
                                    absint($intentions_schedule['intention_count'])
                                );
                                ?>
                            </p>
                            <?php if (! empty($intentions_schedule['warnings'])) : ?>
                                <p style="margin:8px 0 0;color:#8a3c00;">
                                    <?php
                                    printf(
                                        /* translators: %d: počet riadkov s upozorneniami */
                                        esc_html__('Upozornenia pri parsovaní: %d.', 'oznamy-gdocs'),
                                        count($intentions_schedule['warnings'])
                                    );
                                    ?>
                                    <?php echo esc_html(implode(' ', array_slice($intentions_schedule['warnings'], 0, 5))); ?>
                                </p>
                            <?php endif; ?>
                        <?php else : ?>
                            <p style="margin:8px 0 0;<?php echo 'missing' === ($intentions_diagnostic['status'] ?? '') ? 'color:#8a3c00;' : 'color:#c00;'; ?>">
                                <?php echo esc_html($intentions_diagnostic['error'] ?? __('Stav dokumentu sa nepodarilo overiť.', 'oznamy-gdocs')); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (! empty($diagnostic['folder_tree'])) : ?>
                    <?php oznamy_render_folder_tree($diagnostic['folder_tree']); ?>
                <?php endif; ?>

                <!-- Tips and Errors -->
                <?php
                $has_no_subfolders = 0 === absint($diagnostic['total_subfolders'] ?? 0);
                if ($has_no_subfolders || ! empty($diagnostic['debug_hints'])) :
                ?>
                    <div style="background:#e8f4fd; border-left:4px solid #0c7abf; padding:12px; border-radius:2px; margin-bottom:12px;">
                        <strong><?php esc_html_e('Odporúčaná analýza:', 'oznamy-gdocs'); ?></strong><br>

                        <?php if ($has_no_subfolders) : ?>
                            <div style="margin:6px 0;">
                                <?php esc_html_e('V koreňovom priečinku sa nenašli žiadne podpriečinky Oznamy_YYYY.MM.DD. Skontrolujte: (1) správnosť ID koreňového priečinka, (2) formát názvov podpriečinkov (napr. Oznamy_2026.07.17), (3) oprávnenia Service Account-u na koreňový priečinok.', 'oznamy-gdocs'); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (! empty($diagnostic['debug_hints'])) : ?>
                            <?php foreach ($diagnostic['debug_hints'] as $hint) : ?>
                                <div style="margin:4px 0;">- <?php echo esc_html($hint); ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (! empty($diagnostic['debug_errors'])) : ?>
                    <div style="background:#ffe0e0; border-left:4px solid #ff6666; padding:12px; border-radius:2px;">
                        <strong><?php esc_html_e('CHYBY (Debug info):', 'oznamy-gdocs'); ?></strong><br>
                        <?php foreach ($diagnostic['debug_errors'] as $error) : ?>
                            <div style="font-family:monospace; font-size:0.85em; margin:4px 0; color:#c00;">
                                <?php echo esc_html($error); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ('error' === $msg) : ?>
            <div class="notice notice-error is-dismissible">
                <p><?php esc_html_e('Pripojenie zlyhalo. Skontrolujte: (1) ID priečinka, (2) či je priečinok zdieľaný s emailom Service Account-u, (3) Service Account JSON. Detailné chyby: WordPress Debug Log.', 'oznamy-gdocs'); ?></p>
            </div>
        <?php elseif ('refreshed' === $msg) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php
                    printf(
                        /* translators: %s: názov dokumentu */
                        esc_html__('Obsah bol úspešne obnovený. Zobrazuje sa: %s', 'oznamy-gdocs'),
                        '<strong>' . esc_html($doc_name) . '</strong>'
                    );
                    ?>
                </p>
                <?php if ($cleared > 0) : ?>
                    <p>
                        <?php
                        printf(
                            /* translators: %d: počet stránok */
                            esc_html(_n('Zároveň bola vymazaná medzipamäť WP Super Cache pre %d stránku so shortcode oznamov.', 'Zároveň bola vymazaná medzipamäť WP Super Cache pre %d stránok so shortcode oznamov.', $cleared, 'oznamy-gdocs')),
                            $cleared
                        );
                        ?>
                        <?php if ('' !== $cleared_pages) : ?>
                            (<?php echo esc_html($cleared_pages); ?>)
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if ('ok' === sanitize_key($_GET['intentions'] ?? '')) : ?>
                    <p><?php esc_html_e('Úmysly svätých omší boli tiež načítané a uložené do medzipamäte.', 'oznamy-gdocs'); ?></p>
                <?php else : ?>
                    <p class="notice notice-warning inline"><?php esc_html_e('Úmysly svätých omší sa nepodarilo obnoviť. Stav dokumentu skontrolujte cez „Otestovať pripojenie“.', 'oznamy-gdocs'); ?></p>
                <?php endif; ?>
            </div>
        <?php elseif ('refreshed_empty' === $msg) : ?>
            <div class="notice notice-warning is-dismissible">
                <p><?php esc_html_e('Obnova prebehla, ale na Google Drive sa nenašiel žiadny publikovateľný obsah. Staré dáta boli odstránené z cache.', 'oznamy-gdocs'); ?></p>
                <p><?php esc_html_e('Úmysly svätých omší sa nepodarilo obnoviť. Stav dokumentu skontrolujte cez „Otestovať pripojenie“.', 'oznamy-gdocs'); ?></p>
            </div>
        <?php elseif ('refresh_error' === $msg) : ?>
            <div class="notice notice-error is-dismissible">
                <p><?php esc_html_e('Obnova zlyhala. Skontrolujte: (1) či priečinok obsahuje aspoň jeden Google Doc, (2) oprávnenia Service Account-u, (3) WordPress Debug Log.', 'oznamy-gdocs'); ?></p>
            </div>
        <?php endif; ?>

        <?php settings_errors('oznamy_settings'); ?>

        <form method="post" action="options.php">
            <?php settings_fields('oznamy_settings_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="oznamy_folder_id"><?php esc_html_e('ID koreňového priečinka Google Drive', 'oznamy-gdocs'); ?></label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="oznamy_folder_id"
                            name="<?php echo esc_attr(OZNAMY_OPTION_KEY); ?>[folder_id]"
                            value="<?php echo esc_attr($settings['folder_id'] ?? ''); ?>"
                            class="regular-text"
                            placeholder="1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74tGDi8" />
                        <p class="description">
                            <?php esc_html_e('Názov každého priameho podpriečinka verzie musí byť v tvare Oznamy_RRRR.MM.DD s platným nedeľným dátumom (napr. Oznamy_2026.07.19). Ostatné priečinky sa zobrazia v administračnom shortcodе ako chyby. Plugin automaticky vyberie najnovší použiteľný nedeľný oznam. Nedeľný oznam sa môže zobraziť už v sobotu od nastaveného času, ak Google Doc „Oznamy“ obsahuje neprázdny publikovateľný obsah. Obrázky sú voliteľné.', 'oznamy-gdocs'); ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e('ID nájdete v URL Google Drive priečinka (napr. drive.google.com/drive/folders/1BxiMVs0XRA5nF...). DÔLEŽITÉ: Koreňový priečinok MUSÍ byť zdieľaný s emailom Service Account-u (pole „client_email" z JSON, rola „Viewer" alebo vyššia).', 'oznamy-gdocs'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="oznamy_sa_json"><?php esc_html_e('Service Account JSON kľúč', 'oznamy-gdocs'); ?></label>
                    </th>
                    <td>
                        <textarea
                            id="oznamy_sa_json"
                            name="<?php echo esc_attr(OZNAMY_OPTION_KEY); ?>[sa_json]"
                            rows="10"
                            class="large-text code"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder='{"type":"service_account","project_id":"my-project","private_key":"PASTE_PRIVATE_KEY_HERE","client_email":"my-sa@my-project.iam.gserviceaccount.com"}'><?php echo esc_textarea($settings['sa_json'] ?? ''); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('Ako získať JSON kľúč: (1) Google Cloud Console → IAM & Admin → Service Accounts → vyberte účet → Keys → Add Key (Create new key → JSON). (2) Skopírujte obsah stiahnutého JSON súboru sem. (3) V tom istom projekte musia byť zapnuté API: Google Drive API a Google Docs API. ⚠️ Nikdy nezdieľajte tento JSON verejne – obsahuje privátny kľúč!', 'oznamy-gdocs'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="oznamy_cache_ttl"><?php esc_html_e('Trvanie medzipamäte (sekundy)', 'oznamy-gdocs'); ?></label>
                    </th>
                    <td>
                        <input
                            type="number"
                            id="oznamy_cache_ttl"
                            name="<?php echo esc_attr(OZNAMY_OPTION_KEY); ?>[cache_ttl]"
                            value="<?php echo esc_attr($settings['cache_ttl'] ?? 3600); ?>"
                            min="60"
                            max="86400"
                            class="small-text" />
                        <p class="description">
                            <?php esc_html_e('Ako dlho sa ukladá stiahnutý dokument pred ďalším overením na Drive. Rozsah: 60–86400 s. Predvolené: 3600 (1 hodina). Na okamžité obnovenie použite tlačidlo „Vymazať medzipamäť" nižšie.', 'oznamy-gdocs'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="oznamy_sunday_release_time"><?php esc_html_e('Čas zverejnenia nedeľných oznamov', 'oznamy-gdocs'); ?></label>
                    </th>
                    <td>
                        <input
                            type="time"
                            id="oznamy_sunday_release_time"
                            name="<?php echo esc_attr(OZNAMY_OPTION_KEY); ?>[sunday_release_time]"
                            value="<?php echo esc_attr($release_time); ?>"
                            step="60" />
                        <p class="description">
                            <?php esc_html_e('Určuje, odkedy sa v sobotu môžu zobraziť pripravené oznamy na nasledujúcu nedeľu. Používa sa časové pásmo WordPressu. Predvolené: sobota 16:00.', 'oznamy-gdocs'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="oznamy_archive_count"><?php esc_html_e('Počet historických oznamov', 'oznamy-gdocs'); ?></label>
                    </th>
                    <td>
                        <input
                            type="number"
                            id="oznamy_archive_count"
                            name="<?php echo esc_attr(OZNAMY_OPTION_KEY); ?>[archive_count]"
                            value="<?php echo esc_attr($archive_count); ?>"
                            min="0"
                            max="999"
                            step="1"
                            class="small-text" />
                        <p class="description">
                            <?php esc_html_e('Počet najnovších dátumov v rozbaľovacom výbere vrátane najnovšieho. Pri hodnote 0 zostane zobrazené iba neaktívne tlačidlo s dátumom. Rozsah: 0–999. Predvolené: 4.', 'oznamy-gdocs'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Zobrazenie dokumentu', 'oznamy-gdocs'); ?>
                    </th>
                    <td>
                        <?php $show_title = sanitize_key($settings['show_title'] ?? 'enabled'); ?>
                        <?php if (! in_array($show_title, array('enabled', 'disabled'), true)) : ?>
                            <?php $show_title = 'enabled'; ?>
                        <?php endif; ?>
                        <?php $show_date = sanitize_key($settings['show_date'] ?? 'enabled'); ?>
                        <?php if (! in_array($show_date, array('enabled', 'disabled'), true)) : ?>
                            <?php $show_date = 'enabled'; ?>
                        <?php endif; ?>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Zobrazenie nadpisu a dátumu', 'oznamy-gdocs'); ?></legend>
                            <p>
                                <label for="oznamy_show_title"><?php esc_html_e('Nadpis', 'oznamy-gdocs'); ?></label>
                                <select id="oznamy_show_title" name="<?php echo esc_attr(OZNAMY_OPTION_KEY); ?>[show_title]">
                                    <option value="enabled" <?php selected($show_title, 'enabled'); ?>><?php esc_html_e('Zapnuté', 'oznamy-gdocs'); ?></option>
                                    <option value="disabled" <?php selected($show_title, 'disabled'); ?>><?php esc_html_e('Vypnuté', 'oznamy-gdocs'); ?></option>
                                </select>
                            </p>
                            <p>
                                <label for="oznamy_show_date"><?php esc_html_e('Dátum', 'oznamy-gdocs'); ?></label>
                                <select id="oznamy_show_date" name="<?php echo esc_attr(OZNAMY_OPTION_KEY); ?>[show_date]">
                                    <option value="enabled" <?php selected($show_date, 'enabled'); ?>><?php esc_html_e('Zapnuté', 'oznamy-gdocs'); ?></option>
                                    <option value="disabled" <?php selected($show_date, 'disabled'); ?>><?php esc_html_e('Vypnuté', 'oznamy-gdocs'); ?></option>
                                </select>
                            </p>
                            <p class="description">
                                <?php esc_html_e('Nastavenia sa týkajú nadpisu a dátumu v úvodných riadkoch dokumentu Google Docs.', 'oznamy-gdocs'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="oznamy_render_mode"><?php esc_html_e('Režim renderovania', 'oznamy-gdocs'); ?></label>
                    </th>
                    <td>
                        <?php $current_mode = sanitize_key($settings['render_mode'] ?? 'auto'); ?>
                        <select id="oznamy_render_mode" name="<?php echo esc_attr(OZNAMY_OPTION_KEY); ?>[render_mode]">
                            <option value="auto" <?php selected($current_mode, 'auto'); ?>><?php esc_html_e('Auto (Docs API, fallback na legacy)', 'oznamy-gdocs'); ?></option>
                            <option value="docs_api" <?php selected($current_mode, 'docs_api'); ?>><?php esc_html_e('Docs API (vyššia vernosť formátovania)', 'oznamy-gdocs'); ?></option>
                            <option value="legacy_html_export" <?php selected($current_mode, 'legacy_html_export'); ?>><?php esc_html_e('Legacy HTML export (pôvodný režim)', 'oznamy-gdocs'); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Odporúčané je Auto. Docs API lepšie prenáša farby, odsadenia, zoznamy, tabuľky a obrázky.', 'oznamy-gdocs'); ?>
                        </p>
                        <p class="description" style="margin-top:8px; max-width:720px;">
                            <?php esc_html_e('Tipy pre Google Docs (lepší prenos do HTML): 1) Zarovnanie robte cez Formát → Zarovnanie (nie medzerami). 2) Odsadenie nastavujte cez pravítko alebo nastavenie odseku (nie tabulátormi na layout). 3) Nadpisy používajte cez štýly Nadpis 1–6. 4) Riadkovanie nastavujte cez Formát → Riadkovanie odsekov, ak má byť odlišné od bežného textu. 5) Ak potrebujete presné „stĺpce“, použite tabuľku namiesto tabulátorov.', 'oznamy-gdocs'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Uložiť nastavenia', 'oznamy-gdocs')); ?>
        </form>

        <hr>
        <h2><?php esc_html_e('Použitie shortcodu', 'oznamy-gdocs'); ?></h2>
        <p><?php esc_html_e('Vložte shortcode na ľubovoľnú stránku alebo príspevok:', 'oznamy-gdocs'); ?></p>
        <table class="widefat striped" style="max-width:680px">
            <thead>
                <tr>
                    <th><?php esc_html_e('Shortcode', 'oznamy-gdocs'); ?></th>
                    <th><?php esc_html_e('Zobrazuje', 'oznamy-gdocs'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>[farske_oznamy_date_picker]</code></td>
                    <td><?php esc_html_e('Tlačidlo s dátumom zobrazených oznamov a výberom dostupných historických oznamov.', 'oznamy-gdocs'); ?></td>
                </tr>
                <tr>
                    <td><code>[farske_oznamy_banner_history]</code></td>
                    <td><?php esc_html_e('Upozornenie s dátumom a odkazom na najnovšie oznamy, zobrazí sa iba pri historických oznamoch.', 'oznamy-gdocs'); ?></td>
                </tr>
                <tr>
                    <td><code>[farske_oznamy]</code></td>
                    <td><?php esc_html_e('Text oznamov z najnovšieho platného podpriečinka s očíslovanými sekciami v samostatných paneloch (bez parametrov).', 'oznamy-gdocs'); ?></td>
                </tr>
                <tr>
                    <td>
                        <code>[farske_oznamy_obrazky]</code><br>
                        <code>[farske_oznamy_obrazky columns="2"]</code>
                    </td>
                    <td><?php esc_html_e('Galéria obrázkov z aktuálneho podpriečinka. Parameter columns="2" vynúti dva stĺpce na všetkých šírkach obrazovky.', 'oznamy-gdocs'); ?></td>
                </tr>
                <tr>
                    <td><code>[farske_oznamy_buduce]</code></td>
                    <td><?php esc_html_e('Náhľad najbližších budúcich oznamov s očíslovanými sekciami v samostatných paneloch a odpočtom do automatického zverejnenia (bez parametrov).', 'oznamy-gdocs'); ?></td>
                </tr>
                <tr>
                    <td><code>[farske_oznamy_buduce_obrazky]</code></td>
                    <td><?php esc_html_e('Náhľad galérie obrázkov z najbližšieho budúceho podpriečinka s odpočtom do zverejnenia (bez parametrov).', 'oznamy-gdocs'); ?></td>
                </tr>
                <tr>
                    <td><code>[umysly-svatych-omsi]</code></td>
                    <td><?php esc_html_e('Zobrazí úmysly svätých omší z Google Docs „Úmysly svätých omší“ v koreňovom priečinku; umožňuje vybrať mesiac (bez parametrov).', 'oznamy-gdocs'); ?></td>
                </tr>
                <tr>
                    <td><code>[farske_oznamy_admin]</code></td>
                    <td><?php esc_html_e('Zoznam historických, aktuálnych a pripravovaných verzií s možnosťou ich skrytia, opätovného zobrazenia alebo predčasného zverejnenia. Ovládanie môžu použiť návštevníci po odomknutí stránky „Príprava farských oznamov“.', 'oznamy-gdocs'); ?></td>
                </tr>
                <tr>
                    <td><code>[nacitat-farske-oznamy]</code></td>
                    <td><?php esc_html_e('Tlačidlo na manuálne obnovenie obsahu, dostupné po odomknutí stránky „Príprava farských oznamov“ (bez parametrov).', 'oznamy-gdocs'); ?></td>
                </tr>
            </tbody>
        </table>

        <hr>
        <h2><?php esc_html_e('Nástroje', 'oznamy-gdocs'); ?></h2>
        <table style="border-collapse:separate;margin-top:8px">
            <tr style="vertical-align:top">
                <td style="padding:0 28px 0 0">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=oznamy_refresh'), 'oznamy_refresh')); ?>" class="button button-primary">
                        <?php esc_html_e('&#8635; Obnoviť obsah', 'oznamy-gdocs'); ?>
                    </a>
                    <p class="description" style="margin-top:6px;max-width:190px"><?php esc_html_e('Obnoví aktuálne farské oznamy aj dokument „Úmysly svätých omší“ a vymaže WP Super Cache pre stránky s príslušnými shortcode (ak je aktívny).', 'oznamy-gdocs'); ?></p>
                </td>
                <td style="padding:0 28px 0 0">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=oznamy_test_connection'), 'oznamy_test_connection')); ?>" class="button button-secondary">
                        <?php esc_html_e('Otestovať pripojenie', 'oznamy-gdocs'); ?>
                    </a>
                    <p class="description" style="margin-top:6px;max-width:190px"><?php esc_html_e('Overí pripojenie k Google Drive, podpriečinky Oznamy_YYYY.MM.DD a dokument „Úmysly svätých omší“ v koreňovom priečinku.', 'oznamy-gdocs'); ?></p>
                </td>
                <td style="padding:0">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=oznamy_clear_cache'), 'oznamy_clear_cache')); ?>" class="button button-secondary">
                        <?php esc_html_e('Vymazať medzipamäť', 'oznamy-gdocs'); ?>
                    </a>
                    <p class="description" style="margin-top:6px;max-width:190px"><?php esc_html_e('Vymaže uložený obsah vrátane obrázkov. Pri ďalšom načítaní stránky sa stiahne nový obsah z Google Drive.', 'oznamy-gdocs'); ?></p>
                </td>
            </tr>
        </table>

        <?php
        $history = get_option(OZNAMY_HISTORY_KEY, array());
        if (! empty($history)) :
        ?>
            <hr>
            <h2><?php esc_html_e('História synchronizácie', 'oznamy-gdocs'); ?></h2>
            <table class="widefat striped" style="max-width:620px">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Dátum', 'oznamy-gdocs'); ?></th>
                        <th><?php esc_html_e('Názov dokumentu', 'oznamy-gdocs'); ?></th>
                        <th><?php esc_html_e('Naposledy uložený', 'oznamy-gdocs'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $entry) : ?>
                        <tr>
                            <td><?php echo esc_html($entry['date_parsed']); ?></td>
                            <td><?php echo esc_html($entry['name']); ?></td>
                            <td>
                                <?php
                                echo esc_html(
                                    wp_date(
                                        get_option('date_format') . ' ' . get_option('time_format'),
                                        $entry['cached_at']
                                    )
                                );
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php
}
