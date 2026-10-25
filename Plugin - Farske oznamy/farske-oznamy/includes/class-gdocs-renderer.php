<?php

/**
 * Renders Google Docs API structured payload to HTML.
 */

if (! defined('ABSPATH')) {
    exit;
}

if (class_exists('Oznamy_GDocs_Renderer')) {
    return;
}

class Oznamy_GDocs_Renderer
{
    /**
     * @param array $document Docs API document payload
     * @return string
     */
    public function render($document)
    {
        if (! is_array($document) || empty($document['body']['content']) || ! is_array($document['body']['content'])) {
            return '';
        }

        $context = array(
            'lists' => is_array($document['lists'] ?? null) ? $document['lists'] : array(),
            'inlineObjects' => is_array($document['inlineObjects'] ?? null) ? $document['inlineObjects'] : array(),
        );

        return $this->render_structural_elements($document['body']['content'], $context);
    }

    /**
     * @param array<int, array> $elements
     * @param array<string, mixed> $context
     * @return string
     */
    private function render_structural_elements($elements, $context)
    {
        $html = '';
        $list_stack = array();

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            if (! empty($element['table'])) {
                $html .= $this->close_all_lists($list_stack);
                $html .= $this->render_table($element['table'], $context);
                continue;
            }

            if (empty($element['paragraph']) || ! is_array($element['paragraph'])) {
                continue;
            }

            $paragraph = $element['paragraph'];
            $bullet = $paragraph['bullet'] ?? null;

            if (is_array($bullet)) {
                $html .= $this->render_list_item($paragraph, $bullet, $context, $list_stack);
                continue;
            }

            $html .= $this->close_all_lists($list_stack);
            $html .= $this->render_paragraph($paragraph, $context);
        }

        $html .= $this->close_all_lists($list_stack);

        return $html;
    }

    /**
     * @param array<string, mixed> $paragraph
     * @param array<string, mixed> $bullet
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $list_stack
     * @return string
     */
    private function render_list_item($paragraph, $bullet, $context, &$list_stack)
    {
        $list_id = (string) ($bullet['listId'] ?? '');
        $nesting_level = (int) ($bullet['nestingLevel'] ?? 0);
        $list_info = $this->get_list_level_info($context['lists'], $list_id, $nesting_level);
        $list_tag = $list_info['tag'];
        $list_style = $list_info['style'];

        $html = '';

        while (count($list_stack) > $nesting_level + 1) {
            $html .= '</li></' . array_pop($list_stack)['tag'] . '>';
        }

        if (count($list_stack) === $nesting_level + 1) {
            $top = end($list_stack);
            if ($top && $top['tag'] !== $list_tag) {
                $html .= '</li></' . array_pop($list_stack)['tag'] . '>';
            } else {
                $html .= '</li><li>';
            }
        }

        while (count($list_stack) < $nesting_level + 1) {
            $level_tag = count($list_stack) === $nesting_level ? $list_tag : 'ul';
            $level_style = count($list_stack) === $nesting_level ? $list_style : '';
            $html .= '<' . $level_tag . $this->format_attrs(array(
                'class' => 'oznamy-list-lvl-' . count($list_stack),
                'style' => $level_style,
            )) . '><li>';
            $list_stack[] = array('tag' => $level_tag);
        }

        $paragraph_style = is_array($paragraph['paragraphStyle'] ?? null) ? $paragraph['paragraphStyle'] : array();
        $style = $this->build_paragraph_style($paragraph_style, true);
        $inner = $this->render_paragraph_inner($paragraph, $context);

        if ('' === $style) {
            $html .= $inner;
        } else {
            $html .= '<div' . $this->format_attrs(array('style' => $style)) . '>' . $inner . '</div>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $paragraph
     * @param array<string, mixed> $context
     * @return string
     */
    private function render_paragraph($paragraph, $context)
    {
        $inner = $this->render_paragraph_inner($paragraph, $context);
        if ('' === trim(strip_tags($inner))) {
            return '<p><br></p>';
        }

        $paragraph_style = is_array($paragraph['paragraphStyle'] ?? null) ? $paragraph['paragraphStyle'] : array();
        $tag = $this->resolve_paragraph_tag($paragraph_style);
        $style = $this->build_paragraph_style($paragraph_style);

        return '<' . $tag . $this->format_attrs(array('style' => $style)) . '>' . $inner . '</' . $tag . '>';
    }

    /**
     * @param array<string, mixed> $paragraph
     * @param array<string, mixed> $context
     * @return string
     */
    private function render_paragraph_inner($paragraph, $context)
    {
        $html = '';
        $elements = is_array($paragraph['elements'] ?? null) ? $paragraph['elements'] : array();

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            if (! empty($element['textRun']) && is_array($element['textRun'])) {
                $html .= $this->render_text_run($element['textRun']);
                continue;
            }

            if (! empty($element['inlineObjectElement']) && is_array($element['inlineObjectElement'])) {
                $html .= $this->render_inline_object($element['inlineObjectElement'], $context);
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $text_run
     * @return string
     */
    private function render_text_run($text_run)
    {
        $content = (string) ($text_run['content'] ?? '');
        $content = $this->strip_placeholder_chars($content);
        if ('' === $content) {
            return '';
        }

        $content = esc_html($content);
        $content = str_replace("\t", '<span class="oznamy-tab" aria-hidden="true"></span>', $content);
        $content = str_replace("\n", '<br>', $content);

        $text_style = is_array($text_run['textStyle'] ?? null) ? $text_run['textStyle'] : array();

        if (! empty($text_style['link']['url'])) {
            $url = esc_url_raw((string) $text_style['link']['url']);
            if ($url) {
                return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . $this->wrap_text_runs($content, $text_style) . '</a>';
            }
        }

        return $this->wrap_text_runs($content, $text_style);
    }

    /**
     * Google Docs may inject a placeholder replacement character for missing or unsupported inline objects.
     * These placeholders should never be displayed in imported text.
     *
     * @param string $content
     * @return string
     */
    private function strip_placeholder_chars($content)
    {
        $content = preg_replace('/[\x{FFFC}\x{FFFD}\x{FEFF}\x{0000}\x{001A}]/u', '', $content);

        return is_string($content) ? $content : '';
    }

    /**
     * @param string $content
     * @param array<string, mixed> $text_style
     * @return string
     */
    private function wrap_text_runs($content, $text_style)
    {
        $style = $this->build_text_style($text_style);
        $open = '';
        $close = '';

        if (! empty($text_style['bold'])) {
            $open .= '<strong>';
            $close = '</strong>' . $close;
        }

        if (! empty($text_style['italic'])) {
            $open .= '<em>';
            $close = '</em>' . $close;
        }

        if (! empty($text_style['underline'])) {
            $open .= '<u>';
            $close = '</u>' . $close;
        }

        if (! empty($text_style['strikethrough'])) {
            $open .= '<span style="text-decoration:line-through">';
            $close = '</span>' . $close;
        }

        return '<span' . $this->format_attrs(array('style' => $style)) . '>' . $open . $content . $close . '</span>';
    }

    /**
     * @param array<string, mixed> $inline_object_element
     * @param array<string, mixed> $context
     * @return string
     */
    private function render_inline_object($inline_object_element, $context)
    {
        // Inline images embedded directly in Google Docs text are intentionally ignored.
        // The plugin keeps gallery images in the Drive folder separate from document body content.
        return '';
    }

    /**
     * @param array<string, mixed> $table
     * @param array<string, mixed> $context
     * @return string
     */
    private function render_table($table, $context)
    {
        $rows = is_array($table['tableRows'] ?? null) ? $table['tableRows'] : array();
        if (empty($rows)) {
            return '';
        }

        $html = '<table class="oznamy-table"><tbody>';

        foreach ($rows as $row) {
            if (empty($row['tableCells']) || ! is_array($row['tableCells'])) {
                continue;
            }

            $html .= '<tr>';
            foreach ($row['tableCells'] as $cell) {
                $cell_content = is_array($cell['content'] ?? null) ? $cell['content'] : array();
                $html .= '<td>' . $this->render_structural_elements($cell_content, $context) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * @param array<string, mixed> $paragraph_style
     * @return string
     */
    private function resolve_paragraph_tag($paragraph_style)
    {
        $named = (string) ($paragraph_style['namedStyleType'] ?? '');
        $map = array(
            'HEADING_1' => 'h1',
            'HEADING_2' => 'h2',
            'HEADING_3' => 'h3',
            'HEADING_4' => 'h4',
            'HEADING_5' => 'h5',
            'HEADING_6' => 'h6',
        );

        return $map[$named] ?? 'p';
    }

    /**
     * @param array<string, mixed> $paragraph_style
     * @return string
     */
    private function build_paragraph_style($paragraph_style, $for_list_item = false)
    {
        $styles = array();

        $alignment = strtoupper((string) ($paragraph_style['alignment'] ?? ''));
        $alignment_map = array(
            'START' => 'left',
            'CENTER' => 'center',
            'END' => 'right',
            'JUSTIFIED' => 'justify',
        );
        if (isset($alignment_map[$alignment])) {
            $styles[] = 'text-align:' . $alignment_map[$alignment];
        }

        if (isset($paragraph_style['lineSpacing']) && is_numeric($paragraph_style['lineSpacing'])) {
            $line_height = ((float) $paragraph_style['lineSpacing']) / 100;
            if ($line_height > 0) {
                $styles[] = 'line-height:' . rtrim(rtrim(sprintf('%.3f', $line_height), '0'), '.');
            }
        }

        if (! $for_list_item) {
            $margin_left = $this->dimension_to_pt($paragraph_style['indentStart'] ?? null);
            if (null !== $margin_left) {
                $styles[] = 'margin-left:' . $margin_left;
            }

            $margin_right = $this->dimension_to_pt($paragraph_style['indentEnd'] ?? null);
            if (null !== $margin_right) {
                $styles[] = 'margin-right:' . $margin_right;
            }

            $text_indent = $this->dimension_to_pt($paragraph_style['indentFirstLine'] ?? null);
            if (null !== $text_indent) {
                $styles[] = 'text-indent:' . $text_indent;
            }

            $space_top = $this->dimension_to_pt($paragraph_style['spaceAbove'] ?? null);
            if (null !== $space_top) {
                $styles[] = 'margin-top:' . $space_top;
            }

            $space_bottom = $this->dimension_to_pt($paragraph_style['spaceBelow'] ?? null);
            if (null !== $space_bottom) {
                $styles[] = 'margin-bottom:' . $space_bottom;
            }
        }

        return implode(';', $styles);
    }

    /**
     * @param array<string, mixed> $text_style
     * @return string
     */
    private function build_text_style($text_style)
    {
        $styles = array();

        if (! empty($text_style['foregroundColor']['color']['rgbColor'])) {
            $rgb = $this->rgb_to_css($text_style['foregroundColor']['color']['rgbColor']);
            if (null !== $rgb) {
                $styles[] = 'color:' . $rgb;
            }
        }

        if (! empty($text_style['fontSize']['magnitude']) && ! empty($text_style['fontSize']['unit'])) {
            $size = (float) $text_style['fontSize']['magnitude'];
            $unit = strtolower((string) $text_style['fontSize']['unit']);
            if ($size > 0 && in_array($unit, array('pt', 'px', 'em', 'rem'), true)) {
                $styles[] = 'font-size:' . $size . $unit;
            }
        }

        return implode(';', $styles);
    }

    /**
     * @param array<string, mixed> $lists
     * @param string $list_id
     * @param int $nesting_level
     * @return array{tag:string,style:string}
     */
    private function get_list_level_info($lists, $list_id, $nesting_level)
    {
        $glyph_type = '';
        if (! empty($lists[$list_id]['listProperties']['nestingLevels'][$nesting_level]['glyphType'])) {
            $glyph_type = (string) $lists[$list_id]['listProperties']['nestingLevels'][$nesting_level]['glyphType'];
        }

        $ordered_types = array('DECIMAL', 'ZERO_DECIMAL', 'UPPER_ALPHA', 'ALPHA', 'UPPER_ROMAN', 'ROMAN', 'LOWER_ALPHA', 'LOWER_ROMAN');
        $is_ordered = in_array($glyph_type, $ordered_types, true);
        $tag = $is_ordered ? 'ol' : 'ul';
        $style = '';

        $style_map = array(
            'UPPER_ALPHA' => 'upper-alpha',
            'LOWER_ALPHA' => 'lower-alpha',
            'ALPHA' => 'lower-alpha',
            'UPPER_ROMAN' => 'upper-roman',
            'LOWER_ROMAN' => 'lower-roman',
            'ROMAN' => 'lower-roman',
            'DECIMAL' => 'decimal',
            'ZERO_DECIMAL' => 'decimal-leading-zero',
            'DISC' => 'disc',
            'CIRCLE' => 'circle',
            'SQUARE' => 'square',
        );

        if (! empty($style_map[$glyph_type])) {
            $style = 'list-style-type:' . $style_map[$glyph_type];
        }

        return array('tag' => $tag, 'style' => $style);
    }

    /**
     * @param array<int, array<string, mixed>> $list_stack
     * @return string
     */
    private function close_all_lists(&$list_stack)
    {
        $html = '';
        while (! empty($list_stack)) {
            $entry = array_pop($list_stack);
            $html .= '</li></' . $entry['tag'] . '>';
        }
        return $html;
    }

    /**
     * @param mixed $dimension
     * @return string|null
     */
    private function dimension_to_pt($dimension)
    {
        if (! is_array($dimension) || ! isset($dimension['magnitude'])) {
            return null;
        }

        $magnitude = (float) $dimension['magnitude'];
        if (0.0 === $magnitude) {
            return null;
        }

        $unit = strtolower((string) ($dimension['unit'] ?? 'pt'));
        if (! in_array($unit, array('pt', 'px', 'em', 'rem'), true)) {
            $unit = 'pt';
        }

        return $magnitude . $unit;
    }

    /**
     * @param array<string, mixed> $rgb
     * @return string|null
     */
    private function rgb_to_css($rgb)
    {
        $r = isset($rgb['red']) ? (float) $rgb['red'] : null;
        $g = isset($rgb['green']) ? (float) $rgb['green'] : null;
        $b = isset($rgb['blue']) ? (float) $rgb['blue'] : null;

        if (null === $r || null === $g || null === $b) {
            return null;
        }

        $rr = max(0, min(255, (int) round($r * 255)));
        $gg = max(0, min(255, (int) round($g * 255)));
        $bb = max(0, min(255, (int) round($b * 255)));

        return sprintf('#%02x%02x%02x', $rr, $gg, $bb);
    }

    /**
     * @param array<string, string> $attrs
     * @return string
     */
    private function format_attrs($attrs)
    {
        $pairs = array();
        foreach ($attrs as $name => $value) {
            if ('' === (string) $value) {
                continue;
            }
            $pairs[] = $name . '="' . esc_attr((string) $value) . '"';
        }

        return empty($pairs) ? '' : ' ' . implode(' ', $pairs);
    }
}
