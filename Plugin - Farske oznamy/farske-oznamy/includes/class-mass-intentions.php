<?php

/**
 * Loads and displays Mass intentions from the dedicated Google Doc.
 */

if (! defined('ABSPATH')) {
    exit;
}

if (class_exists('Oznamy_Mass_Intentions')) {
    return;
}

class Oznamy_Mass_Intentions
{
    const DOCUMENT_NAME = 'Úmysly svätých omší';
    const CACHE_KEY_PREFIX = 'oznamy_cache_intentions_v3_';
    const STALE_CACHE_KEY_PREFIX = 'oznamy_stale_intentions_v2_';

    /**
     * Render [umysly-svatych-omsi].
     *
     * @param array<string, string> $atts
     * @return string
     */
    public static function render_shortcode($atts)
    {
        if (! empty($atts)) {
            return '<div class="oznamy-intentions oznamy-intentions--error">'
                . esc_html__('Tento shortcode nepodporuje atribúty. Použite ho bez parametrov.', 'oznamy-gdocs')
                . '</div>';
        }

        $schedule = self::get_schedule();
        if (is_wp_error($schedule)) {
            error_log('[Oznamy] Mass intentions shortcode error: ' . $schedule->get_error_message());
            return '<div class="oznamy-intentions oznamy-intentions--error">'
                . esc_html__('Úmysly svätých omší sú dočasne nedostupné. Skúste to prosím neskôr.', 'oznamy-gdocs')
                . '</div>';
        }

        self::enqueue_assets();
        return self::render_schedule($schedule);
    }

    /**
     * Load a parsed schedule from cache or Google Drive.
     *
     * @param bool $force_refresh
     * @return array|WP_Error
     */
    public static function get_schedule($force_refresh = false)
    {
        $settings = get_option(OZNAMY_OPTION_KEY, array());
        $folder_id = sanitize_text_field($settings['folder_id'] ?? '');
        $sa_data = json_decode($settings['sa_json'] ?? '', true);

        if (! $folder_id || ! is_array($sa_data)) {
            $error = new WP_Error('oznamy_intentions_not_configured', 'Plugin nie je úplne nakonfigurovaný.');
            Oznamy_Load_Health::record_issue('intentions', $error->get_error_message());
            return $error;
        }

        $folder_hash = md5($folder_id);
        $cache_key = self::CACHE_KEY_PREFIX . $folder_hash;
        $stale_key = self::STALE_CACHE_KEY_PREFIX . $folder_hash;

        if (! $force_refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached) && ! empty($cached['days'])) {
                if (! empty($cached['warnings'])) {
                    Oznamy_Load_Health::record_issue(
                        'intentions',
                        implode(' ', array_slice($cached['warnings'], 0, 5))
                    );
                }
                return $cached;
            }
        }

        $client = new Oznamy_GDrive_Client($sa_data);
        $inspection = self::inspect_document($client, $folder_id);
        if ('found' !== $inspection['status']) {
            $error = new WP_Error(
                'oznamy_intentions_unavailable',
                (string) ($inspection['error'] ?? 'Dokument s úmyslami omší sa v koreňovom priečinku nenašiel.')
            );
            Oznamy_Load_Health::record_issue('intentions', $error->get_error_message());
            error_log('[Oznamy] Mass intentions fetch failed: ' . $error->get_error_message());

            $stale = get_option($stale_key);
            if (is_array($stale) && ! empty($stale['days'])) {
                $stale['is_stale'] = true;
                return $stale;
            }

            return $error;
        }

        $schedule = $inspection['schedule'];
        $schedule['doc_id'] = (string) $inspection['doc_id'];
        $schedule['doc_name'] = self::DOCUMENT_NAME;
        $schedule['modified_time'] = (string) ($inspection['modified_time'] ?? '');
        $schedule['synced_at'] = time();
        $schedule['is_stale'] = false;
        if (! empty($schedule['warnings'])) {
            Oznamy_Load_Health::record_issue(
                'intentions',
                implode(' ', array_slice($schedule['warnings'], 0, 5))
            );
        } else {
            Oznamy_Load_Health::resolve_issue('intentions');
        }

        $ttl = max(60, min(86400, absint($settings['cache_ttl'] ?? 3600)));
        set_transient($cache_key, $schedule, $ttl);
        update_option($stale_key, $schedule, false);

        return $schedule;
    }

    /**
     * Inspect the named document and parse its schedule.
     *
     * @param Oznamy_GDrive_Client $client
     * @param string $folder_id
     * @return array{status:string,doc_id?:string,modified_time?:string,schedule?:array,error?:string}
     */
    public static function inspect_document($client, $folder_id)
    {
        $file = $client->find_doc_by_exact_name_in_folder_result($folder_id, self::DOCUMENT_NAME);
        if (is_wp_error($file)) {
            return array('status' => 'error', 'error' => $file->get_error_message());
        }
        if (null === $file) {
            return array('status' => 'missing', 'error' => 'Google Docs „' . self::DOCUMENT_NAME . '“ sa v koreňovom priečinku nenašiel.');
        }

        $document = $client->get_doc_structure((string) $file['id']);
        if (is_wp_error($document)) {
            return array('status' => 'error', 'error' => $document->get_error_message());
        }

        $schedule = self::parse_document($document);
        if (is_wp_error($schedule)) {
            return array('status' => 'invalid', 'error' => $schedule->get_error_message());
        }

        return array(
            'status' => 'found',
            'doc_id' => (string) $file['id'],
            'modified_time' => (string) ($file['modifiedTime'] ?? ''),
            'schedule' => $schedule,
        );
    }

    /**
     * Parse date headings and time/intention lines from a Google Docs payload.
     *
     * @param array $document
     * @return array|WP_Error
     */
    private static function parse_document($document)
    {
        if (empty($document['body']['content']) || ! is_array($document['body']['content'])) {
            return new WP_Error('oznamy_intentions_empty_document', 'Dokument neobsahuje text.');
        }

        $text = '';
        self::append_structural_text($document['body']['content'], $text);
        $lines = preg_split('/\R/u', $text);
        if (! is_array($lines)) {
            return new WP_Error('oznamy_intentions_invalid_text', 'Text dokumentu sa nepodarilo spracovať.');
        }

        try {
            $timezone = wp_timezone();
        } catch (Exception $e) {
            $timezone = new DateTimeZone('UTC');
        }

        $days = array();
        $warnings = array();
        $current_date = null;

        foreach ($lines as $index => $raw_line) {
            $line = trim(str_replace("\xc2\xa0", ' ', (string) $raw_line));
            if ('' === $line) {
                continue;
            }

            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s*(?:-|–|—)\s*\p{L}[\p{L}\p{M}\s]*)?$/u', $line, $date_match)) {
                $day = (int) $date_match[1];
                $month = (int) $date_match[2];
                $year = (int) $date_match[3];

                if (! checkdate($month, $day, $year)) {
                    $warnings[] = sprintf('Riadok %d: neplatný dátum.', $index + 1);
                    $current_date = null;
                    continue;
                }

                $date = DateTimeImmutable::createFromFormat(
                    '!Y-m-d',
                    sprintf('%04d-%02d-%02d', $year, $month, $day),
                    $timezone
                );
                if (! $date) {
                    $warnings[] = sprintf('Riadok %d: dátum sa nepodarilo spracovať.', $index + 1);
                    $current_date = null;
                    continue;
                }

                $current_date = $date->format('Y-m-d');
                if (! isset($days[$current_date])) {
                    $days[$current_date] = array(
                        'date' => $date->getTimestamp(),
                        'intentions' => array(),
                    );
                }
                continue;
            }

            if (preg_match('/^(\d{1,2}):([0-5]\d)\s*[-\x{2010}-\x{2015}\x{2212}]\s*(.+)$/u', $line, $time_match)) {
                if (null === $current_date) {
                    $warnings[] = sprintf('Riadok %d: úmysel nemá priradený platný dátum.', $index + 1);
                    continue;
                }

                $hour = (int) $time_match[1];
                if ($hour > 23) {
                    $warnings[] = sprintf('Riadok %d: neplatný čas.', $index + 1);
                    continue;
                }

                $intention = trim($time_match[3]);
                if ('' === $intention) {
                    $warnings[] = sprintf('Riadok %d: text úmyslu je prázdny.', $index + 1);
                    continue;
                }

                $days[$current_date]['intentions'][] = array(
                    'time' => sprintf('%02d:%02d', $hour, (int) $time_match[2]),
                    'text' => $intention,
                );
                continue;
            }

            $warnings[] = sprintf('Riadok %d: nerozpoznaný formát.', $index + 1);
        }

        ksort($days);
        $intention_dates = array();
        foreach ($days as $date => $day_data) {
            if (! empty($day_data['intentions'])) {
                $intention_dates[] = $date;
            }
        }

        if (empty($intention_dates)) {
            return new WP_Error('oznamy_intentions_no_entries', 'V dokumente sa nenašli platné dátumy s úmyslami omší.');
        }

        $first_intention_date = reset($intention_dates);
        $last_intention_date = end($intention_dates);
        foreach ($days as $date => $day_data) {
            if ($date < $first_intention_date || $date > $last_intention_date) {
                unset($days[$date]);
            }
        }

        return array(
            'days' => $days,
            'warnings' => $warnings,
            'intention_count' => array_sum(array_map(
                static function ($day) {
                    return count($day['intentions']);
                },
                $days
            )),
        );
    }

    /**
     * Collect text from document paragraphs, including paragraphs inside tables.
     *
     * @param array $elements
     * @param string $text
     * @return void
     */
    private static function append_structural_text($elements, &$text)
    {
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            if (! empty($element['paragraph']['elements']) && is_array($element['paragraph']['elements'])) {
                $paragraph_text = '';
                foreach ($element['paragraph']['elements'] as $paragraph_element) {
                    if (isset($paragraph_element['textRun']['content'])) {
                        $paragraph_text .= (string) $paragraph_element['textRun']['content'];
                    }
                }
                $text .= $paragraph_text;
                if ('' !== $paragraph_text && "\n" !== substr($paragraph_text, -1)) {
                    $text .= "\n";
                }
                continue;
            }

            if (! empty($element['table']['tableRows']) && is_array($element['table']['tableRows'])) {
                foreach ($element['table']['tableRows'] as $row) {
                    foreach (($row['tableCells'] ?? array()) as $cell) {
                        if (! empty($cell['content']) && is_array($cell['content'])) {
                            self::append_structural_text($cell['content'], $text);
                        }
                    }
                }
            }
        }
    }

    /**
     * Build the responsive month/day list.
     *
     * @param array $schedule
     * @return string
     */
    private static function render_schedule($schedule)
    {
        try {
            $today = new DateTimeImmutable('today', wp_timezone());
        } catch (Exception $e) {
            $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        }

        $current_month = $today->format('Y-m');
        $today_key = $today->format('Y-m-d');
        $days = array();
        $months = array();

        foreach ($schedule['days'] as $date_key => $day) {
            if (substr($date_key, 0, 7) < $current_month) {
                continue;
            }

            $days[$date_key] = $day;
            $month_key = substr($date_key, 0, 7);
            if (! isset($months[$month_key])) {
                $month_label = wp_date('F Y', (int) $day['date']);
                $months[$month_key] = ucfirst($month_label);
            }
        }

        if (empty($days)) {
            return '<div class="oznamy-intentions oznamy-intentions--empty">'
                . esc_html__('Momentálne nie sú zverejnené úmysly omší pre tento ani budúce mesiace.', 'oznamy-gdocs')
                . '</div>';
        }

        $selected_month = isset($months[$current_month]) ? $current_month : (string) key($months);
        $month_picker_id = function_exists('wp_unique_id')
            ? wp_unique_id('oznamy-intentions-month-')
            : 'oznamy-intentions-month-' . wp_rand(1000, 999999);

        $output = '<div class="oznamy-intentions" data-oznamy-intentions data-today-label="'
            . esc_attr__('Dnes', 'oznamy-gdocs')
            . '" data-search-no-results-label="'
            . esc_attr__('Pre tento text sa nenašli žiadne úmysly v aktuálnom ani v budúcich mesiacoch.', 'oznamy-gdocs')
            . '">';
        if (! empty($schedule['is_stale'])) {
            $output .= '<p class="oznamy-intentions__stale" role="status">'
                . esc_html__('Zobrazuje sa posledný úspešne načítaný rozpis; obnovenie z Google Drive dočasne zlyhalo.', 'oznamy-gdocs')
                . '</p>';
        }

        $calendar_icon = '<svg class="oznamy-intentions__calendar-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
            . '<rect x="3.5" y="5" width="17" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/>'
            . '<path d="M7.5 3.5v3M16.5 3.5v3M4 9h16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '</svg>';

        $output .= '<div class="oznamy-intentions__controls">';
        if (count($months) > 1) {
            $output .= '<div class="oznamy-intentions__month-picker" data-oznamy-intentions-month-picker>'
                . '<button type="button" id="' . esc_attr($month_picker_id) . '-trigger"'
                . ' class="oznamy-intentions__month-trigger" data-oznamy-intentions-month-trigger'
                . ' aria-haspopup="listbox" aria-expanded="false" aria-controls="'
                . esc_attr($month_picker_id) . '-options" aria-label="'
                . esc_attr__('Vyberte mesiac úmyslov omší', 'oznamy-gdocs') . '">'
                . $calendar_icon
                . '<span class="oznamy-intentions__month-label">' . esc_html($months[$selected_month]) . '</span>'
                . '<span class="oznamy-intentions__chevron" aria-hidden="true"></span></button>'
                . '<ul id="' . esc_attr($month_picker_id) . '-options"'
                . ' class="oznamy-intentions__month-options" role="listbox" hidden aria-label="'
                . esc_attr__('Dostupné mesiace úmyslov omší', 'oznamy-gdocs') . '">';

            foreach ($months as $month_key => $month_label) {
                $is_selected = $month_key === $selected_month;
                $output .= '<li class="oznamy-intentions__month-option" role="option" tabindex="-1"'
                    . ' data-oznamy-intentions-month-option data-month="' . esc_attr($month_key) . '"'
                    . ' aria-selected="' . ($is_selected ? 'true' : 'false') . '">'
                    . '<span class="oznamy-intentions__month-option-label">' . esc_html($month_label) . '</span>'
                    . '<span class="oznamy-intentions__month-option-check" aria-hidden="true"></span></li>';
            }

            $output .= '</ul></div>';
        } else {
            $month_label = (string) reset($months);
            $output .= '<h2 class="oznamy-intentions__month-heading" data-oznamy-intentions-current-heading>'
                . '<span>' . esc_html($month_label) . '</span></h2>';
        }

        $search_id = $month_picker_id . '-search';
        $output .= '<div class="oznamy-intentions__search">'
            . '<label class="oznamy-intentions__screen-reader-text" for="' . esc_attr($search_id) . '">'
            . esc_html__('Vyhľadať úmysel', 'oznamy-gdocs') . '</label>'
            . '<div class="oznamy-intentions__search-field">'
            . '<input class="oznamy-intentions__search-input" type="search" id="' . esc_attr($search_id) . '"'
            . ' data-oznamy-intentions-search placeholder="' . esc_attr__('Hľadať úmysel', 'oznamy-gdocs') . '"'
            . ' autocomplete="off">'
            . '<button class="oznamy-intentions__search-reset" type="button"'
            . ' data-oznamy-intentions-search-reset aria-label="' . esc_attr__('Vymazať hľadaný text', 'oznamy-gdocs') . '"'
            . ' title="' . esc_attr__('Vymazať hľadaný text', 'oznamy-gdocs') . '" hidden>'
            . '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false">'
            . '<path d="M5 5l10 10M15 5L5 15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '</svg></button></div></div></div>'
            . '<p class="oznamy-intentions__search-no-results" data-oznamy-intentions-search-no-results'
            . ' role="status" hidden></p>';

        $output .= '<div class="oznamy-intentions__months" id="' . esc_attr($month_picker_id) . '-days">';

        foreach ($months as $month_key => $month_label) {
            $is_active = $month_key === $selected_month;
            $output .= '<section class="oznamy-intentions__month'
                . ($is_active ? ' is-active' : '') . '" data-oznamy-intentions-panel="'
                . esc_attr($month_key) . '" aria-label="' . esc_attr($month_label) . '"'
                . ($is_active ? '' : ' hidden') . '>'
                . '<h2 class="oznamy-intentions__month-results-heading" data-oznamy-intentions-month-heading hidden>'
                . esc_html($month_label) . '</h2>';

            foreach ($days as $date_key => $day) {
                if ($month_key !== substr($date_key, 0, 7)) {
                    continue;
                }

                $timestamp = (int) $day['date'];
                $is_today = $date_key === $today_key;
                $is_sunday = '7' === wp_date('N', $timestamp);
                $classes = 'oznamy-intentions__day';
                if ($is_sunday) {
                    $classes .= ' is-sunday';
                }
                if ($is_today) {
                    $classes .= ' is-today';
                }

                $output .= '<article class="' . esc_attr($classes) . '" data-date="' . esc_attr($date_key) . '">'
                    . '<div class="oznamy-intentions__date">'
                    . '<span class="oznamy-intentions__day-number">' . esc_html(wp_date('j.n.', $timestamp)) . '</span>'
                    . '<span class="oznamy-intentions__weekday">' . esc_html(wp_date('l', $timestamp)) . '</span>';
                if ($is_today) {
                    $output .= '<span class="oznamy-intentions__today-label">' . esc_html__('Dnes', 'oznamy-gdocs') . '</span>';
                }
                $output .= '</div><ul class="oznamy-intentions__list">';

                foreach ($day['intentions'] as $intention) {
                    $output .= '<li><time class="oznamy-intentions__time" datetime="'
                        . esc_attr($intention['time']) . '">' . esc_html($intention['time']) . '</time>'
                        . '<span class="oznamy-intentions__text">' . esc_html($intention['text']) . '</span></li>';
                }

                $output .= '</ul></article>';
            }

            $output .= '</section>';
        }

        return $output . '</div></div>';
    }

    /**
     * Load the assets only on pages using the shortcode.
     *
     * @return void
     */
    private static function enqueue_assets()
    {
        wp_enqueue_script(
            'oznamy-intentions',
            OZNAMY_PLUGIN_URL . 'assets/intentions.js',
            array(),
            OZNAMY_VERSION,
            true
        );
    }
}
