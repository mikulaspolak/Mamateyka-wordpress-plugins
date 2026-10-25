<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * CRUD for the book categories (main + secondary share one list).
 * The original categories are read-only and have no write endpoints.
 * Published year has no table - it's always the rolling last 100 years, computed client-side.
 */
class KM_REST_Categories
{

    const NS = 'knizica-mamateyka/v1';

    public static function register_routes()
    {
        register_rest_route(self::NS, '/categories', array(
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'create_category'),
                'permission_callback' => array(__CLASS__, 'can_manage'),
            ),
        ));
        register_rest_route(self::NS, '/categories/(?P<id>\d+)', array(
            array(
                'methods'             => 'PUT',
                'callback'            => array(__CLASS__, 'update_category'),
                'permission_callback' => array(__CLASS__, 'can_manage'),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array(__CLASS__, 'delete_category'),
                'permission_callback' => array(__CLASS__, 'can_manage'),
            ),
        ));
    }

    public static function can_manage()
    {
        return current_user_can('km_manage_books');
    }

    public static function create_category(WP_REST_Request $request)
    {
        global $wpdb;
        $name = self::validated_name($request);
        if (is_wp_error($name)) {
            return $name;
        }

        $table    = KM_DB::table('categories_v2');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE name = %s", $name));
        if ($existing) {
            return new WP_Error('km_duplicate', __('Kategória s týmto názvom už existuje.', 'knizica-mamateyka'), array('status' => 409));
        }

        $style = self::category_style_params($request);
        if (is_wp_error($style)) {
            return $style;
        }

        $existing_code = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE code = %s", $style['code']));
        if ($existing_code) {
            return new WP_Error('km_duplicate_code', __('Kód tejto kategórie už existuje.', 'knizica-mamateyka'), array('status' => 409, 'field' => 'code'));
        }

        $data = array_merge(array('name' => $name, 'color' => '#cccccc'), $style);
        if (false === $wpdb->insert($table, $data)) {
            return new WP_Error('km_database_error', __('Kategóriu sa nepodarilo vytvoriť.', 'knizica-mamateyka'), array('status' => 500));
        }
        return rest_ensure_response(array_merge(array('id' => (int) $wpdb->insert_id), $data));
    }

    public static function update_category(WP_REST_Request $request)
    {
        global $wpdb;
        $id   = absint($request['id']);
        $name = self::validated_name($request);
        if (is_wp_error($name)) {
            return $name;
        }

        $table    = KM_DB::table('categories_v2');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $id));
        if (null === $existing) {
            return new WP_Error('km_not_found', __('Kategória nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }

        $duplicate = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE name = %s AND id != %d", $name, $id));
        if ($duplicate) {
            return new WP_Error('km_duplicate', __('Kategória s týmto názvom už existuje.', 'knizica-mamateyka'), array('status' => 409));
        }
        $style = self::category_style_params($request);
        if (is_wp_error($style)) {
            return $style;
        }

        $duplicate_code = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE code = %s AND id != %d", $style['code'], $id));
        if ($duplicate_code) {
            return new WP_Error('km_duplicate_code', __('Kód tejto kategórie už existuje.', 'knizica-mamateyka'), array('status' => 409, 'field' => 'code'));
        }

        $data = array_merge(array('name' => $name), $style);
        if (false === $wpdb->update($table, $data, array('id' => $id))) {
            return new WP_Error('km_database_error', __('Kategóriu sa nepodarilo upraviť.', 'knizica-mamateyka'), array('status' => 500));
        }
        return rest_ensure_response(array_merge(array('id' => $id), $data));
    }

    private static function validated_name(WP_REST_Request $request)
    {
        $name = trim(sanitize_text_field((string) $request->get_param('name')));
        if ('' === $name) {
            return new WP_Error('km_invalid_category', __('Zadajte názov.', 'knizica-mamateyka'), array('status' => 400));
        }
        if (mb_strlen($name) > 150) {
            return new WP_Error('km_invalid_category', __('Názov kategórie môže mať najviac 150 znakov.', 'knizica-mamateyka'), array('status' => 400));
        }
        return $name;
    }

    private static function category_style_params(WP_REST_Request $request)
    {
        $code = strtoupper(trim(sanitize_text_field((string) $request->get_param('code'))));
        if (! preg_match('/^[A-Z0-9]{2,20}$/', $code)) {
            return new WP_Error('km_invalid_code', __('Kód kategórie musí obsahovať 2 až 20 veľkých písmen alebo číslic.', 'knizica-mamateyka'), array('status' => 400, 'field' => 'code'));
        }
        $out = array('code' => $code);

        if (null !== $request->get_param('color')) {
            $color = trim((string) $request->get_param('color'));
            if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                return new WP_Error('km_invalid_color', __('Farba kategórie musí byť v tvare #RRGGBB.', 'knizica-mamateyka'), array('status' => 400, 'field' => 'color'));
            }
            $out['color'] = $color;
        }
        return $out;
    }

    public static function delete_category(WP_REST_Request $request)
    {
        global $wpdb;
        $id = absint($request['id']);

        $table  = KM_DB::table('categories_v2');
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $id));
        if (null === $exists) {
            return new WP_Error('km_not_found', __('Kategória nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }

        $in_use = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . KM_DB::table('books') . ' b WHERE b.main_category_id = %d OR b.id IN (SELECT book_id FROM ' . KM_DB::table('book_secondary_categories') . ' WHERE category_id = %d)',
            $id,
            $id
        ));
        if ($in_use > 0) {
            return new WP_Error('km_in_use', sprintf(__('Túto kategóriu nie je možné odstrániť, používa sa v %d knihách.', 'knizica-mamateyka'), $in_use), array('status' => 409, 'usage_count' => $in_use));
        }

        if (false === $wpdb->delete($table, array('id' => $id))) {
            return new WP_Error('km_database_error', __('Kategóriu sa nepodarilo odstrániť.', 'knizica-mamateyka'), array('status' => 500));
        }
        return rest_ensure_response(array('deleted' => true));
    }
}
