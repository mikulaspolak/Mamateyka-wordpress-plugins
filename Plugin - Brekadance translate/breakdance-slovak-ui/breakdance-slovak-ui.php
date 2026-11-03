<?php

/**
 * Plugin Name: Breakdance Slovak UI
 * Description: Slovak translation of the Breakdance interface (editor and admin pages). Active only for users whose language is Slovencina (sk_SK).
 * Version: 1.2.0
 * Author: Mikulas Polak
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: Proprietary - All rights reserved
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BSU_PLUGIN_VERSION', '1.2.0');
define('BSU_PLUGIN_PATH', plugin_dir_path(__FILE__));

const BSU_LOCALE = 'sk_SK';
const BSU_DOMAIN = 'breakdance';

/**
 * Translations are generated from Translations/breakdance-sk_SK.json:
 * - languages/js/breakdance-sk_SK.json   editor texts (JED locale data for wp.i18n)
 * - languages/php/{domain}-sk_SK.json    PHP texts: { context: { source text: translation } }
 */
function bsu_load_translations()
{
    $path = BSU_PLUGIN_PATH . 'languages/js/' . BSU_DOMAIN . '-' . BSU_LOCALE . '.json';
    if (!is_readable($path)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($path), true);
    $messages = $data['locale_data'][BSU_DOMAIN] ?? null;

    return is_array($messages) ? $messages : null;
}

/**
 * Adds translations to the locale data Breakdance prints into its editor.
 * Any failure returns the original JSON so the editor keeps working.
 *
 * @param mixed       $json   JSON string from the breakdance_i18n_json filter ('{}' when Breakdance has no file for the locale).
 * @param string|null $locale Locale override, used for testing.
 * @return mixed
 */
function bsu_filter_i18n_json($json, $locale = null)
{
    if (!is_string($json)) {
        return $json;
    }

    if ($locale === null) {
        $locale = get_user_locale();
    }
    if ($locale !== BSU_LOCALE) {
        return $json;
    }

    $ours = bsu_load_translations();
    if ($ours === null) {
        return $json;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return $json;
    }

    $existing = $data['locale_data'][BSU_DOMAIN] ?? [];
    if (!is_array($existing)) {
        return $json;
    }

    // Texts already translated by Breakdance win; ours only fill the gaps.
    // Breakdance sets localeData[""].domain, so the metadata key must exist.
    $merged = $existing + $ours;
    if (!isset($merged['']) || !is_array($merged[''])) {
        $merged[''] = $ours[''] ?? [];
    }

    $data['domain'] = $data['domain'] ?? BSU_DOMAIN;
    $data['locale_data'][BSU_DOMAIN] = $merged;

    $encoded = wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP);

    return is_string($encoded) ? $encoded : $json;
}
add_filter('breakdance_i18n_json', 'bsu_filter_i18n_json', 20);

/**
 * PHP texts are translated per user language (like the editor), not per site language,
 * so they work even when the site language is not Slovak.
 */
function bsu_is_slovak_user()
{
    static $result = null;

    if ($result === null) {
        // The current user is unknown before init; do not cache a wrong answer.
        if (!did_action('init')) {
            return false;
        }
        $result = get_user_locale() === BSU_LOCALE;
    }

    return $result;
}

function bsu_is_breakdance_domain($domain)
{
    return is_string($domain) && strncmp($domain, 'breakdance', 10) === 0 && preg_match('/^breakdance(-[a-z0-9]+)*$/', $domain) === 1;
}

function bsu_php_map($domain)
{
    static $maps = [];

    if (!isset($maps[$domain])) {
        $maps[$domain] = [];
        $path = BSU_PLUGIN_PATH . 'languages/php/' . $domain . '-' . BSU_LOCALE . '.json';
        if (is_readable($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) {
                $maps[$domain] = $data;
            }
        }
    }

    return $maps[$domain];
}

/**
 * Only applies when WordPress/Breakdance returned the untranslated source text,
 * so translations shipped by Breakdance always take precedence.
 */
function bsu_translate($translation, $text, $context, $domain)
{
    if (!bsu_is_breakdance_domain($domain) || $translation !== $text || !bsu_is_slovak_user()) {
        return $translation;
    }

    $map = bsu_php_map($domain);
    $found = $map[$context][$text] ?? null;

    return is_string($found) && $found !== '' ? $found : $translation;
}

function bsu_translate_plural($translation, $single, $plural, $number, $context, $domain)
{
    if (!bsu_is_breakdance_domain($domain) || !bsu_is_slovak_user()) {
        return $translation;
    }

    $source = (int) $number === 1 ? $single : $plural;
    if ($translation !== $source) {
        return $translation;
    }

    $map = bsu_php_map($domain);
    $forms = $map[$context][$single] ?? null;
    if (!is_array($forms) || count($forms) !== 3) {
        return $translation;
    }

    $n = abs((int) $number);
    $index = $n === 1 ? 0 : ($n >= 2 && $n <= 4 ? 1 : 2);

    return is_string($forms[$index] ?? null) && $forms[$index] !== '' ? $forms[$index] : $translation;
}

add_filter('gettext', function ($translation, $text, $domain) {
    return bsu_translate($translation, $text, '', $domain);
}, 10, 3);

add_filter('gettext_with_context', function ($translation, $text, $context, $domain) {
    return bsu_translate($translation, $text, $context, $domain);
}, 10, 4);

add_filter('ngettext', function ($translation, $single, $plural, $number, $domain) {
    return bsu_translate_plural($translation, $single, $plural, $number, '', $domain);
}, 10, 5);

add_filter('ngettext_with_context', function ($translation, $single, $plural, $number, $context, $domain) {
    return bsu_translate_plural($translation, $single, $plural, $number, $context, $domain);
}, 10, 6);
