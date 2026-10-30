<?php

/**
 * [farske_oznamy] shortcode – renders the latest Google Doc with announcement panels.
 *
 * Usage:
 *   [farske_oznamy]                      → newest document (subfolder Oznamy_DD.MM.YYYY)
 *
 * [farske_oznamy_obrazky] shortcode – renders images from the same subfolder.
 *
 * Usage:
 *   [farske_oznamy_obrazky]              → images from the current (latest valid) subfolder
 *
 * [farske_oznamy_buduce] shortcode – previews future announcements before auto-publication.
 *
 * Usage:
 *   [farske_oznamy_buduce]                 → nearest future subfolder
 *
 * [farske_oznamy_buduce_obrazky] shortcode – previews images for future announcements.
 *
 * Usage:
 *   [farske_oznamy_buduce_obrazky]                 → images from nearest future subfolder
 *
 * [farske_oznamy_admin] shortcode – publication controls for visitors who unlocked the protected preparation page.
 *
 * Usage:
 *   [farske_oznamy_admin]                           → version list and publication buttons
 */

if (! defined('ABSPATH')) {
    exit;
}

if (class_exists('Oznamy_Shortcode')) {
    return;
}

class Oznamy_Shortcode
{

    const UNSUPPORTED_ATTS_MESSAGE = 'Tento shortcode už nepodporuje atribúty. Použite ho bez parametrov.';
    const NO_PUBLISHED_ANNOUNCEMENTS_MESSAGE = 'Momentálne nie sú zverejnené žiadne farské oznamy.';
    const NO_FUTURE_ANNOUNCEMENTS_MESSAGE = 'Momentálne nie sú pripravovane žiadne farské oznamy.';
    const DEFAULT_ARCHIVE_LIMIT = 120;
    const MAX_ARCHIVE_LIMIT = 999;
    const FUTURE_MISS_CACHE_TTL = 300;
    const VERSION_STATES_OPTION = 'oznamy_version_states';

    /**
     * [farske_oznamy] shortcode – renders the latest document with announcement panels.
     *
     * @param array<string, string> $atts
     * @return string HTML output
     */
    public static function render_modern($atts)
    {
        return self::render_latest($atts, true);
    }

    /**
     * Register REST route for embedding output into blocks that do not process shortcodes.
     */
    public static function register_embed_route()
    {
        register_rest_route('oznamy-gdocs/v1', '/embed', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'render_embed'),
            'permission_callback' => '__return_true',
            'args' => array(
                'typ' => array(
                    'type' => 'string',
                    'default' => 'oznamy',
                    'enum' => array('oznamy', 'obrazky'),
                ),
            ),
        ));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function render_embed($request)
    {
        if ('obrazky' === $request['typ']) {
            // Display-only embed (monitor vo vestibule): vždy plné originály, nie miniatúry.
            self::$original_images = true;
            $html = self::render_images(array('columns' => '2'));
            self::$original_images = false;
        } else {
            $html = self::render_modern(array());
        }

        $response = new WP_REST_Response(array('html' => $html));
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }

    /**
     * Render the historical archive warning banner.
     *
     * @param array<string, string> $atts
     * @return string HTML output
     */
    public static function render_history_banner($atts)
    {
        if (self::has_unsupported_attributes($atts)) {
            return '<div class="oznamy-content oznamy-error">'
                . esc_html(self::UNSUPPORTED_ATTS_MESSAGE)
                . '</div>';
        }

        self::enqueue_archive_script();

        return self::render_archive_banner();
    }

    /**
     * Render a date picker for the current and archived announcements.
     *
     * @param array<string, string> $atts
     * @return string HTML output
     */
    public static function render_date_picker($atts)
    {
        if (self::has_unsupported_attributes($atts)) {
            return '<div class="oznamy-date-picker-error">'
                . esc_html(self::UNSUPPORTED_ATTS_MESSAGE)
                . '</div>';
        }

        self::enqueue_archive_script();

        $settings = get_option(OZNAMY_OPTION_KEY, array());
        self::schedule_next_release_cache_purge($settings);
        $folder_id = sanitize_text_field($settings['folder_id'] ?? '');
        $sa_data = json_decode($settings['sa_json'] ?? '', true);
        $archive_limit = max(0, min(self::MAX_ARCHIVE_LIMIT, (int) ($settings['archive_count'] ?? 4)));
        $folders = array();
        $archive_folders = array();
        $publication = null;
        $include_future_date_key = null;

        if ($folder_id && is_array($sa_data)) {
            $publication = self::resolve_current_publication(self::resolve_render_mode($settings));
            if (! is_wp_error($publication)) {
                self::prevent_page_cache_for_fallback($publication, $settings);
                try {
                    $today = new DateTimeImmutable('today midnight', wp_timezone());
                } catch (Exception $e) {
                    $today = new DateTimeImmutable('today midnight', new DateTimeZone('UTC'));
                }
                if ((int) $publication['folder']['date'] > $today->getTimestamp()) {
                    $include_future_date_key = wp_date('Y-m-d', (int) $publication['folder']['date']);
                }
            }

            $client = new Oznamy_GDrive_Client($sa_data);
            $result = self::get_archive_folders(
                $client,
                $folder_id,
                max(60, absint($settings['cache_ttl'] ?? 3600)),
                $archive_limit,
                $include_future_date_key
            );
            if (! is_wp_error($result)) {
                $archive_folders = $result;
                $folders = array_values(array_filter($result, static function ($folder) {
                    return empty($folder['ambiguous']);
                }));
            }
        } else {
            Oznamy_Load_Health::record_issue('archive_index', 'Plugin nie je úplne nakonfigurovaný.');
        }

        $latest_data = self::get_archive_latest_data($archive_folders, $publication, $settings);
        $latest_timestamp = $latest_data['timestamp'];
        $latest_date = $latest_data['date'];
        $latest_label = $latest_timestamp
            ? wp_date(get_option('date_format'), $latest_timestamp)
            : __('Najnovšie oznamy', 'oznamy-gdocs');
        $weekday_prefix = __('nedeľa', 'oznamy-gdocs') . ' ';

        $button = '<svg class="oznamy-date-picker__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
            . '<rect x="3.5" y="5" width="17" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/>'
            . '<path d="M7.5 3.5v3M16.5 3.5v3M4 9h16M8 13h2m4 0h2m-8 4h2m4 0h2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '</svg><span class="oznamy-date-picker__label" data-oznamy-picker-label>' . esc_html($latest_label) . '</span>';

        if (0 === $archive_limit || empty($folders)) {
            return '<button type="button" class="oznamy-date-picker__button" disabled>' . $button . '</button>';
        }

        $base_url = remove_query_arg('oznamy');
        $output = '<details class="oznamy-date-picker" data-oznamy-date-picker data-default-date="'
            . esc_attr($latest_date) . '" data-default-date-label="' . esc_attr($latest_label) . '">'
            . '<summary class="oznamy-date-picker__button">' . $button . '</summary>'
            . '<ul class="oznamy-date-picker__menu">'
            . '<li><a href="' . esc_url($base_url) . '" data-oznamy-date-option="latest">'
            . '<span>' . esc_html($weekday_prefix . $latest_label) . '</span></a></li>';

        foreach ($folders as $folder) {
            if ($folder['date_key'] === $latest_date) {
                continue;
            }

            $date_label = $weekday_prefix . wp_date(get_option('date_format'), (int) $folder['date']);
            $url = add_query_arg('oznamy', $folder['date_key'], $base_url);
            $output .= '<li><a href="' . esc_url($url) . '" data-oznamy-date-option="'
                . esc_attr($folder['date_key']) . '"><span>' . esc_html($date_label) . '</span></a></li>';
        }

        return $output . '</ul></details>';
    }

    /**
     * Render the latest document for the modern text shortcode.
     *
     * @param array<string, string> $atts
     * @param bool $modern
     * @return string
     */
    private static function render_latest($atts, $modern)
    {
        if (self::has_unsupported_attributes($atts)) {
            return '<div class="oznamy-content oznamy-error">'
                . esc_html(self::UNSUPPORTED_ATTS_MESSAGE)
                . '</div>';
        }

        $settings = get_option(OZNAMY_OPTION_KEY, array());
        self::schedule_next_release_cache_purge($settings);
        $render_mode = self::resolve_render_mode($settings);

        $publication = self::resolve_current_publication($render_mode);
        self::prevent_page_cache_for_fallback($publication, $settings);
        $slug = self::get_current_cycle_slug($settings, $publication);
        $stale_slug = 'latest';
        $cache_key = OZNAMY_CACHE_KEY_PREFIX . $render_mode . '_' . $slug;
        $cached    = get_transient($cache_key);

        if (false !== $cached) {
            return self::render_latest_output($cached, $modern);
        }

        $html = self::fetch_content($render_mode);

        if (is_wp_error($html)) {
            Oznamy_Load_Health::record_issue('announcements', $html->get_error_message());
            error_log('[Oznamy] Shortcode error: ' . $html->get_error_message());

            // Serve stale content from a previous successful fetch if available.
            $stale = get_option('oznamy_stale_' . $render_mode . '_' . $stale_slug);
            if ($stale) {
                return self::render_latest_output($stale, $modern, true);
            }

            if ('legacy_html_export' !== $render_mode) {
                $legacy_stale = get_option('oznamy_stale_legacy_html_export_' . $stale_slug);
                if ($legacy_stale) {
                    return self::render_latest_output($legacy_stale, $modern, true);
                }
            }

            $legacy_stale_unscoped = get_option('oznamy_stale_' . $stale_slug);
            if ($legacy_stale_unscoped) {
                return self::render_latest_output($legacy_stale_unscoped, $modern, true);
            }

            if (self::is_no_content_error($html, false)) {
                return self::render_no_content_notice(false, $modern);
            }

            self::enqueue_archive_script();
            return '<div class="oznamy-content oznamy-error" data-oznamy-archive-text="'
                . ($modern ? 'modern' : 'plain') . '">'
                . esc_html__('Obsah je dočasne nedostupný. Skúste to prosím znova neskôr.', 'oznamy-gdocs')
                . '</div>';
        }

        if ('' === trim((string) $html)) {
            return self::render_no_content_notice(false, $modern);
        }

        Oznamy_Load_Health::resolve_issue('announcements');
        $ttl = self::get_current_publication_cache_ttl($publication, $settings);

        set_transient($cache_key, $html, $ttl);
        // Keep a stale copy for fallback (no expiry).
        update_option('oznamy_stale_' . $render_mode . '_' . $stale_slug, $html, false);

        return self::render_latest_output($html, $modern);
    }

    /**
     * Add shortcode-specific presentation without changing the cached source HTML.
     *
     * @param string $html
     * @param bool $modern
     * @param bool $stale
     * @return string
     */
    private static function render_latest_output($html, $modern, $stale = false)
    {
        self::enqueue_archive_script();

        $classes = 'oznamy-content';
        if ($stale) {
            $classes .= ' oznamy-stale';
        }

        $visibility = self::get_title_date_visibility();
        if ($modern) {
            $classes .= ' oznamy-modern';
            $html = self::get_cached_modern_content((string) $html, $visibility);
        } elseif ($visibility['hide_title'] || $visibility['hide_date']) {
            $html = self::hide_modern_intro((string) $html, $visibility);
        }

        return '<div class="' . esc_attr($classes) . '" data-oznamy-archive-text="'
            . ($modern ? 'modern' : 'plain') . '">' . $html . '</div>';
    }

    /**
     * Render the shared historical archive banner markup.
     *
     * @return string
     */
    private static function render_archive_banner()
    {
        return '<div class="oznamy-archive-banner" data-oznamy-archive-banner hidden aria-live="polite">'
            . '<div class="oznamy-archive-banner__message">'
            . '<strong class="oznamy-archive-banner__label">'
            . esc_html__('POZOR: ZOBRAZUJÚ SA HISTORICKÉ OZNAMY', 'oznamy-gdocs')
            . '</strong>'
            . '<span class="oznamy-archive-banner__date" data-oznamy-archive-date></span>'
            . '</div>'
            . '<a class="oznamy-archive-banner__latest" data-oznamy-archive-latest href="'
            . esc_url(remove_query_arg('oznamy')) . '">'
            . esc_html__('Najnovšie oznamy', 'oznamy-gdocs') . '</a>'
            . '</div>';
    }

    /**
     * Reuse the modern presentation output while the source document is unchanged.
     *
     * @param string $html
     * @param array{hide_title:bool,hide_date:bool} $visibility
     * @return string
     */
    private static function get_cached_modern_content($html, $visibility)
    {
        $visibility_key = ($visibility['hide_title'] ? 'hide_title' : 'show_title')
            . '_' . ($visibility['hide_date'] ? 'hide_date' : 'show_date');
        $cache_key = OZNAMY_CACHE_KEY_PREFIX . 'modern_' . md5(OZNAMY_VERSION . '|' . $visibility_key . '|' . $html);
        $cached = get_transient($cache_key);
        if (false !== $cached) {
            return (string) $cached;
        }

        $formatted = self::format_modern_content($html, $visibility);
        $settings = get_option(OZNAMY_OPTION_KEY, array());
        $ttl = max(60, absint($settings['cache_ttl'] ?? 3600));
        set_transient($cache_key, $formatted, $ttl);

        return $formatted;
    }

    /**
     * Wrap numbered announcement sections while leaving the preamble untouched.
     *
     * @param string $html
     * @param array{hide_title:bool,hide_date:bool} $visibility
     * @return string
     */
    private static function format_modern_content($html, $visibility)
    {
        if (! function_exists('wp_html_split')) {
            return $html;
        }

        $nodes = self::split_top_level_html_nodes($html);
        if (false === $nodes) {
            return $html;
        }
        $nodes = self::trim_empty_edge_nodes($nodes);

        $node_texts = array();
        $section_headings = array();
        foreach ($nodes as $node_index => $node) {
            $node_texts[$node_index] = self::get_modern_node_text($node);
            $section_headings[$node_index] = self::get_modern_section_heading($node, $node_texts[$node_index]);
        }

        $intro_nodes = self::get_modern_intro_nodes($nodes, $node_texts, $section_headings);
        $output = '';
        $section_open = false;
        $found_section = false;
        $index = 0;

        while ($index < count($nodes)) {
            $hide_intro_node = false !== $intro_nodes
                && (($visibility['hide_title'] && $index === $intro_nodes['title'])
                    || ($visibility['hide_date'] && $index === $intro_nodes['date']));
            if ($hide_intro_node) {
                ++$index;
                continue;
            }

            $node = $nodes[$index];

            // Hidden title/date can leave a blank paragraph at the very top.
            if ('' === $output && self::is_blank_node($node)) {
                ++$index;
                continue;
            }

            if (false !== $intro_nodes && $index === $intro_nodes['title']) {
                $node = '<div class="oznamy-modern-intro-title">' . $node . '</div>';
            } elseif (false !== $intro_nodes && $index === $intro_nodes['date']) {
                $node = '<div class="oznamy-modern-intro-date">' . $node . '</div>';
            }

            $calendar_end = $index;
            $calendar = self::format_modern_calendar($nodes, $index, $calendar_end, $node_texts, $section_headings);
            if (false !== $calendar) {
                $output .= $calendar;
                $index = $calendar_end + 1;
                continue;
            }

            $section_heading = $section_headings[$index] ?? false;
            if (false !== $section_heading) {
                if ($section_open) {
                    $output .= '</div>';
                }
                $output .= '<div class="oznamy-modern-section">';
                $section_open = true;
                $found_section = true;
                $node = self::format_modern_section_heading($node, $section_heading);
            } elseif ($section_open && self::is_empty_paragraph($node)) {
                ++$index;
                continue;
            }

            $output .= $node;
            ++$index;
        }

        if ($section_open) {
            $output .= '</div>';
        }

        return $found_section || false !== $intro_nodes ? trim($output) : $html;
    }

    /**
     * Remove selected detected title/date nodes without changing other document nodes.
     *
     * @param string $html
     * @param array{hide_title:bool,hide_date:bool} $visibility
     * @return string
     */
    private static function hide_modern_intro($html, $visibility)
    {
        if (! $visibility['hide_title'] && ! $visibility['hide_date']) {
            return $html;
        }

        if (! function_exists('wp_html_split')) {
            return $html;
        }

        $nodes = self::split_top_level_html_nodes($html);
        if (false === $nodes) {
            return $html;
        }

        $node_texts = array();
        $section_headings = array();
        foreach ($nodes as $node_index => $node) {
            $node_texts[$node_index] = self::get_modern_node_text($node);
            $section_headings[$node_index] = self::get_modern_section_heading($node, $node_texts[$node_index]);
        }

        $intro_nodes = self::get_modern_intro_nodes($nodes, $node_texts, $section_headings);
        if (false === $intro_nodes) {
            return $html;
        }

        if ($visibility['hide_title']) {
            unset($nodes[$intro_nodes['title']]);
        }
        if ($visibility['hide_date']) {
            unset($nodes[$intro_nodes['date']]);
        }

        return implode('', $nodes);
    }

    /**
     * @param array<string, mixed>|null $settings
     * @return array{hide_title:bool,hide_date:bool}
     */
    private static function get_title_date_visibility($settings = null)
    {
        if (! is_array($settings)) {
            $settings = get_option(OZNAMY_OPTION_KEY, array());
        }

        return array(
            'hide_title' => 'disabled' === sanitize_key($settings['show_title'] ?? 'enabled'),
            'hide_date'  => 'disabled' === sanitize_key($settings['show_date'] ?? 'enabled'),
        );
    }

    /**
     * Find an unambiguous title/date pair before the first numbered section.
     *
     * @param array<int, string> $nodes
     * @return array<string, int>|false
     */
    private static function get_modern_intro_nodes($nodes, $node_texts, $section_headings)
    {
        $intro_nodes = array();

        foreach ($nodes as $index => $node) {
            if (false !== ($section_headings[$index] ?? false)) {
                break;
            }

            if (self::is_empty_paragraph($node)) {
                continue;
            }

            $text = $node_texts[$index] ?? '';
            if ('' === trim($text)) {
                continue;
            }

            if (! preg_match('/^<(?:p|h[1-6])\b/i', $node)) {
                return false;
            }

            $intro_nodes[] = $index;
            if (2 === count($intro_nodes)) {
                if (! self::is_modern_date_subtitle($text)) {
                    return false;
                }

                return array(
                    'title' => $intro_nodes[0],
                    'date'  => $intro_nodes[1],
                );
            }
        }

        return false;
    }

    /**
     * @param string $node
     * @return string
     */
    private static function get_modern_node_text($node)
    {
        $text = html_entity_decode(wp_strip_all_tags($node), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);

        return is_string($text) ? trim($text) : '';
    }

    /**
     * Format a calendar headed by "Liturgický kalendár" without changing other nodes.
     *
     * @param array<int, string> $nodes
     * @param int $start_index
     * @param int $end_index Set to the last consumed node on success.
     * @return string|false
     */
    private static function format_modern_calendar($nodes, $start_index, &$end_index, $node_texts, $section_headings)
    {
        $title_node = $nodes[$start_index] ?? '';
        if (! preg_match('/^liturgický\s+kalendár[.!:]?$/iu', $node_texts[$start_index] ?? '')) {
            return false;
        }

        $rows = array();
        $current_row = null;
        $last_consumed = $start_index;

        for ($index = $start_index + 1, $count = count($nodes); $index < $count; ++$index) {
            $node = $nodes[$index];

            if (false !== ($section_headings[$index] ?? false)) {
                break;
            }

            if (self::is_empty_paragraph($node)) {
                if (null !== $current_row) {
                    $last_consumed = $index;
                }
                continue;
            }

            $date = self::get_modern_calendar_date($node, $node_texts[$index] ?? '');
            if (false !== $date) {
                if (null !== $current_row) {
                    $rows[] = $current_row;
                }
                $current_row = array(
                    'day' => $date['day'],
                    'month' => $date['month'],
                    'weekday' => $date['weekday'],
                    'events' => array(),
                );
                $last_consumed = $index;
                continue;
            }

            if (self::is_modern_calendar_event($node, $node_texts[$index] ?? '')) {
                if (null === $current_row) {
                    return false;
                }
                $current_row['events'][] = $node;
                $last_consumed = $index;
                continue;
            }

            break;
        }

        if (null !== $current_row) {
            $rows[] = $current_row;
        }
        if (empty($rows)) {
            return false;
        }

        $end_index = $last_consumed;
        $output = '<div class="oznamy-modern-calendar">'
            . '<div class="oznamy-modern-calendar-title">'
            . esc_html($node_texts[$start_index] ?? '')
            . '</div>';

        foreach ($rows as $row) {
            $output .= '<div class="oznamy-modern-calendar-row">'
                . '<div class="oznamy-modern-calendar-date">'
                . '<span class="oznamy-modern-calendar-day">' . esc_html($row['day']) . '</span>'
                . '<span class="oznamy-modern-calendar-date-label">'
                . '<span class="oznamy-modern-calendar-month">' . esc_html($row['month']) . '</span>'
                . '<span class="oznamy-modern-calendar-weekday">' . esc_html($row['weekday']) . '</span>'
                . '</span></div>'
                . '<div class="oznamy-modern-calendar-events">';

            foreach ($row['events'] as $event) {
                $event_content = self::remove_modern_heading_prefix($event, '•', '');
                if (false === $event_content) {
                    $event_content = $event;
                }
                $output .= '<div class="oznamy-modern-calendar-event">' . $event_content . '</div>';
            }

            $output .= '</div></div>';
        }

        return $output . '</div>';
    }

    /**
     * @param string $node
     * @return array<string, string>|false
     */
    private static function get_modern_calendar_date($node, $text = null)
    {
        if (null === $text) {
            $text = self::get_modern_node_text($node);
        }
        if (! preg_match('/^(0?[1-9]|[12][0-9]|3[01])\.\s*(\p{L}+|[0-9]{1,2})\s*(?:-|\x{2010}|\x{2011}|\x{2012}|\x{2013}|\x{2014}|\x{2015})\s*(\p{L}+)$/iu', $text, $matches)) {
            return false;
        }

        return array(
            'day' => $matches[1],
            'month' => $matches[2],
            'weekday' => $matches[3],
        );
    }

    /**
     * @param string $node
     * @return bool
     */
    private static function is_modern_calendar_event($node, $text = null)
    {
        if (preg_match('/^<(?:ul|ol)\b/i', $node)) {
            return true;
        }

        if (null === $text) {
            $text = self::get_modern_node_text($node);
        }

        return (bool) preg_match('/^\s*•/u', $text);
    }

    /**
     * @param string $text
     * @return bool
     */
    private static function is_modern_date_subtitle($text)
    {
        return (bool) preg_match('/^(?:\p{L}+\s+)?(?:0?[1-9]|[12]\d|3[01])\.\s*(?:(?:0?[1-9]|1[0-2])|\p{L}+),?\s+\d{4}\.?$/iu', $text);
    }

    /**
     * Split a sanitized HTML fragment into its top-level elements using WordPress' HTML tokenizer.
     *
     * @param string $html
     * @return array<int, string>|false
     */
    private static function split_top_level_html_nodes($html)
    {
        $tokens = wp_html_split($html);
        if (! is_array($tokens)) {
            return false;
        }

        $void_tags = array('area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr');
        $nodes = array();
        $node = '';
        $depth = 0;

        foreach ($tokens as $token) {
            if ('' === $token) {
                continue;
            }

            if (! preg_match('/^<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9:-]*)\b[^>]*>$/s', $token, $tag_match)) {
                if (0 === $depth) {
                    $nodes[] = $token;
                } else {
                    $node .= $token;
                }
                continue;
            }

            $is_closing = '/' === $tag_match[1];
            $tag_name = strtolower($tag_match[2]);
            $is_void = in_array($tag_name, $void_tags, true) || preg_match('/\/\s*>$/', $token);

            if (0 === $depth) {
                $node = $token;
                if ($is_closing || $is_void) {
                    $nodes[] = $node;
                    $node = '';
                } else {
                    $depth = 1;
                }
                continue;
            }

            $node .= $token;
            if ($is_closing) {
                --$depth;
                if (0 === $depth) {
                    $nodes[] = $node;
                    $node = '';
                }
            } elseif (! $is_void) {
                ++$depth;
            }
        }

        if (0 !== $depth) {
            return false;
        }

        if ('' !== $node) {
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * @param string $node
     * @return array<string, string>|false
     */
    private static function get_modern_section_heading($node, $text = null)
    {
        if (! preg_match('/^<p\b[^>]*>/i', $node) || false === stripos($node, '</p')) {
            return false;
        }

        if (null === $text) {
            $text = self::get_modern_node_text($node);
        }
        if (! preg_match('/^\s*(\d+)\s*([.)]|:|[-\x{2013}\x{2014}])\s*(\S)/u', $text, $matches)) {
            return false;
        }

        $has_heading_format = (bool) preg_match('/<u\b/i', $node);
        $starts_with_uppercase = (bool) preg_match('/^\p{Lu}$/u', $matches[3]);
        if (! $has_heading_format && ! $starts_with_uppercase) {
            return false;
        }

        return array(
            'number'    => $matches[1],
            'separator' => $matches[2],
        );
    }

    /**
     * Replace a numbered paragraph prefix with a badge while retaining its inline markup.
     *
     * @param string $node
     * @param array<string, string> $heading
     * @return string
     */
    private static function format_modern_section_heading($node, $heading)
    {
        if (! preg_match('/^(<p\b[^>]*>)(.*)(<\/p\s*>)$/is', $node, $parts)) {
            return $node;
        }

        $title = self::remove_modern_heading_prefix($parts[2], $heading['number'], $heading['separator']);
        if (false === $title) {
            return $node;
        }

        $number_class = strlen($heading['number']) > 3 ? ' is-long' : '';

        return '<div class="oznamy-modern-heading">'
            . '<span class="oznamy-modern-number' . $number_class . '">' . esc_html($heading['number']) . '</span>'
            . '<div class="oznamy-modern-title">' . $parts[1] . $title . $parts[3] . '</div>'
            . '</div>';
    }

    /**
     * Remove a visible numeric prefix from text tokens, leaving any surrounding tags intact.
     *
     * @param string $html
     * @param string $number
     * @param string $separator
     * @return string|false
     */
    private static function remove_modern_heading_prefix($html, $number, $separator)
    {
        $tokens = wp_html_split($html);
        if (! is_array($tokens)) {
            return false;
        }

        $expected = preg_split('//u', $number . $separator, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($expected) || empty($expected)) {
            return false;
        }

        $expected_index = 0;
        $marker_complete = false;
        $title_started = false;
        $output = '';

        foreach ($tokens as $token) {
            if (preg_match('/^<[^>]+>$/s', $token)) {
                $output .= $token;
                continue;
            }

            $decoded = html_entity_decode($token, ENT_QUOTES, 'UTF-8');
            $characters = preg_split('//u', $decoded, -1, PREG_SPLIT_NO_EMPTY);
            if (! is_array($characters)) {
                return false;
            }

            $remaining = '';
            foreach ($characters as $character) {
                if ($title_started) {
                    $remaining .= $character;
                    continue;
                }

                if (preg_match('/^\s$/u', $character)) {
                    continue;
                }

                if ($marker_complete) {
                    $title_started = true;
                    $remaining .= $character;
                    continue;
                }

                if ($character !== $expected[$expected_index]) {
                    return false;
                }

                ++$expected_index;
                if ($expected_index === count($expected)) {
                    $marker_complete = true;
                }
            }

            $output .= esc_html($remaining);
        }

        if (! $marker_complete || ! $title_started) {
            return false;
        }

        return $output;
    }

    /**
     * Drop blank paragraphs and whitespace-only nodes at the start and end of the document.
     *
     * @param array<int, string> $nodes
     * @return array<int, string>
     */
    private static function trim_empty_edge_nodes($nodes)
    {
        while ($nodes && self::is_blank_node(reset($nodes))) {
            array_shift($nodes);
        }
        while ($nodes && self::is_blank_node(end($nodes))) {
            array_pop($nodes);
        }

        return array_values($nodes);
    }

    /**
     * Whitespace, HTML comments (e.g. the oznamy:render marker) and empty paragraphs count as blank.
     *
     * @param string $node
     * @return bool
     */
    private static function is_blank_node($node)
    {
        $node = preg_replace('/<!--.*?-->/s', '', $node);

        return '' === trim($node) || self::is_empty_paragraph($node);
    }

    /**
     * @param string $node
     * @return bool
     */
    private static function is_empty_paragraph($node)
    {
        if (! preg_match('/^<p\b[^>]*>.*<\/p\s*>$/is', $node)) {
            return false;
        }

        $text = html_entity_decode(wp_strip_all_tags($node), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}]+/u', '', $text);

        return '' === $text;
    }

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

    /**
     * [farske_oznamy_buduce] shortcode – modern future preview with countdown.
     *
     * @param array<string, string> $atts
     * @return string
     */
    public static function render_future_modern($atts)
    {
        return self::render_future_output($atts, true);
    }

    /**
     * Render the nearest future announcement preview.
     *
     * @param array<string, string> $atts
     * @param bool $modern
     * @return string
     */
    private static function render_future_output($atts, $modern)
    {
        if (self::has_unsupported_attributes($atts)) {
            return '<div class="oznamy-content oznamy-error">'
                . esc_html(self::UNSUPPORTED_ATTS_MESSAGE)
                . '</div>';
        }

        $settings    = get_option(OZNAMY_OPTION_KEY, array());
        self::schedule_next_release_cache_purge($settings);
        $render_mode = self::resolve_render_mode($settings);
        $slug        = self::get_next_future_cycle_slug($settings);
        $visibility = self::get_title_date_visibility($settings);
        $cache_variant = self::get_future_cache_variant($modern, $visibility);
        $cache_key   = OZNAMY_CACHE_KEY_PREFIX . 'future_' . $render_mode . $cache_variant . '_' . $slug;
        $cached      = get_transient($cache_key);

        if (false !== $cached) {
            return '<div class="oznamy-future-wrap">' . (string) $cached . '</div>';
        }

        $miss_cache_key = self::future_miss_cache_key($cache_key);
        if (false !== get_transient($miss_cache_key)) {
            self::prevent_page_cache_for_fallback(new WP_Error('oznamy_no_future_subfolder', ''), $settings);
            return self::render_no_content_notice(true);
        }

        $result = self::fetch_future_content($render_mode);
        if (is_wp_error($result)) {
            self::prevent_page_cache_for_fallback($result, $settings);
            if (self::is_cacheable_future_miss($result)) {
                if (in_array($result->get_error_code(), array('oznamy_no_doc', 'oznamy_empty_doc', 'oznamy_ambiguous_future_subfolder'), true)) {
                    Oznamy_Load_Health::record_issue('future_announcements', $result->get_error_message());
                }
                set_transient($miss_cache_key, 1, self::FUTURE_MISS_CACHE_TTL);
                return self::render_no_content_notice(true);
            }
            Oznamy_Load_Health::record_issue('future_announcements', $result->get_error_message());
            error_log('[Oznamy] Future shortcode error: ' . $result->get_error_message());

            $stale_source = self::render_stale_future_source($render_mode, $slug, $modern, $visibility);
            if (false !== $stale_source) {
                return $stale_source;
            }

            $stale = get_option('oznamy_stale_future_' . $render_mode . $cache_variant . '_' . $slug);
            if ($stale) {
                return '<div class="oznamy-future-wrap oznamy-stale">' . $stale . '</div>';
            }

            if (self::is_no_content_error($result, true)) {
                return self::render_no_content_notice(true);
            }

            return '';
        }

        if (empty($result['html']) || empty($result['target_ts'])) {
            Oznamy_Load_Health::record_issue(
                'future_announcements',
                __('Budúci oznam nemá platný obsah alebo dátum zverejnenia.', 'oznamy-gdocs')
            );
            return self::render_no_content_notice(true);
        }

        Oznamy_Load_Health::resolve_issue('future_announcements');
        $output = self::build_future_output($result, $modern, $visibility);

        $ttl = self::get_future_output_cache_ttl($settings);
        delete_transient($miss_cache_key);
        set_transient($cache_key, $output, $ttl);
        update_option('oznamy_stale_future_' . $render_mode . $cache_variant . '_' . $slug, $output, false);
        update_option(
            'oznamy_stale_future_source_' . $render_mode . '_' . $slug,
            array('html' => (string) $result['html'], 'target_ts' => (int) $result['target_ts']),
            false
        );

        return '<div class="oznamy-future-wrap">' . $output . '</div>';
    }

    /**
     * Render source content with the current presentation settings.
     *
     * @param array{html:string,target_ts:int} $result
     * @param bool $modern
     * @param array{hide_title:bool,hide_date:bool} $visibility
     * @return string
     */
    private static function build_future_output($result, $modern, $visibility)
    {
        $content = (string) $result['html'];
        $content_class = 'oznamy-content';
        if ($modern) {
            $content_class .= ' oznamy-modern';
            $content = self::format_modern_content($content, $visibility);
        } elseif ($visibility['hide_title'] || $visibility['hide_date']) {
            $content = self::hide_modern_intro($content, $visibility);
        }

        return '<div class="oznamy-future-meta">' . esc_html(self::build_countdown_message((int) $result['target_ts'])) . '</div>'
            . '<div class="' . esc_attr($content_class) . '">' . $content . '</div>';
    }

    /**
     * @param string $render_mode
     * @param string $slug
     * @param bool $modern
     * @param array{hide_title:bool,hide_date:bool} $visibility
     * @return string|false
     */
    private static function render_stale_future_source($render_mode, $slug, $modern, $visibility)
    {
        $result = get_option('oznamy_stale_future_source_' . $render_mode . '_' . $slug);
        if (! is_array($result) || empty($result['html']) || empty($result['target_ts'])) {
            return false;
        }

        return '<div class="oznamy-future-wrap oznamy-stale">'
            . self::build_future_output($result, $modern, $visibility)
            . '</div>';
    }

    /**
     * @param bool $modern
     * @param array{hide_title:bool,hide_date:bool} $visibility
     * @return string
     */
    private static function get_future_cache_variant($modern, $visibility)
    {
        return ($modern ? '_modern' : '')
            . '_title_' . ($visibility['hide_title'] ? 'hidden' : 'shown')
            . '_date_' . ($visibility['hide_date'] ? 'hidden' : 'shown');
    }

    private static function get_future_output_cache_ttl($settings)
    {
        return min(300, max(60, absint($settings['cache_ttl'] ?? 3600)));
    }

    private static function future_miss_cache_key($cache_key)
    {
        return $cache_key . '_not_found';
    }

    private static function is_cacheable_future_miss($error)
    {
        return is_wp_error($error) && in_array(
            $error->get_error_code(),
            array('oznamy_no_future_subfolder', 'oznamy_ambiguous_future_subfolder', 'oznamy_no_subfolder', 'oznamy_not_future', 'oznamy_no_doc', 'oznamy_empty_doc'),
            true
        );
    }

    private static function get_sunday_release_time($settings)
    {
        $release_time = sanitize_text_field($settings['sunday_release_time'] ?? '16:00');
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $release_time) ? $release_time : '16:00';
    }

    private static function get_version_states()
    {
        $stored_states = get_option(self::VERSION_STATES_OPTION, array());
        if (! is_array($stored_states)) {
            return array();
        }

        $states = array();
        foreach ($stored_states as $folder_id => $state) {
            $folder_id = sanitize_text_field((string) $folder_id);
            if ($folder_id && in_array($state, array('hidden', 'early_published'), true)) {
                $states[$folder_id] = $state;
            }
        }

        return $states;
    }

    private static function get_version_state($folder_id, $states = null)
    {
        $states = is_array($states) ? $states : self::get_version_states();
        return $states[(string) $folder_id] ?? '';
    }

    private static function is_version_hidden($folder_id, $states = null)
    {
        return 'hidden' === self::get_version_state($folder_id, $states);
    }

    private static function set_version_state($folder_id, $state)
    {
        $states = self::get_version_states();
        $folder_id = sanitize_text_field((string) $folder_id);

        if ('hidden' === $state || 'early_published' === $state) {
            $states[$folder_id] = $state;
        } else {
            unset($states[$folder_id]);
        }

        update_option(self::VERSION_STATES_OPTION, $states, false);
        $saved_states = self::get_version_states();

        return self::get_version_state($folder_id, $saved_states) === ($state ?: '');
    }

    private static function get_publication_base_sunday($settings, $publication = null)
    {
        $base_sunday = self::get_current_sunday($settings);
        if (is_array($publication) && ! empty($publication['folder']['date'])) {
            $publication_sunday = (new DateTimeImmutable('@' . (int) $publication['folder']['date']))
                ->setTimezone($base_sunday->getTimezone())
                ->setTime(0, 0);
            if ($publication_sunday > $base_sunday) {
                $base_sunday = $publication_sunday;
            }
        }

        return $base_sunday;
    }

    private static function get_sunday_release_timestamp($sunday, $settings)
    {
        $release_time = self::get_sunday_release_time($settings);
        return $sunday->modify('-1 day')
            ->setTime((int) substr($release_time, 0, 2), (int) substr($release_time, 3, 2))
            ->getTimestamp();
    }

    private static function get_next_visible_future_folder_group($folders, $settings, $publication = null)
    {
        $base_sunday = self::get_publication_base_sunday($settings, $publication);
        $groups = array();
        $states = self::get_version_states();

        foreach ($folders as $folder) {
            if (self::is_version_hidden($folder['id'], $states)) {
                continue;
            }

            $folder_date = (new DateTimeImmutable('@' . (int) $folder['date']))->setTimezone($base_sunday->getTimezone());
            if ('7' !== $folder_date->format('N') || $folder_date <= $base_sunday) {
                continue;
            }

            $date_key = $folder_date->format('Y-m-d');
            if (! isset($groups[$date_key])) {
                $groups[$date_key] = array(
                    'date' => $folder_date->setTime(0, 0),
                    'folders' => array(),
                );
            }
            $groups[$date_key]['folders'][] = $folder;
        }

        if (empty($groups)) {
            return null;
        }

        ksort($groups);
        $group = reset($groups);
        $group['date_key'] = key($groups);
        $group['target_ts'] = self::get_sunday_release_timestamp($group['date'], $settings);

        return $group;
    }

    private static function get_current_cycle_slug($settings, $publication = null)
    {
        $slug = 'latest_' . self::get_current_sunday($settings)->format('Ymd');
        if (is_array($publication) && ! empty($publication['folder']['id'])) {
            $slug .= '_' . md5((string) $publication['folder']['id']);
        }
        return $slug . '_' . md5(serialize(self::get_version_states()));
    }

    private static function get_current_publication_cache_ttl($publication, $settings)
    {
        $ttl = max(60, absint($settings['cache_ttl'] ?? 3600));
        if (is_array($publication) && ! empty($publication['folder']['date'])) {
            $target_sunday = self::get_current_sunday($settings)->getTimestamp();
            if ((int) $publication['folder']['date'] < $target_sunday) {
                return min($ttl, self::FUTURE_MISS_CACHE_TTL);
            }
        }
        return $ttl;
    }

    private static function prevent_page_cache_for_fallback($publication, $settings)
    {
        $is_fallback = is_wp_error($publication)
            || (is_array($publication)
                && ! empty($publication['folder']['date'])
                && (int) $publication['folder']['date'] < self::get_current_sunday($settings)->getTimestamp());
        if ($is_fallback && ! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
    }

    private static function get_next_future_cycle_slug($settings)
    {
        $current = self::resolve_current_publication(self::resolve_render_mode($settings));
        $base_sunday = self::get_publication_base_sunday($settings, is_array($current) ? $current : null);
        $next_sunday = $base_sunday->modify('+7 days');
        $current_folder_id = is_array($current) ? (string) ($current['folder']['id'] ?? '') : '';
        $state_signature = md5(serialize(self::get_version_states()));

        return 'next_' . $next_sunday->format('Ymd') . '_'
            . str_replace(':', '', self::get_sunday_release_time($settings)) . '_'
            . md5($current_folder_id . '|' . $state_signature);
    }

    private static function schedule_next_release_cache_purge($settings)
    {
        $hook = 'oznamy_sunday_release_cache_purge';
        if (
            ! function_exists('wpsc_delete_url_cache')
            || ! function_exists('wp_next_scheduled')
            || ! function_exists('wp_schedule_single_event')
        ) {
            return;
        }

        $release = self::get_next_sunday_release($settings);
        $timestamp = $release['target_ts'] + 1;
        $scheduled = wp_next_scheduled($hook);
        if ($scheduled && (int) $scheduled <= time()) {
            return;
        }
        if ($scheduled && (int) $scheduled !== $timestamp && function_exists('wp_unschedule_event')) {
            wp_unschedule_event($scheduled, $hook);
            $scheduled = false;
        }

        if (! $scheduled) {
            wp_schedule_single_event($timestamp, $hook);
        }
    }

    public static function handle_settings_updated()
    {
        $hook = 'oznamy_sunday_release_cache_purge';
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook($hook);
        }
        self::purge_shortcode_pages_cache();
        self::schedule_next_release_cache_purge(get_option(OZNAMY_OPTION_KEY, array()));
    }

    private static function get_current_sunday($settings)
    {
        try {
            $timezone = wp_timezone();
        } catch (Exception $e) {
            $timezone = new DateTimeZone('UTC');
        }

        $now = new DateTimeImmutable('now', $timezone);
        $today = $now->setTime(0, 0);
        $days_since_sunday = (int) $now->format('N') % 7;
        $sunday = $today->modify('-' . $days_since_sunday . ' days');

        if (6 === (int) $now->format('N') && $now->format('H:i') >= self::get_sunday_release_time($settings)) {
            $sunday = $today->modify('+1 day');
        }

        return $sunday;
    }

    /**
     * Return the next Sunday preview date and its Saturday publication timestamp.
     *
     * @return array{date:DateTimeImmutable,target_ts:int}
     */
    private static function get_next_sunday_release($settings)
    {
        try {
            $timezone = wp_timezone();
        } catch (Exception $e) {
            $timezone = new DateTimeZone('UTC');
        }

        $now = new DateTimeImmutable('now', $timezone);
        $today = $now->setTime(0, 0);
        $days_until_sunday = 7 - (int) $now->format('N');
        if (0 === $days_until_sunday) {
            $days_until_sunday = 7;
        }

        $sunday = $today->modify('+' . $days_until_sunday . ' days');
        $release_time = self::get_sunday_release_time($settings);
        $release = $sunday->modify('-1 day')->setTime((int) substr($release_time, 0, 2), (int) substr($release_time, 3, 2));
        if ($release->getTimestamp() <= $now->getTimestamp()) {
            $sunday = $sunday->modify('+7 days');
            $release = $sunday->modify('-1 day')->setTime((int) substr($release_time, 0, 2), (int) substr($release_time, 3, 2));
        }

        return array('date' => $sunday, 'target_ts' => $release->getTimestamp());
    }

    private static function has_visible_content($html)
    {
        $text = html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', $text);
        return is_string($text) && '' !== $text;
    }

    /**
     * Render one document, falling back from Docs API to legacy export where appropriate.
     *
     * @return string|WP_Error
     */
    private static function fetch_document_html($fetcher, $doc_id, $render_mode)
    {
        if ('legacy_html_export' !== $render_mode) {
            $rich = $fetcher->fetch_rich_html($doc_id);
            if (! is_wp_error($rich) && self::has_visible_content($rich)) {
                return '<!-- oznamy:render=docs_api -->' . $rich;
            }
            if (is_wp_error($rich)) {
                error_log('[Oznamy] Docs API render failed: ' . $rich->get_error_message());
            }
        }

        $html = $fetcher->fetch_html($doc_id);
        if (is_wp_error($html)) {
            return $html;
        }
        if (! self::has_visible_content($html)) {
            return new WP_Error('oznamy_empty_doc', 'Dokument neobsahuje žiadny publikovateľný text.');
        }

        return '<!-- oznamy:render=legacy_html_export -->' . $html;
    }

    /**
     * Resolve and cache the latest usable Sunday announcement for this schedule cycle.
     *
     * @return array{folder:array,doc_id:string,html:string}|WP_Error
     */
    private static function resolve_current_publication($render_mode)
    {
        $settings = get_option(OZNAMY_OPTION_KEY, array());
        $folder_id = sanitize_text_field($settings['folder_id'] ?? '');
        $sa_data = json_decode($settings['sa_json'] ?? '', true);
        if (! $folder_id || ! is_array($sa_data)) {
            $error = new WP_Error('oznamy_not_configured', 'Plugin nie je úplné nakonfigurovaný.');
            Oznamy_Load_Health::record_issue('announcements', $error->get_error_message());
            return $error;
        }

        $sunday = self::get_current_sunday($settings);
        $date_key = $sunday->format('Y-m-d');
        $version_states = self::get_version_states();
        $cache_key = OZNAMY_CACHE_KEY_PREFIX . 'current_publication_' . md5(
            $folder_id . '|' . $render_mode . '|' . $date_key . '|' . self::get_sunday_release_time($settings)
                . '|' . md5(serialize($version_states))
        );
        $cached = get_transient($cache_key);
        if (is_array($cached) && ! empty($cached['folder']['id']) && ! empty($cached['html'])) {
            return $cached;
        }

        $client = new Oznamy_GDrive_Client($sa_data);
        $folders = $client->list_subfolders_sorted($folder_id, true);
        if (is_wp_error($folders)) {
            Oznamy_Load_Health::record_issue('announcements', $folders->get_error_message());
            return $folders;
        }

        $sunday_folder_counts = array();
        foreach ($folders as $folder) {
            if (self::is_version_hidden($folder['id'], $version_states)) {
                continue;
            }
            $folder_date = (new DateTimeImmutable('@' . (int) $folder['date']))->setTimezone($sunday->getTimezone());
            $is_early_published = 'early_published' === self::get_version_state($folder['id'], $version_states);
            if (
                '7' === $folder_date->format('N')
                && ((int) $folder['date'] <= $sunday->getTimestamp() || $is_early_published)
            ) {
                $folder_date_key = $folder_date->format('Y-m-d');
                $sunday_folder_counts[$folder_date_key] = ($sunday_folder_counts[$folder_date_key] ?? 0) + 1;
            }
        }

        $fetcher = new Oznamy_GDocs_Fetcher($client);
        $last_error = null;
        foreach ($folders as $folder) {
            if (self::is_version_hidden($folder['id'], $version_states)) {
                continue;
            }

            $folder_date = (new DateTimeImmutable('@' . (int) $folder['date']))->setTimezone($sunday->getTimezone());
            $is_early_published = 'early_published' === self::get_version_state($folder['id'], $version_states);
            if (
                '7' !== $folder_date->format('N')
                || ((int) $folder['date'] > $sunday->getTimestamp() && ! $is_early_published)
            ) {
                continue;
            }
            if (($sunday_folder_counts[$folder_date->format('Y-m-d')] ?? 0) > 1) {
                $last_error = new WP_Error(
                    'oznamy_duplicate_sunday_folder',
                    'Pre dátum ' . $folder_date->format('d.m.Y') . ' existuje viacero nedeľných priečinkov oznamov.'
                );
                continue;
            }

            $doc_id = $client->find_doc_in_subfolder_result($folder['id']);
            if (is_wp_error($doc_id)) {
                $last_error = $doc_id;
                continue;
            }
            if (! $doc_id) {
                $last_error = new WP_Error('oznamy_no_doc', 'V podpriečinku ' . $folder['name'] . ' sa nenašiel Google Doc „Oznamy".');
                continue;
            }

            $html = self::fetch_document_html($fetcher, $doc_id, $render_mode);
            if (is_wp_error($html)) {
                $last_error = $html;
                continue;
            }

            $publication = array('folder' => $folder, 'doc_id' => $doc_id, 'html' => $html);
            if ($last_error) {
                Oznamy_Load_Health::record_issue('announcements', $last_error->get_error_message());
            }
            self::update_history($folder, $doc_id);
            update_option(
                'oznamy_last_publication',
                array(
                    'folder_id' => $folder_id,
                    'folder' => $folder,
                    'doc_id' => $doc_id,
                    'cycle_date' => $date_key,
                    'release_time' => self::get_sunday_release_time($settings),
                ),
                false
            );
            $ttl = self::get_current_publication_cache_ttl($publication, $settings);
            set_transient($cache_key, $publication, $ttl);
            if (! $last_error) {
                Oznamy_Load_Health::resolve_issue('announcements');
            }
            return $publication;
        }

        if ($last_error) {
            Oznamy_Load_Health::record_issue('announcements', $last_error->get_error_message());
            return new WP_Error('oznamy_no_ready_announcement', $last_error->get_error_message());
        }

        $error = new WP_Error('oznamy_no_subfolder', 'V koreňovom priečinku sa nenašiel použiteľný nedeľný oznam.');
        Oznamy_Load_Health::record_issue('announcements', $error->get_error_message());
        return $error;
    }

    public static function get_current_publication_for_admin()
    {
        $settings = get_option(OZNAMY_OPTION_KEY, array());
        return self::resolve_current_publication(self::resolve_render_mode($settings));
    }

    /**
     * Fetch doc HTML from Google Drive/Docs.
     *
     * @return string|WP_Error
     */
    private static function fetch_content($render_mode)
    {
        $publication = self::resolve_current_publication($render_mode);
        return is_wp_error($publication) ? $publication : $publication['html'];
    }

    /**
     * Fetch a selected archive date for the public frontend.
     */
    public static function handle_archive_ajax()
    {
        $date_key = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_key)) {
            wp_send_json_error(array('code' => 'invalid_date'), 400);
        }

        try {
            $timezone = wp_timezone();
        } catch (Exception $e) {
            $timezone = new DateTimeZone('UTC');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $date_key, $timezone);
        $date_errors = DateTimeImmutable::getLastErrors();
        if (! $date || (is_array($date_errors) && ($date_errors['warning_count'] || $date_errors['error_count'])) || $date->format('Y-m-d') !== $date_key) {
            wp_send_json_error(array('code' => 'invalid_date'), 400);
        }

        $settings  = get_option(OZNAMY_OPTION_KEY, array());
        $visibility = self::get_title_date_visibility($settings);
        $folder_id = sanitize_text_field($settings['folder_id'] ?? '');
        $sa_data   = json_decode($settings['sa_json'] ?? '', true);
        if (! $folder_id || ! is_array($sa_data)) {
            Oznamy_Load_Health::record_issue('archive_index', 'Plugin nie je úplne nakonfigurovaný.');
            wp_send_json_error(array('code' => 'not_configured'), 503);
        }

        $ttl = max(60, absint($settings['cache_ttl'] ?? 3600));
        $client = new Oznamy_GDrive_Client($sa_data);
        $publication = self::resolve_current_publication(self::resolve_render_mode($settings));
        $include_future_date_key = null;
        if (! is_wp_error($publication)) {
            $today = new DateTimeImmutable('today midnight', $timezone);
            if ((int) $publication['folder']['date'] > $today->getTimestamp()) {
                $include_future_date_key = wp_date('Y-m-d', (int) $publication['folder']['date']);
            }
        }
        $folders = self::get_archive_folders($client, $folder_id, $ttl, self::DEFAULT_ARCHIVE_LIMIT, $include_future_date_key);
        if (is_wp_error($folders)) {
            Oznamy_Load_Health::record_issue('archive_index', $folders->get_error_message());
            error_log('[Oznamy] Archive index error: ' . $folders->get_error_message());
            wp_send_json_error(array('code' => 'archive_unavailable'), 503);
        }

        $selected = null;
        foreach ($folders as $folder) {
            if ($folder['date_key'] === $date_key) {
                $selected = $folder;
                break;
            }
        }

        if (! $selected || ! empty($selected['ambiguous'])) {
            wp_send_json_error(array('code' => 'not_found'), 404);
        }

        $include_text = isset($_POST['text']) && '1' === sanitize_text_field(wp_unslash($_POST['text']));
        $content = null;
        if ($include_text) {
            $render_mode = self::resolve_render_mode($settings);
            $content_variant = $render_mode
                . '_title_' . ($visibility['hide_title'] ? 'hidden' : 'shown')
                . '_date_' . ($visibility['hide_date'] ? 'hidden' : 'shown');
            $content_key = self::archive_cache_key('content', $folder_id, $selected['id'], $content_variant);
            $content = get_transient($content_key);
            if (false === $content) {
                $doc_id = $client->find_doc_in_subfolder_result($selected['id']);
                if (is_wp_error($doc_id)) {
                    Oznamy_Load_Health::record_issue('archive_announcements', $doc_id->get_error_message());
                    error_log('[Oznamy] Archive document lookup error: ' . $doc_id->get_error_message());
                    wp_send_json_error(array('code' => 'archive_unavailable'), 503);
                } elseif (! $doc_id) {
                    Oznamy_Load_Health::record_issue('archive_announcements', 'V archívnom priečinku sa nenašiel dokument „Oznamy".');
                    wp_send_json_error(array('code' => 'not_found'), 404);
                } else {
                    $fetcher = new Oznamy_GDocs_Fetcher($client);
                    $content_html = $fetcher->fetch_rich_html($doc_id);
                    if (is_wp_error($content_html)) {
                        $content_html = $fetcher->fetch_html($doc_id);
                    }

                    if (is_wp_error($content_html)) {
                        Oznamy_Load_Health::record_issue('archive_announcements', $content_html->get_error_message());
                        error_log('[Oznamy] Archive document fetch error: ' . $content_html->get_error_message());
                        wp_send_json_error(array('code' => 'archive_unavailable'), 503);
                    } else {
                        Oznamy_Load_Health::resolve_issue('archive_announcements');
                        $content = array(
                            'plain'  => (string) $content_html,
                            'modern' => self::format_modern_content((string) $content_html, $visibility),
                        );
                        set_transient($content_key, $content, $ttl);
                    }
                }
            }
        }
        if ($include_text) {
            Oznamy_Load_Health::resolve_issue('archive_announcements');
        }

        $gallery_html = null;
        $include_images = isset($_POST['images']) && '1' === sanitize_text_field(wp_unslash($_POST['images']));
        if ($include_images) {
            $images_key = self::archive_cache_key('images', $folder_id, $selected['id']);
            $gallery_html = get_transient($images_key);

            if (false === $gallery_html) {
                $images = $client->list_images_in_folder_result($selected['id']);
                if (is_wp_error($images)) {
                    Oznamy_Load_Health::record_issue('archive_images', $images->get_error_message());
                    error_log('[Oznamy] Archive image listing error: ' . $images->get_error_message());
                    wp_send_json_error(array('code' => 'archive_unavailable'), 503);
                } else {
                    $cached_images = array();
                    foreach ($images as $image) {
                        $cached = self::ensure_image_cached(
                            $image['id'],
                            $image['name'],
                            $selected['id'],
                            $client,
                            $image['modifiedTime']
                        );
                        if (is_wp_error($cached)) {
                            Oznamy_Load_Health::record_issue('archive_images', $cached->get_error_message());
                            error_log('[Oznamy] Archive image cache error: ' . $cached->get_error_message());
                            wp_send_json_error(array('code' => 'archive_unavailable'), 503);
                        }
                        $cached['name'] = $image['name'];
                        $cached_images[] = $cached;
                    }

                    usort($cached_images, static function ($a, $b) {
                        return strcasecmp($a['name'], $b['name']);
                    });
                    $gallery_html = self::build_images_html($cached_images);
                    set_transient($images_key, $gallery_html, $ttl);
                    Oznamy_Load_Health::resolve_issue('archive_images');
                }
            }
            Oznamy_Load_Health::resolve_issue('archive_images');
        }

        $plain_html = is_array($content) ? $content['plain'] : null;
        if (($visibility['hide_title'] || $visibility['hide_date']) && is_string($plain_html)) {
            $plain_html = self::hide_modern_intro($plain_html, $visibility);
        }

        $latest_data = self::get_archive_latest_data($folders, $publication, $settings);

        wp_send_json_success(
            array(
                'plain_html'  => $plain_html,
                'modern_html' => is_array($content) ? $content['modern'] : null,
                'images_html' => $gallery_html,
                'date_label'  => wp_date(get_option('date_format'), (int) $selected['date']),
                'latest_date' => $latest_data['date'],
            )
        );
    }

    /**
     * Determine the current archive date from validated publication metadata.
     *
     * @param array $folders
     * @return array{date:string,timestamp:int}
     */
    private static function get_archive_latest_data($folders, $publication, $settings)
    {
        $selected_folder = is_array($publication) ? ($publication['folder'] ?? null) : null;
        if (is_array($selected_folder) && self::is_version_hidden($selected_folder['id'] ?? '')) {
            $selected_folder = null;
        }
        if (! is_array($selected_folder) || empty($selected_folder['date'])) {
            $last_publication = get_option('oznamy_last_publication', array());
            $configured_folder_id = sanitize_text_field($settings['folder_id'] ?? '');
            if (
                is_array($last_publication)
                && ($last_publication['folder_id'] ?? '') === $configured_folder_id
                && ($last_publication['release_time'] ?? '') === self::get_sunday_release_time($settings)
                && ! self::is_version_hidden($last_publication['folder']['id'] ?? '')
                && ! empty($last_publication['folder']['date'])
            ) {
                $selected_folder = $last_publication['folder'];
            }
        }

        if (is_array($selected_folder) && ! empty($selected_folder['date'])) {
            $date = wp_date('Y-m-d', (int) $selected_folder['date']);
            foreach ($folders as $folder) {
                if (($folder['date_key'] ?? '') === $date && ! empty($folder['ambiguous'])) {
                    return array('date' => '', 'timestamp' => 0);
                }
            }
            return array('date' => $date, 'timestamp' => (int) $selected_folder['date']);
        }

        return array('date' => '', 'timestamp' => 0);
    }

    /**
     * Load and cache the server-side index of eligible archive folders.
     *
     * @return array|WP_Error
     */
    private static function get_archive_folders($client, $folder_id, $ttl, $limit = self::DEFAULT_ARCHIVE_LIMIT, $include_future_date_key = null)
    {
        $limit = max(1, min(self::MAX_ARCHIVE_LIMIT, (int) $limit));
        $include_future_date_key = is_string($include_future_date_key)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $include_future_date_key)
            ? $include_future_date_key
            : '';
        $states = self::get_version_states();
        $cache_key = OZNAMY_CACHE_KEY_PREFIX . 'archive_index_' . md5(
            $folder_id . '|' . $limit . '|' . $include_future_date_key . '|' . md5(serialize($states))
        );
        $folders = get_transient($cache_key);
        if (is_array($folders)) {
            self::update_archive_index_health($folders);
            return $folders;
        }

        $subfolders = $client->list_subfolders_sorted($folder_id, true);
        if (is_wp_error($subfolders)) {
            Oznamy_Load_Health::record_issue('archive_index', $subfolders->get_error_message());
            return $subfolders;
        }

        try {
            $timezone = wp_timezone();
            $today_timestamp = (new DateTimeImmutable('today midnight', $timezone))->getTimestamp();
        } catch (Exception $e) {
            $timezone = new DateTimeZone('UTC');
            $today_timestamp = (new DateTimeImmutable('today midnight', $timezone))->getTimestamp();
        }

        $by_date = array();
        foreach ($subfolders as $folder) {
            if (self::is_version_hidden($folder['id'], $states)) {
                continue;
            }

            $date = (int) $folder['date'];
            $date_key = (new DateTimeImmutable('@' . $date))->setTimezone($timezone)->format('Y-m-d');
            if ($date > $today_timestamp && $date_key !== $include_future_date_key) {
                continue;
            }

            if (isset($by_date[$date_key])) {
                $by_date[$date_key]['ambiguous'] = true;
                continue;
            }

            $by_date[$date_key] = array(
                'id' => (string) $folder['id'],
                'name' => (string) $folder['name'],
                'date' => $date,
                'date_key' => $date_key,
                'ambiguous' => false,
            );
        }

        $folders = array_values($by_date);
        usort($folders, static function ($a, $b) {
            return $b['date'] <=> $a['date'];
        });
        $folders = array_slice($folders, 0, $limit);
        set_transient($cache_key, $folders, $ttl);
        self::update_archive_index_health($folders);

        return $folders;
    }

    private static function update_archive_index_health($folders)
    {
        foreach ($folders as $folder) {
            if (! empty($folder['ambiguous'])) {
                Oznamy_Load_Health::record_issue(
                    'archive_index',
                    sprintf(
                        /* translators: %s: dátum s duplicitnými priečinkami */
                        __('Pre dátum %s existuje viacero priečinkov oznamov.', 'oznamy-gdocs'),
                        (string) ($folder['date_key'] ?? '')
                    )
                );
                return;
            }
        }

        Oznamy_Load_Health::resolve_issue('archive_index');
    }

    /**
     * Create a bounded transient key for one archive output.
     */
    private static function archive_cache_key($type, $folder_id, $subfolder_id, $render_mode = '')
    {
        return OZNAMY_CACHE_KEY_PREFIX . 'archive_' . $type . '_' . md5($folder_id . '|' . $subfolder_id . '|' . $render_mode);
    }

    /**
     * Enqueue the small archive controller only on pages rendering current notices.
     */
    private static function enqueue_archive_script()
    {
        if (! wp_script_is('oznamy-archive', 'registered')) {
            wp_register_script(
                'oznamy-archive',
                OZNAMY_PLUGIN_URL . 'assets/archive.js',
                array(),
                OZNAMY_VERSION,
                true
            );
            wp_localize_script('oznamy-archive', 'OznamyArchive', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'loadingLabel' => __('Načítavajú sa archívne oznamy', 'oznamy-gdocs'),
            ));
        }
        wp_enqueue_script('oznamy-archive');
    }

    /**
     * Enqueue the gallery lightbox. It builds its own markup, so cached gallery HTML stays free of it.
     */
    private static function enqueue_lightbox_script()
    {
        if (self::$original_images) {
            return;
        }

        if (! wp_script_is('oznamy-lightbox', 'registered')) {
            wp_register_script(
                'oznamy-lightbox',
                OZNAMY_PLUGIN_URL . 'assets/lightbox.js',
                array(),
                OZNAMY_VERSION,
                true
            );
        }
        wp_enqueue_script('oznamy-lightbox');
    }

    /**
     * Resolve the next scheduled Sunday only when its document is usable.
     *
     * @return array{folder:array,doc_id:string,html:string,target_ts:int}|WP_Error
     */
    private static function resolve_future_publication($render_mode)
    {
        $settings = get_option(OZNAMY_OPTION_KEY, array());
        $folder_id = sanitize_text_field($settings['folder_id'] ?? '');
        $sa_data = json_decode($settings['sa_json'] ?? '', true);
        if (! $folder_id || ! is_array($sa_data)) {
            return new WP_Error('oznamy_not_configured', 'Plugin nie je úplné nakonfigurovaný.');
        }

        $current = self::resolve_current_publication($render_mode);
        $future_slug = self::get_next_future_cycle_slug($settings);
        $cache_key = OZNAMY_CACHE_KEY_PREFIX . 'future_publication_' . md5(
            $folder_id . '|' . $render_mode . '|' . $future_slug
        );
        $cached = get_transient($cache_key);
        if (is_array($cached) && ! empty($cached['folder']['id']) && ! empty($cached['html'])) {
            return $cached;
        }

        $client = new Oznamy_GDrive_Client($sa_data);
        $folders = $client->list_subfolders_sorted($folder_id, true);
        if (is_wp_error($folders)) {
            return $folders;
        }

        $future_group = self::get_next_visible_future_folder_group(
            $folders,
            $settings,
            is_array($current) ? $current : null
        );
        if (! $future_group) {
            return new WP_Error('oznamy_no_future_subfolder', 'Nenašiel sa pripravený nedeľný podpriečinok.');
        }
        if (count($future_group['folders']) > 1) {
            return new WP_Error('oznamy_ambiguous_future_subfolder', 'Pre nedeľu ' . $future_group['date_key'] . ' existuje viacero podpriečinkov.');
        }
        $subfolder = $future_group['folders'][0] ?? null;
        if (! $subfolder) {
            return new WP_Error('oznamy_no_future_subfolder', 'Nenašiel sa pripravený nedeľný podpriečinok.');
        }

        $doc_id = $client->find_doc_in_subfolder_result($subfolder['id']);
        if (is_wp_error($doc_id)) {
            return $doc_id;
        }
        if (! $doc_id) {
            return new WP_Error('oznamy_no_doc', 'V podpriečinku ' . $subfolder['name'] . ' sa nenašiel Google Doc „Oznamy".');
        }

        $fetcher = new Oznamy_GDocs_Fetcher($client);
        $html = self::fetch_document_html($fetcher, $doc_id, $render_mode);
        if (is_wp_error($html)) {
            return $html;
        }

        $publication = array(
            'folder' => $subfolder,
            'doc_id' => $doc_id,
            'html' => $html,
            'target_ts' => $future_group['target_ts'],
        );
        $ttl = min(self::FUTURE_MISS_CACHE_TTL, max(60, absint($settings['cache_ttl'] ?? 3600)));
        set_transient($cache_key, $publication, $ttl);

        return $publication;
    }

    /**
     * Fetch doc HTML for the next scheduled Sunday.
     *
     * @return array{html: string, target_ts: int}|WP_Error
     */
    private static function fetch_future_content($render_mode)
    {
        $publication = self::resolve_future_publication($render_mode);
        if (is_wp_error($publication)) {
            return $publication;
        }

        return array(
            'html' => $publication['html'],
            'target_ts' => $publication['target_ts'],
        );
    }

    /**
     * Return countdown message in days/hours/minutes until automatic publication.
     */
    private static function build_countdown_message($target_ts)
    {
        $now       = time();
        $remaining = max(0, $target_ts - $now);

        $days    = (int) floor($remaining / DAY_IN_SECONDS);
        $hours   = (int) floor(($remaining % DAY_IN_SECONDS) / HOUR_IN_SECONDS);
        $minutes = (int) floor(($remaining % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);

        try {
            $tz = wp_timezone();
        } catch (Exception $e) {
            $tz = new DateTimeZone('UTC');
        }

        $target_dt = (new DateTimeImmutable('@' . (int) $target_ts))->setTimezone($tz);
        $weekday_key = $target_dt->format('N');

        $weekday_map = array(
            '1' => 'v pondelok',
            '2' => 'v utorok',
            '3' => 'v stredu',
            '4' => 'vo štvrtok',
            '5' => 'v piatok',
            '6' => 'v sobotu',
            '7' => 'v nedeľu',
        );

        $weekday_phrase = $weekday_map[$weekday_key] ?? 'v tento deň';
        $date_phrase    = $target_dt->format('j.n.Y');

        $time_phrase = 'o polnoci';
        if ('00:00' !== $target_dt->format('H:i')) {
            $time_phrase = 'o ' . $target_dt->format('G:i');
        }

        return sprintf(
            'Tieto oznamy sa automaticky zverejnia %1$s %2$s %3$s. T.j. o %4$d dní, %5$d hodín, %6$d minút.',
            $weekday_phrase,
            $date_phrase,
            $time_phrase,
            $days,
            $hours,
            $minutes
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return string
     */
    private static function resolve_render_mode($settings)
    {
        $mode = sanitize_key((string) ($settings['render_mode'] ?? 'auto'));
        if (! in_array($mode, array('auto', 'docs_api', 'legacy_html_export'), true)) {
            return 'auto';
        }

        return $mode;
    }

    /**
     * Shortcodes in this plugin now work without attributes.
     *
     * @param mixed $atts
     * @return bool
     */
    private static function has_unsupported_attributes($atts)
    {
        return is_array($atts) && ! empty($atts);
    }

    /**
     * Render a user-facing notice when no announcements are available.
     *
     * @param bool $future True for future announcements, false for published.
     * @return string
     */
    private static function render_no_content_notice($future, $modern = false)
    {
        $message = $future
            ? self::NO_FUTURE_ANNOUNCEMENTS_MESSAGE
            : self::NO_PUBLISHED_ANNOUNCEMENTS_MESSAGE;

        if ($future) {
            return '<div class="oznamy-content oznamy-empty">' . esc_html($message) . '</div>';
        }

        self::enqueue_archive_script();
        return '<div class="oznamy-content oznamy-empty" data-oznamy-archive-text="'
            . ($modern ? 'modern' : 'plain') . '">'
            . esc_html($message) . '</div>';
    }

    /**
     * Determine whether an error means there is no content to display.
     *
     * @param WP_Error $error
     * @param bool $future
     * @return bool
     */
    private static function is_no_content_error($error, $future)
    {
        $codes = $future
            ? array('oznamy_no_future_subfolder', 'oznamy_not_future', 'oznamy_no_doc', 'oznamy_empty_doc')
            : array('oznamy_no_subfolder', 'oznamy_no_doc', 'oznamy_empty_doc', 'oznamy_no_ready_announcement');

        return is_wp_error($error) && in_array($error->get_error_code(), $codes, true);
    }

    /**
     * Render publication controls for visitors who unlocked the preparation page.
     *
     * @param array<string, string> $atts
     * @return string
     */
    public static function render_publication_admin($atts)
    {
        if (self::has_unsupported_attributes($atts)) {
            return '<div class="oznamy-publication-admin__error">'
                . esc_html(self::UNSUPPORTED_ATTS_MESSAGE)
                . '</div>';
        }
        $page_id = get_queried_object_id();
        if (! self::is_preparation_page_unlocked($page_id)) {
            return '<div class="oznamy-publication-admin__error">'
                . esc_html__('Stránku najprv odomknite jej heslom.', 'oznamy-gdocs')
                . '</div>';
        }
        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        $settings = get_option(OZNAMY_OPTION_KEY, array());
        $data = self::get_publication_admin_data($settings);
        if (is_wp_error($data)) {
            return '<div class="oznamy-publication-admin__error">'
                . esc_html__('Zoznam verzií sa nepodarilo načítať. Skontrolujte pripojenie k Google Drive.', 'oznamy-gdocs')
                . '</div>';
        }

        $result = isset($_GET['oznamy_admin_result'])
            ? sanitize_key(wp_unslash($_GET['oznamy_admin_result']))
            : '';
        $messages = array(
            'invalid' => __('Stav verzie sa medzitým zmenil alebo túto akciu nemožno vykonať.', 'oznamy-gdocs'),
            'unavailable' => __('Zvolenú verziu sa nepodarilo overiť na Google Drive.', 'oznamy-gdocs'),
            'error' => __('Zmenu sa nepodarilo uložiť. Skúste to znova.', 'oznamy-gdocs'),
        );

        ob_start();
?>
        <div class="oznamy-publication-admin">
            <?php if (isset($messages[$result])) : ?>
                <p class="oznamy-publication-admin__notice" role="status"><?php echo esc_html($messages[$result]); ?></p>
            <?php endif; ?>
            <?php if ($data['current_error']) : ?>
                <p class="oznamy-publication-admin__error">
                    <?php esc_html_e('Aktuálne zverejnenú verziu sa nepodarilo overiť. Skontrolujte pripojenie a obsah priečinkov.', 'oznamy-gdocs'); ?>
                </p>
            <?php endif; ?>
            <?php if (empty($data['rows'])) : ?>
                <p><?php esc_html_e('Nenašli sa žiadne nedeľné verzie oznamov.', 'oznamy-gdocs'); ?></p>
            <?php else : ?>
                <ul class="oznamy-publication-admin__list">
                    <?php foreach ($data['rows'] as $row) : ?>
                        <?php
                        $folder = $row['folder'];
                        if ('hidden' === $row['state']) {
                            $status = __('Skryté', 'oznamy-gdocs');
                        } elseif ($row['early_active']) {
                            $status = __('Zverejnené vopred', 'oznamy-gdocs');
                        } elseif ('current' === $row['role']) {
                            $status = __('Zverejnené', 'oznamy-gdocs');
                        } elseif ('upcoming' === $row['role']) {
                            $status = __('Pripravované', 'oznamy-gdocs');
                        } else {
                            $status = __('Historické', 'oznamy-gdocs');
                        }
                        ?>
                        <li class="oznamy-publication-admin__item">
                            <div class="oznamy-publication-admin__version">
                                <strong>
                                    <?php
                                    printf(
                                        esc_html__('%1$s (%2$s)', 'oznamy-gdocs'),
                                        esc_html($folder['name']),
                                        esc_html($status)
                                    );
                                    ?>
                                </strong>
                                <?php if ('upcoming' === $row['role']) : ?>
                                    <span><?php echo esc_html(self::build_countdown_message($row['target_ts'])); ?></span>
                                    <?php if (! $row['publishable'] && 'hidden' !== $row['state']) : ?>
                                        <span class="oznamy-publication-admin__error">
                                            <?php esc_html_e('Túto verziu nemožno jednoznačne overiť; predčasné zverejnenie nie je dostupné.', 'oznamy-gdocs'); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="oznamy-publication-admin__actions">
                                <?php if ('hidden' === $row['state']) : ?>
                                    <?php echo self::render_version_action_button($folder['id'], 'unhide', __('Zrušiť skrytie oznamov', 'oznamy-gdocs'), 'is-secondary', __('Zrušíte skrytie tejto verzie. Oznamy sa budú zobrazovať podľa bežného plánu zverejňovania.', 'oznamy-gdocs'), $page_id); ?>
                                <?php elseif ($row['early_active']) : ?>
                                    <?php echo self::render_version_action_button($folder['id'], 'cancel_early', __('Zrušiť predčasné zverejnenie', 'oznamy-gdocs'), 'is-secondary', __('Zrušíte predčasné zverejnenie tejto verzie. Zverejní sa až v pôvodne plánovanom termíne.', 'oznamy-gdocs'), $page_id); ?>
                                <?php else : ?>
                                    <?php if ('upcoming' === $row['role'] && $row['publishable']) : ?>
                                        <?php echo self::render_version_action_button($folder['id'], 'publish_now', __('Zverejniť teraz', 'oznamy-gdocs'), 'is-primary', __('Zverejníte túto pripravovanú verziu oznamov okamžite, bez čakania na plánovaný termín.', 'oznamy-gdocs'), $page_id); ?>
                                    <?php endif; ?>
                                    <?php echo self::render_version_action_button($folder['id'], 'hide', __('Skryť tieto oznamy', 'oznamy-gdocs'), 'is-danger', __('Skryjete túto verziu oznamov pred návštevníkmi stránky. Kým skrytie nezrušíte, na stránke sa nebude zobrazovať.', 'oznamy-gdocs'), $page_id); ?>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (! empty($data['invalid_folders'])) : ?>
                <section class="oznamy-publication-admin__invalid">
                    <ul class="oznamy-publication-admin__invalid-list">
                        <?php foreach ($data['invalid_folders'] as $invalid_folder) : ?>
                            <?php
                            $error_messages = array(
                                'legacy_format' => __('Starší formát názvu nie je podporovaný. Použi Oznamy_RRRR.MM.DD, napríklad Oznamy_2026.10.11.', 'oznamy-gdocs'),
                                'invalid_format' => __('Neplatný formát názvu. Použi Oznamy_RRRR.MM.DD, napríklad Oznamy_2026.10.11.', 'oznamy-gdocs'),
                                'invalid_date' => __('Dátum v názve neexistuje. Skontroluj rok, mesiac a deň.', 'oznamy-gdocs'),
                                'not_sunday' => __('Dátum je platný, ale nie je nedeľa. Verzie oznamov musia mať nedeľný dátum.', 'oznamy-gdocs'),
                                'duplicate_date' => __('Pre tento nedeľný dátum existuje viac priečinkov. Ponechaj iba jeden viditeľný priečinok s týmto dátumom.', 'oznamy-gdocs'),
                            );
                            $error_message = $error_messages[$invalid_folder['error_code']]
                                ?? __('Priečinok nespĺňa pravidlá pre verziu farských oznamov.', 'oznamy-gdocs');
                            ?>
                            <li class="oznamy-publication-admin__invalid-item">
                                <strong><?php echo esc_html($invalid_folder['name']); ?></strong>
                                <span><?php echo esc_html($error_message); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </div>
    <?php
        return (string) ob_get_clean();
    }

    private static function get_publication_admin_data($settings)
    {
        $root_folder_id = sanitize_text_field($settings['folder_id'] ?? '');
        $service_account = json_decode($settings['sa_json'] ?? '', true);
        if (! $root_folder_id || ! is_array($service_account)) {
            Oznamy_Load_Health::record_issue('announcement_versions', 'Plugin nie je nakonfigurovaný.');
            return new WP_Error('oznamy_not_configured', 'Plugin nie je nakonfigurovaný.');
        }

        $client = new Oznamy_GDrive_Client($service_account);
        $folder_listing = $client->list_subfolders_with_validation($root_folder_id, true);
        if (is_wp_error($folder_listing)) {
            Oznamy_Load_Health::record_issue('announcement_versions', $folder_listing->get_error_message());
            return $folder_listing;
        }
        $folders = $folder_listing['valid'];
        $invalid_folders = $folder_listing['invalid'];
        if (! empty($invalid_folders)) {
            $invalid_names = array_slice(array_map(static function ($folder) {
                return (string) ($folder['name'] ?? '');
            }, $invalid_folders), 0, 5);
            Oznamy_Load_Health::record_issue(
                'announcement_versions',
                sprintf(
                    /* translators: %s: názvy priečinkov s neplatnou verziou oznamov */
                    __('Neplatné priečinky: %s', 'oznamy-gdocs'),
                    implode(', ', $invalid_names)
                )
            );
        } else {
            Oznamy_Load_Health::resolve_issue('announcement_versions');
        }
        $states = self::get_version_states();

        $render_mode = self::resolve_render_mode($settings);
        $current = self::resolve_current_publication($render_mode);
        $current_id = is_array($current) ? (string) ($current['folder']['id'] ?? '') : '';
        $future_group = self::get_next_visible_future_folder_group(
            $folders,
            $settings,
            is_array($current) ? $current : null
        );
        $future = self::resolve_future_publication($render_mode);
        $publishable_id = is_array($future) ? (string) ($future['folder']['id'] ?? '') : '';
        $upcoming_ids = array();
        if (is_array($future_group)) {
            foreach ($future_group['folders'] as $folder) {
                $upcoming_ids[(string) $folder['id']] = true;
            }
        }

        $groups = array();
        $timezone = wp_timezone();
        foreach ($folders as $folder) {
            $folder_date = (new DateTimeImmutable('@' . (int) $folder['date']))->setTimezone($timezone);
            if ('7' !== $folder_date->format('N')) {
                continue;
            }

            $date_key = $folder_date->format('Y-m-d');
            if (! isset($groups[$date_key])) {
                $groups[$date_key] = array();
            }
            $groups[$date_key][] = $folder;
        }

        $included_ids = array();
        if ($current_id) {
            $included_ids[$current_id] = true;
        }
        foreach ($upcoming_ids as $folder_id => $unused) {
            $included_ids[$folder_id] = true;
        }

        $archive_count = max(0, min(self::MAX_ARCHIVE_LIMIT, (int) ($settings['archive_count'] ?? 4)));
        $history_cutoff = self::get_publication_base_sunday(
            $settings,
            is_array($current) ? $current : null
        )->format('Y-m-d');
        $current_date_key = is_array($current)
            ? wp_date('Y-m-d', (int) $current['folder']['date'])
            : '';
        $historical_dates = array();
        foreach ($groups as $date_key => $date_folders) {
            if ($date_key <= $history_cutoff && $date_key !== $current_date_key) {
                $historical_dates[] = $date_key;
            }
        }
        rsort($historical_dates);
        foreach (array_slice($historical_dates, 0, $archive_count) as $date_key) {
            foreach ($groups[$date_key] as $folder) {
                $included_ids[(string) $folder['id']] = true;
            }
        }

        foreach ($groups as $date_key => $date_folders) {
            $visible_date_folders = array_values(array_filter($date_folders, static function ($folder) use ($states) {
                return ! self::is_version_hidden($folder['id'], $states);
            }));
            if (count($visible_date_folders) > 1) {
                foreach ($visible_date_folders as $folder) {
                    $invalid_folders[] = array(
                        'id' => (string) $folder['id'],
                        'name' => (string) $folder['name'],
                        'error_code' => 'duplicate_date',
                    );
                }
            }
        }
        usort($invalid_folders, static function ($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });

        foreach ($folders as $folder) {
            if (self::is_version_hidden($folder['id'], $states)) {
                $included_ids[(string) $folder['id']] = true;
            }
        }

        $rows = array();
        $cycle_sunday = self::get_current_sunday($settings);
        foreach ($folders as $folder) {
            $folder_id = (string) $folder['id'];
            if (! isset($included_ids[$folder_id])) {
                continue;
            }

            $state = self::get_version_state($folder_id, $states);
            $folder_is_early = 'early_published' === $state
                && (int) $folder['date'] > $cycle_sunday->getTimestamp();
            if ('hidden' === $state) {
                $role = 'hidden';
            } elseif ($folder_id === $current_id) {
                $role = 'current';
            } elseif (isset($upcoming_ids[$folder_id])) {
                $role = 'upcoming';
            } else {
                $role = 'historical';
            }

            $rows[] = array(
                'folder' => $folder,
                'state' => $state,
                'role' => $role,
                'early_active' => $folder_is_early,
                'publishable' => $folder_id === $publishable_id,
                'target_ts' => is_array($future_group) ? (int) $future_group['target_ts'] : 0,
            );
        }

        usort($rows, static function ($a, $b) {
            if ((int) $a['folder']['date'] === (int) $b['folder']['date']) {
                return strcmp((string) $a['folder']['name'], (string) $b['folder']['name']);
            }
            return (int) $b['folder']['date'] <=> (int) $a['folder']['date'];
        });

        return array(
            'rows' => $rows,
            'invalid_folders' => $invalid_folders,
            'current_error' => is_wp_error($current),
        );
    }

    private static function render_version_action_button($folder_id, $command, $label, $class, $tooltip, $page_id)
    {
        $nonce_action = 'oznamy_version_action_' . $folder_id;
        $tooltip_id = wp_unique_id('oznamy-version-tooltip-');
        $output = '<form class="oznamy-publication-admin__form" method="post" action="'
            . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="oznamy_version_action">'
            . '<input type="hidden" name="oznamy_access_page_id" value="' . esc_attr($page_id) . '">'
            . '<input type="hidden" name="oznamy_version_folder_id" value="' . esc_attr($folder_id) . '">'
            . '<input type="hidden" name="oznamy_version_command" value="' . esc_attr($command) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce($nonce_action)) . '">'
            . '<button type="submit" class="oznamy-publication-admin__button ' . esc_attr($class) . '" aria-describedby="' . esc_attr($tooltip_id) . '">'
            . esc_html($label) . '</button>'
            . '<span class="oznamy-publication-admin__tooltip" id="' . esc_attr($tooltip_id) . '" role="tooltip">'
            . esc_html($tooltip) . '</span></form>';

        return $output;
    }

    public static function handle_version_action()
    {
        $page_id = isset($_POST['oznamy_access_page_id'])
            ? absint(wp_unslash($_POST['oznamy_access_page_id']))
            : 0;
        if (! self::is_preparation_page_unlocked($page_id)) {
            wp_die(esc_html__('Stránku najprv odomknite jej heslom.', 'oznamy-gdocs'), 403);
        }

        $folder_id = isset($_POST['oznamy_version_folder_id'])
            ? sanitize_text_field(wp_unslash($_POST['oznamy_version_folder_id']))
            : '';
        if ('' === $folder_id) {
            wp_die(esc_html__('Neplatná verzia oznamov.', 'oznamy-gdocs'), 400);
        }
        check_admin_referer('oznamy_version_action_' . $folder_id);

        $command = isset($_POST['oznamy_version_command'])
            ? sanitize_key(wp_unslash($_POST['oznamy_version_command']))
            : '';
        if (! in_array($command, array('hide', 'unhide', 'publish_now', 'cancel_early'), true)) {
            wp_die(esc_html__('Neplatná akcia.', 'oznamy-gdocs'), 400);
        }

        $settings = get_option(OZNAMY_OPTION_KEY, array());
        $data = self::get_publication_admin_data($settings);
        if (is_wp_error($data)) {
            error_log('[Oznamy] Version action target error: ' . $data->get_error_message());
            self::redirect_version_action('unavailable');
        }

        $row = null;
        foreach ($data['rows'] as $candidate) {
            if ((string) $candidate['folder']['id'] === $folder_id) {
                $row = $candidate;
                break;
            }
        }
        if (! is_array($row)) {
            self::redirect_version_action('invalid');
        }

        $result = '';
        if (
            'hide' === $command
            && in_array($row['state'], array('', 'early_published'), true)
            && ! $row['early_active']
        ) {
            if (! self::set_version_state($folder_id, 'hidden')) {
                self::redirect_version_action('error');
            }
            $result = 'hidden';
        } elseif ('unhide' === $command && 'hidden' === $row['state']) {
            if (! self::set_version_state($folder_id, '')) {
                self::redirect_version_action('error');
            }
            $result = 'unhidden';
        } elseif (
            'publish_now' === $command
            && 'upcoming' === $row['role']
            && '' === $row['state']
            && $row['publishable']
        ) {
            if (! self::set_version_state($folder_id, 'early_published')) {
                self::redirect_version_action('error');
            }
            $result = 'published';
        } elseif (
            'cancel_early' === $command
            && 'early_published' === $row['state']
            && $row['early_active']
        ) {
            if (! self::set_version_state($folder_id, '')) {
                self::redirect_version_action('error');
            }
            $result = 'cancelled';
        } else {
            self::redirect_version_action('invalid');
        }

        self::clear_runtime_content_cache();
        self::schedule_next_release_cache_purge($settings);
        self::clear_wp_super_cache_for_shortcode_pages();
        self::redirect_version_action($result);
    }

    private static function redirect_version_action($result)
    {
        $referer = wp_get_referer();
        if (! $referer) {
            $referer = home_url('/');
        }
        wp_safe_redirect(add_query_arg('oznamy_admin_result', sanitize_key($result), $referer));
        exit;
    }

    private static function is_preparation_page_unlocked($page_id)
    {
        $post = get_post(absint($page_id));
        if (
            ! ($post instanceof WP_Post)
            || 'page' !== $post->post_type
            || 'priprava-farskych-oznamov' !== $post->post_name
            || '' === $post->post_password
        ) {
            return false;
        }

        return ! post_password_required($post);
    }

    /**
     * [nacitat-farske-oznamy] shortcode – renders the protected-page refresh button.
     *
     * Usage:
     *   [nacitat-farske-oznamy]                          → refresh button
     *
     * @param array<string, string> $atts
     * @return string HTML output
     */
    public static function render_refresh_button($atts)
    {
        if (self::has_unsupported_attributes($atts)) {
            return '<div class="oznamy-content oznamy-error">'
                . esc_html(self::UNSUPPORTED_ATTS_MESSAGE)
                . '</div>';
        }
        $page_id = get_queried_object_id();
        if (! self::is_preparation_page_unlocked($page_id)) {
            return '<div class="oznamy-content oznamy-error">'
                . esc_html__('Stránku najprv odomknite jej heslom.', 'oznamy-gdocs')
                . '</div>';
        }
        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        $label = 'Obnoviť farské oznamy a úmysly omší';
        $nonce = wp_create_nonce('oznamy_frontend_refresh');
        $ajax_url = esc_url(admin_url('admin-ajax.php'));

        ob_start();
    ?>
        <span class="oznamy-refresh-wrap">
            <button type="button" class="oznamy-refresh-btn" data-nonce="<?php echo esc_attr($nonce); ?>" data-ajaxurl="<?php echo $ajax_url; ?>" data-page-id="<?php echo esc_attr($page_id); ?>">
                <span class="oznamy-refresh-spinner" aria-hidden="true"></span>
                <span class="oznamy-refresh-btn-label"><?php echo esc_html($label); ?></span>
            </button>
            <span class="oznamy-refresh-status" aria-live="polite"></span>
        </span>
        <script>
            (function() {
                var btn = document.currentScript.previousElementSibling.querySelector('.oznamy-refresh-btn');
                var status = btn.parentNode.querySelector('.oznamy-refresh-status');
                var failureMessage = '<?php echo esc_js(__('Obnovenie bolo neúspešné.', 'oznamy-gdocs')); ?>';

                function getFailureMessage(response) {
                    var message = response && response.data && response.data.message;

                    if ('Plugin not configured.' === message) {
                        return failureMessage + ' ' + '<?php echo esc_js(__('Plugin nie je správne nakonfigurovaný.', 'oznamy-gdocs')); ?>';
                    }
                    if ('Invalid Service Account JSON.' === message) {
                        return failureMessage + ' ' + '<?php echo esc_js(__('Konfiguračný JSON Service Account-u nie je platný.', 'oznamy-gdocs')); ?>';
                    }
                    if ('Page is not unlocked.' === message) {
                        return failureMessage + ' ' + '<?php echo esc_js(__('Stránka už nie je odomknutá. Zadajte jej heslo a skúste to znova.', 'oznamy-gdocs')); ?>';
                    }
                    return failureMessage;
                }

                btn.addEventListener('click', function() {
                    btn.disabled = true;
                    btn.classList.add('is-loading');
                    status.textContent = '<?php echo esc_js(__('Načítavam…', 'oznamy-gdocs')); ?>';
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', btn.getAttribute('data-ajaxurl'), true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        btn.disabled = false;
                        btn.classList.remove('is-loading');
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.success) {
                                status.textContent = '<?php echo esc_js(__('Obnovenie bolo úspešné.', 'oznamy-gdocs')); ?>';
                                window.setTimeout(function() {
                                    window.location.reload();
                                }, 700);
                            } else {
                                status.textContent = getFailureMessage(res);
                            }
                        } catch (e) {
                            status.textContent = failureMessage;
                        }
                    };
                    xhr.onerror = function() {
                        status.textContent = failureMessage + ' ' + '<?php echo esc_js(__('Nepodarilo sa spojiť so serverom.', 'oznamy-gdocs')); ?>';
                        btn.disabled = false;
                        btn.classList.remove('is-loading');
                    };
                    xhr.send(
                        'action=oznamy_frontend_refresh' +
                        '&nonce=' + encodeURIComponent(btn.getAttribute('data-nonce')) +
                        '&page_id=' + encodeURIComponent(btn.getAttribute('data-page-id'))
                    );
                });
            })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler for the frontend refresh button.
     * Registered for both logged-in and anonymous visitors.
     */
    public static function handle_ajax_refresh()
    {
        $page_id = isset($_POST['page_id'])
            ? absint(wp_unslash($_POST['page_id']))
            : 0;
        if (! self::is_preparation_page_unlocked($page_id)) {
            wp_send_json_error(array('message' => 'Page is not unlocked.'), 403);
        }

        if (! isset($_POST['nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'oznamy_frontend_refresh')) {
            wp_send_json_error(array('message' => 'Invalid nonce.'), 403);
        }

        $sync = self::refresh_from_drive();
        if (! empty($sync['error'])) {
            wp_send_json_error(array('message' => (string) $sync['error']), 500);
        }

        wp_send_json_success(
            array(
                'updated' => array(
                    'latest_doc'    => ! empty($sync['latest_doc']),
                    'future_doc'    => ! empty($sync['future_doc']),
                    'latest_images' => ! empty($sync['latest_images']),
                    'future_images' => ! empty($sync['future_images']),
                    'mass_intentions' => ! empty($sync['mass_intentions']),
                ),
                'cache_cleared_pages' => $sync['supercache_pages'] ?? array(),
            )
        );
    }

    /**
     * Refresh all runtime outputs from the current Google Drive state.
     *
     * This intentionally clears stale/runtime cache first so deleted Drive
     * documents/images disappear from WordPress after refresh.
     *
     * @return array
     */
    public static function refresh_from_drive()
    {
        $result = array(
            'error'         => '',
            'latest_doc'    => false,
            'future_doc'    => false,
            'latest_images' => false,
            'future_images' => false,
            'mass_intentions' => false,
            'mass_intentions_error' => '',
            'doc_name'      => '',
        );

        $settings    = get_option(OZNAMY_OPTION_KEY, array());
        $folder_id   = sanitize_text_field($settings['folder_id'] ?? '');
        $sa_json     = $settings['sa_json'] ?? '';
        $render_mode = self::resolve_render_mode($settings);
        $visibility  = self::get_title_date_visibility($settings);

        if (! $folder_id || ! $sa_json) {
            $result['error'] = 'Plugin not configured.';
            Oznamy_Load_Health::record_issue('announcements', $result['error']);
            return $result;
        }

        $sa_data = json_decode($sa_json, true);
        if (! is_array($sa_data)) {
            $result['error'] = 'Invalid Service Account JSON.';
            Oznamy_Load_Health::record_issue('announcements', $result['error']);
            return $result;
        }

        // Strict refresh: drop stale/runtime values and local image cache first.
        self::clear_runtime_content_cache();
        self::clear_image_cache();

        $ttl = absint($settings['cache_ttl'] ?? 3600);

        $publication = self::resolve_current_publication($render_mode);
        $current_ttl = self::get_current_publication_cache_ttl($publication, $settings);
        if (! is_wp_error($publication)) {
            $result['doc_name'] = (string) ($publication['folder']['name'] ?? '');
        }

        $latest_html = self::fetch_content($render_mode);
        if (is_wp_error($latest_html)) {
            Oznamy_Load_Health::record_issue('announcements', $latest_html->get_error_message());
        } elseif ('' !== (string) $latest_html) {
            $slug = self::get_current_cycle_slug($settings, $publication);
            set_transient(OZNAMY_CACHE_KEY_PREFIX . $render_mode . '_' . $slug, $latest_html, $current_ttl);
            update_option('oznamy_stale_' . $render_mode . '_latest', $latest_html, false);
            $result['latest_doc'] = true;
            Oznamy_Load_Health::resolve_issue('announcements');
        }

        $future = self::fetch_future_content($render_mode);
        if (! is_wp_error($future) && is_array($future) && ! empty($future['html'])) {
            $slug = self::get_next_future_cycle_slug($settings);
            foreach (array(false, true) as $modern) {
                $cache_variant = self::get_future_cache_variant($modern, $visibility);
                $cache_key = OZNAMY_CACHE_KEY_PREFIX . 'future_' . $render_mode . $cache_variant . '_' . $slug;
                $output = self::build_future_output($future, $modern, $visibility);

                set_transient($cache_key, $output, self::get_future_output_cache_ttl($settings));
                update_option('oznamy_stale_future_' . $render_mode . $cache_variant . '_' . $slug, $output, false);
            }
            update_option(
                'oznamy_stale_future_source_' . $render_mode . '_' . $slug,
                array('html' => (string) $future['html'], 'target_ts' => (int) $future['target_ts']),
                false
            );
            $result['future_doc'] = true;
            Oznamy_Load_Health::resolve_issue('future_announcements');
        } elseif (is_wp_error($future)) {
            if (self::is_cacheable_future_miss($future)) {
                if (in_array($future->get_error_code(), array('oznamy_no_doc', 'oznamy_empty_doc', 'oznamy_ambiguous_future_subfolder'), true)) {
                    Oznamy_Load_Health::record_issue('future_announcements', $future->get_error_message());
                } else {
                    Oznamy_Load_Health::resolve_issue('future_announcements');
                }
            } else {
                Oznamy_Load_Health::record_issue('future_announcements', $future->get_error_message());
            }
        }

        $latest_images = self::fetch_images(false);
        if (! is_wp_error($latest_images) && '' !== (string) $latest_images) {
            $slug = self::get_current_cycle_slug($settings, $publication);
            set_transient(OZNAMY_CACHE_KEY_PREFIX . 'images_' . $slug, (string) $latest_images, $current_ttl);
            update_option('oznamy_stale_images_latest', (string) $latest_images, false);
            $result['latest_images'] = true;
        }

        $future_images = self::fetch_images(true);
        if (! is_wp_error($future_images) && is_array($future_images) && ! empty($future_images['html'])) {
            $slug = self::get_next_future_cycle_slug($settings);
            $html = (string) $future_images['html'];
            set_transient(OZNAMY_CACHE_KEY_PREFIX . 'future_images_' . $slug, $html, $ttl);
            update_option('oznamy_stale_future_images_' . $slug, $html, false);
            $result['future_images'] = true;
        }

        $intentions = Oznamy_Mass_Intentions::get_schedule(true);
        if (is_wp_error($intentions)) {
            $result['mass_intentions_error'] = $intentions->get_error_message();
            error_log('[Oznamy] Mass intentions refresh failed: ' . $intentions->get_error_message());
        } else {
            $result['mass_intentions'] = true;
        }

        $supercache = self::clear_wp_super_cache_for_shortcode_pages();
        $result['supercache_cleared'] = $supercache['cleared'];
        $result['supercache_pages']   = $supercache['pages'];

        return $result;
    }

    /**
     * Purge WP Super Cache for published pages/posts that use one of this plugin's
     * shortcodes, plus the parish and preparation pages (always, regardless of shortcode detection),
     * so visitors don't see stale cached HTML after a refresh.
     * No-ops silently if WP Super Cache isn't installed/active.
     *
     * @return array{cleared:int,pages:string[]}
     */
    public static function purge_shortcode_pages_cache()
    {
        return self::clear_wp_super_cache_for_shortcode_pages();
    }

    private static function clear_wp_super_cache_for_shortcode_pages()
    {
        $outcome = array('cleared' => 0, 'pages' => array());

        if (! function_exists('wpsc_delete_url_cache')) {
            return $outcome;
        }

        global $wpdb;
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s)",
                'publish',
                '%[farske_oznamy%',
                '%[nacitat-farske-oznamy%',
                '%[umysly-svatych-omsi%'
            )
        );

        $targets = array();
        foreach ($ids as $id) {
            $url = get_permalink((int) $id);
            if ($url) {
                $targets[$url] = get_the_title((int) $id) ?: $url;
            }
        }

        // Always purge the parish page, regardless of whether it uses a shortcode.
        $farnost_page = get_page_by_path('farnost');
        if ($farnost_page) {
            $targets[get_permalink($farnost_page)] = $farnost_page->post_title ?: 'Farnosť';
        } else {
            $targets[home_url('/farnost/')] = 'Farnosť';
        }

        // Always purge the announcements preparation page, regardless of shortcode detection.
        $preparation_page = get_page_by_path('priprava-farskych-oznamov');
        if ($preparation_page) {
            $targets[get_permalink($preparation_page)] = $preparation_page->post_title ?: 'Príprava farských oznamov';
        } else {
            $targets[home_url('/priprava-farskych-oznamov/')] = 'Príprava farských oznamov';
        }

        foreach ($targets as $url => $title) {
            if (wpsc_delete_url_cache($url)) {
                $outcome['cleared']++;
                $outcome['pages'][] = $title;
            }
        }

        return $outcome;
    }

    /**
     * Clear runtime transients + stale option fallbacks for strict re-sync.
     */
    private static function clear_runtime_content_cache()
    {
        global $wpdb;
        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                '_transient_oznamy_cache_%',
                '_transient_timeout_oznamy_cache_%',
                'oznamy_stale_%'
            )
        );
        if (is_array($option_names)) {
            foreach ($option_names as $option_name) {
                wp_cache_delete($option_name, 'options');
            }
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                '_transient_oznamy_cache_%',
                '_transient_timeout_oznamy_cache_%',
                'oznamy_stale_%'
            )
        );
    }

    /**
     * Persist the current subfolder/doc to sync history.
     *
     * @param array{id: string, name: string, date: int} $subfolder
     * @param string $doc_id
     */
    private static function update_history($subfolder, $doc_id)
    {
        $history = get_option(OZNAMY_HISTORY_KEY, array());

        try {
            $tz = wp_timezone();
            $dt = (new DateTimeImmutable('@' . $subfolder['date']))->setTimezone($tz);
            $date_key = $dt->format('d.m.Y');
        } catch (Exception $e) {
            $date_key = gmdate('d.m.Y', $subfolder['date']);
        }

        // Use timestamp as key for correct numeric sort (newest first).
        $history[(string) $subfolder['date']] = array(
            'id'          => $doc_id,
            'name'        => $subfolder['name'],
            'date_parsed' => $date_key,
            'cached_at'   => time(),
        );

        // Newest first; keep at most 52 entries (one year).
        krsort($history);
        $history = array_slice($history, 0, 52, true);

        update_option(OZNAMY_HISTORY_KEY, $history, false);
    }

	// -------------------------------------------------------------------------
	// Images shortcode [farske_oznamy_obrazky]
	// -------------------------------------------------------------------------

    /**
     * [farske_oznamy_obrazky] shortcode – renders image gallery from the current subfolder.
     *
     * @param array<string, string> $atts
     * @return string HTML output
     */
    public static function render_images($atts)
    {
        $atts = is_array($atts) ? $atts : array();
        if (array_diff(array_keys($atts), array('columns'))) {
            return '';
        }

        $columns = isset($atts['columns']) ? sanitize_key((string) $atts['columns']) : '';
        if ('' !== $columns && '2' !== $columns) {
            return '';
        }
        $two_columns = '2' === $columns;

        self::enqueue_archive_script();
        self::enqueue_lightbox_script();

        // Miniatúry a originály majú oddelenú cache, aby sa nikdy nemiešali.
        $variant   = self::$original_images ? '_full' : '';
        $settings  = get_option(OZNAMY_OPTION_KEY, array());
        self::schedule_next_release_cache_purge($settings);
        $publication = self::resolve_current_publication(self::resolve_render_mode($settings));
        self::prevent_page_cache_for_fallback($publication, $settings);
        $slug      = self::get_current_cycle_slug($settings, $publication);
        $stale_slug = 'latest' . $variant;
        $cache_key = OZNAMY_CACHE_KEY_PREFIX . 'images_' . $slug . $variant;
        $cached    = get_transient($cache_key);

        if (false !== $cached) {
            return self::render_archive_gallery_output((string) $cached, $two_columns);
        }

        $html = self::fetch_images(false);

        if (is_wp_error($html)) {
            error_log('[Oznamy] Images shortcode error: ' . $html->get_error_message());

            $stale = get_option('oznamy_stale_images_' . $stale_slug);
            if ($stale) {
                return self::render_archive_gallery_output((string) $stale, $two_columns);
            }

            return self::render_archive_gallery_output('', $two_columns); // Images are optional – no visible error shown.
        }

        if ('' === $html) {
            return self::render_archive_gallery_output('', $two_columns); // No images in this subfolder.
        }

        $ttl = self::get_current_publication_cache_ttl($publication, $settings);
        set_transient($cache_key, $html, $ttl);
        update_option('oznamy_stale_images_' . $stale_slug, $html, false);

        return self::render_archive_gallery_output($html, $two_columns);
    }

    /**
     * Keep a stable current-gallery target even when the current week has no posters.
     */
    private static function render_archive_gallery_output($html, $two_columns = false)
    {
        if ('' === trim($html)) {
            $html = self::build_images_html(array());
        }

        $class = $two_columns ? ' oznamy-archive-gallery--two-columns' : '';

        return '<div class="oznamy-archive-gallery' . $class . '">' . $html . '</div>';
    }

    /**
     * [farske_oznamy_buduce_obrazky] shortcode – renders image gallery for nearest future subfolder.
     *
     * @param array<string, string> $atts
     * @return string HTML output
     */
    public static function render_future_images($atts)
    {
        if (self::has_unsupported_attributes($atts)) {
            return '';
        }

        self::enqueue_lightbox_script();

        $settings  = get_option(OZNAMY_OPTION_KEY, array());
        self::schedule_next_release_cache_purge($settings);
        $slug      = self::get_next_future_cycle_slug($settings);
        $cache_key = OZNAMY_CACHE_KEY_PREFIX . 'future_images_' . $slug;
        $cached    = get_transient($cache_key);

        if (false !== $cached) {
            return (string) $cached;
        }

        $miss_cache_key = self::future_miss_cache_key($cache_key);
        if (false !== get_transient($miss_cache_key)) {
            self::prevent_page_cache_for_fallback(new WP_Error('oznamy_no_future_subfolder', ''), $settings);
            return '';
        }

        $result = self::fetch_images(true);

        if (is_wp_error($result)) {
            self::prevent_page_cache_for_fallback($result, $settings);
            if (self::is_cacheable_future_miss($result)) {
                set_transient($miss_cache_key, 1, self::FUTURE_MISS_CACHE_TTL);
                return '';
            }
            error_log('[Oznamy] Future images shortcode error: ' . $result->get_error_message());

            $stale = get_option('oznamy_stale_future_images_' . $slug);
            if ($stale) {
                return (string) $stale;
            }

            return '';
        }

        if (! is_array($result) || empty($result['html'])) {
            $ttl = max(60, absint($settings['cache_ttl'] ?? 3600));
            delete_transient($miss_cache_key);
            set_transient($cache_key, '', $ttl);
            return '';
        }

        $output = (string) $result['html'];

        $ttl = absint($settings['cache_ttl'] ?? 3600);
        delete_transient($miss_cache_key);
        set_transient($cache_key, $output, $ttl);
        update_option('oznamy_stale_future_images_' . $slug, $output, false);

        return $output;
    }

    /**
     * Fetch, locally cache and return rendered image gallery HTML.
     *
     * @return string|array{html:string,target_ts?:int}|WP_Error
     */
    private static function fetch_images($future_only)
    {
        $settings  = get_option(OZNAMY_OPTION_KEY, array());
        $folder_id = sanitize_text_field($settings['folder_id'] ?? '');
        $sa_json   = $settings['sa_json'] ?? '';
        $source = $future_only ? 'future_images' : 'images';

        if (! $folder_id || ! $sa_json) {
            $error = new WP_Error('oznamy_not_configured', 'Plugin nie je úplné nakonfigurovaný.');
            Oznamy_Load_Health::record_issue($source, $error->get_error_message());
            return $error;
        }

        $sa_data = json_decode($sa_json, true);
        if (! is_array($sa_data)) {
            $error = new WP_Error('oznamy_bad_json', 'Service Account JSON je neplatný.');
            Oznamy_Load_Health::record_issue($source, $error->get_error_message());
            return $error;
        }

        $client = new Oznamy_GDrive_Client($sa_data);

        if ($future_only) {
            $publication = self::resolve_future_publication(self::resolve_render_mode($settings));
            if (is_wp_error($publication)) {
                if (
                    ! self::is_cacheable_future_miss($publication)
                    || in_array($publication->get_error_code(), array('oznamy_no_doc', 'oznamy_empty_doc', 'oznamy_ambiguous_future_subfolder'), true)
                ) {
                    Oznamy_Load_Health::record_issue($source, $publication->get_error_message());
                } else {
                    Oznamy_Load_Health::resolve_issue($source);
                }
                return $publication;
            }
            $subfolder = $publication['folder'];
        } else {
            $publication = self::resolve_current_publication(self::resolve_render_mode($settings));
            if (is_wp_error($publication)) {
                Oznamy_Load_Health::record_issue($source, $publication->get_error_message());
                return $publication;
            }
            $subfolder = $publication['folder'];
        }

        if (is_wp_error($subfolder)) {
            Oznamy_Load_Health::record_issue($source, $subfolder->get_error_message());
            return $subfolder;
        }

        if (! $subfolder) {
            $error = new WP_Error('oznamy_no_subfolder', 'Nenašiel sa žiadny zodpovedajúci podpriečinok.');
            Oznamy_Load_Health::record_issue($source, $error->get_error_message());
            return $error;
        }

        if ($future_only) {
            try {
                $today_ts = (new DateTimeImmutable('today midnight', wp_timezone()))->getTimestamp();
            } catch (Exception $e) {
                $today_ts = strtotime('today midnight');
            }

            if ((int) $subfolder['date'] <= (int) $today_ts) {
                $error = new WP_Error('oznamy_not_future', 'Vybraný dátum už nie je budúci.');
                Oznamy_Load_Health::record_issue($source, $error->get_error_message());
                return $error;
            }
        }

        $images = $client->list_images_in_folder_result($subfolder['id']);
        if (is_wp_error($images)) {
            Oznamy_Load_Health::record_issue($source, $images->get_error_message());
            return $images;
        }

        if (empty($images)) {
            Oznamy_Load_Health::resolve_issue($source);
            return ''; // No images is valid, not an error.
        }

        $cached_images = array();
        foreach ($images as $image) {
            $cached = self::ensure_image_cached($image['id'], $image['name'], $subfolder['id'], $client);
            if (! is_wp_error($cached)) {
                $cached['name'] = $image['name'];
                $cached_images[] = $cached;
            } else {
                Oznamy_Load_Health::record_issue($source, $cached->get_error_message());
                error_log('[Oznamy] Failed to cache image ' . $image['name'] . ': ' . $cached->get_error_message());
            }
        }

        if (empty($cached_images)) {
            if (empty($images)) {
                Oznamy_Load_Health::resolve_issue($source);
            }
            return '';
        }

        // Sort images alphabetically by name (A-Z)
        usort($cached_images, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        $gallery_html = self::build_images_html($cached_images);
        if (count($cached_images) === count($images)) {
            Oznamy_Load_Health::resolve_issue($source);
        }
        if (! $future_only) {
            return $gallery_html;
        }

        return array(
            'html'      => $gallery_html,
            'target_ts' => (int) $subfolder['date'],
        );
    }

    /** @var bool When true, galleries use the full originals instead of thumbnails (monitor embed). */
    private static $original_images = false;

    /**
     * Build masonry image grid HTML. The lightbox lives in assets/lightbox.js, so the (cached)
     * markup contains only the figures.
     *
     * @param array<int, array{url: string, name: string, thumb_url?: string, thumb_width?: int, thumb_height?: int}> $images
     * @return string
     */
    private static function build_images_html($images)
    {
        ob_start();

        $total = count($images);
        $position = 0;

        echo '<div class="oznamy-images">';
        foreach ($images as $image) {
            $position++;
            $src   = esc_url($image['url']);
            $alt   = esc_attr(self::image_alt_text($image['name'], $position, $total));
            $thumb = (! self::$original_images && ! empty($image['thumb_url'])) ? esc_url($image['thumb_url']) : $src;

            $size_attrs = '';
            if ($thumb !== $src && ! empty($image['thumb_width']) && ! empty($image['thumb_height'])) {
                $size_attrs = ' width="' . (int) $image['thumb_width'] . '" height="' . (int) $image['thumb_height'] . '"';
            }

            echo '<figure class="oznamy-img-item" data-src="' . $src . '" data-alt="' . $alt . '" role="button" tabindex="0">';
            echo '<img src="' . $thumb . '" alt="' . $alt . '"' . $size_attrs . ' loading="lazy" decoding="async">';
            echo '</figure>';
        }
        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Alt text for a gallery image: "Príloha 2 z 6", plus the Drive file name when it reads like a title.
     * Names such as "1.jpg" or "FB_IMG_1793048914160.jpg" say nothing to a screen reader, so they are skipped.
     */
    private static function image_alt_text($name, $position, $total)
    {
        $label = sprintf(__('Príloha %1$d z %2$d', 'oznamy-gdocs'), $position, $total);

        $title = preg_replace('/\.(jpe?g|png|gif|webp)$/i', '', (string) $name);
        $title = trim((string) preg_replace('/[_\s]+/u', ' ', $title));

        $letters = (int) preg_match_all('/\p{L}/u', $title);
        $visible = (int) mb_strlen((string) preg_replace('/\s+/u', '', $title));
        if ($letters >= 4 && $visible > 0 && $letters / $visible >= 0.6) {
            return $label . ': ' . $title;
        }

        return $label;
    }

    // -------------------------------------------------------------------------
    // Image filesystem cache helpers
    // -------------------------------------------------------------------------

    private static function get_image_cache_dir($subfolder_id)
    {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'oznamy-images/' . sanitize_file_name($subfolder_id);
    }

    private static function get_image_cache_url($subfolder_id, $safe_filename)
    {
        $upload = wp_upload_dir();
        return trailingslashit($upload['baseurl'])
            . 'oznamy-images/' . rawurlencode(sanitize_file_name($subfolder_id))
            . '/' . rawurlencode($safe_filename);
    }

    /** Longest side (px) of the gallery thumbnail; the full original stays next to it for the lightbox. */
    const THUMB_MAX_SIDE = 480;
    const THUMB_QUALITY = 80;
    // Skenované plagáty môžu byť obrovské; nad touto hranicou sa miniatúra nerobí, aby nepadol PHP kvôli pamäti.
    const THUMB_MAX_SOURCE_PIXELS = 50000000;

    /**
     * Ensure an image is downloaded to the local cache together with its thumbnail.
     *
     * @return array{url:string,thumb_url:string,thumb_width:int,thumb_height:int}|WP_Error
     */
    private static function ensure_image_cached($file_id, $filename, $subfolder_id, $client, $modified_time = '')
    {
        $safe_name = self::versioned_image_filename(sanitize_file_name($filename), $file_id, $modified_time);
        $dir       = self::get_image_cache_dir($subfolder_id);
        $path      = $dir . '/' . $safe_name;

        if (! file_exists($path) || filesize($path) <= 0) {
            $result = $client->fetch_image_to_file($file_id, $path);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        $cached = array(
            'url'          => self::get_image_cache_url($subfolder_id, $safe_name),
            'thumb_url'    => '',
            'thumb_width'  => 0,
            'thumb_height' => 0,
        );

        // Miniatúra je len optimalizácia: ak sa nepodarí, galéria použije originál.
        $thumb = self::ensure_image_thumbnail($path);
        if ($thumb) {
            $cached['thumb_url']    = self::get_image_cache_url($subfolder_id, $thumb['file']);
            $cached['thumb_width']  = $thumb['width'];
            $cached['thumb_height'] = $thumb['height'];
        }

        return $cached;
    }

    /**
     * Create (or reuse) a small thumbnail next to the cached original.
     *
     * @return array{file:string,width:int,height:int}|null Null when no thumbnail is needed or possible.
     */
    private static function ensure_image_thumbnail($path)
    {
        $info = pathinfo($path);

        $source = @getimagesize($path);
        if (! $source) {
            return null;
        }

        // Typ sa berie z obsahu súboru, lebo príloha z Drive nemusí mať v názve príponu.
        $types = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png');
        if (defined('IMAGETYPE_WEBP')) {
            $types[IMAGETYPE_WEBP] = 'webp';
        }
        $ext = $types[$source[2]] ?? '';
        if ('' === $ext) {
            return null;
        }

        // PNG sú často fotky; ako WebP sú pri rovnakej kvalite oveľa menšie a priehľadnosť zachovajú.
        $target_ext = $ext;
        if ('png' === $ext && wp_image_editor_supports(array('mime_type' => 'image/webp'))) {
            $target_ext = 'webp';
        }

        $thumb_file = $info['filename'] . '-oznamy-' . self::THUMB_MAX_SIDE . '.' . $target_ext;
        $thumb_path = $info['dirname'] . '/' . $thumb_file;

        if (max($source[0], $source[1]) <= self::THUMB_MAX_SIDE) {
            return null;
        }
        if ($source[0] * $source[1] > self::THUMB_MAX_SOURCE_PIXELS) {
            return null;
        }

        if (file_exists($thumb_path) && filesize($thumb_path) > 0 && filemtime($thumb_path) >= filemtime($path)) {
            $existing = @getimagesize($thumb_path);
            if ($existing) {
                return array('file' => $thumb_file, 'width' => (int) $existing[0], 'height' => (int) $existing[1]);
            }
        }

        wp_raise_memory_limit('image');

        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            error_log('[Oznamy] Thumbnail skipped: ' . $editor->get_error_message());
            return null;
        }

        // Prehliadač originál otočí podľa EXIF, preto musí byť otočená aj miniatúra.
        if (method_exists($editor, 'maybe_exif_rotate')) {
            $editor->maybe_exif_rotate();
        }
        $editor->set_quality(self::THUMB_QUALITY);

        $resized = $editor->resize(self::THUMB_MAX_SIDE, self::THUMB_MAX_SIDE, false);
        if (is_wp_error($resized)) {
            error_log('[Oznamy] Thumbnail resize failed: ' . $resized->get_error_message());
            return null;
        }

        // Zapisuje sa do dočasného súboru, aby návštevník nikdy nedostal napoly zapísaný obrázok.
        $tmp_path = $info['dirname'] . '/' . $info['filename'] . '-oznamy-tmp-' . wp_generate_password(8, false) . '.' . $target_ext;
        $saved    = $editor->save($tmp_path);
        if (is_wp_error($saved) || ! file_exists($tmp_path) || filesize($tmp_path) <= 0) {
            @unlink($tmp_path);
            error_log('[Oznamy] Thumbnail save failed' . (is_wp_error($saved) ? ': ' . $saved->get_error_message() : '.'));
            return null;
        }

        if (! @rename($tmp_path, $thumb_path)) {
            @unlink($tmp_path);
            return null;
        }

        return array(
            'file'   => $thumb_file,
            'width'  => (int) ($saved['width'] ?? 0),
            'height' => (int) ($saved['height'] ?? 0),
        );
    }

    /**
     * Give archive images a revision-specific filename so edited Drive files refresh.
     */
    private static function versioned_image_filename($filename, $file_id, $modified_time)
    {
        if ('' === (string) $modified_time) {
            return $filename;
        }

        $path_info = pathinfo($filename);
        $base_name = sanitize_file_name($path_info['filename'] ?? 'image');
        $extension = isset($path_info['extension']) ? '.' . sanitize_file_name($path_info['extension']) : '';
        $revision  = substr(md5($file_id . '|' . $modified_time), 0, 12);

        return $base_name . '-' . $revision . $extension;
    }

    /**
     * Delete all locally cached images (called when admin clears cache).
     */
    public static function clear_image_cache()
    {
        $upload = wp_upload_dir();
        $dir    = trailingslashit($upload['basedir']) . 'oznamy-images';
        if (is_dir($dir)) {
            self::rrmdir($dir);
        }
    }

    private static function rrmdir($dir)
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ('.' === $item || '..' === $item) {
                    continue;
                }
                $path = $dir . '/' . $item;
                if (is_dir($path)) {
                    self::rrmdir($path);
                } else {
                    @unlink($path);
                }
            }
        }
        @rmdir($dir);
    }
}
