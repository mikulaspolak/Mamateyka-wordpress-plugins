<?php

namespace BreakdanceElementsByMiki;

use function Breakdance\Elements\c;

\Breakdance\ElementStudio\registerElementForEditing(
    'BreakdanceElementsByMiki\\ToastOznamenie',
    \Breakdance\Util\getDirectoryPathRelativeToPluginFolder(__DIR__)
);

class ToastOznamenie extends \Breakdance\Elements\Element
{
    public static function uiIcon()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="6" width="18" height="12" rx="3"/><circle cx="7.5" cy="12" r="1.5"/><path d="M11.5 10.5h6M11.5 13.5h4"/></svg>';
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
        return t('Toast');
    }

    public static function className()
    {
        return 'bde-miki-toast';
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
                'message' => [
                    'show_icon' => true,
                    'icon' => '!',
                    'title' => 'Mamateyka 30 rokov',
                    'text' => 'Príďte s nami osláviť 30 rokov.',
                    'close_label' => 'Zavrieť',
                ],
                'button' => [
                    'show' => true,
                    'label' => 'Zobraziť program',
                    'url' => '/30-rokov-mamateyky',
                    'new_tab' => false,
                ],
                'behavior' => [
                    'remember' => true,
                    'storage_key' => 'top-toast-seen',
                ],
            ],
            'design' => [
                'layout' => [
                    'top' => ['number' => 72, 'unit' => 'px', 'style' => '72px'],
                    'top_mobile' => ['number' => 62, 'unit' => 'px', 'style' => '62px'],
                    'max_width' => ['number' => 1200, 'unit' => 'px', 'style' => '1200px'],
                    'z_index' => 8,
                    'radius' => ['number' => 10, 'unit' => 'px', 'style' => '10px'],
                    'shadow' => true,
                ],
                'typography' => [
                    'title_size' => ['number' => 14, 'unit' => 'px', 'style' => '14px'],
                    'title_size_mobile' => ['number' => 13, 'unit' => 'px', 'style' => '13px'],
                ],
                'colors' => [
                    'background' => '#ffffff',
                    'text' => '#1d2630',
                    'accent' => '#537891',
                    'accent_hover' => '#41647a',
                    'icon_text' => '#ffffff',
                    'button_text' => '#ffffff',
                    'close' => '#7a858d',
                    'close_hover' => '#1d2630',
                ],
            ],
        ];
    }

    public static function contentControls()
    {
        return [
            c(
                'message',
                t('Message'),
                [
                    c('show_icon', t('Show icon'), [], ['type' => 'toggle', 'layout' => 'inline'], false, false),
                    c(
                        'icon',
                        t('Icon character'),
                        [],
                        [
                            'type' => 'text',
                            'layout' => 'inline',
                            'condition' => ['path' => 'content.message.show_icon', 'operand' => 'equals', 'value' => true],
                        ],
                        false,
                        false
                    ),
                    c('title', t('Title'), [], ['type' => 'text', 'layout' => 'vertical'], false, false),
                    c('text', t('Text'), [], ['type' => 'text', 'layout' => 'vertical'], false, false),
                    c('close_label', t('Close button label (for screen readers)'), [], ['type' => 'text', 'layout' => 'vertical'], false, false),
                ],
                ['type' => 'section', 'layout' => 'vertical'],
                false,
                false
            ),
            c(
                'button',
                t('Button'),
                [
                    c('show', t('Show button'), [], ['type' => 'toggle', 'layout' => 'inline'], false, false),
                    c(
                        'label',
                        t('Button text'),
                        [],
                        [
                            'type' => 'text',
                            'layout' => 'vertical',
                            'condition' => ['path' => 'content.button.show', 'operand' => 'equals', 'value' => true],
                        ],
                        false,
                        false
                    ),
                    c(
                        'url',
                        t('Button link'),
                        [],
                        [
                            'type' => 'text',
                            'layout' => 'vertical',
                            'condition' => ['path' => 'content.button.show', 'operand' => 'equals', 'value' => true],
                        ],
                        false,
                        false
                    ),
                    c(
                        'new_tab',
                        t('Open in a new tab'),
                        [],
                        [
                            'type' => 'toggle',
                            'layout' => 'inline',
                            'condition' => ['path' => 'content.button.show', 'operand' => 'equals', 'value' => true],
                        ],
                        false,
                        false
                    ),
                ],
                ['type' => 'section', 'layout' => 'vertical'],
                false,
                false
            ),
            c(
                'behavior',
                t('Behavior'),
                [
                    c(
                        'behavior_hint',
                        t('Behavior hint'),
                        [],
                        ['type' => 'alert_box', 'layout' => 'vertical', 'alertBoxOptions' => ['style' => 'info', 'content' => '<p>' . t('The toast can be closed with the × button or by swiping it sideways. In the editor it is shown as a normal block so you can edit it.') . '</p>']],
                        false,
                        false
                    ),
                    c('remember', t('Do not show again in this browser session after it is closed or the button is used'), [], ['type' => 'toggle', 'layout' => 'vertical'], false, false),
                    c(
                        'storage_key',
                        t('Toast ID (use a different ID for each toast)'),
                        [],
                        [
                            'type' => 'text',
                            'layout' => 'vertical',
                            'condition' => ['path' => 'content.behavior.remember', 'operand' => 'equals', 'value' => true],
                        ],
                        false,
                        false
                    ),
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
                        'top',
                        t('Distance from top'),
                        [],
                        ['type' => 'unit', 'layout' => 'inline', 'unitOptions' => ['types' => ['px', 'rem', 'em'], 'defaultType' => 'px']],
                        false,
                        false
                    ),
                    c(
                        'top_mobile',
                        t('Distance from top on mobile (up to 600 px)'),
                        [],
                        ['type' => 'unit', 'layout' => 'inline', 'unitOptions' => ['types' => ['px', 'rem', 'em'], 'defaultType' => 'px']],
                        false,
                        false
                    ),
                    c(
                        'max_width',
                        t('Maximum width'),
                        [],
                        ['type' => 'unit', 'layout' => 'inline', 'unitOptions' => ['types' => ['px', 'rem', 'em'], 'defaultType' => 'px']],
                        false,
                        false
                    ),
                    c(
                        'z_index',
                        t('Layer (z-index)'),
                        [],
                        ['type' => 'number', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 9999, 'step' => 1]],
                        false,
                        false
                    ),
                    c(
                        'radius',
                        t('Corner radius'),
                        [],
                        ['type' => 'unit', 'layout' => 'inline', 'unitOptions' => ['types' => ['px', 'rem'], 'defaultType' => 'px']],
                        false,
                        false
                    ),
                    c('shadow', t('Shadow'), [], ['type' => 'toggle', 'layout' => 'inline'], false, false),
                ],
                ['type' => 'section', 'layout' => 'vertical'],
                false,
                false
            ),
            c(
                'typography',
                t('Text size'),
                [
                    c(
                        'title_size',
                        t('Title size'),
                        [],
                        ['type' => 'unit', 'layout' => 'inline', 'unitOptions' => ['types' => ['px', 'rem', 'em'], 'defaultType' => 'px']],
                        false,
                        false
                    ),
                    c(
                        'title_size_mobile',
                        t('Title size on mobile (up to 600 px)'),
                        [],
                        ['type' => 'unit', 'layout' => 'inline', 'unitOptions' => ['types' => ['px', 'rem', 'em'], 'defaultType' => 'px']],
                        false,
                        false
                    ),
                    c(
                        'text_size',
                        t('Text size (empty = inherited from the page)'),
                        [],
                        ['type' => 'unit', 'layout' => 'inline', 'unitOptions' => ['types' => ['px', 'rem', 'em'], 'defaultType' => 'px']],
                        false,
                        false
                    ),
                    c(
                        'text_size_mobile',
                        t('Text size on mobile (empty = same as desktop)'),
                        [],
                        ['type' => 'unit', 'layout' => 'inline', 'unitOptions' => ['types' => ['px', 'rem', 'em'], 'defaultType' => 'px']],
                        false,
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
                    c('background', t('Background'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c('text', t('Text color'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c('accent', t('Accent color (icon and button)'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c('accent_hover', t('Button color on hover'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c('icon_text', t('Icon character color'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c('button_text', t('Button text color'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c('close', t('Close button color'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c('close_hover', t('Close button color on hover'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
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
