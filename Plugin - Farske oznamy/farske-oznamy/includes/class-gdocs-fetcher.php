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

        // Normalise excess blank lines.
        $html = preg_replace('/\n{3,}/', "\n\n", $html);

        return trim($html);
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
