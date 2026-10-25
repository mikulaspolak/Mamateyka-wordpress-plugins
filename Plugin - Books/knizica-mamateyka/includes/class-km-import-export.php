<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Import/export in the version 3 JSON shape with category names on books.
 * Book exports include created_at; imports merge rows and never delete local data.
 */
class KM_Import_Export
{
    const FORMAT  = 'knizica-mamateyka';
    const VERSION = 3;

    /**
     * @param array $data Decoded JSON (format version 3).
     * @return array{groups:int,legacy_categories:int,categories:int,books:int}|WP_Error counts of upserted rows.
     */
    public static function import(array $data)
    {
        global $wpdb;

        $validation = self::validate_import($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $wpdb->query('START TRANSACTION');
        $counts = self::run_import($data);
        if (is_wp_error($counts)) {
            $wpdb->query('ROLLBACK');
            return $counts;
        }
        $wpdb->query('COMMIT');

        return $counts;
    }

    private static function run_import(array $data)
    {
        global $wpdb;

        $counts = array('groups' => 0, 'legacy_categories' => 0, 'categories' => 0, 'books' => 0);

        $groups_table     = KM_DB::table('category_groups');
        $legacy_table     = KM_DB::table('categories');
        $categories_table = KM_DB::table('categories_v2');
        $books_table      = KM_DB::table('books');

        foreach ($data['legacy_category_groups'] as $group) {
            if (false === $wpdb->replace($groups_table, array(
                'id'   => absint($group['id']),
                'name' => sanitize_text_field($group['name']),
            ), array('%d', '%s'))) {
                return self::database_error();
            }
            $counts['groups']++;
        }

        foreach ($data['legacy_categories'] as $cat) {
            $name = sanitize_text_field($cat['name'] ?? '');
            $category_data = array(
                'name'       => $name,
                'group_id'   => isset($cat['group_id']) ? absint($cat['group_id']) : null,
                'code'       => isset($cat['code']) && '' !== $cat['code'] ? substr(sanitize_text_field($cat['code']), 0, 20) : null,
                'color'      => self::nullable_hex_color($cat['color'] ?? null),
                'text_color' => self::nullable_hex_color($cat['text_color'] ?? null),
            );
            $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$legacy_table} WHERE name = %s", $name));
            $result = $existing_id
                ? $wpdb->update($legacy_table, $category_data, array('id' => (int) $existing_id))
                : $wpdb->insert($legacy_table, $category_data);
            if (false === $result) {
                return self::database_error();
            }
            $counts['legacy_categories']++;
        }

        foreach ($data['categories'] as $cat) {
            $name = sanitize_text_field($cat['name']);
            $category_data = array(
                'name'  => $name,
                'code'  => strtoupper(sanitize_text_field($cat['code'])),
                'color' => self::nullable_hex_color($cat['color'] ?? null),
            );
            $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$categories_table} WHERE name = %s", $name));
            $result = $existing_id
                ? $wpdb->update($categories_table, $category_data, array('id' => (int) $existing_id))
                : $wpdb->insert($categories_table, $category_data);
            if (false === $result) {
                return self::database_error();
            }
            $counts['categories']++;
        }

        $legacy_ids = self::name_to_id_map($legacy_table);
        $new_ids    = self::name_to_id_map($categories_table);

        $imported_book_ids = array();
        foreach ($data['books'] as $book) {
            $book_id = absint($book['id']);

            $main_name = isset($book['main_category']) ? sanitize_text_field((string) $book['main_category']) : '';

            $book_data = array(
                'id'                => $book_id,
                'title'             => sanitize_text_field($book['title'] ?? ''),
                'author'            => self::nullable_text($book['author'] ?? null),
                'co_authors'        => self::nullable_text($book['co_authors'] ?? null),
                'language'          => self::nullable_text($book['language'] ?? null),
                'published_year'    => isset($book['published_year']) ? self::nullable_int($book['published_year']) : null,
                'edition'           => self::nullable_text($book['edition'] ?? null),
                'series'            => self::nullable_text($book['series'] ?? null),
                'isbn'              => self::nullable_text($book['isbn'] ?? null),
                'page_count'        => self::nullable_int($book['page_count'] ?? null),
                'total_copies'      => isset($book['total_copies']) ? absint($book['total_copies']) : 1,
                'available_copies'  => isset($book['available_copies']) ? absint($book['available_copies']) : 1,
                'image'             => KM_REST_Books::sanitize_image_url($book['image'] ?? null),
                'description'       => isset($book['description']) ? wp_kses_post((string) $book['description']) : null,
                'main_category_id'  => '' !== $main_name ? $new_ids[$main_name] : null,
            );

            if (array_key_exists('last_checked_at', $book)) {
                $book_data['last_checked_at'] = (null === $book['last_checked_at'] || '' === $book['last_checked_at']) ? null : $book['last_checked_at'];
            }

            if (isset($book['deleted_at']) && self::is_valid_datetime($book['deleted_at'])) {
                $book_data['deleted_at'] = $book['deleted_at'];
                $book_data['deleted_by'] = isset($book['deleted_by']) ? absint($book['deleted_by']) : null;
            }

            if (isset($book['created_at']) && self::is_valid_datetime($book['created_at'])) {
                $book_data['created_at'] = $book['created_at'];
            }

            if (false === $wpdb->replace($books_table, $book_data)) {
                return self::database_error();
            }

            $legacy_category_ids = array();
            foreach (self::string_list($book['legacy_categories'] ?? null) as $name) {
                $legacy_category_ids[] = $legacy_ids[$name];
            }
            self::replace_book_links(KM_DB::table('book_categories'), $book_id, $legacy_category_ids);

            $secondary_ids = array();
            foreach (self::string_list($book['secondary_categories'] ?? null) as $name) {
                if ($new_ids[$name] !== $book_data['main_category_id']) {
                    $secondary_ids[] = $new_ids[$name];
                }
            }
            self::replace_book_links(KM_DB::table('book_secondary_categories'), $book_id, $secondary_ids);

            $imported_book_ids[] = $book_id;
            $counts['books']++;
        }

        $history_table = KM_DB::table('book_history');
        foreach ((array) ($data['book_history'] ?? array()) as $history) {
            $book_id = absint($history['book_id'] ?? 0);
            if (! $book_id || ! $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $books_table . ' WHERE id = %d', $book_id))) {
                continue;
            }
            $wpdb->insert($history_table, array(
                'book_id'    => $book_id,
                'event_type' => sanitize_key($history['event_type'] ?? 'updated'),
                'user_id'    => isset($history['user_id']) ? absint($history['user_id']) : null,
                'user_name'  => sanitize_text_field($history['user_name'] ?? ''),
                'changes'    => wp_json_encode(is_array($history['changes'] ?? null) ? $history['changes'] : array()),
                'created_at' => self::is_valid_datetime($history['created_at'] ?? '') ? $history['created_at'] : current_time('mysql'),
            ));
        }

        foreach (array_unique($imported_book_ids) as $book_id) {
            self::record_import_history($book_id);
        }

        return $counts;
    }

    public static function export()
    {
        global $wpdb;

        $groups_table     = KM_DB::table('category_groups');
        $legacy_table     = KM_DB::table('categories');
        $categories_table = KM_DB::table('categories_v2');
        $books_table      = KM_DB::table('books');

        $groups     = $wpdb->get_results("SELECT id, name FROM {$groups_table} ORDER BY id", ARRAY_A);
        $legacy     = $wpdb->get_results("SELECT name, group_id, code, color, text_color FROM {$legacy_table} ORDER BY name", ARRAY_A);
        $categories = $wpdb->get_results("SELECT name, code, color FROM {$categories_table} ORDER BY name", ARRAY_A);
        $books      = $wpdb->get_results(
            "SELECT b.id, b.title, b.author, b.co_authors, b.language, b.published_year, b.edition, b.series, b.isbn, b.page_count, b.total_copies, b.available_copies, b.image, b.description, c.name AS main_category, b.last_checked_at, b.created_at, b.deleted_at, b.deleted_by
            FROM {$books_table} b
            LEFT JOIN {$categories_table} c ON c.id = b.main_category_id
            ORDER BY b.id",
            ARRAY_A
        );
        $history    = $wpdb->get_results(
            "SELECT h.book_id, h.event_type, h.user_id, h.user_name, h.changes, h.created_at
            FROM " . KM_DB::table('book_history') . " h
            INNER JOIN {$books_table} b ON b.id = h.book_id
            ORDER BY h.id",
            ARRAY_A
        );

        $legacy_map = array();
        foreach ($wpdb->get_results('SELECT bc.book_id, c.name FROM ' . KM_DB::table('book_categories') . " bc INNER JOIN {$legacy_table} c ON c.id = bc.category_id ORDER BY c.name") as $row) {
            $legacy_map[(int) $row->book_id][] = $row->name;
        }
        $secondary_map = array();
        foreach ($wpdb->get_results('SELECT sc.book_id, c.name FROM ' . KM_DB::table('book_secondary_categories') . " sc INNER JOIN {$categories_table} c ON c.id = sc.category_id ORDER BY c.name") as $row) {
            $secondary_map[(int) $row->book_id][] = $row->name;
        }

        foreach ($books as &$book) {
            $book['id']                   = (int) $book['id'];
            $book['published_year']       = null !== $book['published_year'] ? (int) $book['published_year'] : null;
            $book['page_count']           = null !== $book['page_count'] ? (int) $book['page_count'] : null;
            $book['total_copies']         = (int) $book['total_copies'];
            $book['available_copies']     = (int) $book['available_copies'];
            $book['legacy_categories']    = isset($legacy_map[$book['id']]) ? array_values($legacy_map[$book['id']]) : array();
            $book['secondary_categories'] = isset($secondary_map[$book['id']]) ? array_values($secondary_map[$book['id']]) : array();
        }
        unset($book);

        foreach ($groups as &$group) {
            $group['id'] = (int) $group['id'];
        }
        unset($group);

        foreach ($legacy as &$cat) {
            $cat['group_id'] = null !== $cat['group_id'] ? (int) $cat['group_id'] : null;
        }
        unset($cat);

        foreach ($history as &$entry) {
            $entry['book_id'] = (int) $entry['book_id'];
            $entry['user_id'] = null !== $entry['user_id'] ? (int) $entry['user_id'] : null;
            $entry['changes'] = json_decode($entry['changes'], true) ?: array();
        }
        unset($entry);

        return array(
            'format'                 => self::FORMAT,
            'version'                => self::VERSION,
            'legacy_category_groups' => $groups,
            'legacy_categories'      => $legacy,
            'categories'             => $categories,
            'books'                  => $books,
            'book_history'           => $history,
        );
    }

    private static function validate_import(array $data)
    {
        global $wpdb;

        if (($data['format'] ?? null) !== self::FORMAT || ($data['version'] ?? null) !== self::VERSION) {
            return new WP_Error('km_invalid_import', __('Tento súbor pochádza zo staršej verzie knižnice (alebo nie je export knižnice) a nedá sa importovať. Použite export z aktuálnej verzie pluginu.', 'knizica-mamateyka'), array('status' => 400));
        }

        foreach (array('legacy_category_groups', 'legacy_categories', 'categories', 'books') as $key) {
            if (! array_key_exists($key, $data) || ! is_array($data[$key])) {
                return new WP_Error('km_invalid_import', sprintf(__('Importovaný súbor musí obsahovať pole "%s".', 'knizica-mamateyka'), $key), array('status' => 400));
            }
        }

        if (array_key_exists('book_history', $data) && ! is_array($data['book_history'])) {
            return new WP_Error('km_invalid_import', __('Pole "book_history" musí byť pole.', 'knizica-mamateyka'), array('status' => 400));
        }

        $invalid_category = new WP_Error('km_invalid_import', __('Import obsahuje neplatnú kategóriu alebo skupinu.', 'knizica-mamateyka'), array('status' => 400));

        $group_ids = array_fill_keys($wpdb->get_col('SELECT id FROM ' . KM_DB::table('category_groups')), true);
        foreach ($data['legacy_category_groups'] as $group) {
            if (! is_array($group) || ! absint($group['id'] ?? 0) || '' === sanitize_text_field($group['name'] ?? '')) {
                return $invalid_category;
            }
            $group_ids[absint($group['id'])] = true;
        }

        $legacy_names = array_fill_keys($wpdb->get_col('SELECT name FROM ' . KM_DB::table('categories')), true);
        foreach ($data['legacy_categories'] as $category) {
            if (! is_array($category)) {
                return $invalid_category;
            }
            $name = sanitize_text_field($category['name'] ?? '');
            if ('' === $name || (isset($category['group_id']) && null !== $category['group_id'] && ! isset($group_ids[absint($category['group_id'])]))) {
                return $invalid_category;
            }
            foreach (array('color', 'text_color') as $color_key) {
                if (array_key_exists($color_key, $category) && ! self::is_nullable_hex_color($category[$color_key])) {
                    return new WP_Error('km_invalid_import', sprintf(__('Import obsahuje neplatnú farbu v poli "%s".', 'knizica-mamateyka'), $color_key), array('status' => 400));
                }
            }
            $legacy_names[$name] = true;
        }

        $new_names = array_fill_keys($wpdb->get_col('SELECT name FROM ' . KM_DB::table('categories_v2')), true);
        $codes_by_name = array();
        foreach ($wpdb->get_results('SELECT name, code FROM ' . KM_DB::table('categories_v2')) as $row) {
            $codes_by_name[$row->name] = $row->code;
        }
        $payload_codes = array();
        foreach ($data['categories'] as $category) {
            if (! is_array($category)) {
                return $invalid_category;
            }
            $name = sanitize_text_field($category['name'] ?? '');
            $code = is_string($category['code'] ?? null) ? strtoupper(sanitize_text_field($category['code'])) : '';
            if ('' === $name || mb_strlen($name) > 150 || 1 !== preg_match('/^[A-Z0-9]{2,20}$/', $code) || isset($payload_codes[$code])) {
                return $invalid_category;
            }
            if (array_key_exists('color', $category) && ! self::is_nullable_hex_color($category['color'])) {
                return new WP_Error('km_invalid_import', sprintf(__('Import obsahuje neplatnú farbu v poli "%s".', 'knizica-mamateyka'), 'color'), array('status' => 400));
            }
            $payload_codes[$code] = $name;
            $new_names[$name]     = true;
        }
        // A code already used in the DB by a different category would violate the unique key.
        foreach ($codes_by_name as $name => $code) {
            if (isset($payload_codes[$code]) && $payload_codes[$code] !== $name) {
                return new WP_Error('km_invalid_import', sprintf(__('Kód kategórie "%s" už používa iná kategória.', 'knizica-mamateyka'), $code), array('status' => 400));
            }
        }

        foreach ($data['books'] as $book) {
            if (! is_array($book) || ! absint($book['id'] ?? 0)) {
                return new WP_Error('km_invalid_import', __('Import obsahuje neplatnú knihu.', 'knizica-mamateyka'), array('status' => 400));
            }
            if (array_key_exists('last_checked_at', $book) && null !== $book['last_checked_at'] && '' !== $book['last_checked_at'] && ! self::is_valid_date($book['last_checked_at'])) {
                return new WP_Error('km_invalid_import', __('Import obsahuje neplatný dátum kontroly knihy.', 'knizica-mamateyka'), array('status' => 400));
            }
            if (is_wp_error(KM_REST_Books::sanitize_image_url($book['image'] ?? null))) {
                return new WP_Error('km_invalid_import', sprintf(__('Kniha %d má neplatnú URL obrázka.', 'knizica-mamateyka'), absint($book['id'])), array('status' => 400));
            }
            $main_name = $book['main_category'] ?? null;
            if (null !== $main_name && '' !== $main_name && (! is_string($main_name) || ! isset($new_names[sanitize_text_field($main_name)]))) {
                return new WP_Error('km_unknown_category', sprintf(__('Kategória "%s" neexistuje.', 'knizica-mamateyka'), is_string($main_name) ? $main_name : ''), array('status' => 400));
            }
            foreach (self::string_list($book['secondary_categories'] ?? null) as $name) {
                if (! isset($new_names[$name])) {
                    return new WP_Error('km_unknown_category', sprintf(__('Kategória "%s" neexistuje.', 'knizica-mamateyka'), $name), array('status' => 400));
                }
            }
            foreach (self::string_list($book['legacy_categories'] ?? null) as $name) {
                if (! isset($legacy_names[$name])) {
                    return new WP_Error('km_unknown_category', sprintf(__('Pôvodná kategória "%s" neexistuje.', 'knizica-mamateyka'), $name), array('status' => 400));
                }
            }
        }

        return true;
    }

    /**
     * Empties all catalog tables (books, categories, reservations, history); settings and users stay.
     *
     * @return true|WP_Error
     */
    public static function delete_all_data()
    {
        global $wpdb;

        $tables = array('reservations', 'book_history', 'book_secondary_categories', 'book_categories', 'books', 'categories_v2', 'categories', 'category_groups');
        foreach ($tables as $table) {
            // TRUNCATE also resets AUTO_INCREMENT.
            if (false === $wpdb->query('TRUNCATE TABLE ' . KM_DB::table($table))) {
                return new WP_Error('km_delete_database', __('Databázu sa nepodarilo zmazať.', 'knizica-mamateyka'), array('status' => 500));
            }
        }

        return true;
    }

    private static function database_error()
    {
        return new WP_Error('km_import_database', __('Import sa nepodarilo dokončiť.', 'knizica-mamateyka'), array('status' => 500));
    }

    private static function name_to_id_map($table)
    {
        global $wpdb;
        $map = array();
        foreach ($wpdb->get_results("SELECT id, name FROM {$table}") as $row) {
            $map[$row->name] = (int) $row->id;
        }
        return $map;
    }

    private static function replace_book_links($table, $book_id, array $category_ids)
    {
        global $wpdb;
        $wpdb->delete($table, array('book_id' => $book_id));
        foreach (array_unique($category_ids) as $category_id) {
            $wpdb->insert($table, array('book_id' => $book_id, 'category_id' => $category_id));
        }
    }

    private static function record_import_history($book_id)
    {
        global $wpdb;
        $user = wp_get_current_user();
        $wpdb->insert(KM_DB::table('book_history'), array(
            'book_id'    => absint($book_id),
            'event_type' => 'imported',
            'user_id'    => get_current_user_id() ?: null,
            'user_name'  => $user->exists() ? sanitize_text_field($user->display_name) : __('Systém', 'knizica-mamateyka'),
            'changes'    => wp_json_encode(array()),
            'created_at' => current_time('mysql'),
        ));
    }

    private static function nullable_text($value)
    {
        if (null === $value || '' === $value) {
            return null;
        }
        return sanitize_text_field((string) $value);
    }

    private static function is_valid_datetime($value)
    {
        $date = DateTime::createFromFormat('!Y-m-d H:i:s', (string) $value);
        return false !== $date && $date->format('Y-m-d H:i:s') === $value;
    }

    private static function is_valid_date($value)
    {
        $date = DateTime::createFromFormat('!Y-m-d', (string) $value);
        return false !== $date && $date->format('Y-m-d') === $value;
    }

    private static function nullable_int($value)
    {
        if (null === $value || '' === $value) {
            return null;
        }
        return (int) $value;
    }

    private static function nullable_hex_color($value)
    {
        if (null === $value || '' === $value) {
            return null;
        }
        return sanitize_hex_color((string) $value);
    }

    private static function is_nullable_hex_color($value)
    {
        return null === $value || '' === $value || (is_string($value) && 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $value));
    }

    /**
     * Normalizes an import value (array, single scalar, or null) into a de-duped
     * list of non-empty sanitized strings.
     */
    private static function string_list($value)
    {
        if (null === $value || '' === $value) {
            return array();
        }
        $list = is_array($value) ? $value : array($value);
        $list = array_map(function ($v) {
            return sanitize_text_field((string) $v);
        }, $list);
        $list = array_filter($list, function ($v) {
            return '' !== $v;
        });
        return array_values(array_unique($list));
    }
}
