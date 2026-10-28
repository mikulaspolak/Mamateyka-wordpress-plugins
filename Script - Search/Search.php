<?php
/*
 * Mamateyka - vyhľadávanie (jeden snippet pre celé vyhľadávanie).
 *
 * Shortcody:
 *   [mamateyka_search]          lupa a rozbaľovací panel so živými návrhmi (vkladá sa do navigácie)
 *                               voliteľne: placeholder="…" recent="5"
 *                               links="Názov|/adresa;Názov 2|/adresa-2" (rýchle odkazy v prázdnom poli)
 *   [mamateyka_search_results]  stránka výsledkov vyhľadávania (voliteľne pages_visible="6" per_page="9")
 *
 * REST endpoint pre návrhy: /wp-json/mamateyka/v1/search?q=…
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

    /*
     * Úryvok so zvýraznením: vyberie text s najväčším počtom hľadaných výrazov a okno
     * dlhé $max znakov, ktoré ich obsahuje čo najviac a začína tesne pred prvou zhodou,
     * aby zvýraznené slovo bolo viditeľné hneď na začiatku.
     */
    function msr_snippet( array $sources, array $terms, $max = 170, $lead = 35 ) {
        $chosen      = '';
        $chosen_fold = '';
        $best        = -1;
        foreach ( $sources as $source ) {
            $source = trim( (string) $source );
            if ( '' === $source ) {
                continue;
            }
            $fold  = msr_fold( $source );
            $count = 0;
            foreach ( $terms as $term ) {
                if ( false !== mb_strpos( $fold, msr_fold( $term ), 0, 'UTF-8' ) ) {
                    $count++;
                }
            }
            if ( $count > $best ) {
                $best        = $count;
                $chosen      = $source;
                $chosen_fold = $fold;
            }
        }
        if ( '' === $chosen ) {
            return '';
        }

        $len = mb_strlen( $chosen, 'UTF-8' );

        // Kandidáti na okno: výskyty výrazov (max. 40) a okno s najviac rôznymi výrazmi.
        $positions = [];
        foreach ( $terms as $term ) {
            $needle = msr_fold( $term );
            $nlen   = mb_strlen( $needle, 'UTF-8' );
            $offset = 0;
            $taken  = 0;
            while ( $nlen > 0 && $taken < 10 && false !== ( $pos = mb_strpos( $chosen_fold, $needle, $offset, 'UTF-8' ) ) ) {
                $positions[] = $pos;
                $offset      = $pos + $nlen;
                $taken++;
            }
        }
        sort( $positions );
        $positions = array_slice( $positions, 0, 40 );

        $start = 0;
        if ( $positions ) {
            $best_score = -1;
            foreach ( $positions as $candidate ) {
                $from  = max( 0, $candidate - $lead );
                $slice = mb_substr( $chosen_fold, $from, $max, 'UTF-8' );
                $score = 0;
                foreach ( $terms as $term ) {
                    if ( false !== mb_strpos( $slice, msr_fold( $term ), 0, 'UTF-8' ) ) {
                        $score++;
                    }
                }
                if ( $score > $best_score ) {
                    $best_score = $score;
                    $start      = $from;
                }
            }
        }

        // Začiatok okna posunúť na začiatok slova, ale nie za prvú zhodu.
        if ( $start > 0 ) {
            $first = $start;
            foreach ( $positions as $candidate ) {
                if ( $candidate >= $start ) {
                    $first = $candidate;
                    break;
                }
            }
            $space = mb_strpos( $chosen, ' ', $start, 'UTF-8' );
            if ( false !== $space && $space - $start < 15 && $space + 1 <= $first ) {
                $start = $space + 1;
            }
        }

        $slice = mb_substr( $chosen, $start, $max, 'UTF-8' );
        $end   = $start + mb_strlen( $slice, 'UTF-8' );
        // Koniec okna orezať na celé slovo.
        if ( $end < $len ) {
            $cut = mb_strrpos( $slice, ' ', 0, 'UTF-8' );
            if ( false !== $cut && $cut > $max * 0.6 ) {
                $slice = mb_substr( $slice, 0, $cut, 'UTF-8' );
                $end   = $start + $cut;
            }
        }
        $slice  = trim( $slice );
        $prefix = $start > 0 ? '… ' : '';
        $suffix = ( $end < $len ) ? ' …' : '';

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
            'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
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
            190
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
        $snippet = msr_snippet( [ msr_plain( $post->post_excerpt ), msr_plain( $post->post_content ) ], $terms, 230 );

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
                --msr-muted: #5f6a73;
                --msr-blue: #537891;
                --msr-blue-dark: #3f6174;
                --msr-border: #dfe8ee;
                --msr-soft: #eaf1f5;
                --msr-surface: #fff;
                --msr-w-heading: 600;
                --msr-w-medium: 500;
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
                background: rgba(83, 120, 145, .2);
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
                font-weight: var(--msr-w-medium);
                letter-spacing: .1em;
                text-transform: uppercase;
            }
            .msr-eyebrow svg { width: 18px; height: 18px; }
            .msr-title {
                color: var(--msr-ink);
                font-size: clamp(1.9rem, 4.5vw, 2.75rem);
                font-weight: var(--msr-w-heading);
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
                font-weight: var(--msr-w-medium);
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
                color: var(--msr-ink);
                font-size: clamp(1.5rem, 3vw, 2rem);
                font-weight: var(--msr-w-heading);
                line-height: 1.2;
            }
            .msr-section__lead { flex-basis: 100%; color: var(--msr-muted); }
            .msr-section__lead strong { color: var(--msr-ink); font-weight: var(--msr-w-medium); }

            .msr-sort {
                display: inline-flex;
                padding: 3px;
                border-radius: 999px;
                background: var(--msr-soft);
            }
            .msr-sort__option, .msr a.msr-sort__option {
                padding: .4rem 1rem;
                border-radius: 999px;
                color: var(--msr-blue-dark);
                font-size: .88rem;
                font-weight: var(--msr-w-medium);
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

            .msr a.msr-pagecard {
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
                color: var(--msr-ink);
                font-size: 1.15rem;
                font-weight: var(--msr-w-medium);
                line-height: 1.25;
                overflow-wrap: anywhere;
            }
            .msr-pagecard__text {
                display: -webkit-box;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 3;
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
            .msr a.msr-post__link {
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
                color: var(--msr-ink);
                font-size: 1.25rem;
                font-weight: var(--msr-w-medium);
                line-height: 1.25;
                overflow-wrap: anywhere;
            }
            .msr-post__text {
                display: -webkit-box;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 4;
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
                font-weight: var(--msr-w-medium);
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
            .msr-pagination__num, .msr-pagination__nav, .msr-pagination__gap,
            .msr a.msr-pagination__num, .msr a.msr-pagination__nav {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 42px;
                height: 42px;
                padding: 0 .85rem;
                border-radius: 12px;
                color: var(--msr-blue-dark);
                font-weight: var(--msr-w-medium);
                text-decoration: none;
            }
            .msr-pagination__num, .msr-pagination__nav { background: var(--msr-surface); box-shadow: var(--msr-shadow); transition: background .2s ease, color .2s ease; }
            .msr .msr-pagination a:hover { background: var(--msr-blue); color: #fff; }
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
            .msr-empty h2 { margin-bottom: .5rem; color: var(--msr-ink); font-size: clamp(1.4rem, 3vw, 1.8rem); font-weight: var(--msr-w-heading); }
            .msr-empty p { max-width: 520px; margin: 0 auto; color: var(--msr-muted); }
            .msr-empty__links { display: flex; flex-wrap: wrap; justify-content: center; gap: .6rem; margin-top: 1.25rem; }
            .msr .msr-empty__links a {
                padding: .55rem 1.1rem;
                border-radius: 999px;
                background: var(--msr-blue);
                color: #fff;
                font-weight: var(--msr-w-medium);
                text-decoration: none;
                transition: background .2s ease;
            }
            .msr .msr-empty__links a:hover { background: var(--msr-blue-dark); color: #fff; }

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
                    <span class="msr-icon msr-tone--blue"><?php echo msr_icon( 'search' ); ?></span>
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

    /* =====================================================================
     * Živé vyhľadávanie v navigácii: [mamateyka_search]
     * ================================================================== */

    function msr_live_version() {
        return (string) get_option( 'msr_live_ver', '1' );
    }

    // Zmena obsahu zneplatní cache návrhov.
    function msr_live_bump( $post_id ) {
        if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
            return;
        }
        update_option( 'msr_live_ver', (string) microtime( true ) );
    }
    add_action( 'save_post', 'msr_live_bump' );
    add_action( 'deleted_post', 'msr_live_bump' );
    add_action( 'trashed_post', 'msr_live_bump' );

    // Max. 4 stránky a 4 články + celkové počty; rovnaké pravidlá ako stránka výsledkov.
    function msr_live_search( $raw_query ) {
        $terms = msr_terms( $raw_query );
        if ( ! $terms ) {
            return [ 'pages' => [], 'posts' => [], 'pages_total' => 0, 'posts_total' => 0 ];
        }

        $key    = 'msr_live_' . md5( msr_live_version() . '|' . msr_fold( implode( ' ', $terms ) ) );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        msr_ensure_index();
        $all_pages = msr_rank_pages( msr_query( 'page', $terms, 60 )->posts, $terms );
        $posts_q   = msr_query( 'post', $terms, 4 );

        $pages = [];
        foreach ( array_slice( $all_pages, 0, 4 ) as $page ) {
            $crumbs = [];
            foreach ( array_reverse( get_post_ancestors( $page ) ) as $ancestor_id ) {
                $crumbs[] = msr_title( $ancestor_id );
            }
            $index   = (string) get_post_meta( $page->ID, MSR_INDEX_KEY, true );
            $pages[] = [
                'title'   => msr_highlight( msr_title( $page ), $terms ),
                'url'     => get_permalink( $page ),
                'crumb'   => implode( ' › ', $crumbs ),
                'snippet' => msr_snippet( [ msr_plain( $page->post_excerpt ), $index, msr_plain( $page->post_content ) ], $terms, 140 ),
            ];
        }

        $posts = [];
        foreach ( $posts_q->posts as $post ) {
            $thumb   = get_the_post_thumbnail_url( $post->ID, 'thumbnail' );
            $posts[] = [
                'title' => msr_highlight( msr_title( $post ), $terms ),
                'url'   => get_permalink( $post ),
                'date'  => get_the_date( 'd.m.Y', $post ),
                'thumb' => $thumb ? $thumb : '',
            ];
        }

        $data = [
            'pages'       => $pages,
            'posts'       => $posts,
            'pages_total' => count( $all_pages ),
            'posts_total' => (int) $posts_q->found_posts,
        ];
        set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
        return $data;
    }

    add_action( 'rest_api_init', function () {
        register_rest_route( 'mamateyka/v1', '/search', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => [
                'q' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
            'callback'            => function ( WP_REST_Request $request ) {
                $query    = mb_substr( (string) $request->get_param( 'q' ), 0, 100, 'UTF-8' );
                $response = rest_ensure_response( msr_live_search( $query ) );
                $response->header( 'Cache-Control', 'public, max-age=60' );
                return $response;
            },
        ] );
    } );

    // "Názov|/adresa;Názov 2|/adresa-2" -> [ [názov, url], ... ]
    function msl_parse_links( $value ) {
        $links = [];
        foreach ( explode( ';', (string) $value ) as $part ) {
            $pieces = array_map( 'trim', explode( '|', $part, 2 ) );
            if ( 2 !== count( $pieces ) || '' === $pieces[0] || '' === $pieces[1] ) {
                continue;
            }
            $url     = ( 0 === strpos( $pieces[1], '/' ) ) ? home_url( $pieces[1] ) : $pieces[1];
            $links[] = [ $pieces[0], $url ];
        }
        return $links;
    }

    function msl_assets() {
        ob_start();
        ?>
        <style>
            .msl {
                --msl-ink: #1d2630;
                --msl-muted: #5f6a73;
                --msl-blue: #537891;
                --msl-blue-dark: #3f6174;
                --msl-border: #dfe8ee;
                --msl-soft: #eef4f8;
                position: relative;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                color: var(--msl-ink);
                font-family: "Fira Sans", sans-serif;
                font-size: 1rem;
                line-height: 1.4;
            }
            .msl *, .msl *::before, .msl *::after { box-sizing: border-box; }
            .msl svg { display: block; flex: none; }
            .msl mark { padding: 0 .1em; border-radius: 3px; background: rgba(83, 120, 145, .2); color: inherit; }

            .msl__button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 44px;
                height: 44px;
                margin: 0;
                padding: 0;
                border: 0;
                border-radius: 0;
                background: transparent;
                color: currentColor;
                cursor: pointer;
            }
            .msl__button:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
            .msl__button svg { width: 22px; height: 22px; fill: var(--msl-blue); }
            @media (max-width: 767px) { .msl__button { width: 36px; } }

            .msl__panel {
                position: absolute;
                top: calc(100% + 8px);
                right: 0;
                z-index: 9999;
                width: min(420px, calc(100vw - 20px));
                border: 1px solid var(--msl-border);
                border-radius: 18px;
                background: #fff;
                box-shadow: 0 18px 40px rgba(29, 38, 48, .16);
                overflow: hidden;
                text-align: left;
                visibility: hidden;
                opacity: 0;
                pointer-events: none;
                transform: translateY(-8px);
                transition: opacity .18s ease, transform .18s ease, visibility 0s linear .18s;
            }
            .msl.is-open .msl__panel {
                visibility: visible;
                opacity: 1;
                pointer-events: auto;
                transform: none;
                transition: opacity .18s ease, transform .18s ease, visibility 0s;
            }

            .msl__form { display: flex; align-items: center; gap: .5rem; margin: 0; padding: .75rem; border-bottom: 1px solid var(--msl-border); }
            .msl__input {
                flex: 1 1 auto;
                min-width: 0;
                height: 44px;
                margin: 0;
                padding: 0 .9rem;
                border: 1.5px solid rgba(83, 120, 145, .25);
                border-radius: 12px;
                background: #fff;
                color: var(--msl-ink);
                font: inherit;
                font-size: 16px;
                box-shadow: none;
                outline: none;
                -webkit-appearance: none;
                appearance: none;
            }
            .msl__input:focus { border-color: var(--msl-blue); box-shadow: 0 0 0 3px rgba(83, 120, 145, .16); }
            .msl__submit {
                display: inline-flex;
                flex: none;
                align-items: center;
                justify-content: center;
                width: 44px;
                height: 44px;
                margin: 0;
                padding: 0;
                border: 0;
                border-radius: 12px;
                background: var(--msl-blue);
                color: #fff;
                cursor: pointer;
                transition: background .2s ease;
            }
            .msl__submit:hover { background: var(--msl-blue-dark); }
            .msl__submit svg { width: 18px; height: 18px; }

            .msl__body { max-height: var(--msl-body-max, 60vh); padding: .4rem .5rem .6rem; overflow-y: auto; overscroll-behavior: contain; transition: opacity .15s ease; }
            .msl.is-loading .msl__body { opacity: .6; }
            .msl__heading {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: .6rem .5rem .3rem;
                color: var(--msl-muted);
                font-size: .72rem;
                font-weight: 500;
                letter-spacing: .08em;
                text-transform: uppercase;
            }
            .msl__clear { margin: 0; padding: 0; border: 0; background: transparent; color: var(--msl-blue); font: inherit; font-size: .78rem; font-weight: 500; letter-spacing: 0; text-transform: none; cursor: pointer; }
            .msl__clear:hover { text-decoration: underline; }

            .msl a.msl__item {
                display: flex;
                align-items: center;
                gap: .75rem;
                padding: .55rem .6rem;
                border-radius: 12px;
                color: var(--msl-ink);
                text-decoration: none;
            }
            .msl a.msl__item:hover, .msl a.msl__item.is-active { background: var(--msl-soft); color: var(--msl-ink); }
            .msl a.msl__item:focus-visible { outline: 2px solid var(--msl-blue); outline-offset: -2px; }
            .msl__icon {
                display: inline-flex;
                flex: none;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                overflow: hidden;
                border-radius: 10px;
                background: #dbe8f1;
                color: #2f6b94;
            }
            .msl__icon img { width: 100%; height: 100%; object-fit: cover; }
            .msl__icon svg { width: 20px; height: 20px; }
            .msl__text { display: flex; flex: 1 1 auto; flex-direction: column; min-width: 0; }
            .msl__title { overflow: hidden; font-weight: 500; text-overflow: ellipsis; white-space: nowrap; }
            .msl__meta { overflow: hidden; color: var(--msl-muted); font-size: .8rem; text-overflow: ellipsis; white-space: nowrap; }
            .msl__snippet { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; color: var(--msl-muted); font-size: .85rem; line-height: 1.4; }
            .msl__recent-item svg { width: 18px; height: 18px; color: var(--msl-muted); }
            .msl a.msl__recent-item { gap: .6rem; padding: .45rem .6rem; }

            .msl__chips { display: flex; flex-wrap: wrap; gap: .4rem; padding: .25rem .5rem .5rem; }
            .msl a.msl__chip {
                padding: .4rem .9rem;
                border-radius: 999px;
                background: var(--msl-soft);
                color: var(--msl-blue-dark);
                font-size: .9rem;
                font-weight: 500;
                text-decoration: none;
                transition: background .2s ease, color .2s ease;
            }
            .msl a.msl__chip:hover, .msl a.msl__chip.is-active { background: var(--msl-blue); color: #fff; }
            .msl__empty { padding: 1.1rem .75rem; color: var(--msl-muted); text-align: center; }

            .msl a.msl__all {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: .5rem;
                padding: .8rem 1rem;
                border-top: 1px solid var(--msl-border);
                background: var(--msl-soft);
                color: var(--msl-blue-dark);
                font-weight: 500;
                text-decoration: none;
            }
            .msl a.msl__all:hover, .msl a.msl__all.is-active { background: #e2ecf2; color: var(--msl-blue-dark); }
            .msl__all svg { width: 16px; height: 16px; }
            .msl a.msl__all[hidden], .msl [hidden] { display: none; }
            .msl__status { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

            @media (prefers-reduced-motion: reduce) {
                .msl *, .msl *::before, .msl *::after { transition: none !important; }
            }
        </style>
        <script>
            (function () {
                // Zatvorenie mobilnej klávesnice mení iba výšku viewportu; ScrollTrigger to nesmie brať ako zmenu rozloženia.
                function applyConfig() {
                    if (window.ScrollTrigger) { ScrollTrigger.config({ ignoreMobileResize: true }); }
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', applyConfig);
                } else {
                    applyConfig();
                }
                window.addEventListener('load', applyConfig);
                document.addEventListener('focusout', function (e) {
                    if (e.target.matches && e.target.matches('input[type="search"], input[type="text"]') && window.ScrollTrigger) {
                        setTimeout(function () { ScrollTrigger.refresh(); }, 300);
                    }
                });

                function el(tag, cls, text) {
                    var node = document.createElement(tag);
                    if (cls) { node.className = cls; }
                    if (text) { node.textContent = text; }
                    return node;
                }

                function init(root) {
                    var q = function (s) { return root.querySelector(s); };
                    var button = q('[data-msl-button]'), panel = q('[data-msl-panel]'), input = q('[data-msl-input]'),
                        form = q('[data-msl-form]'), body = q('[data-msl-body]'), initial = q('[data-msl-initial]'),
                        results = q('[data-msl-results]'), footer = q('[data-msl-all]'), status = q('[data-msl-status]'),
                        recentBox = q('[data-msl-recent]'), recentList = q('[data-msl-recent-list]'), clearBtn = q('[data-msl-clear]');
                    if (!button || !panel || !input || !results) { return; }

                    var endpoint = root.getAttribute('data-endpoint');
                    var action = root.getAttribute('data-action') || '/';
                    var maxRecent = parseInt(root.getAttribute('data-recent'), 10) || 5;
                    var icons = {};
                    try { icons = JSON.parse(root.getAttribute('data-icons') || '{}'); } catch (e) { icons = {}; }

                    var MIN = 2, DELAY = 250, STORE = 'msl_recent';
                    var timer = 0, controller = null, cache = {}, active = -1, ignoreUntil = 0, seq = 0;

                    function allUrl(term) { return action + (action.indexOf('?') > -1 ? '&' : '?') + 's=' + encodeURIComponent(term); }
                    function icon(name) { var s = el('span', 'msl__icon'); s.innerHTML = icons[name] || ''; return s; }

                    /* --- posledné hľadania --- */
                    function getRecent() {
                        try {
                            var list = JSON.parse(localStorage.getItem(STORE) || '[]');
                            return Array.isArray(list) ? list.filter(function (x) { return typeof x === 'string'; }).slice(0, maxRecent) : [];
                        } catch (e) { return []; }
                    }
                    function saveRecent(term) {
                        term = (term || '').trim();
                        if (term.length < MIN) { return; }
                        try {
                            var list = getRecent().filter(function (x) { return x.toLowerCase() !== term.toLowerCase(); });
                            list.unshift(term);
                            localStorage.setItem(STORE, JSON.stringify(list.slice(0, maxRecent)));
                        } catch (e) { /* súkromný režim: preskočiť */ }
                    }
                    function renderRecent() {
                        if (!recentBox || !recentList) { return; }
                        var list = getRecent();
                        recentList.textContent = '';
                        recentBox.hidden = list.length === 0;
                        list.forEach(function (term) {
                            var a = navLink(allUrl(term), 'msl__item msl__recent-item');
                            var ic = el('span'); ic.innerHTML = icons.clock || '';
                            a.appendChild(ic);
                            a.appendChild(el('span', 'msl__title', term));
                            recentList.appendChild(a);
                        });
                    }

                    /* --- navigácia klávesnicou --- */
                    function navLink(url, cls) {
                        var a = el('a', cls);
                        a.href = url;
                        a.id = root.id + '-o' + (seq++);
                        a.setAttribute('role', 'option');
                        a.setAttribute('data-msl-nav', '');
                        return a;
                    }
                    function navItems() {
                        var scope = results.hidden ? initial : results;
                        var items = Array.prototype.slice.call(scope.querySelectorAll('[data-msl-nav]'));
                        if (!footer.hidden) { items.push(footer); }
                        return items;
                    }
                    function setActive(index) {
                        var items = navItems();
                        items.forEach(function (item) { item.classList.remove('is-active'); });
                        active = index;
                        if (index >= 0 && items[index]) {
                            items[index].classList.add('is-active');
                            input.setAttribute('aria-activedescendant', items[index].id);
                            items[index].scrollIntoView({ block: 'nearest' });
                        } else {
                            active = -1;
                            input.removeAttribute('aria-activedescendant');
                        }
                    }

                    /* --- zobrazenia --- */
                    function say(text) { if (status) { status.textContent = text; } }
                    function showInitial() {
                        if (controller) { controller.abort(); }
                        root.classList.remove('is-loading');
                        results.hidden = true;
                        results.textContent = '';
                        footer.hidden = true;
                        initial.hidden = false;
                        setActive(-1);
                    }
                    function group(label, count, nodes) {
                        var head = el('div', 'msl__heading');
                        head.appendChild(el('span', '', label + ' · ' + count));
                        results.appendChild(head);
                        nodes.forEach(function (n) { results.appendChild(n); });
                    }
                    function showMessage(text, term) {
                        results.textContent = '';
                        results.appendChild(el('div', 'msl__empty', text));
                        results.hidden = false;
                        initial.hidden = true;
                        footer.hidden = !term;
                        if (term) { footer.href = allUrl(term); footer.firstChild.textContent = 'Hľadať na celom webe'; }
                        setActive(-1);
                        say(text);
                    }
                    function render(data, term) {
                        results.textContent = '';
                        if (!data.pages.length && !data.posts.length) {
                            showMessage('Nič sme nenašli. Skúste iné slovo alebo kratší výraz.', '');
                            return;
                        }
                        if (data.pages.length) {
                            group('Stránky', data.pages_total, data.pages.map(function (p) {
                                var a = navLink(p.url, 'msl__item');
                                a.appendChild(icon('file'));
                                var t = el('span', 'msl__text');
                                var title = el('span', 'msl__title'); title.innerHTML = p.title; t.appendChild(title);
                                if (p.crumb) { t.appendChild(el('span', 'msl__meta', p.crumb)); }
                                if (p.snippet) { var sn = el('span', 'msl__snippet'); sn.innerHTML = p.snippet; t.appendChild(sn); }
                                a.appendChild(t);
                                return a;
                            }));
                        }
                        if (data.posts.length) {
                            group('Články', data.posts_total, data.posts.map(function (p) {
                                var a = navLink(p.url, 'msl__item');
                                var ic = icon('news');
                                if (p.thumb) { ic.textContent = ''; var img = el('img'); img.src = p.thumb; img.alt = ''; img.loading = 'lazy'; ic.appendChild(img); }
                                a.appendChild(ic);
                                var t = el('span', 'msl__text');
                                var title = el('span', 'msl__title'); title.innerHTML = p.title; t.appendChild(title);
                                t.appendChild(el('span', 'msl__meta', p.date));
                                a.appendChild(t);
                                return a;
                            }));
                        }
                        results.hidden = false;
                        initial.hidden = true;
                        footer.href = allUrl(term);
                        footer.firstChild.textContent = 'Zobraziť všetky výsledky (' + (data.pages_total + data.posts_total) + ')';
                        footer.hidden = false;
                        setActive(-1);
                        say('Nájdené: ' + data.pages_total + ' stránok, ' + data.posts_total + ' článkov.');
                    }

                    function run(term) {
                        if (term.length < MIN) { showInitial(); return; }
                        if (cache[term]) { render(cache[term], term); return; }
                        if (controller) { controller.abort(); }
                        controller = window.AbortController ? new AbortController() : null;
                        root.classList.add('is-loading');
                        var url = endpoint + (endpoint.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(term);
                        fetch(url, { signal: controller ? controller.signal : undefined, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                            .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
                            .then(function (data) {
                                root.classList.remove('is-loading');
                                cache[term] = data;
                                if (input.value.trim() === term) { render(data, term); }
                            })
                            .catch(function (err) {
                                if (err && err.name === 'AbortError') { return; }
                                root.classList.remove('is-loading');
                                if (input.value.trim() === term) {
                                    showMessage('Vyhľadávanie sa nepodarilo. Stlačte Enter a zobrazíme všetky výsledky.', term);
                                }
                            });
                    }

                    /* --- otváranie a umiestnenie --- */
                    function layout() {
                        var vw = document.documentElement.clientWidth;
                        var width = Math.min(420, vw - 20);
                        var rect = root.getBoundingClientRect();
                        var left = Math.max(10, Math.min(rect.right - width, vw - width - 10));
                        panel.style.width = width + 'px';
                        panel.style.right = 'auto';
                        panel.style.left = (left - rect.left) + 'px';

                        var header = root.closest('section, header');
                        var anchorBottom = header ? header.getBoundingClientRect().bottom : rect.bottom;
                        var top = Math.max(rect.bottom, anchorBottom) + 6;
                        panel.style.top = (top - rect.top) + 'px';
                        var vh = window.visualViewport ? window.visualViewport.height : window.innerHeight;
                        panel.style.setProperty('--msl-body-max', Math.max(180, vh - top - 140) + 'px');
                    }
                    function open() {
                        layout();
                        root.classList.add('is-open');
                        button.setAttribute('aria-expanded', 'true');
                        input.setAttribute('aria-expanded', 'true');
                        ignoreUntil = Date.now() + 350;
                        renderRecent();
                        var term = input.value.trim();
                        if (term.length >= MIN) { run(term); } else { showInitial(); }
                        // Zaostrenie priamo v kliknutí, inak mobilný prehliadač klávesnicu nezobrazí.
                        input.focus({ preventScroll: true });
                    }
                    function close() {
                        root.classList.remove('is-open');
                        button.setAttribute('aria-expanded', 'false');
                        input.setAttribute('aria-expanded', 'false');
                        if (controller) { controller.abort(); }
                        root.classList.remove('is-loading');
                        setActive(-1);
                    }
                    function isOpen() { return root.classList.contains('is-open'); }

                    button.addEventListener('click', function (event) {
                        event.stopPropagation();
                        if (isOpen()) { close(); } else { open(); }
                    });
                    document.addEventListener('pointerdown', function (event) {
                        if (!isOpen() || Date.now() < ignoreUntil || root.contains(event.target)) { return; }
                        close();
                    });
                    document.addEventListener('keydown', function (event) {
                        if (event.key === 'Escape' && isOpen()) { close(); button.focus(); }
                    });
                    window.addEventListener('resize', function () { if (isOpen()) { layout(); } });
                    if (window.visualViewport) {
                        window.visualViewport.addEventListener('resize', function () { if (isOpen()) { layout(); } });
                    }

                    input.addEventListener('input', function () {
                        clearTimeout(timer);
                        var term = input.value.trim();
                        if (term.length < MIN) { showInitial(); return; }
                        timer = setTimeout(function () { run(term); }, DELAY);
                    });
                    input.addEventListener('keydown', function (event) {
                        var items = navItems();
                        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                            if (!items.length) { return; }
                            event.preventDefault();
                            var step = event.key === 'ArrowDown' ? 1 : -1;
                            var next = active + step;
                            if (next < 0) { next = items.length - 1; }
                            if (next >= items.length) { next = 0; }
                            setActive(next);
                        } else if (event.key === 'Enter' && active >= 0 && items[active]) {
                            event.preventDefault();
                            saveRecent(input.value);
                            window.location.href = items[active].href;
                        }
                    });
                    form.addEventListener('submit', function () { saveRecent(input.value); });
                    panel.addEventListener('click', function (event) {
                        var link = event.target.closest ? event.target.closest('a[data-msl-nav], a[data-msl-all]') : null;
                        if (link && !link.classList.contains('msl__recent-item')) { saveRecent(input.value); }
                    });
                    if (clearBtn) {
                        clearBtn.addEventListener('click', function () {
                            try { localStorage.removeItem(STORE); } catch (e) { /* ignorovať */ }
                            renderRecent();
                            input.focus({ preventScroll: true });
                        });
                    }
                }

                function start() {
                    document.querySelectorAll('[data-msl]').forEach(init);
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', start);
                } else {
                    start();
                }
            })();
        </script>
        <?php
        return ob_get_clean();
    }

    add_shortcode( 'mamateyka_search', function ( $atts = [] ) {
        $atts = shortcode_atts( [
            'placeholder' => 'Hľadať na webe…',
            'links'       => 'Farské oznamy|/farnost/#sekcia-oznamy;Kalendár|/kalendar/;Aktuality|/aktuality/;Kontakt|/kontakt/;Podpor nás|/podpor-nas/',
            'recent'      => 5,
        ], $atts, 'mamateyka_search' );

        static $assets_printed = false;
        static $instance = 0;
        $instance++;
        $id     = 'msl-' . $instance;
        $output = '';
        if ( ! $assets_printed ) {
            $assets_printed = true;
            $output         = msl_assets();
        }

        $icons = [
            'file'  => msr_icon( 'file' ),
            'news'  => msr_icon( 'news' ),
            'clock' => msr_icon( 'clock' ),
        ];
        $prefill = is_search() ? (string) get_search_query( false ) : '';
        $links   = msl_parse_links( $atts['links'] );

        ob_start();
        ?>
        <div class="msl" id="<?php echo esc_attr( $id ); ?>" data-msl
            data-endpoint="<?php echo esc_attr( wp_make_link_relative( rest_url( 'mamateyka/v1/search' ) ) ); ?>"
            data-action="<?php echo esc_attr( wp_make_link_relative( home_url( '/' ) ) ); ?>"
            data-recent="<?php echo (int) $atts['recent']; ?>"
            data-icons="<?php echo esc_attr( wp_json_encode( $icons ) ); ?>">
            <button type="button" class="msl__button" data-msl-button aria-label="Zobraziť vyhľadávanie" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>-panel">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" aria-hidden="true" focusable="false"><path d="M460.355,421.59L353.844,315.078c20.041-27.553,31.885-61.437,31.885-98.037C385.729,124.934,310.793,50,218.686,50C126.58,50,51.645,124.934,51.645,217.041c0,92.106,74.936,167.041,167.041,167.041c34.912,0,67.352-10.773,94.184-29.158L419.945,462L460.355,421.59z M100.631,217.041c0-65.096,52.959-118.056,118.055-118.056c65.098,0,118.057,52.959,118.057,118.056c0,65.096-52.959,118.056-118.057,118.056C153.59,335.097,100.631,282.137,100.631,217.041z"></path></svg>
            </button>
            <div class="msl__panel" id="<?php echo esc_attr( $id ); ?>-panel" data-msl-panel role="dialog" aria-label="Vyhľadávanie">
                <form class="msl__form" data-msl-form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                    <input class="msl__input" data-msl-input type="search" name="s" value="<?php echo esc_attr( $prefill ); ?>" placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>" aria-label="Hľadať na webe" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>-results" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search">
                    <button class="msl__submit" type="submit" aria-label="Hľadať"><?php echo msr_icon( 'search' ); ?></button>
                </form>
                <div class="msl__body" data-msl-body>
                    <div data-msl-initial>
                        <div data-msl-recent hidden>
                            <div class="msl__heading"><span>Posledné hľadania</span><button type="button" class="msl__clear" data-msl-clear>Vymazať</button></div>
                            <div data-msl-recent-list role="listbox" aria-label="Posledné hľadania"></div>
                        </div>
                        <?php if ( $links ) : ?>
                            <div class="msl__heading"><span>Rýchle odkazy</span></div>
                            <div class="msl__chips" role="listbox" aria-label="Rýchle odkazy">
                                <?php foreach ( $links as $index => $link ) : ?>
                                    <a class="msl__chip" role="option" data-msl-nav id="<?php echo esc_attr( $id . '-q' . $index ); ?>" href="<?php echo esc_url( $link[1] ); ?>"><?php echo esc_html( $link[0] ); ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div data-msl-results id="<?php echo esc_attr( $id ); ?>-results" role="listbox" aria-label="Návrhy" hidden></div>
                </div>
                <a class="msl__all" data-msl-all id="<?php echo esc_attr( $id ); ?>-all" role="option" href="<?php echo esc_url( home_url( '/' ) ); ?>" hidden><span>Zobraziť všetky výsledky</span><?php echo msr_icon( 'arrow' ); ?></a>
                <div class="msl__status" data-msl-status role="status" aria-live="polite"></div>
            </div>
        </div>
        <?php
        return $output . ob_get_clean();
    } );
}
