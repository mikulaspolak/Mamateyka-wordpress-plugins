<?php

/**
 * Fetches a Google Doc as HTML via the Drive export API and sanitizes it.
 */

if (! defined('ABSPATH')) {
    exit;
}

if (class_exists('Oznamy_GDocs_Fetcher')) {
    return;
}

class Oznamy_GDocs_Fetcher
{

    /**
     * HTML tags + attributes that are safe to keep after sanitisation.
     */
    const ALLOWED_TAGS = array(
        'h1'     => array(),
        'h2'     => array(),
        'h3'     => array(),
        'h4'     => array(),
        'h5'     => array(),
        'h6'     => array(),
        'p'      => array('style' => array(), 'class' => array()),
        'strong' => array(),
        'b'      => array(),
        'em'     => array(),
        'i'      => array(),
        'u'      => array(),
        'br'     => array(),
        'ul'     => array('class' => array(), 'style' => array()),
        'ol'     => array('class' => array(), 'style' => array()),
        'li'     => array('style' => array()),
        'a'      => array(
            'href'   => array(),
            'target' => array(),
            'rel'    => array(),
            'style'  => array(),
        ),
        'span'   => array('style' => array(), 'class' => array(), 'aria-hidden' => array()),
        'div'    => array('class' => array(), 'style' => array()),
        'table'  => array('class' => array(), 'style' => array()),
        'thead'  => array(),
        'tbody'  => array(),
        'tr'     => array(),
        'td'     => array('colspan' => array(), 'rowspan' => array(), 'style' => array()),
        'th'     => array('colspan' => array(), 'rowspan' => array(), 'style' => array()),
    );

    /**
     * CSS allowlist for inline styles produced by Docs renderer.
     */
    const ALLOWED_STYLE_PROPERTIES = array(
        'color',
        'font-size',
        'font-weight',
        'font-style',
        'text-decoration',
        'text-align',
        'margin-left',
        'margin-right',
        'margin-top',
        'margin-bottom',
        'text-indent',
        'list-style-type',
        'line-height',
    );

    private $client;

    public function __construct($client)
    {
        $this->client = $client;
    }

    /**
     * Export a Google Doc as sanitized HTML.
     *
     * @return string|WP_Error Clean HTML on success, WP_Error on failure.
     */
    public function fetch_html($doc_id)
    {
        if (! preg_match('/^[a-zA-Z0-9_\-]+$/', $doc_id)) {
            return new WP_Error('oznamy_invalid_id', __('Neplatné ID dokumentu.', 'oznamy-gdocs'));
        }

        $token = $this->client->get_token();
        if (! $token) {
            return new WP_Error('oznamy_no_token', __('Nepodarilo sa overiť pripojenie ku Google.', 'oznamy-gdocs'));
        }

        $url = sprintf(
            'https://www.googleapis.com/drive/v3/files/%s/export?mimeType=text%%2Fhtml',
            rawurlencode($doc_id)
        );

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 20,
                'headers' => array('Authorization' => 'Bearer ' . $token),
            )
        );

        if (is_wp_error($response)) {
            error_log('[Oznamy] Doc export failed: ' . $response->get_error_message());
            return new WP_Error('oznamy_fetch_failed', $response->get_error_message());
        }

        $http_status = wp_remote_retrieve_response_code($response);
        if (200 !== $http_status) {
            error_log('[Oznamy] Doc export HTTP ' . $http_status);
            return new WP_Error('oznamy_http_error', sprintf('HTTP %d', $http_status));
        }

        return $this->sanitize_html(wp_remote_retrieve_body($response));
    }

    /**
     * Render a Google Doc via Docs API for higher formatting fidelity.
     *
     * @return string|WP_Error
     */
    public function fetch_rich_html($doc_id)
    {
        $document = $this->client->get_doc_structure($doc_id);
        if (is_wp_error($document)) {
            return $document;
        }

        $renderer = new Oznamy_GDocs_Renderer();
        $html = $renderer->render($document);

        if ('' === trim((string) $html)) {
            return new WP_Error('oznamy_docs_api_empty', 'Docs API renderer vrátil prázdny výstup.');
        }

        return $this->sanitize_html($html);
    }

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

    /**
     * Strip the full HTML document wrapper and return safe body content.
     */
    private function sanitize_html($html)
    {
        // Keep only the <body> contents.
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) {
            $html = $m[1];
        }

        // Remove embedded <style> and <script> blocks before wp_kses.
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);

        // Images embedded in the doc body are intentionally dropped (not copied).
        $html = preg_replace('/<img\b[^>]*>/i', '', $html);

        // Google leaves an invisible placeholder char where the image used to be; browsers render it as an empty box.
        $html = preg_replace('/[\x{FFFC}\x{FFFD}\x{FEFF}\x{0000}\x{001A}\x{E907}]/u', '', $html);

        $html = wp_kses($html, self::ALLOWED_TAGS);
        $html = $this->sanitize_inline_styles($html);
        $html = $this->remove_preface_parish_title_and_date($html);

        // Normalise excess blank lines.
        $html = preg_replace('/\n{3,}/', "\n\n", $html);

        return trim($html);
    }

    /**
     * Remove the repeated title and date rows before the liturgical calendar.
     */
    private function remove_preface_parish_title_and_date($html)
    {
        $pattern = '/<(p|h[1-6])\b[^>]*>(.*?)<\/\1\s*>/is';
        if (! preg_match_all($pattern, (string) $html, $nodes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $calendar_offset = null;
        foreach ($nodes as $node) {
            if (preg_match('/^liturgicky\s+kalendar[.!:]?$/u', $this->normalize_document_text($node[2][0]))) {
                $calendar_offset = $node[0][1];
                break;
            }
        }

        if (null === $calendar_offset) {
            return $html;
        }

        $output = '';
        $offset = 0;
        foreach ($nodes as $node) {
            $node_offset = $node[0][1];
            if ($node_offset >= $calendar_offset) {
                break;
            }

            $output .= substr($html, $offset, $node_offset - $offset);
            $node_text = $this->normalize_document_text($node[2][0]);
            if (! preg_match('/^farske\s+oznamy[.!:]?$/u', $node_text) && ! $this->is_document_date($node_text)) {
                $output .= $node[0][0];
            }
            $offset = $node_offset + strlen($node[0][0]);
        }

        return $output . substr($html, $offset);
    }

    /**
     * Normalize block text for case- and diacritic-insensitive comparisons.
     */
    private function normalize_document_text($html)
    {
        $text = preg_replace('/<br\s*\/?>/i', ' ', (string) $html);
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', trim($text));

        return is_string($text) ? strtolower(remove_accents($text)) : '';
    }

    /**
     * Recognize common numeric, Slovak, and English date-only rows.
     */
    private function is_document_date($text)
    {
        $day = '(?:0?[1-9]|[12]\d|3[01])';
        $month_number = '(?:0?[1-9]|1[0-2])';
        if (preg_match('/^(?:' . $day . '\s*[.\/-]\s*' . $month_number . '\s*[.\/-]\s*\d{4}|\d{4}\s*[.\/-]\s*' . $month_number . '\s*[.\/-]\s*' . $day . ')\.?$/u', $text)) {
            return true;
        }

        $month_name = '(?:januar(?:a)?|februar(?:a)?|marec|marca|april(?:a)?|maj(?:a)?|jun(?:a)?|jul(?:a)?|august(?:a)?|september|oktober(?:a)?|november(?:a)?|december(?:a)?|january|february|march|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sep|sept|oct|nov|dec)';
        $weekday = '(?:pondelok|utorok|streda|stvrtok|piatok|sobota|nedela|monday|tuesday|wednesday|thursday|friday|saturday|sunday)';
        $day_first = $day . '\.?\s+(?:' . $month_name . '|\p{L}{3,})\.?';
        $month_first = '(?:' . $month_name . '|\p{L}{3,})\.?\s+' . $day . ',?';

        return (bool) preg_match(
            '/^(?:' . $weekday . '[,\s]+)?(?:' . $day_first . '|' . $month_first . ')(?:(?:,\s*|\s+)\d{4})?\.?$/u',
            $text
        );
    }

    /**
     * Remove style declarations that are not in the allowlist.
     */
    private function sanitize_inline_styles($html)
    {
        return preg_replace_callback(
            '/style\s*=\s*"([^"]*)"/i',
            function ($matches) {
                $raw = (string) ($matches[1] ?? '');
                $parts = explode(';', $raw);
                $safe = array();

                foreach ($parts as $part) {
                    $part = trim($part);
                    if ('' === $part || false === strpos($part, ':')) {
                        continue;
                    }

                    list($name, $value) = array_map('trim', explode(':', $part, 2));
                    $name = strtolower($name);
                    $value = preg_replace('/[\n\r\t]+/', ' ', (string) $value);

                    if (! in_array($name, self::ALLOWED_STYLE_PROPERTIES, true)) {
                        continue;
                    }

                    if (preg_match('/expression|javascript:|url\s*\(/i', $value)) {
                        continue;
                    }

                    $safe[] = $name . ':' . $value;
                }

                if (empty($safe)) {
                    return '';
                }

                return 'style="' . esc_attr(implode(';', $safe)) . '"';
            },
            (string) $html
        );
    }
}
