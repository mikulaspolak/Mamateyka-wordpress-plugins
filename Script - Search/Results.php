<?php
/*
 * Mamateyka - výsledky vyhľadávania.
 * Shortcode: [mamateyka_search_results]  (voliteľne pages_visible="6" per_page="9")
 *
 * Code Snippets: spustiť "Všade" (nie len na frontende), aby sa index textu
 * stránok z Breakdance aktualizoval aj pri ukladaní v administrácii.
 */

if ( ! function_exists( 'msr_fold' ) ) {

    if ( ! defined( 'MSR_INDEX_KEY' ) ) {
        // v3: čistý text (SQL hľadanie) + štruktúra nadpisov (radenie); staré kľúče sa ignorujú.
        define( 'MSR_INDEX_KEY', '_msr_search_text_v3' );
        define( 'MSR_STRUCT_KEY', '_msr_search_struct_v3' );
    }

    /* ---------------------------------------------------------------------
     * Pomocné funkcie: text, diakritika, zvýraznenie
     * ------------------------------------------------------------------ */

    // Zjednotí text na malé písmená bez diakritiky; dĺžka (počet znakov) ostáva zachovaná.
    function msr_fold( $text ) {
        static $map = null;
        if ( null === $map ) {
            $map = [
                'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e',
                'í' => 'i', 'ĺ' => 'l', 'ľ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o',
                'ö' => 'o', 'ŕ' => 'r', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
                'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ž' => 'z',
            ];
        }
        return strtr( mb_strtolower( (string) $text, 'UTF-8' ), $map );
    }

    function msr_terms( $query ) {
        $query = str_replace( [ '"', "'", '„', '“', '”', '‘', '’', '«', '»', '`' ], ' ', wp_strip_all_tags( (string) $query ) );
        $query = trim( $query );
        if ( '' === $query ) {
            return [];
        }
        $terms = preg_split( '/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY );
        $terms = array_map( function ( $t ) {
            return mb_substr( $t, 0, 60, 'UTF-8' );
        }, $terms );
        $terms = array_filter( $terms, function ( $t ) {
            return mb_strlen( $t, 'UTF-8' ) >= 2;
        } );
        return array_slice( array_values( array_unique( $terms ) ), 0, 6 );
    }

    function msr_plural( $n, $one, $few, $many ) {
        if ( 1 === $n ) {
            return $one;
        }
        return ( $n >= 2 && $n <= 4 ) ? $few : $many;
    }

    function msr_plain( $html ) {
        $html = preg_replace( '#<(script|style)\b.*?</\1>#is', ' ', (string) $html );
        $html = strip_shortcodes( $html );
        $html = preg_replace( '/<[^>]*>/', ' ', $html );
        $html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', $html ) );
    }

    // Zhoda výrazu na začiatku slova (bez diakritiky); vráti počet zhôd.
    function msr_word_hits( $folded_text, $term ) {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote( msr_fold( $term ), '/' ) . '/u';
        return (int) preg_match_all( $pattern, $folded_text );
    }

    // Vráti ESCAPOVANÝ HTML, kde sú nájdené výrazy obalené v <mark> (bez ohľadu na diakritiku).
    function msr_highlight( $text, array $terms ) {
        $fold   = msr_fold( $text );
        $ranges = [];
        foreach ( $terms as $term ) {
            $needle = msr_fold( $term );
            $nlen   = mb_strlen( $needle, 'UTF-8' );
            if ( 0 === $nlen ) {
                continue;
            }
            $offset = 0;
            while ( false !== ( $pos = mb_strpos( $fold, $needle, $offset, 'UTF-8' ) ) ) {
                $ranges[] = [ $pos, $pos + $nlen ];
                $offset   = $pos + $nlen;
            }
        }
        if ( ! $ranges ) {
            return esc_html( $text );
        }
        usort( $ranges, function ( $a, $b ) {
            return $a[0] <=> $b[0];
        } );
        $merged = [ $ranges[0] ];
        foreach ( array_slice( $ranges, 1 ) as $r ) {
            $last = count( $merged ) - 1;
            if ( $r[0] <= $merged[ $last ][1] ) {
                $merged[ $last ][1] = max( $merged[ $last ][1], $r[1] );
            } else {
                $merged[] = $r;
            }
        }
        $out    = '';
        $cursor = 0;
        foreach ( $merged as $r ) {
            $out   .= esc_html( mb_substr( $text, $cursor, $r[0] - $cursor, 'UTF-8' ) );
            $out   .= '<mark>' . esc_html( mb_substr( $text, $r[0], $r[1] - $r[0], 'UTF-8' ) ) . '</mark>';
            $cursor = $r[1];
        }
        return $out . esc_html( mb_substr( $text, $cursor, null, 'UTF-8' ) );
    }

    // Z prvého textu, v ktorom sa nájde hľadaný výraz, urobí krátky úryvok so zvýraznením.
    function msr_snippet( array $sources, array $terms, $max = 170 ) {
        $chosen = '';
        $pos    = false;
        foreach ( $sources as $source ) {
            $source = trim( (string) $source );
            if ( '' === $source ) {
                continue;
            }
            if ( '' === $chosen ) {
                $chosen = $source;
            }
            $fold = msr_fold( $source );
            foreach ( $terms as $term ) {
                $found = mb_strpos( $fold, msr_fold( $term ), 0, 'UTF-8' );
                if ( false !== $found ) {
                    $chosen = $source;
                    $pos    = $found;
                    break 2;
                }
            }
        }
        if ( '' === $chosen ) {
            return '';
        }

        $len   = mb_strlen( $chosen, 'UTF-8' );
        $start = ( false !== $pos && $pos > 50 ) ? $pos - 50 : 0;
        if ( $start > 0 ) {
            $space = mb_strpos( $chosen, ' ', $start, 'UTF-8' );
            if ( false !== $space && $space < $pos ) {
                $start = $space + 1;
            }
        }
        $slice  = trim( mb_substr( $chosen, $start, $max, 'UTF-8' ) );
        $prefix = $start > 0 ? '… ' : '';
        $suffix = ( $start + $max < $len ) ? ' …' : '';

        return $prefix . msr_highlight( $slice, $terms ) . $suffix;
    }

    /* ---------------------------------------------------------------------
     * Index textu stránok z Breakdance (_breakdance_data -> čistý text)
     * ------------------------------------------------------------------ */

    // Očistí text z Breakdance; vráti '' ak ide o kód, odkaz, názov triedy a pod.
    function msr_bd_clean( $raw ) {
        // CSS/JS z kódových blokov nesmie skončiť v indexe.
        if ( preg_match( '/\{\s*[\w.#>:-]+\s*:|;\s*[}\w.-]+\s*:|=>|&&|\|\||\bfunction\b|\bvar\(|\bdocument\.|\bwindow\.|\bconst\s|\blet\s|<\/?(?:script|style)\b/i', $raw ) ) {
            return '';
        }
        $text = preg_replace( '/<[^>]*>/', ' ', $raw );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/\[\/?[a-z_][\w-]*(?:\s[^\]]*)?\]/i', ' ', $text );
        $text = trim( preg_replace( '/\s+/u', ' ', preg_replace( '/\{[^{}]{1,80}\}/', '', $text ) ) );
        if (
            '' === $text
            || ! preg_match( '/\p{L}/u', $text )
            || preg_match( '~^(https?:)?//|^[#/.]|\.(jpe?g|png|svg|webp|gif|pdf)$~i', $text )
            || preg_match( '/^[a-z][a-z0-9_-]{0,11}$/', $text )
        ) {
            return '';
        }
        return $text;
    }

    // $level: 1-6 = nadpis H1-H6, 0 = bežný text.
    function msr_bd_add( array &$out, $level, $raw ) {
        $text = msr_bd_clean( (string) $raw );
        if ( '' !== $text ) {
            $out[] = [ (int) $level, $text ];
        }
    }

    function msr_bd_collect( $node, array &$out, $level = 0, $key = '' ) {
        static $deny = [
            'tag', 'type', 'url', 'link', 'href', 'target', 'layout', 'style', 'icon', 'id', 'class',
            'classes', 'size', 'alignment', 'align', 'position', 'source', 'name', 'format', 'condition',
            'conditions', 'rel', 'lightbox', 'image', 'images', 'video', 'file', 'attributes', 'direction', 'tags',
        ];
        if ( is_string( $node ) ) {
            // Nadpisy vložené priamo v rich texte (<h2>…</h2>) sa berú ako nadpisy.
            if ( preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $node, $found, PREG_SET_ORDER ) ) {
                foreach ( $found as $heading ) {
                    msr_bd_add( $out, (int) $heading[1], $heading[2] );
                }
                $node = preg_replace( '#<h([1-6])\b[^>]*>.*?</h\1>#is', ' ', $node );
            }
            // Názvy položiek (akordeón, karty…) majú váhu približne H4.
            if ( 0 === $level && in_array( strtolower( (string) $key ), [ 'title', 'heading', 'headline' ], true ) ) {
                $level = 4;
            }
            msr_bd_add( $out, $level, $node );
            return;
        }
        if ( is_array( $node ) ) {
            foreach ( $node as $child_key => $value ) {
                if ( is_string( $child_key ) && in_array( strtolower( $child_key ), $deny, true ) ) {
                    continue;
                }
                msr_bd_collect( $value, $out, $level, is_string( $child_key ) ? $child_key : $key );
            }
        }
    }

    // Úroveň nadpisu z elementu Heading (kľúč tags/tag/level = "h1".."h6"); predvolene H2.
    function msr_bd_heading_level( $content ) {
        if ( is_array( $content ) ) {
            foreach ( $content as $key => $value ) {
                if ( is_string( $key ) && in_array( strtolower( $key ), [ 'tags', 'tag', 'level' ], true )
                    && is_string( $value ) && preg_match( '/^h([1-6])$/i', $value, $m ) ) {
                    return (int) $m[1];
                }
                if ( is_array( $value ) ) {
                    $found = msr_bd_heading_level( $value );
                    if ( $found ) {
                        return $found;
                    }
                }
            }
        }
        return 0;
    }

    function msr_bd_walk( $node, array &$out ) {
        if ( ! is_array( $node ) ) {
            return;
        }
        // $node je buď element ({data, children}), alebo jeho "data" ({type, properties}).
        $type = '';
        if ( isset( $node['data']['type'] ) && is_string( $node['data']['type'] ) ) {
            $type = $node['data']['type'];
        } elseif ( isset( $node['type'], $node['properties'] ) && is_string( $node['type'] ) ) {
            $type = $node['type'];
        }
        // Kódové, HTML a shortcode elementy sa preskočia celé.
        if ( $type && preg_match( '/code|html|script|style|css|javascript|shortcode|svg|embed/i', $type ) ) {
            return;
        }
        foreach ( $node as $key => $value ) {
            if ( 'properties' === $key && is_array( $value ) ) {
                if ( isset( $value['content'] ) ) {
                    $level = 0;
                    if ( $type && preg_match( '/heading/i', $type ) ) {
                        $level = msr_bd_heading_level( $value['content'] ) ?: 2;
                    }
                    msr_bd_collect( $value['content'], $out, $level );
                }
                continue;
            }
            if ( is_array( $value ) ) {
                msr_bd_walk( $value, $out );
            }
        }
    }

    // Vráti zoznam [úroveň, text]; úroveň 1-6 = H1-H6, 0 = bežný text.
    function msr_extract_breakdance_entries( $raw ) {
        $data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
        if ( is_array( $data ) && isset( $data['tree_json_string'] ) && is_string( $data['tree_json_string'] ) ) {
            $data = json_decode( $data['tree_json_string'], true );
        }
        if ( ! is_array( $data ) ) {
            return [];
        }
        $entries = [];
        msr_bd_walk( $data, $entries );
        return $entries;
    }

    function msr_extract_breakdance_text( $raw ) {
        return trim( implode( ' ', array_column( msr_extract_breakdance_entries( $raw ), 1 ) ) );
    }

    function msr_build_index( $post_id ) {
        $raw     = get_post_meta( $post_id, '_breakdance_data', true );
        $entries = $raw ? msr_extract_breakdance_entries( $raw ) : [];
        // Štruktúra sa ukladá ako prvá; podľa textového kľúča sa zisťuje, či stránka už má index.
        update_post_meta( $post_id, MSR_STRUCT_KEY, wp_slash( wp_json_encode( $entries, JSON_UNESCAPED_UNICODE ) ) );
        update_post_meta( $post_id, MSR_INDEX_KEY, wp_slash( trim( implode( ' ', array_column( $entries, 1 ) ) ) ) );
    }

    function msr_ensure_index() {
        $ids = get_posts( [
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'posts_per_page'   => 200,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'meta_query'       => [ [ 'key' => MSR_INDEX_KEY, 'compare' => 'NOT EXISTS' ] ],
        ] );
        foreach ( $ids as $id ) {
            msr_build_index( (int) $id );
        }
    }

    add_action( 'save_post_page', function ( $post_id ) {
        if ( ! wp_is_post_revision( $post_id ) ) {
            msr_build_index( $post_id );
        }
    } );

    $msr_meta_hook = function ( $meta_id, $post_id, $meta_key ) {
        if ( '_breakdance_data' === $meta_key && 'page' === get_post_type( $post_id ) ) {
            msr_build_index( (int) $post_id );
        }
    };
    add_action( 'added_post_meta', $msr_meta_hook, 10, 3 );
    add_action( 'updated_post_meta', $msr_meta_hook, 10, 3 );

    // Ajax Search Lite odosiela výraz na /?s=... cez POST. Prehliadač potom pri Späť ukáže
    // "Confirm Form Resubmission", preto POST presmerujeme (303) na čistý GET.
    add_action( 'template_redirect', function () {
        if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || ! is_search() ) {
            return;
        }
        $term = trim( (string) get_search_query( false ) );
        if ( '' === $term ) {
            return;
        }
        wp_safe_redirect( home_url( '/?s=' . rawurlencode( $term ) ), 303 );
        exit;
    }, 1 );

    // Ručné prebudovanie indexu pre administrátora: ?msr_reindex=1
    add_action( 'init', function () {
        if ( isset( $_GET['msr_reindex'] ) && current_user_can( 'manage_options' ) ) {
            delete_post_meta_by_key( '_msr_search_text' );
            delete_post_meta_by_key( '_msr_search_text_v2' );
            $ids = get_posts( [
                'post_type'        => 'page',
                'post_status'      => 'any',
                'posts_per_page'   => -1,
                'fields'           => 'ids',
                'suppress_filters' => true,
            ] );
            foreach ( $ids as $id ) {
                msr_build_index( (int) $id );
            }
        }
    } );

    /* ---------------------------------------------------------------------
     * SQL filtre (aktívne iba pre dotazy s príznakom msr_search)
     * ------------------------------------------------------------------ */

    add_filter( 'posts_join', function ( $join, $query ) {
        global $wpdb;
        if ( ! $query->get( 'msr_search' ) ) {
            return $join;
        }
        return $join . $wpdb->prepare(
            " LEFT JOIN {$wpdb->postmeta} AS msr_idx ON ({$wpdb->posts}.ID = msr_idx.post_id AND msr_idx.meta_key = %s)",
            MSR_INDEX_KEY
        );
    }, 10, 2 );

    add_filter( 'posts_search', function ( $search, $query ) {
        global $wpdb;
        if ( ! $query->get( 'msr_search' ) ) {
            return $search;
        }
        $terms = (array) $query->get( 'msr_terms' );
        if ( ! $terms ) {
            return ' AND 1=0 ';
        }
        $parts = [];
        foreach ( $terms as $term ) {
            $like    = '%' . $wpdb->esc_like( $term ) . '%';
            $parts[] = $wpdb->prepare(
                "({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR COALESCE(NULLIF(msr_idx.meta_value, ''), {$wpdb->posts}.post_content) LIKE %s)",
                $like,
                $like,
                $like
            );
        }
        return ' AND (' . implode( ' AND ', $parts ) . ') ';
    }, 10, 2 );

    add_filter( 'posts_where', function ( $where, $query ) {
        global $wpdb;
        if ( ! $query->get( 'msr_search' ) ) {
            return $where;
        }

        static $table_exists = null;
        $table = $wpdb->prefix . 'yoast_indexable';
        if ( null === $table_exists ) {
            $table_exists = $wpdb->get_var(
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
            ) === $table;
        }
        if ( ! $table_exists ) {
            return $where;
        }

        return $where . $wpdb->prepare(
            " AND NOT EXISTS (
                SELECT 1 FROM `{$table}` AS msr_yoast
                WHERE msr_yoast.object_id = {$wpdb->posts}.ID
                  AND msr_yoast.object_type = %s
                  AND msr_yoast.is_robots_noindex = 1
            )",
            'post'
        );
    }, 10, 2 );

    // Relevancia: celá fráza v názve > všetky slová v názve > zhoda v excerpte, potom dátum.
    // Pri msr_sort=date sa radí iba podľa dátumu.
    add_filter( 'posts_orderby', function ( $orderby, $query ) {
        global $wpdb;
        if ( ! $query->get( 'msr_search' ) ) {
            return $orderby;
        }
        if ( 'date' === $query->get( 'msr_sort' ) ) {
            return "{$wpdb->posts}.post_date DESC";
        }
        $terms = (array) $query->get( 'msr_terms' );
        if ( ! $terms ) {
            return $orderby;
        }
        $phrase   = '%' . $wpdb->esc_like( implode( ' ', $terms ) ) . '%';
        $in_title = [];
        foreach ( $terms as $term ) {
            $in_title[] = $wpdb->prepare( "{$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like( $term ) . '%' );
        }
        $score = 'CASE WHEN ' . $wpdb->prepare( "{$wpdb->posts}.post_title LIKE %s", $phrase )
            . ' THEN 4 WHEN (' . implode( ' AND ', $in_title ) . ') THEN 3 ELSE 0 END'
            . ' + CASE WHEN ' . $wpdb->prepare( "{$wpdb->posts}.post_excerpt LIKE %s", $phrase ) . ' THEN 1 ELSE 0 END';

        return "({$score}) DESC, {$wpdb->posts}.post_date DESC";
    }, 10, 2 );

    function msr_query( $post_type, array $terms, $per_page, $paged = 1, $sort = 'relevance' ) {
        return new WP_Query( [
            'post_type'           => $post_type,
            'post_status'         => 'publish',
            'has_password'        => false,
            'posts_per_page'      => $per_page,
            'paged'               => max( 1, (int) $paged ),
            'ignore_sticky_posts' => true,
            'msr_search'          => true,
            'msr_terms'           => $terms,
            'msr_sort'            => $sort,
        ] );
    }

    /* ---------------------------------------------------------------------
     * Ikony a farebné tóny pre karty stránok
     * ------------------------------------------------------------------ */

    function msr_icon( $name ) {
        static $icons = [
            'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
            'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'news'     => '<path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2M18 14h-8M15 18h-5M10 6h8v4h-8V6Z"/>',
            'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/>',
        ];
        $path = isset( $icons[ $name ] ) ? $icons[ $name ] : $icons['file'];
        return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
    }

    /* ---------------------------------------------------------------------
     * Zobrazenie
     * ------------------------------------------------------------------ */

    // Váhy zhody podľa miesta výskytu: názov stránky > H1 > H2 … > excerpt > bežný text.
    function msr_level_weight( $level ) {
        static $weights = [ 1 => 60, 2 => 50, 3 => 40, 4 => 30, 5 => 20, 6 => 15 ];
        return isset( $weights[ $level ] ) ? $weights[ $level ] : 5;
    }

    /*
     * Stránky: každý výraz musí sedieť na začiatku slova (nie "ples" v "simple").
     * Radenie: celá fráza v názve, potom súčet najlepších zhôd jednotlivých výrazov
     * (názov 100, H1 60, H2 50, H3 40, H4 30, H5 20, H6 15, excerpt 8, text 5),
     * potom počet zhôd vážený úrovňou, nakoniec pôvodné poradie.
     */
    function msr_rank_pages( array $pages, array $terms ) {
        $ranked = [];
        foreach ( $pages as $order => $page ) {
            $entries = json_decode( (string) get_post_meta( $page->ID, MSR_STRUCT_KEY, true ), true );
            if ( ! is_array( $entries ) || ! $entries ) {
                $index   = (string) get_post_meta( $page->ID, MSR_INDEX_KEY, true );
                $entries = [ [ 0, '' !== $index ? $index : msr_plain( $page->post_content ) ] ];
            }
            if ( '' !== trim( (string) $page->post_excerpt ) ) {
                $entries[] = [ 'excerpt', $page->post_excerpt ];
            }
            array_unshift( $entries, [ 'title', $page->post_title ] );

            $fold_entries = [];
            foreach ( $entries as $entry ) {
                $fold_entries[] = [ $entry[0], msr_fold( $entry[1] ) ];
            }

            $total  = 0;
            $points = 0;
            foreach ( $terms as $term ) {
                $best = 0;
                foreach ( $fold_entries as $entry ) {
                    $hits = msr_word_hits( $entry[1], $term );
                    if ( ! $hits ) {
                        continue;
                    }
                    $weight = 'title' === $entry[0] ? 100 : ( 'excerpt' === $entry[0] ? 8 : msr_level_weight( (int) $entry[0] ) );
                    $best   = max( $best, $weight );
                    $points += $weight * min( $hits, 3 );
                }
                if ( 0 === $best ) {
                    continue 2;
                }
                $total += $best;
            }

            $title_fold = $fold_entries[0][1];
            $phrase     = count( $terms ) > 1 && false !== mb_strpos( $title_fold, msr_fold( implode( ' ', $terms ) ), 0, 'UTF-8' );
            $ranked[]   = [ 'page' => $page, 'score' => [ $phrase ? 1 : 0, $total, min( $points, 2000 ), -$order ] ];
        }
        usort( $ranked, function ( $a, $b ) {
            return $b['score'] <=> $a['score'];
        } );
        return array_column( $ranked, 'page' );
    }

    function msr_page_url( $query, $paged, $sort = 'relevance' ) {
        $args = [ 's' => rawurlencode( $query ) ];
        if ( 'date' === $sort ) {
            $args['msr_sort'] = 'date';
        }
        if ( $paged > 1 ) {
            $args['msr_page'] = $paged;
        }
        return add_query_arg( $args, home_url( '/' ) );
    }

    function msr_render_sort( $query, $sort ) {
        $options = [ 'relevance' => 'Najrelevantnejšie', 'date' => 'Najnovšie' ];
        $html    = '<div class="msr-sort" role="group" aria-label="Radenie článkov">';
        foreach ( $options as $value => $label ) {
            if ( $value === $sort ) {
                $html .= '<span class="msr-sort__option is-active" aria-current="true">' . esc_html( $label ) . '</span>';
            } else {
                $html .= '<a class="msr-sort__option" href="' . esc_url( msr_page_url( $query, 1, $value ) . '#msr-articles' ) . '">' . esc_html( $label ) . '</a>';
            }
        }
        return $html . '</div>';
    }

    function msr_render_pagination( $query, $current, $total, $sort = 'relevance' ) {
        if ( $total < 2 ) {
            return '';
        }
        $show = [];
        for ( $i = 1; $i <= $total; $i++ ) {
            if ( 1 === $i || $total === $i || abs( $i - $current ) <= 1 ) {
                $show[] = $i;
            }
        }
        $html = '<nav class="msr-pagination" aria-label="Stránkovanie článkov">';
        if ( $current > 1 ) {
            $html .= '<a class="msr-pagination__nav" rel="prev" href="' . esc_url( msr_page_url( $query, $current - 1, $sort ) . '#msr-articles' ) . '">‹ Predošlá</a>';
        }
        $previous = 0;
        foreach ( $show as $i ) {
            if ( $i - $previous > 1 ) {
                $html .= '<span class="msr-pagination__gap" aria-hidden="true">…</span>';
            }
            if ( $i === $current ) {
                $html .= '<span class="msr-pagination__num is-current" aria-current="page">' . (int) $i . '</span>';
            } else {
                $html .= '<a class="msr-pagination__num" href="' . esc_url( msr_page_url( $query, $i, $sort ) . '#msr-articles' ) . '" aria-label="Strana ' . (int) $i . '">' . (int) $i . '</a>';
            }
            $previous = $i;
        }
        if ( $current < $total ) {
            $html .= '<a class="msr-pagination__nav" rel="next" href="' . esc_url( msr_page_url( $query, $current + 1, $sort ) . '#msr-articles' ) . '">Ďalšia ›</a>';
        }
        return $html . '</nav>';
    }

    function msr_title( $post ) {
        return html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    function msr_render_page_card( $post, array $terms, $extra ) {
        $crumbs = [];
        foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
            $crumbs[] = msr_title( $ancestor_id );
        }
        $index   = (string) get_post_meta( $post->ID, MSR_INDEX_KEY, true );
        $snippet = msr_snippet(
            [ msr_plain( $post->post_excerpt ), $index, msr_plain( $post->post_content ) ],
            $terms,
            120
        );

        ob_start();
        ?>
        <a class="msr-pagecard<?php echo $extra ? ' is-extra' : ''; ?>" href="<?php echo esc_url( get_permalink( $post ) ); ?>">
            <span class="msr-icon msr-tone--blue"><?php echo msr_icon( 'file' ); ?></span>
            <span class="msr-pagecard__body">
                <?php if ( $crumbs ) : ?>
                    <span class="msr-pagecard__crumbs"><?php echo esc_html( implode( ' › ', $crumbs ) ); ?></span>
                <?php endif; ?>
                <h3 class="msr-pagecard__title"><?php echo msr_highlight( msr_title( $post ), $terms ); ?></h3>
                <?php if ( $snippet ) : ?>
                    <span class="msr-pagecard__text"><?php echo $snippet; // escapované v msr_highlight() ?></span>
                <?php endif; ?>
            </span>
            <span class="msr-arrow" aria-hidden="true"><?php echo msr_icon( 'arrow' ); ?></span>
        </a>
        <?php
        return ob_get_clean();
    }

    function msr_render_post_card( $post, array $terms ) {
        $thumb   = get_the_post_thumbnail( $post->ID, 'medium_large', [ 'loading' => 'lazy', 'decoding' => 'async', 'alt' => '' ] );
        $snippet = msr_snippet( [ msr_plain( $post->post_excerpt ), msr_plain( $post->post_content ) ], $terms, 150 );

        ob_start();
        ?>
        <article class="msr-post">
            <a class="msr-post__link" href="<?php echo esc_url( get_permalink( $post ) ); ?>">
                <span class="msr-post__media<?php echo $thumb ? '' : ' is-empty'; ?>">
                    <?php echo $thumb ? $thumb : msr_icon( 'news' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </span>
                <span class="msr-post__body">
                    <h3 class="msr-post__title"><?php echo msr_highlight( msr_title( $post ), $terms ); ?></h3>
                    <?php if ( $snippet ) : ?>
                        <span class="msr-post__text"><?php echo $snippet; // escapované v msr_highlight() ?></span>
                    <?php endif; ?>
                    <span class="msr-post__meta">
                        <span class="msr-post__date"><?php echo msr_icon( 'calendar' ); ?><time datetime="<?php echo esc_attr( get_the_date( 'c', $post ) ); ?>"><?php echo esc_html( get_the_date( 'd.m.Y', $post ) ); ?></time></span>
                        <span class="msr-arrow" aria-hidden="true"><?php echo msr_icon( 'arrow' ); ?></span>
                    </span>
                </span>
            </a>
        </article>
        <?php
        return ob_get_clean();
    }

    function msr_assets() {
        ob_start();
        ?>
        <style>
            .msr {
                --msr-ink: #1d2630;
                --msr-heading: #161b23;
                --msr-muted: #64717c;
                --msr-blue: #537891;
                --msr-blue-dark: #3f6174;
                --msr-orange: #f3a738;
                --msr-border: #dfe8ee;
                --msr-soft: #eaf1f5;
                --msr-surface: #fff;
                --msr-shadow: 0 5px 18px rgba(29, 38, 48, .06);
                --msr-shadow-hover: 0 12px 28px rgba(29, 38, 48, .12);
                width: 100%;
                margin: 0;
                color: var(--msr-ink);
                font-family: "Fira Sans", sans-serif;
                font-size: 1rem;
                line-height: 1.5;
            }
            .msr *, .msr *::before, .msr *::after { box-sizing: border-box; }
            .msr h1, .msr h2, .msr h3, .msr p { margin: 0; font-family: inherit; }
            .msr svg { display: block; flex: none; }
            .msr mark {
                padding: 0 .12em;
                border-radius: 3px;
                background: rgba(243, 167, 56, .38);
                color: inherit;
            }

            .msr-head { margin: 0 0 clamp(1.5rem, 3vw, 2.25rem); }
            .msr-eyebrow {
                display: inline-flex;
                align-items: center;
                gap: .5rem;
                margin-bottom: .6rem;
                color: var(--msr-blue);
                font-size: .8rem;
                font-weight: 600;
                letter-spacing: .1em;
                text-transform: uppercase;
            }
            .msr-eyebrow svg { width: 18px; height: 18px; }
            .msr-title {
                color: var(--msr-heading);
                font-size: clamp(1.9rem, 4.5vw, 2.75rem);
                font-weight: 700;
                line-height: 1.15;
            }
            .msr-title em { color: var(--msr-blue); font-style: normal; }

            .msr-form {
                display: flex;
                max-width: 640px;
                margin-top: 1.25rem;
                border: 1.5px solid rgba(83, 120, 145, .25);
                border-radius: 16px;
                background: var(--msr-surface);
                box-shadow: var(--msr-shadow);
                overflow: hidden;
                transition: border-color .2s ease, box-shadow .2s ease;
            }
            .msr-form:focus-within {
                border-color: var(--msr-blue);
                box-shadow: 0 0 0 4px rgba(83, 120, 145, .14);
            }
            .msr-form input[type="search"] {
                flex: 1 1 auto;
                min-width: 0;
                height: 52px;
                margin: 0;
                padding: 0 1.1rem;
                border: 0;
                background: transparent;
                color: var(--msr-ink);
                font: inherit;
                outline: none;
                box-shadow: none;
            }
            .msr-form button {
                display: inline-flex;
                align-items: center;
                gap: .5rem;
                height: 52px;
                margin: 0;
                padding: 0 1.4rem;
                border: 0;
                border-radius: 0;
                background: var(--msr-blue);
                color: #fff;
                font: inherit;
                font-weight: 600;
                cursor: pointer;
                transition: background .2s ease;
            }
            .msr-form button:hover { background: var(--msr-blue-dark); }
            .msr-form button svg { width: 18px; height: 18px; }

            .msr-section { margin: 0 0 clamp(2rem, 4vw, 3rem); }
            .msr-section__head {
                display: flex;
                flex-wrap: wrap;
                align-items: baseline;
                justify-content: space-between;
                gap: .25rem 1rem;
                margin-bottom: 1.1rem;
            }
            .msr-section__title {
                color: var(--msr-heading);
                font-size: clamp(1.5rem, 3vw, 2rem);
                font-weight: 700;
                line-height: 1.2;
            }
            .msr-section__lead { flex-basis: 100%; color: var(--msr-muted); }
            .msr-section__lead strong { color: var(--msr-ink); }

            .msr-sort {
                display: inline-flex;
                padding: 3px;
                border-radius: 999px;
                background: var(--msr-soft);
            }
            .msr-sort__option {
                padding: .4rem 1rem;
                border-radius: 999px;
                color: var(--msr-blue-dark);
                font-size: .88rem;
                font-weight: 600;
                text-decoration: none;
                transition: background .2s ease, color .2s ease;
            }
            .msr-sort a.msr-sort__option:hover { background: rgba(83, 120, 145, .14); }
            .msr-sort__option.is-active { background: var(--msr-blue); color: #fff; }

            .msr-panel {
                padding: clamp(1.1rem, 3vw, 1.9rem);
                border-radius: 24px;
                background: var(--msr-soft);
            }

            .msr-grid {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 1.1rem;
            }

            .msr-icon {
                display: inline-flex;
                flex: none;
                align-items: center;
                justify-content: center;
                width: 48px;
                height: 48px;
                border-radius: 50%;
                background: var(--tone-bg);
                color: var(--tone-fg);
            }
            .msr-tone--blue   { --tone-bg: #dbe8f1; --tone-fg: #2f6b94; }
            .msr-tone--orange { --tone-bg: #fde9c9; --tone-fg: #c77d0a; }

            .msr-arrow {
                display: inline-flex;
                flex: none;
                align-items: center;
                justify-content: center;
                width: 34px;
                height: 34px;
                border-radius: 50%;
                background: #edf2f5;
                color: var(--msr-blue);
                transition: background .2s ease, color .2s ease, transform .2s ease;
            }
            .msr-arrow svg { width: 16px; height: 16px; }

            .msr-pagecard {
                display: flex;
                align-items: center;
                gap: 1rem;
                min-width: 0;
                padding: 1.1rem 1.1rem 1.1rem 1.2rem;
                border: 1px solid transparent;
                border-radius: 18px;
                background: var(--msr-surface);
                box-shadow: var(--msr-shadow);
                color: inherit;
                text-decoration: none;
                transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
            }
            .msr-pagecard__body { display: flex; flex: 1 1 auto; flex-direction: column; gap: .2rem; min-width: 0; }
            .msr-pagecard__crumbs { color: var(--msr-muted); font-size: .78rem; }
            .msr-pagecard__title {
                color: var(--msr-heading);
                font-size: 1.15rem;
                font-weight: 700;
                line-height: 1.25;
                overflow-wrap: anywhere;
            }
            .msr-pagecard__text {
                display: -webkit-box;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 2;
                overflow: hidden;
                color: var(--msr-muted);
                font-size: .9rem;
                line-height: 1.45;
            }

            .msr-post {
                min-width: 0;
                border-radius: 18px;
                background: var(--msr-surface);
                box-shadow: var(--msr-shadow);
                overflow: hidden;
                transition: transform .2s ease, box-shadow .2s ease;
            }
            .msr-post__link {
                display: flex;
                flex-direction: column;
                height: 100%;
                color: inherit;
                text-decoration: none;
            }
            .msr-post__media {
                display: flex;
                align-items: center;
                justify-content: center;
                aspect-ratio: 16 / 10;
                background: linear-gradient(135deg, #dbe8f1, #eef4f8);
                color: var(--msr-blue);
                overflow: hidden;
            }
            .msr-post__media img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                transition: transform .35s ease;
            }
            .msr-post__media.is-empty svg { width: 44px; height: 44px; opacity: .55; }
            .msr-post__body { display: flex; flex: 1 1 auto; flex-direction: column; gap: .5rem; padding: 1.1rem 1.2rem 1.2rem; }
            .msr-post__title {
                color: var(--msr-heading);
                font-size: 1.25rem;
                font-weight: 700;
                line-height: 1.25;
                overflow-wrap: anywhere;
            }
            .msr-post__text {
                display: -webkit-box;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 3;
                overflow: hidden;
                color: var(--msr-muted);
                font-size: .92rem;
                line-height: 1.5;
            }
            .msr-post__meta {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: .75rem;
                margin-top: auto;
                padding-top: .4rem;
            }
            .msr-post__date { display: inline-flex; align-items: center; gap: .45rem; color: var(--msr-muted); font-size: .88rem; }
            .msr-post__date svg { width: 16px; height: 16px; }

            .msr-pagecard:hover, .msr-pagecard:focus-visible { transform: translateY(-2px); box-shadow: var(--msr-shadow-hover); border-color: rgba(83, 120, 145, .3); }
            .msr-post:hover, .msr-post:focus-within { transform: translateY(-2px); box-shadow: var(--msr-shadow-hover); }
            .msr-post:hover .msr-post__media img { transform: scale(1.04); }
            .msr-pagecard:hover .msr-arrow, .msr-post:hover .msr-arrow,
            .msr-pagecard:focus-visible .msr-arrow, .msr-post__link:focus-visible .msr-arrow {
                background: var(--msr-blue);
                color: #fff;
                transform: translateX(2px);
            }
            .msr a:focus-visible { outline: 3px solid rgba(83, 120, 145, .55); outline-offset: 3px; }

            .msr-more { display: none; margin: 1.25rem auto 0; }
            .msr--js .msr-more {
                display: flex;
                align-items: center;
                gap: .5rem;
                padding: .7rem 1.4rem;
                border: 1.5px solid var(--msr-blue);
                border-radius: 999px;
                background: transparent;
                color: var(--msr-blue);
                font: inherit;
                font-weight: 600;
                cursor: pointer;
                transition: background .2s ease, color .2s ease;
            }
            .msr--js .msr-more:hover { background: var(--msr-blue); color: #fff; }
            .msr .msr-more[hidden] { display: none; }
            .msr--js:not(.msr--expanded) .msr-pagecard.is-extra { display: none; }

            .msr-pagination {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: center;
                gap: .4rem;
                margin-top: 1.75rem;
            }
            .msr-pagination__num, .msr-pagination__nav, .msr-pagination__gap {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 42px;
                height: 42px;
                padding: 0 .85rem;
                border-radius: 12px;
                color: var(--msr-blue-dark);
                font-weight: 600;
                text-decoration: none;
            }
            .msr-pagination__num, .msr-pagination__nav { background: var(--msr-surface); box-shadow: var(--msr-shadow); transition: background .2s ease, color .2s ease; }
            .msr-pagination a:hover { background: var(--msr-blue); color: #fff; }
            .msr-pagination__num.is-current { background: var(--msr-blue); color: #fff; }

            .msr-empty {
                padding: clamp(1.75rem, 5vw, 3rem);
                border-radius: 24px;
                background: var(--msr-surface);
                box-shadow: var(--msr-shadow);
                text-align: center;
            }
            .msr-empty .msr-icon { width: 64px; height: 64px; margin-bottom: 1rem; }
            .msr-empty .msr-icon svg { width: 30px; height: 30px; }
            .msr-empty h2 { margin-bottom: .5rem; color: var(--msr-heading); font-size: clamp(1.4rem, 3vw, 1.8rem); font-weight: 700; }
            .msr-empty p { max-width: 520px; margin: 0 auto; color: var(--msr-muted); }
            .msr-empty__links { display: flex; flex-wrap: wrap; justify-content: center; gap: .6rem; margin-top: 1.25rem; }
            .msr-empty__links a {
                padding: .55rem 1.1rem;
                border-radius: 999px;
                background: var(--msr-blue);
                color: #fff;
                font-weight: 600;
                text-decoration: none;
                transition: background .2s ease;
            }
            .msr-empty__links a:hover { background: var(--msr-blue-dark); }

            @media (max-width: 1023px) {
                .msr-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            }
            @media (max-width: 639px) {
                .msr-grid { grid-template-columns: minmax(0, 1fr); }
                .msr-form button span { display: none; }
                .msr-form button { padding: 0 1.1rem; }
                .msr-panel { border-radius: 18px; }
            }
            @media (prefers-reduced-motion: reduce) {
                .msr *, .msr *::before, .msr *::after { transition: none !important; }
                .msr-pagecard:hover, .msr-post:hover { transform: none; }
            }
        </style>
        <script>
            (function () {
                function init() {
                    document.querySelectorAll('.msr').forEach(function (root) {
                        root.classList.add('msr--js');
                        var button = root.querySelector('[data-msr-more]');
                        if (!button) { return; }
                        button.addEventListener('click', function () {
                            root.classList.add('msr--expanded');
                            button.hidden = true;
                        });
                    });
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', init);
                } else {
                    init();
                }
            })();
        </script>
        <?php
        return ob_get_clean();
    }

    add_shortcode( 'mamateyka_search_results', function ( $atts = [] ) {
        $atts = shortcode_atts( [ 'pages_visible' => 6, 'per_page' => 9 ], $atts, 'mamateyka_search_results' );

        $visible  = max( 1, (int) $atts['pages_visible'] );
        $per_page = max( 1, (int) $atts['per_page'] );

        $query = trim( (string) get_search_query( false ) );
        if ( '' === $query && isset( $_GET['s'] ) ) {
            $query = trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) );
        }
        $terms = msr_terms( $query );
        $paged = isset( $_GET['msr_page'] ) ? max( 1, absint( $_GET['msr_page'] ) ) : 1;
        $sort  = ( isset( $_GET['msr_sort'] ) && 'date' === $_GET['msr_sort'] ) ? 'date' : 'relevance';

        $pages = $posts = null;
        $page_list = [];
        if ( $terms ) {
            msr_ensure_index();
            $pages     = msr_query( 'page', $terms, 60 );
            $page_list = msr_rank_pages( $pages->posts, $terms );
            $posts     = msr_query( 'post', $terms, $per_page, $paged, $sort );
            if ( ! $posts->have_posts() && $paged > 1 ) {
                $paged = 1;
                $posts = msr_query( 'post', $terms, $per_page, 1, $sort );
            }
        }

        static $assets_printed = false;
        $output = '';
        if ( ! $assets_printed ) {
            $assets_printed = true;
            $output         = msr_assets();
        }

        $pages_count = count( $page_list );
        $posts_count = $posts ? (int) $posts->found_posts : 0;

        ob_start();
        ?>
        <div class="msr">
            <header class="msr-head">
                <span class="msr-eyebrow"><?php echo msr_icon( 'search' ); ?>Výsledky vyhľadávania</span>
                <?php if ( $query ) : ?>
                    <h1 class="msr-title">Hľadaný výraz: <em>„<?php echo esc_html( $query ); ?>“</em></h1>
                <?php else : ?>
                    <h1 class="msr-title">Čo hľadáte?</h1>
                <?php endif; ?>
                <form class="msr-form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                    <input type="search" name="s" value="<?php echo esc_attr( $query ); ?>" placeholder="Hľadať na webe…" aria-label="Hľadať na webe" autocomplete="off">
                    <button type="submit"><?php echo msr_icon( 'search' ); ?><span>Hľadať</span></button>
                </form>
            </header>

            <?php if ( ! $terms ) : ?>
                <div class="msr-empty">
                    <span class="msr-icon msr-tone--blue"><?php echo msr_icon( 'search' ); ?></span>
                    <?php if ( '' !== $query ) : ?>
                        <h2>Výraz je príliš krátky</h2>
                        <p>Zadajte aspoň dva znaky, aby sme mohli prehľadať stránky aj články.</p>
                    <?php else : ?>
                        <h2>Zadajte hľadaný výraz</h2>
                        <p>Napíšte slovo alebo meno do poľa vyššie a prehľadáme stránky aj články Mamateyky.</p>
                    <?php endif; ?>
                </div>
            <?php elseif ( 0 === $pages_count && 0 === $posts_count ) : ?>
                <div class="msr-empty">
                    <span class="msr-icon msr-tone--orange"><?php echo msr_icon( 'search' ); ?></span>
                    <h2>Nič sme nenašli</h2>
                    <p>Pre výraz „<?php echo esc_html( $query ); ?>“ sme nenašli žiadnu stránku ani článok. Skúste iné slovo, kratší výraz alebo skontrolujte preklepy.</p>
                    <div class="msr-empty__links">
                        <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Domov</a>
                        <a href="<?php echo esc_url( home_url( '/aktuality/' ) ); ?>">Aktuality</a>
                    </div>
                </div>
            <?php else : ?>

                <?php if ( $pages_count ) : ?>
                    <section class="msr-section" id="msr-pages" aria-labelledby="msr-pages-title">
                        <div class="msr-panel">
                            <div class="msr-section__head">
                                <h2 class="msr-section__title" id="msr-pages-title">Nájdené stránky:</h2>
                                <p class="msr-section__lead">Našli sme <strong><?php echo (int) $pages_count; ?></strong> <?php echo esc_html( msr_plural( $pages_count, 'podstránku, ktorá obsahuje', 'podstránky, ktoré obsahujú', 'podstránok, ktoré obsahujú' ) ); ?> hľadaný výraz.</p>
                            </div>
                            <div class="msr-grid">
                                <?php
                                foreach ( $page_list as $index => $page ) {
                                    echo msr_render_page_card( $page, $terms, $index >= $visible ); // phpcs:ignore WordPress.Security.EscapeOutput
                                }
                                ?>
                            </div>
                            <?php if ( $pages_count > $visible ) : ?>
                                <button type="button" class="msr-more" data-msr-more>Zobraziť všetky stránky (<?php echo (int) $pages_count; ?>)</button>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ( $posts_count ) : ?>
                    <section class="msr-section" id="msr-articles" aria-labelledby="msr-articles-title">
                        <div class="msr-section__head">
                            <h2 class="msr-section__title" id="msr-articles-title">Nájdené články:</h2>
                            <?php echo $posts_count > 1 ? msr_render_sort( $query, $sort ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <p class="msr-section__lead">Našli sme <strong><?php echo (int) $posts_count; ?></strong> <?php echo esc_html( msr_plural( $posts_count, 'článok, ktorý obsahuje', 'články, ktoré obsahujú', 'článkov, ktoré obsahujú' ) ); ?> hľadaný výraz.</p>
                        </div>
                        <div class="msr-grid">
                            <?php
                            foreach ( $posts->posts as $post_item ) {
                                echo msr_render_post_card( $post_item, $terms ); // phpcs:ignore WordPress.Security.EscapeOutput
                            }
                            ?>
                        </div>
                        <?php echo msr_render_pagination( $query, $paged, (int) $posts->max_num_pages, $sort ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </section>
                <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
        return $output . ob_get_clean();
    } );
}
