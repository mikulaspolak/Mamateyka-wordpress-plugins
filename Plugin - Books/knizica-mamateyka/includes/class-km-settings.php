<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Plugin settings stored as a single option, editable only by WP administrators.
 */
class KM_Settings
{

    const OPTION_KEY = 'km_settings';

    public static function defaults()
    {
        return array(
            'notification_email'    => get_option('admin_email'),
            'gdpr_text'              => __('Súhlasím so spracovaním osobných údajov za účelom vybavenia rezervácie knihy.', 'knizica-mamateyka'),
            'reservation_intro_text' => __('Vyplňte, prosím, svoje meno, email a telefónne číslo. Ozveme sa vám, keď bude kniha pripravená na vyzdvihnutie.', 'knizica-mamateyka'),
            'max_reservations_per_email' => 10,
        );
    }

    public static function get_all()
    {
        $saved = get_option(self::OPTION_KEY, array());
        return wp_parse_args($saved, self::defaults());
    }

    public static function get($key)
    {
        $all = self::get_all();
        return isset($all[$key]) ? $all[$key] : null;
    }

    public static function update(array $values)
    {
        $current = self::get_all();
        $merged  = array_merge($current, $values);
        update_option(self::OPTION_KEY, $merged);
        return $merged;
    }
}
