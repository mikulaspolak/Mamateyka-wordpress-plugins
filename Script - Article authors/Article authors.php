<?php

// Article authors: two optional post fields (text author, photo author), a
// Gutenberg sidebar panel to edit them, and one shortcode per field.
// Meta keys and shortcode slugs are a stable data contract. Do not rename them.

function mamateyka_article_authors_fields()
{
    return array(
        'text' => array(
            'meta_key'  => 'mamateyka_autor_textu',
            'shortcode' => 'clanok_autor_textu',
            'label'     => 'Autor textu',
            'class'     => 'mamateyka-autor--text',
            'icon'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        ),
        'photo' => array(
            'meta_key'  => 'mamateyka_autor_fotografii',
            'shortcode' => 'clanok_autor_fotografii',
            'label'     => 'Autor fotografií',
            'class'     => 'mamateyka-autor--foto',
            'icon'      => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
        ),
    );
}

function mamateyka_article_authors_sanitize($value)
{
    $value = sanitize_text_field(is_scalar($value) ? (string) $value : '');

    return mb_substr($value, 0, 200);
}

add_action('init', function () {
    foreach (mamateyka_article_authors_fields() as $field) {
        register_post_meta('post', $field['meta_key'], array(
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'show_in_rest'      => true,
            'sanitize_callback' => 'mamateyka_article_authors_sanitize',
            'auth_callback'     => static function ($allowed, $meta_key, $post_id) {
                return current_user_can('edit_post', $post_id);
            },
        ));
    }
});

add_action('enqueue_block_editor_assets', function () {
    $handle = 'mamateyka-article-authors-editor';

    wp_register_script($handle, false, array(
        'wp-plugins',
        'wp-editor',
        'wp-edit-post',
        'wp-element',
        'wp-components',
        'wp-data',
        'wp-core-data',
    ), '1.0.0', true);
    wp_enqueue_script($handle);

    $fields = array();
    foreach (mamateyka_article_authors_fields() as $field) {
        $fields[] = array(
            'key'   => $field['meta_key'],
            'label' => $field['label'],
        );
    }

    $script = <<<'JS'
(function (wp) {
    var Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel) ||
        (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
    if (!Panel || !wp.plugins || !wp.data || !wp.coreData) {
        return;
    }

    var el = wp.element.createElement;
    var TextControl = wp.components.TextControl;
    var fields = __FIELDS__;

    function AuthorsFields() {
        var entity = wp.coreData.useEntityProp('postType', 'post', 'meta');
        var meta = entity[0] || {};
        var setMeta = entity[1];

        return el(
            Panel,
            { name: 'mamateyka-article-authors', title: 'Autori článku' },
            fields.map(function (field) {
                return el(TextControl, {
                    key: field.key,
                    label: field.label,
                    value: meta[field.key] || '',
                    maxLength: 200,
                    __nextHasNoMarginBottom: true,
                    onChange: function (value) {
                        var change = {};
                        change[field.key] = value;
                        setMeta(Object.assign({}, meta, change));
                    }
                });
            }),
            el('p', { style: { marginBottom: 0, color: '#757575' } },
                'Nechajte prázdne, ak sa nemá zobraziť. Viac mien oddeľte čiarkou.')
        );
    }

    function AuthorsPanel() {
        var postType = wp.data.useSelect(function (select) {
            return select('core/editor').getCurrentPostType();
        }, []);

        return postType === 'post' ? el(AuthorsFields) : null;
    }

    wp.plugins.registerPlugin('mamateyka-article-authors', { render: AuthorsPanel });
})(window.wp);
JS;

    wp_add_inline_script($handle, str_replace('__FIELDS__', wp_json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG), $script));
});

function mamateyka_article_authors_styles()
{
    static $printed = false;

    if ($printed) {
        return '';
    }
    $printed = true;

    return '<style>'
        . '.mamateyka-autor{display:inline-flex;align-items:center;gap:.4em;color:inherit;font:inherit;line-height:inherit}'
        . '.mamateyka-autor__icon{width:1.1em;height:1.1em;flex:none;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}'
        . '.mamateyka-autor__label{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}'
        . '</style>';
}

function mamateyka_article_authors_render($field_key, $atts)
{
    $fields = mamateyka_article_authors_fields();
    if (!isset($fields[$field_key])) {
        return '';
    }
    $field = $fields[$field_key];

    $atts = shortcode_atts(array('id' => 0), $atts, $field['shortcode']);
    $current_id = (int) get_the_ID();
    $post_id = absint($atts['id']);
    if (!$post_id) {
        $post_id = $current_id;
    }
    if (!$post_id) {
        return '';
    }

    if ($post_id !== $current_id && !is_post_publicly_viewable($post_id) && !current_user_can('read_post', $post_id)) {
        return '';
    }

    $name = get_post_meta($post_id, $field['meta_key'], true);
    $name = is_string($name) ? trim($name) : '';
    if ($name === '') {
        return '';
    }

    return mamateyka_article_authors_styles()
        . '<span class="mamateyka-autor ' . esc_attr($field['class']) . '">'
        . '<svg class="mamateyka-autor__icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $field['icon'] . '</svg>'
        . '<span class="mamateyka-autor__label">' . esc_html($field['label']) . ':</span>'
        . '<span class="mamateyka-autor__name">' . esc_html($name) . '</span>'
        . '</span>';
}

add_action('init', function () {
    add_shortcode('clanok_autor_textu', static function ($atts = array()) {
        return mamateyka_article_authors_render('text', $atts);
    });

    add_shortcode('clanok_autor_fotografii', static function ($atts = array()) {
        return mamateyka_article_authors_render('photo', $atts);
    });
});
