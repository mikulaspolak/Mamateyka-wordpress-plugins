<?php
global $wpdb;

if ( empty( $GLOBALS['mamateyka_yoast_noindex_filter_registered'] ) ) {
    $GLOBALS['mamateyka_yoast_noindex_filter_registered'] = true;

    add_filter( 'posts_where', function( $where, $query ) use ( $wpdb ) {

        if ( ! $query->get( 'mamateyka_exclude_yoast_noindex' ) ) {
            return $where;
        }

        static $indexable_table_exists = null;
        $indexable_table = $wpdb->prefix . 'yoast_indexable';

        if ( null === $indexable_table_exists ) {
            $indexable_table_exists = $wpdb->get_var(
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $indexable_table ) )
            ) === $indexable_table;
        }

        if ( ! $indexable_table_exists ) {
            if ( empty( $GLOBALS['mamateyka_yoast_indexables_missing_logged'] ) ) {
                error_log( 'Mamateyka search: Yoast indexables table is missing; noindex results could not be excluded.' );
                $GLOBALS['mamateyka_yoast_indexables_missing_logged'] = true;
            }

            return $where;
        }

        return $where . $wpdb->prepare(
            " AND NOT EXISTS (
                SELECT 1
                FROM `{$indexable_table}` AS mamateyka_yoast_noindex
                WHERE mamateyka_yoast_noindex.object_id = {$wpdb->posts}.ID
                  AND mamateyka_yoast_noindex.object_type = %s
                  AND mamateyka_yoast_noindex.is_robots_noindex = 1
            )",
            'post'
        );
    }, 10, 2 );
}

return [
    'post_type' => 'post',
    's' => get_search_query(),
    'mamateyka_exclude_yoast_noindex' => true,
];