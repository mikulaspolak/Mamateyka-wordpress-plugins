<?php

namespace BreakdanceElementsByMiki;

use function Breakdance\Elements\c;

\Breakdance\ElementStudio\registerElementForEditing(
    'BreakdanceElementsByMiki\\PostGallery',
    \Breakdance\Util\getDirectoryPathRelativeToPluginFolder(__DIR__)
);

class PostGallery extends \Breakdance\Elements\Element
{
    public static function uiIcon()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m21 16-5-5-8 9"/></svg>';
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
        return t('Post Gallery');
    }

    public static function className()
    {
        return 'bde-miki-post-gallery';
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
        $hint = addcslashes(t('Post Gallery: select images in the Content tab.'), "\"\\\n\r");

        return str_replace('%%MIKI_EMPTY_HINT%%', $hint, file_get_contents(__DIR__ . '/default.css'));
    }

    public static function cssTemplate()
    {
        return file_get_contents(__DIR__ . '/css.twig');
    }

    public static function defaultProperties()
    {
        // No stored defaults. The builder treats `false` as "no defaults"; an empty PHP array would reach the editor as
        // a JSON list `[]`, and the editor then cannot store any value set on it (selected images were shown in the
        // panel but never saved). Toggles are phrased so that "off/unset" is the desired state, which also avoids
        // the "modified" dot Breakdance shows when a value differs from the default.
        return false;
    }

    private static function unitControl(string $slug, string $label, bool $responsive = false, array $types = ['px', 'rem', 'em'])
    {
        return c(
            $slug,
            $label,
            [],
            [
                'type' => 'unit',
                'layout' => 'inline',
                'unitOptions' => ['types' => $types, 'defaultType' => $types[0]],
            ],
            $responsive,
            false
        );
    }

    public static function contentControls()
    {
        return [
            c(
                'images',
                t('Images'),
                [
                    c(
                        'gallery',
                        t('Gallery images'),
                        [],
                        ['type' => 'wpmedia', 'layout' => 'vertical', 'mediaOptions' => ['acceptedFileTypes' => ['image'], 'multiple' => true]],
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
                    self::unitControl('column_gap', t('Gap between columns'), true),
                    self::unitControl('row_gap', t('Gap between rows'), true),
                ],
                ['type' => 'section', 'layout' => 'vertical'],
                false,
                false
            ),
            c(
                'spacing',
                t('Spacing'),
                [
                    \Breakdance\Elements\PresetSections\getPresetSection(
                        'EssentialElements\\spacing_padding_all',
                        t('Padding'),
                        'padding',
                        ['type' => 'popout']
                    ),
                ],
                ['type' => 'section', 'layout' => 'vertical'],
                false,
                false
            ),
            c(
                'item',
                t('Image'),
                [
                    self::unitControl('radius', t('Corner radius'), false, ['px', 'rem', 'em']),
                    c('background', t('Placeholder color'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    self::unitControl('border_width', t('Border width'), false, ['px', 'rem']),
                    c('border_color', t('Border color'), [], ['type' => 'color', 'layout' => 'inline'], false, false),
                    c('shadow_disabled', t('No shadow'), [], ['type' => 'toggle', 'layout' => 'inline'], false, false),
                    c('caption_visible', t('Show caption on tile'), [], ['type' => 'toggle', 'layout' => 'inline'], false, false),
                    c(
                        'caption_position',
                        t('Caption position'),
                        [],
                        [
                            'type' => 'dropdown',
                            'layout' => 'inline',
                            'items' => [
                                ['text' => t('Overlay on hover'), 'value' => 'hover'],
                                ['text' => t('Below image'), 'value' => 'below'],
                            ],
                            'condition' => ['path' => 'design.item.caption_visible', 'operand' => 'equals', 'value' => true],
                        ],
                        false,
                        false
                    ),
                    \Breakdance\Elements\PresetSections\getPresetSection(
                        'EssentialElements\\typography',
                        t('Caption typography'),
                        'caption_typography',
                        ['type' => 'popout', 'condition' => ['path' => 'design.item.caption_visible', 'operand' => 'equals', 'value' => true]]
                    ),
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
        return [];
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
