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

$search = get_search_query();

if ( ! empty( $search ) ) {

    add_filter( 'posts_join', function( $join, $query ) use ( $wpdb ) {

        if ( $query->get( 'mamateyka_breakdance_search' ) ) {
            $join .= " LEFT JOIN {$wpdb->postmeta} AS bd_search_meta
                       ON {$wpdb->posts}.ID = bd_search_meta.post_id
                       AND bd_search_meta.meta_key = '_breakdance_data' ";
        }

        return $join;

    }, 10, 2 );


    add_filter( 'posts_search', function( $search_sql, $query ) use ( $wpdb, $search ) {

        if (
            ! $query->get( 'mamateyka_breakdance_search' )
            || empty( $search )
        ) {
            return $search_sql;
        }

        $like = '%' . $wpdb->esc_like( $search ) . '%';

        $breakdance_search = $wpdb->prepare(
            "bd_search_meta.meta_value LIKE %s",
            $like
        );

        // Pôvodné WP vyhľadávanie:
        // názov + obsah + excerpt
        //
        // Rozšírime ho o _breakdance_data.
        $search_sql = preg_replace(
            '/^\s*AND\s*\((.*)\)\s*$/s',
            ' AND (($1) OR (' . $breakdance_search . '))',
            $search_sql
        );

        return $search_sql;

    }, 10, 2 );
}

return [
    'post_type' => [ 'page' ],
    's' => $search,
    'mamateyka_breakdance_search' => true,
    'mamateyka_exclude_yoast_noindex' => true,
];