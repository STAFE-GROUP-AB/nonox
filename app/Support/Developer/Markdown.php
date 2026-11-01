<?php

declare(strict_types=1);

namespace App\Support\Developer;

use Closure;
use Illuminate\Support\Str;

/**
 * Markdown from docs/api (the guides and the changelog) as the developer
 * site shows it. On top of GitHub-flavoured Markdown:
 *
 * - fenced code is highlighted and gets a copy button;
 * - code between `<!-- tabs -->` and `<!-- /tabs -->` becomes one tab group;
 * - `<!-- nonox:name -->` is replaced with HTML generated from the spec;
 * - `<!-- lang:sv -->…<!-- /lang -->` keeps only the reader's language;
 * - headings get ids, the h2s make the page's table of contents;
 * - links to `other-guide.md` and to https://nonox.app/utvecklare go to the
 *   pages on this installation, in the reader's language;
 * - tables scroll sideways inside their own box on a narrow screen.
 *
 * The files are ours, in the repository; their HTML is trusted.
 */
final class Markdown
{
    /**
     * @param  array<string, Closure(): string>  $placeholders
     * @return array{title: string, html: string, toc: list<array{id: string, text: string}>}
     */
    public static function render(string $markdown, string $lang, array $placeholders = []): array
    {
        $markdown = (string) preg_replace_callback(
            '/<!-- lang:(sv|en) -->(.*?)<!-- \/lang -->/s',
            fn (array $match): string => $match[1] === $lang ? $match[2] : '',
            $markdown,
        );

        $title = '';

        if (preg_match('/\A#\s+(.+)\n/', ltrim($markdown), $match) === 1) {
            $title = trim($match[1]);
            $markdown = (string) preg_replace('/\A#\s+.+\n/', '', ltrim($markdown));
        }

        $html = Str::markdown($markdown, ['html_input' => 'allow', 'allow_unsafe_links' => false]);

        $html = (string) preg_replace_callback('/<!-- tabs -->(.*?)<!-- \/tabs -->/s', function (array $match) use ($lang): string {
            preg_match_all('#<pre><code(?: class="language-([\w-]+)")?>(.*?)</code></pre>#s', $match[1], $blocks, PREG_SET_ORDER);

            return CodeBlock::tabs(array_map(fn (array $block): array => [
                'id' => Highlighter::language($block[1]),
                'label' => self::label($block[1]),
                'language' => $block[1],
                'code' => html_entity_decode($block[2], ENT_QUOTES | ENT_HTML5),
            ], $blocks), $lang, DeveloperPortal::t('code_examples', $lang));
        }, $html);

        $html = (string) preg_replace_callback('#<pre><code(?: class="language-([\w-]+)")?>(.*?)</code></pre>#s', fn (array $match): string => CodeBlock::html(
            html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5),
            $match[1],
            $lang,
            $match[1] === '' ? null : self::label($match[1]),
        ), $html);

        $html = (string) preg_replace_callback('/<!-- nonox:([\w:-]+) -->/', fn (array $match): string => isset($placeholders[$match[1]]) ? ($placeholders[$match[1]])() : '', $html);

        $html = str_replace(['<table>', '</table>'], ['<div class="dv-table" tabindex="0"><table>', '</table></div>'], $html);

        $html = (string) preg_replace_callback('/href="([^"]+)"/', fn (array $match): string => 'href="'.e(self::link(html_entity_decode($match[1]), $lang)).'"', $html);

        $toc = [];
        $used = [];
        $html = (string) preg_replace_callback('#<h([23])>(.*?)</h\1>#s', function (array $match) use (&$toc, &$used): string {
            $text = trim(strip_tags($match[2]));
            $base = Str::slug($text) ?: 'avsnitt';
            $used[$base] = ($used[$base] ?? 0) + 1;
            $id = $used[$base] === 1 ? $base : "{$base}-{$used[$base]}";

            if ($match[1] === '2') {
                $toc[] = ['id' => $id, 'text' => $text];
            }

            return '<h'.$match[1].' id="'.$id.'"><a class="dv-anchor" href="#'.$id.'">'.$match[2].'</a></h'.$match[1].'>';
        }, $html);

        return ['title' => $title, 'html' => $html, 'toc' => $toc];
    }

    public static function label(string $fence): string
    {
        return match (Highlighter::language($fence)) {
            'bash' => $fence === 'curl' ? 'curl' : 'Shell',
            'php' => 'PHP',
            'js' => 'JavaScript',
            'python' => 'Python',
            'json' => 'JSON',
            default => 'HTTP',
        };
    }

    /** A link in a guide: another guide's file, a page on nonox.app/utvecklare, or anything else as it is. */
    private static function link(string $href, string $lang): string
    {
        if (preg_match('#^([a-z0-9-]+)\.md(\#.*)?$#', $href, $match) === 1 && Guides::exists($match[1])) {
            return DeveloperPortal::url('developers.guide', ['guide' => $match[1]], $lang).($match[2] ?? '');
        }

        if (preg_match('#^https://nonox\.app(/utvecklare(?:/[a-z0-9/-]*)?)(\#.*)?$#', $href, $match) === 1) {
            return DeveloperPortal::localized(url($match[1]), $lang).($match[2] ?? '');
        }

        return $href;
    }
}
