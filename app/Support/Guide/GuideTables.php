<?php

declare(strict_types=1);

namespace App\Support\Guide;

/**
 * Amounts in the guide's tables are right-aligned with tabular numbers
 * (AGENTS.md §11.1). A column counts as numeric when every filled cell
 * under its header is a number in Swedish form, optionally signed and
 * followed by "kr", "%" or "procent" ("1 234,50 kr", "−500", "25 %").
 * The manual's tables are left as they are.
 */
final class GuideTables
{
    public const string NUMBER = '/^[−–+-]?\s?\d{1,3}(?:[\s\x{00A0}\x{202F}]?\d{3})*(?:,\d+)?(?:\s?(?:kr|%|procent|öre|SEK))?$/u';

    public static function alignNumbers(string $html): string
    {
        return (string) preg_replace_callback('#<table>(.*?)</table>#s', fn (array $match): string => '<table>'.self::table($match[1]).'</table>', $html);
    }

    public static function isNumber(string $text): bool
    {
        return preg_match(self::NUMBER, trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5))) === 1;
    }

    private static function table(string $table): string
    {
        preg_match_all('#<tbody>(.*?)</tbody>#s', $table, $bodies);
        preg_match_all('#<tr>(.*?)</tr>#s', implode('', $bodies[1]), $rows);
        $numeric = [];

        foreach ($rows[1] as $row) {
            preg_match_all('#<td(?:\s[^>]*)?>(.*?)</td>#s', $row, $cells);

            foreach ($cells[1] as $column => $cell) {
                if (trim(strip_tags($cell)) === '') {
                    continue;
                }

                $numeric[$column] = ($numeric[$column] ?? true) && self::isNumber($cell);
            }
        }

        $columns = array_keys(array_filter($numeric));

        if ($columns === []) {
            return $table;
        }

        return (string) preg_replace_callback('#<tr>(.*?)</tr>#s', function (array $row) use ($columns): string {
            $index = -1;

            return '<tr>'.preg_replace_callback('#<(td|th)(\s[^>]*)?>#', function (array $cell) use (&$index, $columns): string {
                $index++;

                if (! in_array($index, $columns, true)) {
                    return $cell[0];
                }

                // A column alignment from the Markdown (style="text-align: …") gives way to the number's.
                $attributes = (string) preg_replace('/\sstyle="text-align:\s*\w+;?"/', '', $cell[2] ?? '');

                return "<{$cell[1]} class=\"gd-num\"{$attributes}>";
            }, $row[1]).'</tr>';
        }, $table);
    }
}
