<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Custom database tables for the library catalog.
 */
class KM_DB
{
    const DEFAULT_BOOK_IMAGE = '/wp-content/uploads/2026/10/empty_book_cover.jpg';

    public static function table($name)
    {
        global $wpdb;
        return $wpdb->prefix . 'km_' . $name;
    }

    public static function create_tables($update_version = true)
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $category_groups      = self::table('category_groups');
        $categories           = self::table('categories');
        $books                = self::table('books');
        $book_categories      = self::table('book_categories');
        $categories_v2        = self::table('categories_v2');
        $book_secondary_cats  = self::table('book_secondary_categories');
        $reservations         = self::table('reservations');
        $book_history         = self::table('book_history');

        $sql = "CREATE TABLE {$category_groups} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(200) NOT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};

		CREATE TABLE {$categories} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL,
			group_id BIGINT UNSIGNED DEFAULT NULL,
			code VARCHAR(20) DEFAULT NULL,
			color VARCHAR(7) DEFAULT NULL,
			text_color VARCHAR(7) DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY name (name(150)),
			KEY group_id (group_id)
		) {$charset_collate};

		CREATE TABLE {$books} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(500) DEFAULT NULL,
			author VARCHAR(150) DEFAULT NULL,
			co_authors VARCHAR(500) DEFAULT NULL,
			language VARCHAR(100) DEFAULT NULL,
			published_year INT DEFAULT NULL,
			edition VARCHAR(100) DEFAULT NULL,
			series VARCHAR(200) DEFAULT NULL,
			isbn VARCHAR(30) DEFAULT NULL,
			page_count INT DEFAULT NULL,
			total_copies INT NOT NULL DEFAULT 1,
			available_copies INT NOT NULL DEFAULT 1,
			image VARCHAR(255) DEFAULT NULL,
			description TEXT DEFAULT NULL,
			main_category_id BIGINT UNSIGNED DEFAULT NULL,
            last_checked_at DATE DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL,
            deleted_by BIGINT UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY title (title(191)),
            KEY author (author(100)),
            KEY created_at (created_at),
            KEY deleted_at (deleted_at),
            KEY last_checked_at (last_checked_at),
            KEY main_category_id (main_category_id)
		) {$charset_collate};

		CREATE TABLE {$categories_v2} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(150) NOT NULL,
			code VARCHAR(20) NOT NULL,
			color VARCHAR(7) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY name (name),
			UNIQUE KEY code (code)
		) {$charset_collate};

		CREATE TABLE {$book_secondary_cats} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			book_id BIGINT UNSIGNED NOT NULL,
			category_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY book_category (book_id, category_id),
			KEY category_id (category_id)
		) {$charset_collate};

		CREATE TABLE {$book_categories} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			book_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
            UNIQUE KEY book_category (book_id, category_id),
			KEY book_id (book_id),
            KEY category_id (category_id)
		) {$charset_collate};
		CREATE TABLE {$reservations} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			book_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(200) NOT NULL,
			email VARCHAR(200) NOT NULL,
			phone VARCHAR(30) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			note VARCHAR(500) DEFAULT NULL,
            librarian_name VARCHAR(200) DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			handled_by BIGINT UNSIGNED DEFAULT NULL,
			handled_at DATETIME DEFAULT NULL,
            assigned_at DATETIME DEFAULT NULL,
            borrowed_at DATETIME DEFAULT NULL,
            returned_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY book_id (book_id),
			KEY status (status),
			KEY email (email)
		) {$charset_collate};";

        $sql .= " CREATE TABLE {$book_history} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            book_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(30) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            user_name VARCHAR(200) NOT NULL DEFAULT '',
            changes LONGTEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY book_id (book_id),
            KEY created_at (created_at),
            KEY event_type (event_type)
        ) {$charset_collate};";

        dbDelta($sql);

        if ($update_version) {
            update_option('km_db_version', KM_DB_VERSION);
        }
    }

    /**
     * Runs once per KM_DB_VERSION bump: creates any new tables via dbDelta,
     * then drops removed legacy columns and tables.
     */
    public static function upgrade()
    {
        if (get_option('km_db_version') === KM_DB_VERSION) {
            return;
        }

        global $wpdb;
        if (! self::migrate_categories_to_ids()) {
            return;
        }
        // Version is written only at the end so a failed step is retried on the next load.
        self::create_tables(false);

        $books     = self::table('books');
        $tags      = self::table('tags');
        $book_tags = self::table('book_tags');
        $recommended_for      = self::table('recommended_for');
        $book_recommended_for = self::table('book_recommended_for');

        $last_checked_column = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM {$books} LIKE %s",
            'last_checked_at'
        ));
        if (! $last_checked_column) {
            $wpdb->query("ALTER TABLE {$books} ADD COLUMN last_checked_at DATE DEFAULT NULL, ADD KEY last_checked_at (last_checked_at)");
        }

        $format_column = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM {$books} LIKE %s",
            'format'
        ));
        if ($format_column) {
            $wpdb->query("ALTER TABLE {$books} DROP COLUMN format");
        }

        $recommended_for_column = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM {$books} LIKE %s",
            'recommended_for'
        ));
        if ($recommended_for_column) {
            $wpdb->query("ALTER TABLE {$books} DROP COLUMN recommended_for");
        }

        $wpdb->query("DROP TABLE IF EXISTS {$book_tags}");
        $wpdb->query("DROP TABLE IF EXISTS {$tags}");
        $wpdb->query("DROP TABLE IF EXISTS " . self::table('languages'));
        $wpdb->query("DROP TABLE IF EXISTS {$book_recommended_for}");
        $wpdb->query("DROP TABLE IF EXISTS {$recommended_for}");

        self::migrate_book_categories();
        self::migrate_reservations();
        self::migrate_images_and_shelf();

        update_option('km_db_version', KM_DB_VERSION);
    }

    /**
     * Fills empty book images with the default cover and drops the removed `shelf` column.
     */
    private static function migrate_images_and_shelf()
    {
        global $wpdb;
        $books = self::table('books');

        $wpdb->query($wpdb->prepare(
            "UPDATE {$books} SET image = %s WHERE image IS NULL OR image = ''",
            self::DEFAULT_BOOK_IMAGE
        ));

        $shelf_column = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$books} LIKE %s", 'shelf'));
        if ($shelf_column) {
            $wpdb->query("ALTER TABLE {$books} DROP COLUMN shelf");
        }
    }

    private static function migrate_reservations()
    {
        global $wpdb;
        $table = self::table('reservations');

        $columns = array(
            'phone'          => "ADD COLUMN phone VARCHAR(30) NOT NULL DEFAULT '' AFTER email",
            'librarian_name' => "ADD COLUMN librarian_name VARCHAR(200) DEFAULT NULL AFTER note",
            'assigned_at'    => "ADD COLUMN assigned_at DATETIME DEFAULT NULL AFTER handled_at",
            'borrowed_at'    => "ADD COLUMN borrowed_at DATETIME DEFAULT NULL AFTER assigned_at",
            'returned_at'    => "ADD COLUMN returned_at DATETIME DEFAULT NULL AFTER borrowed_at",
        );

        foreach ($columns as $column => $definition) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
            if (! $exists) {
                $wpdb->query("ALTER TABLE {$table} {$definition}");
            }
        }
    }

    private static function migrate_categories_to_ids()
    {
        global $wpdb;

        $categories      = self::table('categories');
        $book_categories = self::table('book_categories');

        $category_id = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$categories} LIKE %s", 'id'));
        $category_code = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$categories} LIKE %s", 'code'));
        if ($category_code && ! $category_id) {
            $wpdb->query("ALTER TABLE {$categories} ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE FIRST");
            $wpdb->query("ALTER TABLE {$categories} DROP PRIMARY KEY, ADD PRIMARY KEY (id)");
        }

        $book_category_id = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$book_categories} LIKE %s", 'category_id'));
        $book_category_code = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$book_categories} LIKE %s", 'category_code'));
        if ($book_category_code && ! $book_category_id) {
            $wpdb->query("ALTER TABLE {$book_categories} ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER book_id");
            $wpdb->query("UPDATE {$book_categories} bc INNER JOIN {$categories} c ON c.code = bc.category_code SET bc.category_id = c.id");
            $unmapped = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$book_categories} WHERE category_id IS NULL");
            if ($unmapped > 0) {
                return false;
            }
            $wpdb->query("ALTER TABLE {$book_categories} DROP INDEX book_category, DROP INDEX category_code, DROP COLUMN category_code, MODIFY category_id BIGINT UNSIGNED NOT NULL, ADD UNIQUE KEY book_category (book_id, category_id), ADD KEY category_id (category_id)");
        }

        return true;
    }

    /**
     * One-time migration from the old single-value `books.category_code`
     * column to the many-to-many category junction table.
     */
    private static function migrate_book_categories()
    {
        global $wpdb;

        $books                = self::table('books');
        $categories           = self::table('categories');
        $book_categories      = self::table('book_categories');

        $category_column = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM {$books} LIKE %s",
            'category_code'
        ));
        if ($category_column) {
            $rows = $wpdb->get_results(
                "SELECT id, category_code FROM {$books} WHERE category_code IS NOT NULL AND category_code <> ''"
            );
            foreach ($rows as $row) {
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$book_categories} (book_id, category_id) SELECT %d, id FROM {$categories} WHERE code = %s",
                    $row->id,
                    $row->category_code
                ));
            }
            $wpdb->query("ALTER TABLE {$books} DROP COLUMN category_code");
        }
    }
}
