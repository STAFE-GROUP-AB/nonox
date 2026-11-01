<?php

declare(strict_types=1);

namespace App\Support\Developer;

/**
 * The HTML of a code block on the developer site, with highlighting and a
 * copy button, and of a group of code blocks shown as tabs. Without
 * JavaScript every block stays visible; the site's own script turns a group
 * into tabs and makes the copy buttons work (resources/js/developer-portal.js).
 */
final class CodeBlock
{
    private static int $counter = 0;

    public static function html(string $code, string $language, string $lang, ?string $label = null): string
    {
        $code = rtrim($code, "\n");
        $copy = DeveloperPortal::t('copy', $lang);
        $label = $label === null ? '' : '<span class="dv-code-label">'.e($label).'</span>';

        return '<div class="dv-code">'
            .'<div class="dv-code-bar">'.$label.'<button type="button" class="dv-copy" data-copy data-copied="'.e(DeveloperPortal::t('copied', $lang)).'" hidden>'.e($copy).'</button></div>'
            .'<pre tabindex="0"><code class="lang-'.e(Highlighter::language($language)).'">'.Highlighter::highlight($code, $language).'</code></pre>'
            .'</div>';
    }

    /**
     * Code blocks as tabs. The tab id names the language, so choosing PHP in
     * one group chooses it in every group on the page.
     *
     * @param  list<array{id: string, label: string, language: string, code: string}>  $blocks
     */
    public static function tabs(array $blocks, string $lang, string $title): string
    {
        $group = 'dv-t'.(++self::$counter);
        $tabs = '';
        $panels = '';

        foreach ($blocks as $index => $block) {
            $tab = "{$group}-{$index}";
            $selected = $index === 0 ? 'true' : 'false';
            $tabs .= '<button type="button" role="tab" id="'.$tab.'-tab" aria-controls="'.$tab.'" aria-selected="'.$selected.'" tabindex="'.($index === 0 ? '0' : '-1').'" data-tab="'.e($block['id']).'">'.e($block['label']).'</button>';
            $panels .= '<div role="tabpanel" id="'.$tab.'" aria-labelledby="'.$tab.'-tab" data-tab-panel="'.e($block['id']).'">'.self::html($block['code'], $block['language'], $lang, $block['label']).'</div>';
        }

        return '<div class="dv-tabs" data-tabs><div class="dv-tablist" role="tablist" aria-label="'.e($title).'" hidden>'.$tabs.'</div>'.$panels.'</div>';
    }
}
