<?php

// Šablóna pre nový element. Skopíruj celý priečinok do breakdance-elements-by-miki/elements/<Názov_Elementu>/
// a premenuj: PascalCase triedu (NewElement), slug v registerElementForEditing, className, CSS prefix `miki-new-element`.
// Element nesmie závisieť od iných elementov ani zdieľaných súborov.
// Texty v editore píš po anglicky cez t('...') a slovenský preklad doplň do languages/sk_SK.php.

namespace BreakdanceElementsByMiki;

use function Breakdance\Elements\c;

\Breakdance\ElementStudio\registerElementForEditing(
    'BreakdanceElementsByMiki\\NewElement',
    \Breakdance\Util\getDirectoryPathRelativeToPluginFolder(__DIR__)
);

class NewElement extends \Breakdance\Elements\Element
{
    public static function uiIcon()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" focusable="false"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>';
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
        return t('New Element');
    }

    public static function className()
    {
        return 'bde-miki-new-element';
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
            'content' => ['content' => ['text' => 'Ahoj']],
        ];
    }

    public static function contentControls()
    {
        return [
            c(
                'content',
                t('Content'),
                [
                    c('text', t('Text'), [], ['type' => 'text', 'layout' => 'vertical'], false, false),
                ],
                ['type' => 'section', 'layout' => 'vertical'],
                false,
                false
            ),
        ];
    }

    public static function designControls()
    {
        return [];
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
