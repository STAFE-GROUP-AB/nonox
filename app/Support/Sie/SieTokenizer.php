<?php

declare(strict_types=1);

namespace App\Support\Sie;

/**
 * Splits one line of a SIE file into fields (§5.7): fields are separated by
 * spaces or tabs, may be quoted, a quote inside a field is written \" and an
 * object list is enclosed in braces. Works on raw bytes; the caller decodes.
 */
final class SieTokenizer
{
    /**
     * @return list<string|list<string>>
     *
     * @throws SieSyntaxError
     */
    public static function tokenize(string $line): array
    {
        $tokens = [];
        $length = strlen($line);
        $position = 0;
        $list = null;

        while (true) {
            $position += strspn($line, " \t", $position);

            if ($position >= $length) {
                break;
            }

            $char = $line[$position];

            if ($char === '{') {
                if ($list !== null) {
                    throw new SieSyntaxError('nested object list');
                }

                $list = [];
                $position++;

                continue;
            }

            if ($char === '}') {
                if ($list === null) {
                    throw new SieSyntaxError('closing brace without an object list');
                }

                $tokens[] = $list;
                $list = null;
                $position++;

                continue;
            }

            $token = $char === '"'
                ? self::quoted($line, $position)
                : self::bare($line, $position);

            if ($list !== null) {
                $list[] = $token;
            } else {
                $tokens[] = $token;
            }
        }

        if ($list !== null) {
            throw new SieSyntaxError('object list is not closed');
        }

        return $tokens;
    }

    private static function quoted(string $line, int &$position): string
    {
        $value = '';
        $length = strlen($line);
        $position++;

        while ($position < $length) {
            $char = $line[$position];

            if ($char === '\\' && ($line[$position + 1] ?? '') === '"') {
                $value .= '"';
                $position += 2;

                continue;
            }

            if ($char === '"') {
                $position++;

                return $value;
            }

            $value .= $char;
            $position++;
        }

        throw new SieSyntaxError('quoted field is not closed');
    }

    private static function bare(string $line, int &$position): string
    {
        $span = strcspn($line, " \t{}\"", $position);
        $value = substr($line, $position, $span);
        $position += $span;

        return $value;
    }
}
