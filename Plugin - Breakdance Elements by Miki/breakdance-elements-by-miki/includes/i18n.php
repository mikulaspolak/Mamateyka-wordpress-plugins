<?php

namespace BreakdanceElementsByMiki;

if (!defined('ABSPATH')) {
    exit;
}

const TRANSLATION_LOCALE = 'sk_SK';

/**
 * Translates an English source string for the editor user's language (like the Breakdance editor itself).
 * Without a translation, or before the user is known, the English source text is returned.
 */
function t(string $text): string
{
    static $map = null;

    if (!did_action('init') || get_user_locale() !== TRANSLATION_LOCALE) {
        return $text;
    }

    if ($map === null) {
        $file = dirname(__DIR__) . '/languages/' . TRANSLATION_LOCALE . '.php';
        $loaded = is_readable($file) ? include $file : [];
        $map = is_array($loaded) ? $loaded : [];
    }

    return isset($map[$text]) && is_string($map[$text]) && $map[$text] !== '' ? $map[$text] : $text;
}
