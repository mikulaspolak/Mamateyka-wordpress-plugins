<?php

namespace BreakdanceElementsByMiki;

use function Breakdance\Elements\c;

\Breakdance\ElementStudio\registerElementForEditing(
    'BreakdanceElementsByMiki\\GoogleCalendarGoogleKalendar',
    \Breakdance\Util\getDirectoryPathRelativeToPluginFolder(__DIR__)
);

class GoogleCalendarGoogleKalendar extends \Breakdance\Elements\Element
{
    public static function uiIcon()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/><circle cx="8" cy="14.5" r="1"/><circle cx="12" cy="14.5" r="1"/><circle cx="16" cy="14.5" r="1"/></svg>';
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
        return t('Google Calendar');
    }

    public static function className()
    {
        return 'bde-miki-google-calendar';
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
                    'title' => 'Kalendár podujatí Mamateyka',
                ],
            ],
            'design' => [
                'layout' => [
                    'height' => ['breakpoint_base' => ['number' => 600, 'unit' => 'px', 'style' => '600px']],
                ],
                'colors' => [
                    'accent' => '#537891',
                    'background' => '#f4f8fa',
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
                    c(
                        'calendar_hint',
                        t('Calendar ID hint'),
                        [],
                        ['type' => 'alert_box', 'layout' => 'vertical', 'alertBoxOptions' => ['style' => 'info', 'content' => '<p>' . t('The calendar must be public. The ID is in Google Calendar under Settings for my calendars, Integrate calendar.') . '</p>']],
                        false,
                        false
                    ),
                    c('title', t('Calendar title (for screen readers)'), [], ['type' => 'text', 'layout' => 'vertical'], false, false),
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
                'layout',
                t('Layout'),
                [
                    c(
                        'height',
                        t('Height'),
                        [],
                        [
                            'type' => 'unit',
                            'layout' => 'inline',
                            'unitOptions' => ['types' => ['px', 'rem', 'em', 'vh'], 'defaultType' => 'px'],
                        ],
                        true,
                        false
                    ),
                ],
                ['type' => 'section', 'layout' => 'vertical'],
                false,
                false
            ),
            c(
                'colors',
                t('Colors'),
                [
                    c('accent', t('Calendar accent color'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c('background', t('Background'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
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
