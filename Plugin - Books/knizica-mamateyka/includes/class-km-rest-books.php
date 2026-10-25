<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * REST endpoints for browsing and managing the book catalog.
 */
class KM_REST_Books
{

    const NS = 'knizica-mamateyka/v1';

    /** Attachment meta holding the id of the book a cover was uploaded for. */
    const COVER_META       = '_km_book_cover';
    const COVER_MAX_BYTES  = 5242880;
    const COVER_MAX_HEIGHT = 1200;
    const COVER_MIN_SIDE   = 100;

    public static function register_routes()
    {
        register_rest_route(self::NS, '/books', array(
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'list_books'),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'create_book'),
                'permission_callback' => array(__CLASS__, 'can_manage_books'),
            ),
        ));

        register_rest_route(self::NS, '/books/(?P<id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'get_book'),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => 'PUT',
                'callback'            => array(__CLASS__, 'update_book'),
                'permission_callback' => array(__CLASS__, 'can_manage_books'),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array(__CLASS__, 'delete_book'),
                'permission_callback' => array(__CLASS__, 'can_manage_books'),
            ),
        ));

        register_rest_route(self::NS, '/books/(?P<id>\d+)/permanent', array(
            'methods'             => 'DELETE',
            'callback'            => array(__CLASS__, 'permanently_delete_book'),
            'permission_callback' => array(__CLASS__, 'can_manage_books'),
        ));

        register_rest_route(self::NS, '/books/(?P<id>\d+)/history', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'get_history'),
            'permission_callback' => array(__CLASS__, 'can_manage_books'),
        ));

        register_rest_route(self::NS, '/books/(?P<id>\d+)/image-history', array(
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'get_image_history'),
                'permission_callback' => array(__CLASS__, 'can_manage_books'),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array(__CLASS__, 'delete_image_file'),
                'permission_callback' => array(__CLASS__, 'can_manage_books'),
            ),
        ));

        register_rest_route(self::NS, '/books/(?P<id>\d+)/image/restore', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'restore_image'),
            'permission_callback' => array(__CLASS__, 'can_manage_books'),
        ));

        register_rest_route(self::NS, '/books/(?P<id>\d+)/image', array(
            array(
                'methods'             => 'POST',
                'callback'            => array(__CLASS__, 'upload_image'),
                'permission_callback' => array(__CLASS__, 'can_manage_books'),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array(__CLASS__, 'remove_image'),
                'permission_callback' => array(__CLASS__, 'can_manage_books'),
            ),
        ));

        register_rest_route(self::NS, '/books/(?P<id>\d+)/restore', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'restore_book'),
            'permission_callback' => array(__CLASS__, 'can_manage_books'),
        ));

        register_rest_route(self::NS, '/archived-books', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'list_archived_books'),
            'permission_callback' => array(__CLASS__, 'can_manage_books'),
        ));

        register_rest_route(self::NS, '/meta', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'get_meta'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function can_manage_books()
    {
        return current_user_can('km_manage_books');
    }

    public static function get_meta()
    {
        global $wpdb;
        $categories      = $wpdb->get_results('SELECT id, name, code, color FROM ' . KM_DB::table('categories_v2') . ' ORDER BY name', ARRAY_A) ?: array();
        $years           = $wpdb->get_col('SELECT DISTINCT published_year FROM ' . KM_DB::table('books') . ' WHERE published_year IS NOT NULL ORDER BY published_year DESC');

        usort($categories, function ($left, $right) {
            return strnatcasecmp((string) $left['name'], (string) $right['name']);
        });

        foreach ($categories as &$category) {
            $category['id'] = (int) $category['id'];
        }
        unset($category);

        $meta = array(
            'categories'       => $categories,
            'published_years'  => array_map('intval', $years),
            'default_image'    => KM_DB::DEFAULT_BOOK_IMAGE,
            'is_editor'        => self::can_manage_books(),
        );

        // Original categories are only kept in the background and visible to editors.
        if (self::can_manage_books()) {
            $legacy = $wpdb->get_results('SELECT id, name, code, color FROM ' . KM_DB::table('categories') . ' ORDER BY name', ARRAY_A) ?: array();
            usort($legacy, function ($left, $right) {
                return strnatcasecmp((string) $left['name'], (string) $right['name']);
            });
            foreach ($legacy as &$category) {
                $category['id'] = (int) $category['id'];
            }
            unset($category);
            $meta['legacy_categories'] = $legacy;
        }

        return rest_ensure_response($meta);
    }

    /**
     * ORDER BY fragment: letters (Slovak alphabet), then digits, then other characters.
     * $column must be a trusted literal column name.
     */
    private static function alpha_order_sql($column, $direction)
    {
        $trimmed = "TRIM({$column})";
        $group   = "CASE WHEN BINARY UPPER(LEFT({$trimmed}, 1)) <> BINARY LOWER(LEFT({$trimmed}, 1)) THEN 0"
            . " WHEN {$trimmed} REGEXP '^[0-9]' THEN 1 ELSE 2 END";
        $collate = self::slovak_collation();
        $value   = $collate ? "{$trimmed} COLLATE {$collate}" : $trimmed;

        return "{$group} ASC, {$value} {$direction}, id ASC";
    }

    /** First available Slovak collation on this DB server, or '' to use the column default. */
    private static function slovak_collation()
    {
        global $wpdb;
        static $resolved = null;
        if (null !== $resolved) {
            return $resolved;
        }

        $resolved = '';
        if ('utf8mb4' === strtolower((string) $wpdb->charset)) {
            $found = get_transient('km_slovak_collation');
            if (false === $found) {
                $found      = 'none';
                $candidates = array('utf8mb4_sk_0900_ai_ci', 'utf8mb4_slovak_ci', 'utf8mb4_uca1400_slovak_ai_ci');
                $available  = (array) $wpdb->get_col("SHOW COLLATION WHERE Collation IN ('" . implode("','", $candidates) . "')");
                foreach ($candidates as $candidate) {
                    if (in_array($candidate, $available, true)) {
                        $found = $candidate;
                        break;
                    }
                }
                set_transient('km_slovak_collation', $found, DAY_IN_SECONDS);
            }
            $resolved = 'none' === $found ? '' : (string) $found;
        }

        return $resolved;
    }

    public static function list_books(WP_REST_Request $request)
    {
        global $wpdb;
        $table = KM_DB::table('books');

        $where  = array('1=1', 'deleted_at IS NULL');
        $params = array();

        $search = trim((string) $request->get_param('search'));
        if ('' !== $search) {
            $where[]  = '(title LIKE %s OR author LIKE %s OR co_authors LIKE %s OR isbn LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $category_ids = array_values(array_filter(array_map('absint', self::split_csv_param($request->get_param('category_id')))));
        if ($category_ids) {
            $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
            $where[]  = "(main_category_id IN ({$placeholders}) OR id IN (SELECT book_id FROM " . KM_DB::table('book_secondary_categories') . " WHERE category_id IN ({$placeholders})))";
            $params   = array_merge($params, $category_ids, $category_ids);
        }

        $year = $request->get_param('year');
        $years = array_values(array_filter(array_map('absint', self::split_csv_param($year))));
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '%d'));
            $where[]  = "published_year IN ({$placeholders})";
            $params   = array_merge($params, $years);
        }

        if ('true' === $request->get_param('available_only')) {
            $where[] = 'available_copies > 0';
        }

        if ('true' === $request->get_param('unchecked')) {
            if (! self::can_manage_books()) {
                return new WP_Error('km_forbidden', __('Tento filter je dostupný iba knihovníkom.', 'knizica-mamateyka'), array('status' => 403));
            }
            $where[] = 'last_checked_at IS NULL';
        } elseif ('true' === $request->get_param('checked')) {
            if (! self::can_manage_books()) {
                return new WP_Error('km_forbidden', __('Tento filter je dostupný iba knihovníkom.', 'knizica-mamateyka'), array('status' => 403));
            }
            $where[] = 'last_checked_at IS NOT NULL';
        }

        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 24));
        $page     = max(1, (int) $request->get_param('page') ?: 1);
        $offset   = ($page - 1) * $per_page;

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total     = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));

        // Whitelist prevents arbitrary column/direction injection via the sort param.
        $sort_map = array(
            'title_asc'   => self::alpha_order_sql('title', 'ASC'),
            'title_desc'  => self::alpha_order_sql('title', 'DESC'),
            'author_asc'  => self::alpha_order_sql('author', 'ASC'),
            'author_desc' => self::alpha_order_sql('author', 'DESC'),
            'year_desc'   => 'published_year DESC',
            'year_asc'    => 'published_year ASC',
            'created_desc' => 'created_at DESC, id DESC',
            'created_asc'  => 'created_at ASC, id ASC',
        );
        $sort_key  = (string) $request->get_param('sort');
        $order_sql = isset($sort_map[$sort_key]) ? $sort_map[$sort_key] : $sort_map['title_asc'];

        $data_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d";
        $data_params = array_merge($params, array($per_page, $offset));
        $books       = $wpdb->get_results($wpdb->prepare($data_sql, $data_params), ARRAY_A);

        foreach ($books as &$book) {
            self::cast_book($book);
            self::hide_private_fields($book);
        }
        unset($book);
        self::attach_book_associations($books);

        $response = rest_ensure_response(array(
            'items'    => $books,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ));
        return $response;
    }

    public static function get_book(WP_REST_Request $request)
    {
        global $wpdb;
        $id   = absint($request['id']);
        $book = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . KM_DB::table('books') . ' WHERE id = %d', $id), ARRAY_A);
        if (! $book) {
            return new WP_Error('km_not_found', __('Kniha nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }
        if (! empty($book['deleted_at']) && ! self::can_manage_books()) {
            return new WP_Error('km_not_found', __('Kniha nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }
        self::cast_book($book);
        self::hide_private_fields($book);
        $wrapped = array($book);
        self::attach_book_associations($wrapped);
        $book = $wrapped[0];

        return rest_ensure_response($book);
    }

    public static function create_book(WP_REST_Request $request)
    {
        global $wpdb;
        $fields                = self::sanitize_book_fields($request->get_json_params());
        if (is_wp_error($fields)) {
            return $fields;
        }
        $secondary_ids         = $fields['__secondary_ids'];
        unset($fields['__secondary_ids']);
        if (empty($fields['image'])) {
            $fields['image'] = KM_DB::DEFAULT_BOOK_IMAGE;
        }

        $wpdb->insert(KM_DB::table('books'), $fields);
        $id = $wpdb->insert_id;

        self::sync_book_associations($id, $secondary_ids);
        self::record_history($id, 'created', array(), self::get_book_snapshot($id));

        return rest_ensure_response(array('id' => $id));
    }

    public static function update_book(WP_REST_Request $request)
    {
        global $wpdb;
        $id                     = absint($request['id']);
        $before                 = self::get_book_snapshot($id);
        if (! $before) {
            return new WP_Error('km_not_found', __('Kniha nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }
        $fields                 = self::sanitize_book_fields($request->get_json_params());
        if (is_wp_error($fields)) {
            return $fields;
        }
        $secondary_ids          = $fields['__secondary_ids'];
        unset($fields['__secondary_ids']);
        $fields['updated_at'] = current_time('mysql');

        $updated = $wpdb->update(KM_DB::table('books'), $fields, array('id' => $id));
        if (false === $updated) {
            return new WP_Error('km_update_failed', __('Aktualizácia knihy zlyhala.', 'knizica-mamateyka'), array('status' => 500));
        }

        self::sync_book_associations($id, $secondary_ids);
        $after = self::get_book_snapshot($id);
        self::record_history($id, 'updated', self::diff_snapshots($before, $after));

        return rest_ensure_response(array('id' => $id, 'updated' => true));
    }

    public static function delete_book(WP_REST_Request $request)
    {
        global $wpdb;
        $id = absint($request['id']);
        $book = self::get_book_snapshot($id);
        if (! $book) {
            return new WP_Error('km_not_found', __('Kniha nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }
        $wpdb->update(KM_DB::table('books'), array(
            'deleted_at' => current_time('mysql'),
            'deleted_by' => get_current_user_id(),
            'updated_at' => current_time('mysql'),
        ), array('id' => $id));
        self::record_history($id, 'archived', array('archived' => array('old' => false, 'new' => true)));
        return rest_ensure_response(array('deleted' => true, 'archived' => true));
    }

    public static function restore_book(WP_REST_Request $request)
    {
        global $wpdb;
        $id = absint($request['id']);
        $book = self::get_book_snapshot($id);
        if (! $book) {
            return new WP_Error('km_not_found', __('Kniha nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }
        $wpdb->update(KM_DB::table('books'), array(
            'deleted_at' => null,
            'deleted_by' => null,
            'updated_at' => current_time('mysql'),
        ), array('id' => $id));
        self::record_history($id, 'restored', array('archived' => array('old' => true, 'new' => false)));
        return rest_ensure_response(array('id' => $id, 'restored' => true));
    }

    public static function permanently_delete_book(WP_REST_Request $request)
    {
        global $wpdb;
        $id   = absint($request['id']);
        $book = $wpdb->get_row($wpdb->prepare('SELECT id, deleted_at FROM ' . KM_DB::table('books') . ' WHERE id = %d', $id), ARRAY_A);
        if (! $book) {
            return new WP_Error('km_not_found', __('Kniha nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }
        if (empty($book['deleted_at'])) {
            return new WP_Error('km_not_archived', __('Trvalo odstrániť možno iba knihu v koši.', 'knizica-mamateyka'), array('status' => 409));
        }

        $cover_ids = get_posts(array(
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => self::COVER_META,
            'meta_value'     => $id,
        ));
        foreach ($cover_ids as $cover_id) {
            wp_delete_attachment($cover_id, true);
        }

        $wpdb->delete(KM_DB::table('reservations'), array('book_id' => $id), array('%d'));
        $wpdb->delete(KM_DB::table('book_history'), array('book_id' => $id), array('%d'));
        $wpdb->delete(KM_DB::table('book_categories'), array('book_id' => $id), array('%d'));
        $wpdb->delete(KM_DB::table('book_secondary_categories'), array('book_id' => $id), array('%d'));
        $wpdb->delete(KM_DB::table('books'), array('id' => $id), array('%d'));

        return rest_ensure_response(array('id' => $id, 'deleted' => true, 'permanent' => true));
    }

    public static function get_history(WP_REST_Request $request)
    {
        global $wpdb;
        $id = absint($request['id']);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, book_id, event_type, user_id, user_name, changes, created_at FROM ' . KM_DB::table('book_history') . ' WHERE book_id = %d ORDER BY created_at DESC, id DESC',
            $id
        ), ARRAY_A);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['book_id'] = (int) $row['book_id'];
            $row['user_id'] = null !== $row['user_id'] ? (int) $row['user_id'] : null;
            $row['changes'] = json_decode($row['changes'], true) ?: array();
        }
        return rest_ensure_response($rows);
    }

    /**
     * Lowercase ASCII slug of the title + book id + "-cover" (no extension).
     */
    public static function cover_filename_base($title, $id)
    {
        $slug = strtolower(remove_accents((string) $title));
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        $slug = trim(substr($slug, 0, 60), '-');
        if ('' === $slug) {
            $slug = 'kniha';
        }
        return $slug . '-' . absint($id) . '-cover';
    }

    public static function upload_image(WP_REST_Request $request)
    {
        try {
            return self::process_image_upload($request);
        } catch (Throwable $e) {
            error_log('[knizica-mamateyka] cover upload failed: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            return new WP_Error(
                'km_upload_exception',
                sprintf(__('Chyba servera pri nahr\u00e1van\u00ed: %s', 'knizica-mamateyka'), $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'),
                array('status' => 500)
            );
        }
    }

    private static function process_image_upload(WP_REST_Request $request)
    {
        global $wpdb;
        $id     = absint($request['id']);
        $before = self::get_book_snapshot($id);
        if (! $before) {
            return new WP_Error('km_not_found', __('Kniha nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }

        $files = $request->get_file_params();
        $file  = isset($files['image']) && is_array($files['image']) ? $files['image'] : null;
        if (! $file || ! isset($file['error'], $file['tmp_name'])) {
            return new WP_Error('km_no_file', __('Nevybrali ste žiadny súbor.', 'knizica-mamateyka'), array('status' => 400));
        }
        if (UPLOAD_ERR_INI_SIZE === $file['error'] || UPLOAD_ERR_FORM_SIZE === $file['error']) {
            return new WP_Error('km_file_too_large', __('Obrázok je príliš veľký (max. 5 MB).', 'knizica-mamateyka'), array('status' => 413));
        }
        if (UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('km_upload_failed', __('Nahrávanie obrázka zlyhalo.', 'knizica-mamateyka'), array('status' => 400));
        }
        if (filesize($file['tmp_name']) > self::COVER_MAX_BYTES) {
            return new WP_Error('km_file_too_large', __('Obrázok je príliš veľký (max. 5 MB).', 'knizica-mamateyka'), array('status' => 413));
        }

        // Content-based detection: the client-supplied name and type are not trusted.
        $info    = @getimagesize($file['tmp_name']);
        $allowed = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
        if (! $info || ! isset($allowed[$info['mime']])) {
            return new WP_Error('km_invalid_image_type', __('Povolené sú iba obrázky JPG, PNG a WebP.', 'knizica-mamateyka'), array('status' => 415));
        }
        if ($info[0] < self::COVER_MIN_SIDE || $info[1] < self::COVER_MIN_SIDE) {
            return new WP_Error('km_image_too_small', __('Obrázok je príliš malý (min. 100 × 100 px).', 'knizica-mamateyka'), array('status' => 400));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $editor = wp_get_image_editor($file['tmp_name']);
        if (is_wp_error($editor)) {
            return new WP_Error('km_image_unreadable', __('Obrázok sa nepodarilo spracovať.', 'knizica-mamateyka'), array('status' => 400));
        }
        $editor->maybe_exif_rotate();
        $size = $editor->get_size();
        if ($size['height'] > self::COVER_MAX_HEIGHT) {
            $editor->resize(null, self::COVER_MAX_HEIGHT, false);
        }
        $editor->set_quality(85);

        $out_mime = wp_image_editor_supports(array('mime_type' => 'image/webp')) ? 'image/webp' : $info['mime'];
        $out_ext  = array_search($out_mime, array('jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'), true);
        $name     = self::cover_filename_base($before['title'], $id) . '.' . $out_ext;
        $tmp_dir  = get_temp_dir();
        // Re-encoding drops EXIF/GPS metadata.
        $saved    = $editor->save(trailingslashit($tmp_dir) . wp_unique_filename($tmp_dir, $name), $out_mime);
        if (is_wp_error($saved)) {
            return new WP_Error('km_image_save_failed', __('Obrázok sa nepodarilo uložiť.', 'knizica-mamateyka'), array('status' => 500));
        }

        // wp_handle_sideload() takes its first argument by reference.
        $sideload = array(
            'name'     => $name,
            'type'     => $out_mime,
            'tmp_name' => $saved['path'],
            'error'    => 0,
            'size'     => filesize($saved['path']),
        );
        $moved = wp_handle_sideload(
            $sideload,
            array(
                'test_form' => false,
                'mimes'     => array('jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'),
            )
        );
        if (! empty($moved['error'])) {
            @unlink($saved['path']);
            return new WP_Error('km_image_save_failed', __('Obrázok sa nepodarilo uložiť.', 'knizica-mamateyka'), array('status' => 500));
        }

        $title      = (string) $before['title'];
        $attachment = wp_insert_attachment(array(
            'post_mime_type' => $moved['type'],
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ), $moved['file']);
        if (is_wp_error($attachment) || ! $attachment) {
            @unlink($moved['file']);
            return new WP_Error('km_image_save_failed', __('Obrázok sa nepodarilo uložiť.', 'knizica-mamateyka'), array('status' => 500));
        }
        update_post_meta($attachment, self::COVER_META, $id);
        update_post_meta($attachment, '_wp_attachment_image_alt', $title);
        wp_update_attachment_metadata($attachment, wp_generate_attachment_metadata($attachment, $moved['file']));

        $url = wp_make_link_relative($moved['url']);
        if (strlen($url) > 255) {
            wp_delete_attachment($attachment, true);
            return new WP_Error('km_invalid_image', __('Adresa obrázka je príliš dlhá.', 'knizica-mamateyka'), array('status' => 400));
        }

        $wpdb->update(KM_DB::table('books'), array('image' => $url, 'updated_at' => current_time('mysql')), array('id' => $id));
        self::record_history($id, 'updated', self::diff_snapshots($before, self::get_book_snapshot($id)));

        return rest_ensure_response(array('id' => $id, 'image' => $url));
    }

    /**
     * Resets the cover to the default image. The uploaded file stays in the media
     * library so history links keep working; it is deleted with the book.
     */
    public static function remove_image(WP_REST_Request $request)
    {
        global $wpdb;
        $id     = absint($request['id']);
        $before = self::get_book_snapshot($id);
        if (! $before) {
            return new WP_Error('km_not_found', __('Kniha nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }

        $wpdb->update(KM_DB::table('books'), array('image' => KM_DB::DEFAULT_BOOK_IMAGE, 'updated_at' => current_time('mysql')), array('id' => $id));
        self::record_history($id, 'updated', self::diff_snapshots($before, self::get_book_snapshot($id)));

        return rest_ensure_response(array('id' => $id, 'image' => KM_DB::DEFAULT_BOOK_IMAGE));
    }

    /**
     * Previous covers of a book taken from its history (newest first, no duplicates,
     * without the current and the default image).
     */
    private static function previous_covers($id, $current, $limit = 12)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT user_name, changes, created_at FROM ' . KM_DB::table('book_history') . ' WHERE book_id = %d ORDER BY created_at DESC, id DESC',
            absint($id)
        ), ARRAY_A) ?: array();

        $covers = array();
        $seen   = array((string) $current => true, KM_DB::DEFAULT_BOOK_IMAGE => true);
        foreach ($rows as $row) {
            $changes = json_decode((string) $row['changes'], true);
            $old     = is_array($changes) && isset($changes['image']['old']) && is_string($changes['image']['old']) ? $changes['image']['old'] : '';
            if ('' === $old || isset($seen[$old])) {
                continue;
            }
            $seen[$old] = true;
            $new        = isset($changes['image']['new']) ? $changes['image']['new'] : null;
            $covers[]   = array(
                'url'        => $old,
                'changed_at' => $row['created_at'],
                'user_name'  => $row['user_name'],
                'reason'     => KM_DB::DEFAULT_BOOK_IMAGE === $new ? 'removed' : 'replaced',
            );
            if (count($covers) >= $limit) {
                break;
            }
        }
        return $covers;
    }

    /** Filesystem path of a file in this site's uploads folder, or null for any other URL. */
    private static function local_upload_path($url)
    {
        $parts = wp_parse_url($url);
        if (! $parts || empty($parts['path'])) {
            return null;
        }
        if (! empty($parts['host']) && strtolower($parts['host']) !== strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))) {
            return null;
        }
        $uploads   = wp_upload_dir();
        $base_path = rtrim((string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH), '/') . '/';
        $path      = rawurldecode($parts['path']);
        if (0 !== strpos($path, $base_path) || false !== strpos($path, '..')) {
            return null;
        }
        return $uploads['basedir'] . '/' . substr($path, strlen($base_path));
    }

    /** True when the cover can still be shown; null when it cannot be verified server-side cheaply. */
    private static function cover_is_available($url, $verify_remote)
    {
        $local = self::local_upload_path($url);
        if (null !== $local) {
            return is_file($local);
        }
        if (! $verify_remote) {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            return false;
        }
        $args     = array('timeout' => 5, 'redirection' => 3);
        $response = wp_safe_remote_head($url, $args);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
            $response = wp_safe_remote_get($url, $args + array('limit_response_size' => 1024));
        }
        if (is_wp_error($response)) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300 && 0 === stripos((string) wp_remote_retrieve_header($response, 'content-type'), 'image/');
    }

    public static function get_image_history(WP_REST_Request $request)
    {
        global $wpdb;
        $id   = absint($request['id']);
        $book = $wpdb->get_row($wpdb->prepare('SELECT image FROM ' . KM_DB::table('books') . ' WHERE id = %d', $id), ARRAY_A);
        if (! $book) {
            return new WP_Error('km_not_found', __('Kniha nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }

        $covers = self::previous_covers($id, $book['image']);
        foreach ($covers as &$cover) {
            $cover['available'] = self::cover_is_available($cover['url'], false);
            $cover['deletable'] = self::find_deletable_cover($id, $cover['url']) > 0;
        }
        unset($cover);

        return rest_ensure_response($covers);
    }

    /**
     * Attachment id of a cover uploaded for this book, or 0 when the file must not be
     * deleted (not uploaded by the plugin for this book, or used by another book).
     */
    private static function find_deletable_cover($book_id, $url)
    {
        global $wpdb;
        if (null === self::local_upload_path($url)) {
            return 0;
        }
        $attachments = get_posts(array(
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => self::COVER_META,
            'meta_value'     => absint($book_id),
        ));
        foreach ($attachments as $attachment_id) {
            $file_url = wp_get_attachment_url($attachment_id);
            if (! $file_url || wp_make_link_relative($file_url) !== wp_make_link_relative($url)) {
                continue;
            }
            $used = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . KM_DB::table('books') . ' WHERE image = %s AND id <> %d',
                $url,
                absint($book_id)
            ));
            return $used ? 0 : (int) $attachment_id;
        }
        return 0;
    }

    public static function delete_image_file(WP_REST_Request $request)
    {
        try {
            return self::process_image_file_delete($request);
        } catch (Throwable $e) {
            error_log('[knizica-mamateyka] cover file delete failed: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            return new WP_Error('km_delete_exception', __('Súbor sa nepodarilo zmazať.', 'knizica-mamateyka'), array('status' => 500));
        }
    }

    private static function process_image_file_delete(WP_REST_Request $request)
    {
        global $wpdb;
        $id   = absint($request['id']);
        $book = $wpdb->get_row($wpdb->prepare('SELECT image FROM ' . KM_DB::table('books') . ' WHERE id = %d', $id), ARRAY_A);
        if (! $book) {
            return new WP_Error('km_not_found', __('Kniha nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }

        $params  = $request->get_json_params();
        $url     = is_array($params) && isset($params['url']) && is_string($params['url']) ? $params['url'] : '';
        $allowed = wp_list_pluck(self::previous_covers($id, $book['image']), 'url');
        if (! in_array($url, $allowed, true)) {
            return new WP_Error('km_cover_not_in_history', __('Táto obálka nie je v histórii knihy.', 'knizica-mamateyka'), array('status' => 400));
        }

        $attachment_id = self::find_deletable_cover($id, $url);
        if (! $attachment_id) {
            return new WP_Error('km_cover_not_deletable', __('Tento súbor sa nedá zmazať.', 'knizica-mamateyka'), array('status' => 409));
        }
        if (! wp_delete_attachment($attachment_id, true)) {
            return new WP_Error('km_delete_failed', __('Súbor sa nepodarilo zmazať.', 'knizica-mamateyka'), array('status' => 500));
        }

        return rest_ensure_response(array('url' => $url, 'deleted' => true));
    }

    public static function restore_image(WP_REST_Request $request)
    {
        try {
            return self::process_image_restore($request);
        } catch (Throwable $e) {
            error_log('[knizica-mamateyka] cover restore failed: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            return new WP_Error('km_restore_exception', __('Obálku sa nepodarilo obnoviť.', 'knizica-mamateyka'), array('status' => 500));
        }
    }

    private static function process_image_restore(WP_REST_Request $request)
    {
        global $wpdb;
        $id     = absint($request['id']);
        $before = self::get_book_snapshot($id);
        if (! $before) {
            return new WP_Error('km_not_found', __('Kniha nebola nájdená.', 'knizica-mamateyka'), array('status' => 404));
        }

        $params = $request->get_json_params();
        $url    = is_array($params) && isset($params['url']) && is_string($params['url']) ? $params['url'] : '';
        $allowed = wp_list_pluck(self::previous_covers($id, $before['image']), 'url');
        if (! in_array($url, $allowed, true)) {
            return new WP_Error('km_cover_not_in_history', __('Táto obálka nie je v histórii knihy.', 'knizica-mamateyka'), array('status' => 400));
        }
        if (! self::cover_is_available($url, true)) {
            return new WP_Error('km_cover_unavailable', __('Táto obálka už nie je dostupná.', 'knizica-mamateyka'), array('status' => 409));
        }

        $wpdb->update(KM_DB::table('books'), array('image' => $url, 'updated_at' => current_time('mysql')), array('id' => $id));
        self::record_history($id, 'updated', self::diff_snapshots($before, self::get_book_snapshot($id)));

        return rest_ensure_response(array('id' => $id, 'image' => $url));
    }

    public static function list_archived_books()
    {
        global $wpdb;
        $books = $wpdb->get_results('SELECT * FROM ' . KM_DB::table('books') . ' WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC, id DESC LIMIT 100', ARRAY_A);
        foreach ($books as &$book) {
            self::cast_book($book);
            self::hide_private_fields($book);
        }
        unset($book);
        self::attach_book_associations($books);
        return rest_ensure_response($books);
    }

    private static function get_book_snapshot($id)
    {
        global $wpdb;
        $book = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . KM_DB::table('books') . ' WHERE id = %d',
            absint($id)
        ), ARRAY_A);
        if (! $book) {
            return null;
        }

        self::cast_book($book);
        $books = array($book);
        self::attach_book_associations($books);
        $book = $books[0];
        $fields = array(
            'title',
            'author',
            'co_authors',
            'language',
            'main_category',
            'secondary_categories',
            'published_year',
            'edition',
            'series',
            'isbn',
            'page_count',
            'total_copies',
            'available_copies',
            'image',
            'description',
            'last_checked_at',
        );
        $snapshot = array();
        foreach ($fields as $field) {
            $snapshot[$field] = isset($book[$field]) ? $book[$field] : null;
        }
        return $snapshot;
    }

    private static function diff_snapshots($before, $after)
    {
        $changes = array();
        foreach (array_unique(array_merge(array_keys((array) $before), array_keys((array) $after))) as $field) {
            $old = isset($before[$field]) ? $before[$field] : null;
            $new = isset($after[$field]) ? $after[$field] : null;
            if (is_array($old)) {
                sort($old);
            }
            if (is_array($new)) {
                sort($new);
            }
            if ($old !== $new) {
                $changes[$field] = array('old' => $old, 'new' => $new);
            }
        }
        return $changes;
    }

    private static function record_history($book_id, $event_type, $changes)
    {
        if (empty($changes) && 'updated' === $event_type) {
            return;
        }

        global $wpdb;
        $user = wp_get_current_user();
        $wpdb->insert(KM_DB::table('book_history'), array(
            'book_id'    => absint($book_id),
            'event_type' => sanitize_key($event_type),
            'user_id'    => get_current_user_id() ?: null,
            'user_name'  => $user->exists() ? sanitize_text_field($user->display_name) : __('Systém', 'knizica-mamateyka'),
            'changes'    => wp_json_encode($changes),
            'created_at' => current_time('mysql'),
        ));
    }

    private static function sanitize_book_fields($input)
    {
        $input = (array) $input;
        $map   = array(
            'title'            => 'sanitize_text_field',
            'author'           => 'sanitize_text_field',
            'co_authors'       => 'sanitize_text_field',
            'language'         => 'sanitize_text_field',
            'edition'          => 'sanitize_text_field',
            'series'           => 'sanitize_text_field',
            'isbn'             => 'sanitize_text_field',
        );

        $fields = array();
        foreach ($map as $key => $sanitizer) {
            if (array_key_exists($key, $input)) {
                $fields[$key] = '' === $input[$key] ? null : call_user_func($sanitizer, $input[$key]);
            }
        }

        if (array_key_exists('image', $input)) {
            $image = self::sanitize_image_url($input['image']);
            if (is_wp_error($image)) {
                return $image;
            }
            $fields['image'] = $image;
        }
        if (array_key_exists('description', $input)) {
            $fields['description'] = wp_kses_post((string) $input['description']);
        }
        if (array_key_exists('last_checked_at', $input)) {
            if ('' === $input['last_checked_at'] || null === $input['last_checked_at']) {
                $fields['last_checked_at'] = null;
            } elseif (! self::is_valid_date($input['last_checked_at'])) {
                return new WP_Error('km_invalid_date', __('Dátum kontroly musí byť vo formáte RRRR-MM-DD.', 'knizica-mamateyka'), array('status' => 400));
            } else {
                $fields['last_checked_at'] = (string) $input['last_checked_at'];
            }
        }
        if (array_key_exists('published_year', $input)) {
            $fields['published_year'] = '' === $input['published_year'] ? null : (int) $input['published_year'];
        }
        if (array_key_exists('page_count', $input)) {
            $fields['page_count'] = '' === $input['page_count'] ? null : max(1, (int) $input['page_count']);
        }
        foreach (array('total_copies', 'available_copies') as $key) {
            if (array_key_exists($key, $input)) {
                $fields[$key] = max(0, (int) $input[$key]);
            }
        }

        $main_id = null;
        if (array_key_exists('main_category', $input)) {
            $main_name = is_scalar($input['main_category']) ? sanitize_text_field((string) $input['main_category']) : '';
            if ('' !== $main_name) {
                $main_id = self::find_category_id($main_name);
                if (is_wp_error($main_id)) {
                    return $main_id;
                }
            }
            $fields['main_category_id'] = $main_id;
        }

        // Not a real book column: pulled out by the caller to sync the junction table.
        $fields['__secondary_ids'] = null;
        if (array_key_exists('secondary_categories', $input)) {
            $secondary_ids = array();
            foreach (self::sanitize_string_list($input['secondary_categories']) as $name) {
                $category_id = self::find_category_id($name);
                if (is_wp_error($category_id)) {
                    return $category_id;
                }
                if ($category_id !== $main_id) {
                    $secondary_ids[] = $category_id;
                }
            }
            $fields['__secondary_ids'] = array_values(array_unique($secondary_ids));
        }

        return $fields;
    }

    private static function find_category_id($name)
    {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . KM_DB::table('categories_v2') . ' WHERE name = %s', $name));
        if (! $id) {
            return new WP_Error('km_unknown_category', sprintf(__('Kategória "%s" neexistuje.', 'knizica-mamateyka'), $name), array('status' => 400));
        }
        return (int) $id;
    }

    /**
     * Accepts an absolute http(s) URL or a site-relative path; empty falls back to the default cover.
     */
    public static function sanitize_image_url($value)
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ('' === $value) {
            return KM_DB::DEFAULT_BOOK_IMAGE;
        }
        $is_relative = 0 === strpos($value, '/') && 0 !== strpos($value, '//');
        $url         = esc_url_raw($value, array('http', 'https'));
        if ('' === $url || (! $is_relative && ! preg_match('#^https?://#i', $url)) || strlen($url) > 255) {
            return new WP_Error('km_invalid_image', __('Obrázok musí byť platná URL adresa (http/https) alebo cesta začínajúca "/".', 'knizica-mamateyka'), array('status' => 400, 'field' => 'image'));
        }
        return $url;
    }

    /**
     * Normalizes a request value (array or single scalar) into a de-duped list of
     * non-empty sanitized strings.
     */
    private static function sanitize_string_list($value)
    {
        $list = is_array($value) ? $value : (('' === $value || null === $value) ? array() : array($value));
        $list = array_map('sanitize_text_field', $list);
        $list = array_filter($list, function ($v) {
            return '' !== $v;
        });
        return array_values(array_unique($list));
    }

    /**
     * Splits a comma-separated GET query param into a sanitized list of values.
     */
    private static function split_csv_param($value)
    {
        if (null === $value || '' === $value) {
            return array();
        }
        $items = array_map('trim', explode(',', (string) $value));
        $items = array_map('sanitize_text_field', $items);
        return array_values(array_filter($items, function ($v) {
            return '' !== $v;
        }));
    }

    /**
     * Replaces a book's secondary category rows when they are included in the request.
     */
    private static function sync_book_associations($book_id, $secondary_ids)
    {
        global $wpdb;
        $table = KM_DB::table('book_secondary_categories');

        if (null !== $secondary_ids) {
            $wpdb->delete($table, array('book_id' => $book_id));
            foreach ($secondary_ids as $category_id) {
                $wpdb->insert($table, array('book_id' => $book_id, 'category_id' => $category_id));
            }
        }

        // A category can't be both main and secondary for the same book.
        $wpdb->query($wpdb->prepare(
            "DELETE sc FROM {$table} sc INNER JOIN " . KM_DB::table('books') . ' b ON b.id = sc.book_id AND b.main_category_id = sc.category_id WHERE sc.book_id = %d',
            $book_id
        ));
    }

    /**
     * Attaches the category array to each book row by batch-querying the junction table.
     */
    private static function attach_book_associations(array &$books)
    {
        global $wpdb;
        if (empty($books)) {
            return;
        }

        $ids = array_map(function ($book) {
            return (int) $book['id'];
        }, $books);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $v2_table = KM_DB::table('categories_v2');

        $secondary_rows = $wpdb->get_results($wpdb->prepare(
            'SELECT sc.book_id, c.name FROM ' . KM_DB::table('book_secondary_categories') . " sc INNER JOIN {$v2_table} c ON c.id = sc.category_id WHERE sc.book_id IN ({$placeholders}) ORDER BY c.name",
            $ids
        ));
        $secondary_map = array();
        foreach ($secondary_rows as $row) {
            $secondary_map[(int) $row->book_id][] = $row->name;
        }

        $main_rows = $wpdb->get_results($wpdb->prepare(
            'SELECT b.id AS book_id, c.name FROM ' . KM_DB::table('books') . " b INNER JOIN {$v2_table} c ON c.id = b.main_category_id WHERE b.id IN ({$placeholders})",
            $ids
        ));
        $main_map = array();
        foreach ($main_rows as $row) {
            $main_map[(int) $row->book_id] = $row->name;
        }

        $legacy_map = array();
        if (self::can_manage_books()) {
            $legacy_rows = $wpdb->get_results($wpdb->prepare(
                'SELECT bc.book_id, c.name FROM ' . KM_DB::table('book_categories') . ' bc INNER JOIN ' . KM_DB::table('categories') . " c ON c.id = bc.category_id WHERE bc.book_id IN ({$placeholders})",
                $ids
            ));
            foreach ($legacy_rows as $row) {
                $legacy_map[(int) $row->book_id][] = $row->name;
            }
        }

        foreach ($books as &$book) {
            $bid                          = (int) $book['id'];
            $book['main_category']        = isset($main_map[$bid]) ? $main_map[$bid] : null;
            $book['secondary_categories'] = isset($secondary_map[$bid]) ? array_values($secondary_map[$bid]) : array();
            if (self::can_manage_books()) {
                $book['legacy_categories'] = isset($legacy_map[$bid]) ? array_values($legacy_map[$bid]) : array();
            }
            unset($book['main_category_id']);
        }
        unset($book);
    }

    private static function cast_book(&$book)
    {
        $book['id']               = (int) $book['id'];
        $book['published_year']   = null !== $book['published_year'] ? (int) $book['published_year'] : null;
        $book['page_count']       = null !== $book['page_count'] ? (int) $book['page_count'] : null;
        $book['total_copies']     = (int) $book['total_copies'];
        $book['available_copies'] = (int) $book['available_copies'];
    }

    private static function is_valid_date($value)
    {
        $date = DateTime::createFromFormat('!Y-m-d', (string) $value);
        return false !== $date && $date->format('Y-m-d') === $value;
    }

    private static function hide_private_fields(&$book)
    {
        if (! self::can_manage_books()) {
            unset($book['last_checked_at']);
        }
    }
}
