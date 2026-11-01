<?php

declare(strict_types=1);

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Finding a person by the last four digits of the personal number, for
 * those allowed to see personal numbers (ADR-026). The numbers are
 * encrypted at rest, so they are decrypted here, for one company's people
 * only and at most MAX of them; the number itself never leaves this class,
 * is never logged and is never shown in a result.
 */
final class PersonalNumberSuffix
{
    public const int MAX = 5000;

    /** The four digits, when that is all the user typed. */
    public static function of(SearchQuery $query): ?string
    {
        $text = trim($query->text);

        return preg_match('/^\d{4}$/', $text) === 1 ? $text : null;
    }

    /**
     * Ids of the records whose personal number ends with the digits.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  already scoped to the company
     * @return list<int>
     */
    public static function matching(Builder $query, string $suffix): array
    {
        $ids = [];

        foreach ($query->limit(self::MAX)->get(['id', 'personal_number']) as $record) {
            $number = preg_replace('/\D/', '', (string) $record->getAttribute('personal_number'));

            if (is_string($number) && str_ends_with($number, $suffix)) {
                $ids[] = (int) $record->getKey();
            }
        }

        return $ids;
    }

    public static function label(string $suffix): string
    {
        return __('Personnummer slutar på :digits', ['digits' => $suffix]);
    }
}
