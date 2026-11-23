<?php

namespace BreakdanceElementsByMiki;

use function Breakdance\Elements\c;

\Breakdance\ElementStudio\registerElementForEditing(
    'BreakdanceElementsByMiki\\FeaturedImage',
    \Breakdance\Util\getDirectoryPathRelativeToPluginFolder(__DIR__)
);

class FeaturedImage extends \Breakdance\Elements\Element
{
    public static function uiIcon()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><ellipse cx="8" cy="15" rx="5" ry="4.5" opacity=".45"/><rect x="6" y="4" width="15" height="12" rx="3"/><circle cx="11" cy="8.5" r="1.3"/><path d="m21 13-3.5-3.5L10 16"/></svg>';
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
        return t('Featured Image');
    }

    public static function className()
    {
        return 'bde-miki-featured-image';
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
        $hint = addcslashes(t('Featured Image: the post featured image is shown here on the site.'), "\"\\\n\r");

        return str_replace('%%MIKI_EMPTY_HINT%%', $hint, file_get_contents(__DIR__ . '/default.css'));
    }

    public static function cssTemplate()
    {
        return file_get_contents(__DIR__ . '/css.twig');
    }

    public static function defaultProperties()
    {
        return [
            'design' => [
                'visual' => ['style' => 'v1'],
                'size' => ['image_size' => 'full'],
                'crop' => [
                    'aspect_ratio' => 'original',
                    'object_fit' => ['breakpoint_base' => 'cover'],
                    'position_x' => ['breakpoint_base' => ['number' => 50, 'unit' => '%', 'style' => '50%']],
                    'position_y' => ['breakpoint_base' => ['number' => 50, 'unit' => '%', 'style' => '50%']],
                ],
            ],
        ];
    }

    private static function unitControl(string $slug, string $label, array $types, bool $responsive = false, array $options = [])
    {
        return c(
            $slug,
            $label,
            [],
            array_merge(
                [
                    'type' => 'unit',
                    'layout' => 'inline',
                    'unitOptions' => ['types' => $types, 'defaultType' => $types[0]],
                ],
                $options
            ),
            $responsive,
            false
        );
    }

    public static function contentControls()
    {
        return [
            c(
                'source',
                t('Image'),
                [
                    c(
                        'info',
                        '',
                        [],
                        [
                            'type' => 'alert_box',
                            'layout' => 'vertical',
                            'alertBoxOptions' => ['style' => 'info', 'content' => '<p>' . t('Shows the featured image of the current post. Set it in the post editor. Nothing is shown when the post has no featured image.') . '</p>'],
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
                'visual',
                t('Visual'),
                [
                    c(
                        'style',
                        t('Visual'),
                        [],
                        [
                            'type' => 'dropdown',
                            'layout' => 'inline',
                            'items' => [
                                ['text' => t('None'), 'value' => 'none'],
                                ['text' => t('Visual 1 - frame with shape bottom left to top right'), 'value' => 'v1'],
                                ['text' => t('Visual 2 - frame with shape top right to bottom left'), 'value' => 'v2'],
                                ['text' => t('Visual 3 - soft frame with shape top left to bottom right'), 'value' => 'v3'],
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
            c(
                'size',
                t('Size and position'),
                [
                    self::unitControl('max_width', t('Maximum width'), ['px', 'rem', '%'], true),
                    c(
                        'align',
                        t('Alignment'),
                        [],
                        [
                            'type' => 'dropdown',
                            'layout' => 'inline',
                            'items' => [
                                ['text' => t('Center'), 'value' => 'center'],
                                ['text' => t('Left'), 'value' => 'left'],
                                ['text' => t('Right'), 'value' => 'right'],
                            ],
                        ],
                        true,
                        false
                    ),
                    c(
                        'image_size',
                        t('Image file size'),
                        [],
                        [
                            'type' => 'dropdown',
                            'layout' => 'inline',
                            'items' => [
                                ['text' => t('Full size'), 'value' => 'full'],
                                ['text' => t('Extra large'), 'value' => '1536x1536'],
                                ['text' => t('Large'), 'value' => 'large'],
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
            c(
                'crop',
                t('Image crop'),
                [
                    c(
                        'aspect_ratio',
                        t('Aspect ratio'),
                        [],
                        [
                            'type' => 'dropdown',
                            'layout' => 'inline',
                            'items' => [
                                ['text' => t('Original'), 'value' => 'original'],
                                ['text' => '1:1', 'value' => '1 / 1'],
                                ['text' => '4:3', 'value' => '4 / 3'],
                                ['text' => '3:2', 'value' => '3 / 2'],
                                ['text' => '16:9', 'value' => '16 / 9'],
                                ['text' => '21:9', 'value' => '21 / 9'],
                                ['text' => '2:1', 'value' => '2 / 1'],
                                ['text' => '3:4', 'value' => '3 / 4'],
                                ['text' => '2:3', 'value' => '2 / 3'],
                                ['text' => '9:16', 'value' => '9 / 16'],
                                ['text' => t('Custom'), 'value' => 'custom'],
                            ],
                        ],
                        false,
                        false
                    ),
                    c(
                        'custom_width',
                        t('Custom ratio - width'),
                        [],
                        [
                            'type' => 'number',
                            'layout' => 'inline',
                            'rangeOptions' => ['min' => 1, 'max' => 100, 'step' => 1],
                            'condition' => ['path' => 'design.crop.aspect_ratio', 'operand' => 'equals', 'value' => 'custom'],
                        ],
                        false,
                        false
                    ),
                    c(
                        'custom_height',
                        t('Custom ratio - height'),
                        [],
                        [
                            'type' => 'number',
                            'layout' => 'inline',
                            'rangeOptions' => ['min' => 1, 'max' => 100, 'step' => 1],
                            'condition' => ['path' => 'design.crop.aspect_ratio', 'operand' => 'equals', 'value' => 'custom'],
                        ],
                        false,
                        false
                    ),
                    c(
                        'object_fit',
                        t('Image fit'),
                        [],
                        [
                            'type' => 'dropdown',
                            'layout' => 'inline',
                            'items' => [
                                ['text' => t('Cover (crop)'), 'value' => 'cover'],
                                ['text' => t('Contain (fit whole image)'), 'value' => 'contain'],
                                ['text' => t('Fill (stretch)'), 'value' => 'fill'],
                            ],
                            'condition' => ['path' => 'design.crop.aspect_ratio', 'operand' => 'not equals', 'value' => 'original'],
                        ],
                        true,
                        false
                    ),
                    self::unitControl(
                        'position_x',
                        t('Horizontal position'),
                        ['%'],
                        true,
                        ['condition' => ['path' => 'design.crop.aspect_ratio', 'operand' => 'not equals', 'value' => 'original']]
                    ),
                    self::unitControl(
                        'position_y',
                        t('Vertical position'),
                        ['%'],
                        true,
                        ['condition' => ['path' => 'design.crop.aspect_ratio', 'operand' => 'not equals', 'value' => 'original']]
                    ),
                    c(
                        'info',
                        '',
                        [],
                        [
                            'type' => 'alert_box',
                            'layout' => 'vertical',
                            'alertBoxOptions' => ['style' => 'info', 'content' => '<p>' . t('Position moves the visible part of a cropped image: 0% is the left or top edge, 50% the center, 100% the right or bottom edge.') . '</p>'],
                            'condition' => ['path' => 'design.crop.aspect_ratio', 'operand' => 'not equals', 'value' => 'original'],
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
                'appearance',
                t('Appearance'),
                [
                    self::unitControl(
                        'radius',
                        t('Corner radius'),
                        ['px', 'rem', 'em'],
                        false,
                        ['condition' => ['path' => 'design.visual.style', 'operand' => 'equals', 'value' => 'none']]
                    ),
                    c('shadow_disabled', t('No shadow'), [], ['type' => 'toggle', 'layout' => 'inline'], false, false),
                ],
                ['type' => 'section', 'layout' => 'vertical'],
                false,
                false
            ),
            c(
                'animation',
                t('Animation'),
                [
                    c('disabled', t('No load animation'), [], ['type' => 'toggle', 'layout' => 'inline'], false, false),
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
