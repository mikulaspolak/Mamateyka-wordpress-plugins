<?php

namespace BreakdanceElementsByMiki;

use function Breakdance\Elements\c;

\Breakdance\ElementStudio\registerElementForEditing(
    'BreakdanceElementsByMiki\\MemoriesSpomienky',
    \Breakdance\Util\getDirectoryPathRelativeToPluginFolder(__DIR__)
);

class MemoriesSpomienky extends \Breakdance\Elements\Element
{
    public static function uiIcon()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="8" height="10" rx="1.5"/><rect x="13" y="3" width="8" height="6" rx="1.5"/><rect x="13" y="11" width="8" height="10" rx="1.5"/><rect x="3" y="15" width="8" height="6" rx="1.5"/><circle cx="7" cy="7" r="1"/></svg>';
    }

    public static function tag()
    {
        return 'div';
    }

    public static function tagOptions()
    {
        return ['div', 'section'];
    }

    public static function tagControlPath()
    {
        return false;
    }

    public static function name()
    {
        return t('Memories');
    }

    public static function className()
    {
        return 'bde-miki-memories';
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
                'memories' => [
                    'items' => [
                        [
                            'heading' => 'Miesto, kde sme vyrastali',
                            'author' => 'Ján Novák',
                            'tag' => 'tabor',
                            'tag_color' => 'orange',
                            'text' => 'Mamateyka bola pre mňa druhým domovom. Po škole som tu našiel kamarátov, zmysluplné aktivity a ľudí, ktorí nás podržali.',
                        ],
                        [
                            'heading' => 'Prvé leto na Mamateyke',
                            'author' => 'Mária Kováčová',
                            'tag' => 'leto',
                            'tag_color' => 'orange',
                            'text' => 'Bolo to moje prvé leto na Mamateyke. Nevnímala som to len ako prázdniny, ale ako miesto, kde som našla priateľov.',
                        ],
                        [
                            'heading' => 'Hudba, ktorá spája',
                            'author' => 'Katarína Veselá',
                            'tag' => 'hudba',
                            'tag_color' => 'orange',
                            'quote' => 'Hudba dokáže spojiť ľudí.',
                            'text' => 'V Mamateyke som objavila, že hudba dokáže spojiť ľudí, dať im nádej a radosť.',
                        ],
                    ],
                ],
            ],
            'design' => [
                'layout' => [
                    'columns_mode' => 'auto',
                    'min_card_width' => ['breakpoint_base' => ['number' => 280, 'unit' => 'px', 'style' => '280px']],
                    'columns' => ['breakpoint_base' => 3],
                    'gap' => ['breakpoint_base' => ['number' => 24, 'unit' => 'px', 'style' => '24px']],
                ],
                'card' => [
                    'text_lines' => 4,
                ],
                'tags' => [
                    'show_hashtag' => true,
                ],
                'decor' => [
                    'tilt' => true,
                    'tape' => true,
                    'hover' => true,
                ],
            ],
        ];
    }

    public static function contentControls()
    {
        return [
            c(
                'memories',
                t('Memories'),
                [
                    c(
                        'limit_hint',
                        t('Limit'),
                        [],
                        ['type' => 'alert_box', 'layout' => 'vertical', 'alertBoxOptions' => ['style' => 'info', 'content' => '<p>' . t('Up to 99 cards are shown. Additional items are skipped on the site.') . '</p>']],
                        false,
                        false
                    ),
                    c(
                        'items',
                        t('Cards'),
                        [
                            c('image', t('Image'), [], ['type' => 'wpmedia', 'layout' => 'vertical', 'mediaOptions' => ['acceptedFileTypes' => ['image'], 'multiple' => false]], false, false),
                            c('image_alt', t('Image alt text (optional)'), [], ['type' => 'text', 'layout' => 'vertical'], false, false),
                            c('avatar', t('Author avatar'), [], ['type' => 'wpmedia', 'layout' => 'vertical', 'mediaOptions' => ['acceptedFileTypes' => ['image'], 'multiple' => false]], false, false),
                            c('author', t('Author'), [], ['type' => 'text', 'layout' => 'vertical'], false, false),
                            c('heading', t('Heading'), [], ['type' => 'text', 'layout' => 'vertical'], false, false),
                            c('tag', t('Tag'), [], ['type' => 'text', 'layout' => 'vertical'], false, false),
                            c(
                                'tag_color',
                                t('Tag color'),
                                [],
                                [
                                    'type' => 'dropdown',
                                    'layout' => 'vertical',
                                    'items' => [
                                        ['text' => t('Orange'), 'value' => 'orange'],
                                    ],
                                ],
                                false,
                                false
                            ),
                            c('quote', t('Quote'), [], ['type' => 'text', 'layout' => 'vertical', 'textOptions' => ['multiline' => true]], false, false),
                            c('text', t('Text'), [], ['type' => 'text', 'layout' => 'vertical', 'textOptions' => ['multiline' => true]], false, false),
                        ],
                        [
                            'type' => 'repeater',
                            'layout' => 'vertical',
                            'repeaterOptions' => [
                                'titleTemplate' => '{heading}',
                                'defaultTitle' => t('Memory'),
                                'buttonName' => t('Add memory'),
                            ],
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
                        'columns_mode',
                        t('Column mode'),
                        [],
                        [
                            'type' => 'dropdown',
                            'layout' => 'inline',
                            'items' => [
                                ['text' => t('Automatic'), 'value' => 'auto'],
                                ['text' => t('Manual'), 'value' => 'manual'],
                            ],
                        ],
                        false,
                        false
                    ),
                    c(
                        'min_card_width',
                        t('Minimum card width'),
                        [],
                        [
                            'type' => 'unit',
                            'layout' => 'inline',
                            'unitOptions' => ['types' => ['px', 'rem', 'em'], 'defaultType' => 'px'],
                            'condition' => ['path' => 'design.layout.columns_mode', 'operand' => 'not equals', 'value' => 'manual'],
                        ],
                        true,
                        false
                    ),
                    c(
                        'columns',
                        t('Number of columns'),
                        [],
                        [
                            'type' => 'number',
                            'layout' => 'inline',
                            'rangeOptions' => ['min' => 1, 'max' => 6, 'step' => 1],
                            'condition' => ['path' => 'design.layout.columns_mode', 'operand' => 'equals', 'value' => 'manual'],
                        ],
                        true,
                        false
                    ),
                    c(
                        'gap',
                        t('Gap between cards'),
                        [],
                        [
                            'type' => 'unit',
                            'layout' => 'inline',
                            'unitOptions' => ['types' => ['px', 'rem', 'em'], 'defaultType' => 'px'],
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
                'card',
                t('Card'),
                [
                    c('background', t('Card background'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c('heading_color', t('Heading color'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c('text_color', t('Text color'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c(
                        'radius',
                        t('Corner radius'),
                        [],
                        [
                            'type' => 'unit',
                            'layout' => 'inline',
                            'unitOptions' => ['types' => ['px', 'rem'], 'defaultType' => 'px'],
                        ],
                        false,
                        false
                    ),
                    c(
                        'text_lines',
                        t('Max text lines (0 = full text)'),
                        [],
                        ['type' => 'number', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 12, 'step' => 1]],
                        false,
                        false
                    ),
                ],
                ['type' => 'section', 'layout' => 'vertical'],
                false,
                false
            ),
            c(
                'tags',
                t('Tags'),
                [
                    c('show_hashtag', t('Show hashtag (#)'), [], ['type' => 'toggle', 'layout' => 'inline'], false, false),
                ],
                ['type' => 'section', 'layout' => 'vertical'],
                false,
                false
            ),
            c(
                'decor',
                t('Decorations'),
                [
                    c('tilt', t('Slight card tilt'), [], ['type' => 'toggle', 'layout' => 'inline'], false, false),
                    c('tape', t('Washi tape'), [], ['type' => 'toggle', 'layout' => 'inline'], false, false),
                    c('hover', t('Subtle hover effect'), [], ['type' => 'toggle', 'layout' => 'inline'], false, false),
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

    public static function dependencies()
    {
        return false;
    }

    public static function settings()
    {
        return false;
    }

    public static function addPanelRules()
    {
        return false;
    }

    public static function actions()
    {
        return false;
    }

    public static function nestingRule()
    {
        return ['type' => 'final'];
    }

    public static function spacingBars()
    {
        return false;
    }

    public static function attributes()
    {
        return false;
    }

    public static function experimental()
    {
        return false;
    }

    public static function order()
    {
        return 0;
    }

    public static function dynamicPropertyPaths()
    {
        return [
            ['accepts' => 'string', 'path' => 'content.memories.items[].heading'],
            ['accepts' => 'string', 'path' => 'content.memories.items[].author'],
            ['accepts' => 'string', 'path' => 'content.memories.items[].text'],
        ];
    }

    public static function additionalClasses()
    {
        return false;
    }

    public static function projectManagement()
    {
        return false;
    }

    public static function propertyPathsToWhitelistInFlatProps()
    {
        return false;
    }

    public static function propertyPathsToSsrElementWhenValueChanges()
    {
        return false;
    }
}
