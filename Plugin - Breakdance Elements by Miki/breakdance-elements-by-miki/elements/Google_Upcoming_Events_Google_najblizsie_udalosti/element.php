<?php

namespace BreakdanceElementsByMiki;

use function Breakdance\Elements\c;

\Breakdance\ElementStudio\registerElementForEditing(
    'BreakdanceElementsByMiki\\GoogleUpcomingEventsGoogleNajblizsieUdalosti',
    \Breakdance\Util\getDirectoryPathRelativeToPluginFolder(__DIR__)
);

class GoogleUpcomingEventsGoogleNajblizsieUdalosti extends \Breakdance\Elements\Element
{
    public static function uiIcon()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="4" width="6" height="6" rx="1.5"/><rect x="3" y="14" width="6" height="6" rx="1.5"/><path d="M12.5 6h8M12.5 8.5h5M12.5 16h8M12.5 18.5h5"/></svg>';
    }

    public static function tag()
    {
        return 'div';
    }

    public static function tagOptions()
    {
        return [];
    }

    public static function name()
    {
        return t('Google Upcoming Events');
    }

    public static function className()
    {
        return 'bde-miki-google-upcoming-events';
    }

    public static function category()
    {
        return 'miki-elements';
    }

    public static function slug()
    {
        return __CLASS__;
    }

    public static function template()
    {
        return file_get_contents(__DIR__ . '/html.twig');
    }

    public static function defaultCss()
    {
        return file_get_contents(__DIR__ . '/default.css');
    }

    public static function cssTemplate()
    {
        return file_get_contents(__DIR__ . '/css.twig');
    }

    public static function defaultProperties()
    {
        return [
            'content' => [
                'source' => [
                    'calendar_id' => '9j6qtv0ic0pgieobm312t1kc9k@group.calendar.google.com',
                    'api_key' => '',
                ],
                'display' => [
                    'count_desktop' => 6,
                    'count_mobile' => 4,
                ],
                'texts' => [
                    'empty' => 'Žiadne pripravované udalosti.',
                    'error' => 'Nepodarilo sa načítať udalosti.',
                    'fallback_title' => 'Udalosť',
                ],
            ],
            'design' => [
                'colors' => [
                    'date_background' => '#cddfe7',
                    'date_text' => '#40647d',
                    'meta_text' => '#66737b',
                    'divider' => '#d9e8ee',
                ],
            ],
        ];
    }

    public static function contentControls()
    {
        return [
            c(
                'source',
                t('Calendar'),
                [
                    c('calendar_id', t('Calendar ID'), [], ['type' => 'text', 'layout' => 'vertical'], false, false),
                    c('api_key', t('Google API key'), [], ['type' => 'text', 'layout' => 'vertical'], false, false),
                    c(
                        'api_key_hint',
                        t('API key hint'),
                        [],
                        ['type' => 'alert_box', 'layout' => 'vertical', 'alertBoxOptions' => ['style' => 'info', 'content' => '<p>' . t('The calendar must be public. The key is visible in the page source, so restrict it in Google Cloud Console to the Google Calendar API and to the website referrer (for example mamateyka.sk/*).') . '</p>']],
                        false,
                        false
                    ),
                ],
                ['type' => 'section', 'layout' => 'vertical'],
                false,
                false
            ),
            c(
                'display',
                t('Display'),
                [
                    c(
                        'count_desktop',
                        t('Events on desktop (1024 px and wider)'),
                        [],
                        ['type' => 'number', 'layout' => 'inline', 'rangeOptions' => ['min' => 1, 'max' => 20, 'step' => 1]],
                        false,
                        false
                    ),
                    c(
                        'count_mobile',
                        t('Events on tablet and mobile'),
                        [],
                        ['type' => 'number', 'layout' => 'inline', 'rangeOptions' => ['min' => 1, 'max' => 20, 'step' => 1]],
                        false,
                        false
                    ),
                ],
                ['type' => 'section', 'layout' => 'vertical'],
                false,
                false
            ),
            c(
                'texts',
                t('Texts'),
                [
                    c('empty', t('Text when there are no events'), [], ['type' => 'text', 'layout' => 'vertical'], false, false),
                    c('error', t('Text when loading fails'), [], ['type' => 'text', 'layout' => 'vertical'], false, false),
                    c('fallback_title', t('Title for events without a name'), [], ['type' => 'text', 'layout' => 'vertical'], false, false),
                ],
                ['type' => 'section', 'layout' => 'vertical'],
                false,
                false
            ),
        ];
    }

    public static function designControls()
    {
        return [
            c(
                'colors',
                t('Colors'),
                [
                    c('date_background', t('Date background'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c('date_text', t('Date text'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c('meta_text', t('Secondary text (day and time)'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c('divider', t('Divider line'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                ],
                ['type' => 'section', 'layout' => 'vertical'],
                false,
                false
            ),
        ];
    }

    public static function settingsControls()
    {
        return [];
    }

    public static function nestingRule()
    {
        return ['type' => 'final'];
    }
}
