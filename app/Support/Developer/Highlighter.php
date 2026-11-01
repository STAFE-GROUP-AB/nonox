<?php

declare(strict_types=1);

namespace App\Support\Developer;

/**
 * A small server-side syntax highlighter for the code on the developer
 * site: comments, strings, numbers, keywords, variables, flags and JSON keys
 * become spans with a class; everything else is escaped text. No script
 * runs in the browser for it, and nothing is loaded from elsewhere.
 */
final class Highlighter
{
    private const array KEYWORDS = [
        'php' => ['use', 'new', 'return', 'function', 'fn', 'if', 'else', 'elseif', 'foreach', 'as', 'throw', 'true', 'false', 'null', 'array', 'echo', 'try', 'catch', 'static', 'public', 'private', 'class', 'declare', 'string', 'int', 'bool', 'void', 'match', 'hash_equals', 'hash_hmac'],
        'js' => ['const', 'let', 'var', 'await', 'async', 'function', 'return', 'if', 'else', 'throw', 'new', 'true', 'false', 'null', 'undefined', 'import', 'from', 'export', 'try', 'catch', 'of', 'for', 'typeof'],
        'python' => ['import', 'from', 'def', 'return', 'if', 'else', 'elif', 'raise', 'True', 'False', 'None', 'with', 'as', 'for', 'in', 'not', 'and', 'or', 'try', 'except', 'lambda', 'class'],
        'bash' => ['curl', 'export', 'echo', 'openssl', 'printf', 'tr', 'cut', 'php', 'composer', 'git', 'uuidgen'],
        'json' => ['true', 'false', 'null'],
        'http' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'HTTP'],
    ];

    /** Fence names in Markdown that mean one of our languages. */
    public const array ALIASES = ['sh' => 'bash', 'shell' => 'bash', 'curl' => 'bash', 'javascript' => 'js', 'node' => 'js', 'py' => 'python', 'text' => 'http', 'plain' => 'http'];

    public static function language(string $name): string
    {
        $name = strtolower(trim($name));
        $name = self::ALIASES[$name] ?? $name;

        return array_key_exists($name, self::KEYWORDS) ? $name : 'http';
    }

    /** The code as escaped HTML with highlighting spans. */
    public static function highlight(string $code, string $language): string
    {
        $language = self::language($language);
        $pattern = self::pattern($language);

        if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return e($code);
        }

        $html = '';
        $position = 0;

        foreach ($matches as $match) {
            [$text, $offset] = $match[0];

            if ($text === '') {
                continue;
            }

            $html .= e(substr($code, $position, $offset - $position));
            $html .= self::span($match, $text, $language);
            $position = $offset + strlen($text);
        }

        return $html.e(substr($code, $position));
    }

    /** @param array<int|string, array{0: string, 1: int}> $match */
    private static function span(array $match, string $text, string $language): string
    {
        $class = match (true) {
            self::captured($match, 'comment') => 'c',
            self::captured($match, 'key') => 'p',
            self::captured($match, 'string') => 's',
            self::captured($match, 'number') => 'n',
            self::captured($match, 'variable') => 'v',
            self::captured($match, 'flag') => 'f',
            self::captured($match, 'word') && in_array($text, self::KEYWORDS[$language], true) => 'k',
            default => null,
        };

        return $class === null ? e($text) : '<span class="tk-'.$class.'">'.e($text).'</span>';
    }

    /** @param array<int|string, array{0: string, 1: int}> $match */
    private static function captured(array $match, string $group): bool
    {
        return isset($match[$group]) && $match[$group][1] !== -1 && $match[$group][0] !== '';
    }

    private static function pattern(string $language): string
    {
        $comment = match ($language) {
            'php' => '\/\/[^\n]*|#(?!\[)[^\n]*|\/\*[\s\S]*?\*\/',
            'js' => '\/\/[^\n]*|\/\*[\s\S]*?\*\/',
            'python' => '#[^\n]*',
            'bash' => '(?<![\w$])#[^\n]*',
            default => '(?!)',
        };
        $string = $language === 'js'
            ? '"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'|`(?:[^`\\\\]|\\\\.)*`'
            : '"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'';
        $key = in_array($language, ['json', 'js', 'python', 'php'], true) ? '"(?:[^"\\\\\n]|\\\\.)*"(?=\s*:(?!:))' : '(?!)';
        $variable = match ($language) {
            'php' => '\$[A-Za-z_]\w*',
            'bash' => '\$\{[^}\n]+\}|\$\([^)\n]*\)|\$[A-Za-z_]\w*',
            default => '(?!)',
        };
        $flag = $language === 'bash' ? '(?<=\s)--?[A-Za-z][\w-]*' : '(?!)';

        return '/(?<comment>'.$comment.')|(?<key>'.$key.')|(?<string>'.$string.')|(?<variable>'.$variable.')|(?<flag>'.$flag.')|(?<number>(?<![\w.])-?\d+(?:\.\d+)?(?![\w.]))|(?<word>[A-Za-z_][\w]*)/';
    }
}
