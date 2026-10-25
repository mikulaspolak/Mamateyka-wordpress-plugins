<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * WP Admin menu: settings, librarian accounts, import/export. Administrator-only (manage_options).
 */
class KM_Admin
{

    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_km_save_settings', array(__CLASS__, 'handle_save_settings'));
        add_action('admin_post_km_create_librarian', array(__CLASS__, 'handle_create_librarian'));
        add_action('admin_post_km_remove_librarian', array(__CLASS__, 'handle_remove_librarian'));
        add_action('admin_post_km_toggle_librarian', array(__CLASS__, 'handle_toggle_librarian'));
        add_action('admin_post_km_import', array(__CLASS__, 'handle_import'));
        add_action('admin_post_km_export', array(__CLASS__, 'handle_export'));
        add_action('admin_post_km_delete_database', array(__CLASS__, 'handle_delete_database'));
    }

    public static function menu()
    {
        add_menu_page(
            __('Knižnica', 'knizica-mamateyka'),
            __('Knižnica', 'knizica-mamateyka'),
            'manage_options',
            'knizica-mamateyka',
            array(__CLASS__, 'render_settings'),
            'dashicons-book'
        );
        add_submenu_page('knizica-mamateyka', __('Nastavenia', 'knizica-mamateyka'), __('Nastavenia', 'knizica-mamateyka'), 'manage_options', 'knizica-mamateyka', array(__CLASS__, 'render_settings'));
        add_submenu_page('knizica-mamateyka', __('Knihovníčky', 'knizica-mamateyka'), __('Knihovníčky', 'knizica-mamateyka'), 'manage_options', 'knizica-mamateyka-librarians', array(__CLASS__, 'render_librarians'));
        add_submenu_page('knizica-mamateyka', __('Import / Export', 'knizica-mamateyka'), __('Import / Export', 'knizica-mamateyka'), 'manage_options', 'knizica-mamateyka-import-export', array(__CLASS__, 'render_import_export'));
    }

    public static function render_settings()
    {
        require KM_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    public static function render_librarians()
    {
        require KM_PLUGIN_DIR . 'admin/views/librarians-page.php';
    }

    public static function render_import_export()
    {
        require KM_PLUGIN_DIR . 'admin/views/import-export-page.php';
    }

    public static function handle_save_settings()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Nemáte oprávnenie.', 'knizica-mamateyka'));
        }
        check_admin_referer('km_save_settings');

        KM_Settings::update(array(
            'notification_email'         => self::sanitize_email_list($_POST['notification_email'] ?? ''),
            'max_reservations_per_email'  => max(1, (int) ($_POST['max_reservations_per_email'] ?? 10)),
        ));

        wp_safe_redirect(add_query_arg('updated', '1', wp_get_referer()));
        exit;
    }

    /**
     * Splits a ";"-separated list of emails, keeping only valid addresses.
     */
    private static function sanitize_email_list($raw)
    {
        $emails = array_map('trim', explode(';', wp_unslash((string) $raw)));
        $emails = array_filter($emails, 'is_email');
        $emails = array_map('sanitize_email', $emails);
        return implode(';', $emails);
    }

    public static function handle_create_librarian()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Nemáte oprávnenie.', 'knizica-mamateyka'));
        }
        check_admin_referer('km_create_librarian');

        $name     = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $email    = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $username = sanitize_user($name);

        $redirect = wp_get_referer();

        if (! $name || ! $username || ! is_email($email)) {
            wp_safe_redirect(add_query_arg('km_error', rawurlencode(__('Zadajte platné meno a email.', 'knizica-mamateyka')), $redirect));
            exit;
        }
        if (username_exists($username) || email_exists($email)) {
            wp_safe_redirect(add_query_arg('km_error', rawurlencode(__('Používateľ s týmto menom alebo emailom už existuje.', 'knizica-mamateyka')), $redirect));
            exit;
        }

        $password = wp_generate_password(16, false);
        $user_id  = wp_insert_user(array(
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $password,
            'display_name' => $name,
            'role'         => KM_Roles::ROLE,
        ));

        if (is_wp_error($user_id)) {
            wp_safe_redirect(add_query_arg('km_error', rawurlencode($user_id->get_error_message()), $redirect));
            exit;
        }

        wp_new_user_notification($user_id, null, 'user');

        wp_safe_redirect(add_query_arg('km_created', '1', $redirect));
        exit;
    }

    public static function handle_remove_librarian()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Nemáte oprávnenie.', 'knizica-mamateyka'));
        }
        check_admin_referer('km_remove_librarian');

        $user_id = absint($_POST['user_id'] ?? 0);
        $user    = get_userdata($user_id);
        if ($user && in_array(KM_Roles::ROLE, $user->roles, true)) {
            $user->remove_role(KM_Roles::ROLE);
        }

        wp_safe_redirect(wp_get_referer());
        exit;
    }

    public static function handle_toggle_librarian()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Nemáte oprávnenie.', 'knizica-mamateyka'));
        }
        check_admin_referer('km_toggle_librarian');

        $user_id = absint($_POST['user_id'] ?? 0);
        $enable  = ! empty($_POST['enable']);
        $user    = get_userdata($user_id);
        $redirect = wp_get_referer();

        if (! $user) {
            wp_safe_redirect(add_query_arg('km_error', rawurlencode(__('Používateľ neexistuje.', 'knizica-mamateyka')), $redirect));
            exit;
        }

        if ($enable) {
            $user->add_role(KM_Roles::ROLE);
            delete_user_meta($user_id, '_km_librarian_access');
            $status = 'enabled';
        } else {
            $user->remove_role(KM_Roles::ROLE);
            update_user_meta($user_id, '_km_librarian_access', 'disabled');
            $status = 'disabled';
        }

        wp_safe_redirect(add_query_arg('km_librarian_status', $status, $redirect));
        exit;
    }

    public static function handle_import()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Nemáte oprávnenie.', 'knizica-mamateyka'));
        }
        check_admin_referer('km_import');

        $redirect = wp_get_referer();

        if (empty($_FILES['import_file']['tmp_name']) || UPLOAD_ERR_OK !== $_FILES['import_file']['error']) {
            wp_safe_redirect(add_query_arg('km_error', rawurlencode(__('Nahrajte, prosím, JSON súbor.', 'knizica-mamateyka')), $redirect));
            exit;
        }

        $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
        if ('json' !== $ext) {
            wp_safe_redirect(add_query_arg('km_error', rawurlencode(__('Podporovaný je iba formát JSON.', 'knizica-mamateyka')), $redirect));
            exit;
        }

        $content = file_get_contents($_FILES['import_file']['tmp_name']);
        $data    = json_decode($content, true);

        if (! is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            wp_safe_redirect(add_query_arg('km_error', rawurlencode(__('Súbor sa nepodarilo prečítať - neplatný JSON.', 'knizica-mamateyka')), $redirect));
            exit;
        }

        $counts = KM_Import_Export::import($data);

        if (is_wp_error($counts)) {
            wp_safe_redirect(add_query_arg('km_error', rawurlencode($counts->get_error_message()), $redirect));
            exit;
        }

        wp_safe_redirect(add_query_arg(array(
            'km_imported'       => '1',
            'books'             => $counts['books'],
            'categories'        => $counts['categories'],
            'legacy_categories' => $counts['legacy_categories'],
            'groups'            => $counts['groups'],
        ), $redirect));
        exit;
    }

    public static function handle_delete_database()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Nemáte oprávnenie.', 'knizica-mamateyka'));
        }
        check_admin_referer('km_delete_database');

        $redirect = wp_get_referer();
        $confirm  = isset($_POST['confirm_text']) ? trim(wp_unslash($_POST['confirm_text'])) : '';

        if ('DELETE' !== $confirm) {
            wp_safe_redirect(add_query_arg('km_error', rawurlencode(__('Potvrdenie nesedí - databáza nebola zmazaná.', 'knizica-mamateyka')), $redirect));
            exit;
        }

        $result = KM_Import_Export::delete_all_data();

        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('km_error', rawurlencode($result->get_error_message()), $redirect));
            exit;
        }

        wp_safe_redirect(add_query_arg('km_deleted', '1', remove_query_arg(array('km_error', 'km_imported'), $redirect)));
        exit;
    }

    public static function handle_export()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Nemáte oprávnenie.', 'knizica-mamateyka'));
        }
        check_admin_referer('km_export');

        $data = KM_Import_Export::export();

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="kniznica-export-' . gmdate('Y-m-d_H-i') . '.json"');
        echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
