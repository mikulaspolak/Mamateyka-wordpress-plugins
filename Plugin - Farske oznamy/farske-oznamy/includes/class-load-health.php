<?php

if (! defined('ABSPATH')) {
    exit;
}

if (class_exists('Oznamy_Load_Health')) {
    return;
}

class Oznamy_Load_Health
{
    const OPTION_KEY = 'oznamy_load_issues';

    private static function sources()
    {
        return array(
            'announcements' => __('Aktuálne farské oznamy', 'oznamy-gdocs'),
            'announcement_versions' => __('Verzie farských oznamov', 'oznamy-gdocs'),
            'future_announcements' => __('Budúce farské oznamy', 'oznamy-gdocs'),
            'archive_index' => __('Archív oznamov', 'oznamy-gdocs'),
            'archive_announcements' => __('Oznamy v archíve', 'oznamy-gdocs'),
            'intentions' => __('Úmysly svätých omší', 'oznamy-gdocs'),
            'images' => __('Obrázky pri aktuálnych oznamoch', 'oznamy-gdocs'),
            'future_images' => __('Obrázky pri budúcich oznamoch', 'oznamy-gdocs'),
            'archive_images' => __('Obrázky v archíve', 'oznamy-gdocs'),
        );
    }

    public static function record_issue($source, $message)
    {
        $sources = self::sources();
        if (! isset($sources[$source])) {
            return;
        }

        $issues = get_option(self::OPTION_KEY, array());
        if (! is_array($issues)) {
            $issues = array();
        }

        $message = sanitize_text_field((string) $message);
        if ('' === $message) {
            $message = __('Obsah sa nepodarilo načítať alebo overiť.', 'oznamy-gdocs');
        }

        if (isset($issues[$source]['message']) && $issues[$source]['message'] === $message) {
            return;
        }

        $issues[$source] = array(
            'message' => $message,
            'timestamp' => time(),
        );
        update_option(self::OPTION_KEY, $issues, false);
    }

    public static function resolve_issue($source)
    {
        $sources = self::sources();
        if (! isset($sources[$source])) {
            return;
        }

        $issues = get_option(self::OPTION_KEY, array());
        if (! is_array($issues) || ! isset($issues[$source])) {
            return;
        }

        unset($issues[$source]);
        if (empty($issues)) {
            delete_option(self::OPTION_KEY);
        } else {
            update_option(self::OPTION_KEY, $issues, false);
        }
    }

    public static function render_admin_notice()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $issues = get_option(self::OPTION_KEY, array());
        if (! is_array($issues) || empty($issues)) {
            return;
        }

        $sources = self::sources();
        ?>
        <div class="notice notice-warning">
            <p><strong><?php esc_html_e('Farské oznamy: pri načítaní alebo kontrole obsahu sa zistil problém.', 'oznamy-gdocs'); ?></strong></p>
            <ul>
                <?php foreach ($issues as $source => $issue) : ?>
                    <?php if (! isset($sources[$source]) || ! is_array($issue)) {
                        continue;
                    } ?>
                    <li>
                        <strong><?php echo esc_html($sources[$source]); ?>:</strong>
                        <?php echo esc_html((string) ($issue['message'] ?? '')); ?>
                        <?php if (! empty($issue['timestamp'])) : ?>
                            <span><?php
                                printf(
                                    /* translators: %s: dátum a čas zistenia problému */
                                    esc_html__('(zistené %s)', 'oznamy-gdocs'),
                                    esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), absint($issue['timestamp'])))
                                );
                            ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p>
                <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=oznamy-gdocs')); ?>">
                    <?php esc_html_e('Otvoriť nastavenia a diagnostiku pluginu', 'oznamy-gdocs'); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
