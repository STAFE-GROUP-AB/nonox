<?php

declare(strict_types=1);

namespace App\Support\Manual;

use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * One article of the user manual (docs/manual/sv, SKRIVREGLER.md) as the
 * manual shows it. On top of GitHub-flavoured Markdown:
 *
 * - YAML front matter between `---` lines is read as the article's data;
 * - the first `#` heading is the title, left out of the body;
 * - GitHub alerts (`> [!TIP]`, `[!NOTE]`, `[!IMPORTANT]`, `[!WARNING]`)
 *   become notices with a Swedish label;
 * - a numbered list right under a heading that starts with "Så här" is
 *   shown as steps, and its items are the steps of the page's HowTo data;
 * - headings get ids and anchors, and the h2s make the table of contents;
 * - tables scroll sideways inside their own box on a narrow screen;
 * - links to other sites get rel="noopener", root-relative links get this
 *   installation's address.
 *
 * The files are ours, in the repository; their HTML (`<kbd>`) is trusted.
 * Unlike the developer site's Markdown, nothing here rewrites links to
 * guides or knows about languages: the manual has its own rules.
 *
 * @phpstan-type Rendered array{title: string, html: string, toc: list<array{id: string, text: string}>, headings: list<string>, steps: list<string>, text: string, minutes: int}
 */
final class ManualMarkdown
{
    /** The alert types and their labels; CAUTION is GitHub's other word for a warning. */
    public const array ALERTS = [
        'TIP' => ['tip', 'Tips'],
        'NOTE' => ['note', 'Bra att veta'],
        'IMPORTANT' => ['important', 'Viktigt'],
        'WARNING' => ['warning', 'Varning'],
        'CAUTION' => ['warning', 'Varning'],
    ];

    /** Words a reader reads per minute, for the reading time. */
    private const int WORDS_PER_MINUTE = 180;

    /**
     * The front matter and the Markdown after it.
     *
     * @return array{meta: array<string, mixed>, body: string}
     */
    public static function split(string $source): array
    {
        $source = str_replace("\r\n", "\n", $source);

        if (preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $source, $match) !== 1) {
            return ['meta' => [], 'body' => $source];
        }

        $meta = Yaml::parse($match[1]);

        return ['meta' => is_array($meta) ? $meta : [], 'body' => $match[2]];
    }

    /** @return Rendered */
    public static function render(string $markdown): array
    {
        $title = '';
        $markdown = ltrim($markdown);

        if (preg_match('/\A#\s+(.+)\n?/', $markdown, $match) === 1) {
            $title = trim($match[1]);
            $markdown = (string) substr($markdown, strlen($match[0]));
        }

        $html = Str::markdown($markdown, ['html_input' => 'allow', 'allow_unsafe_links' => false]);

        $html = self::alerts($html);
        $steps = [];
        $html = self::steps($html, $steps);

        $html = (string) preg_replace('#<table>(.*?)</table>#s', '<div class="mn-table" role="region" aria-label="Tabell" tabindex="0"><table>$1</table></div>', $html);
        $html = (string) preg_replace_callback('/<a href="([^"]*)"/', fn (array $match): string => self::link(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5)), $html);

        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5)));

        $toc = [];
        $headings = [];
        $used = [];
        $html = (string) preg_replace_callback('#<h([23])>(.*?)</h\1>#s', function (array $match) use (&$toc, &$headings, &$used): string {
            $text = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5));
            $base = Str::slug($text) ?: 'avsnitt';
            $used[$base] = ($used[$base] ?? 0) + 1;
            $id = $used[$base] === 1 ? $base : "{$base}-{$used[$base]}";
            $headings[] = $text;

            if ($match[1] === '2') {
                $toc[] = ['id' => $id, 'text' => $text];
            }

            return '<h'.$match[1].' id="'.$id.'"><a class="mn-anchor" href="#'.$id.'">'.$match[2].'</a></h'.$match[1].'>';
        }, $html);

        $words = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return [
            'title' => $title,
            'html' => $html,
            'toc' => $toc,
            'headings' => $headings,
            'steps' => $steps,
            'text' => $text,
            'minutes' => max(1, (int) ceil($words / self::WORDS_PER_MINUTE)),
        ];
    }

    /** `> [!TIP]` and its siblings as a notice with its label and icon. */
    private static function alerts(string $html): string
    {
        return (string) preg_replace_callback('#<blockquote>\s*<p>\[!(TIP|NOTE|IMPORTANT|WARNING|CAUTION)\][ \t]*\n?(.*?)</blockquote>#s', function (array $match): string {
            [$type, $label] = self::ALERTS[$match[1]];
            $body = (string) preg_replace('#<p>\s*</p>#', '', '<p>'.trim($match[2]));

            return '<div class="mn-callout mn-callout-'.$type.'" role="note">'
                .'<p class="mn-callout-title">'.Icons::svg('alert-'.$type, 18).'<span>'.$label.'</span></p>'
                .'<div class="mn-callout-body">'.$body.'</div></div>';
        }, $html);
    }

    /**
     * The numbered list right under a "Så här …" heading as steps.
     *
     * @param  list<string>  $steps  the text of every step, for the HowTo data
     */
    private static function steps(string $html, array &$steps): string
    {
        return (string) preg_replace_callback('#(<h([23])>\s*Så här.*?</h\2>\s*)<ol>(.*?)</ol>#su', function (array $match) use (&$steps): string {
            preg_match_all('#<li>(.*?)</li>#s', $match[3], $items);

            foreach ($items[1] as $item) {
                $steps[] = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($item), ENT_QUOTES | ENT_HTML5)));
            }

            return $match[1].'<ol class="mn-steps">'.$match[3].'</ol>';
        }, $html);
    }

    /** A link to another site opens nothing of ours; a root-relative link gets this installation's address. */
    private static function link(string $href): string
    {
        if (preg_match('#^https?://#i', $href) === 1 && parse_url($href, PHP_URL_HOST) !== parse_url(url('/'), PHP_URL_HOST)) {
            return '<a href="'.e($href).'" rel="noopener"';
        }

        if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            return '<a href="'.e(url($href)).'"';
        }

        return '<a href="'.e($href).'"';
    }
}
