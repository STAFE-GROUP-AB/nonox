<?php

declare(strict_types=1);

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Shared ranking for providers that search a table (ADR-026).
 *
 * Score: an exact number or identifier 1000, an exact name 800, a name that
 * starts with the text 600, a word that does 500, contains it 400, and a
 * fuzzy (trigram) match 100–300. Ties go to the most recent record.
 *
 * Text is matched with ILIKE and pg_trgm's word similarity (`<%`), both of
 * which use the GIN trigram indexes added with the search.
 */
abstract class DatabaseSearchProvider extends BaseSearchProvider
{
    public const int EXACT = 1000;

    /**
     * Requires every word of the free text to match one of the columns,
     * or one of the identifier conditions in $orWhere to hold.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<literal-string>  $columns  SQL expressions, from code
     * @param  null|callable(Builder<TModel>): void  $orWhere
     */
    protected function whereText(Builder $query, array $columns, SearchQuery $search, ?callable $orWhere = null): void
    {
        $terms = $search->terms();

        if ($terms === [] && $orWhere === null) {
            return;
        }

        $query->where(function (Builder $outer) use ($terms, $columns, $orWhere): void {
            if ($terms !== []) {
                $outer->where(function (Builder $all) use ($terms, $columns): void {
                    foreach ($terms as $term) {
                        $all->where(function (Builder $any) use ($term, $columns): void {
                            foreach ($columns as $column) {
                                $any->orWhereRaw("{$column} ILIKE ?", ['%'.self::escapeLike($term).'%']);

                                if (mb_strlen($term) >= 4) {
                                    $any->orWhereRaw("? <% {$column}", [$term]);
                                }
                            }
                        });
                    }
                });
            }

            if ($orWhere !== null) {
                $outer->orWhere(fn (Builder $identifier) => $orWhere($identifier));
            }
        });
    }

    /**
     * The rank of the free text against the columns, as a SQL expression
     * with its bindings.
     *
     * @param  list<literal-string>  $columns
     * @return array{0: literal-string, 1: list<mixed>}
     */
    protected function textRank(array $columns, SearchQuery $search): array
    {
        $text = trim($search->text);

        if ($text === '' || $columns === []) {
            return ['0', []];
        }

        $escaped = self::escapeLike($text);
        $parts = [];
        $bindings = [];

        foreach ($columns as $column) {
            $value = "coalesce({$column}, '')";
            $parts[] = "CASE WHEN lower({$value}) = lower(?) THEN 800 WHEN {$value} ILIKE ? THEN 600 WHEN {$value} ILIKE ? THEN 500 WHEN {$value} ILIKE ? THEN 400 ELSE 100 + 200 * word_similarity(?, {$value}) END";
            array_push($bindings, $text, "{$escaped}%", "% {$escaped}%", "%{$escaped}%", $text);
        }

        return [self::greatest($parts), $bindings];
    }

    /**
     * Adds the rank column `search_rank`: the highest of the identifier
     * matches (each worth EXACT or the given score) and the text rank.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<literal-string>  $textColumns
     * @param  list<array{0: literal-string, 1: list<mixed>, 2?: int}>  $identifiers  SQL condition, bindings, score
     */
    protected function selectRank(Builder $query, array $textColumns, SearchQuery $search, array $identifiers = []): void
    {
        [$text, $bindings] = $this->textRank($textColumns, $search);
        $parts = [$text];

        foreach ($identifiers as $identifier) {
            $parts[] = 'CASE WHEN '.$identifier[0].' THEN CAST(? AS double precision) ELSE 0 END';
            array_push($bindings, ...$identifier[1]);
            $bindings[] = $identifier[2] ?? self::EXACT;
        }

        $query->selectRaw(self::greatest($parts).' AS search_rank', $bindings);
    }

    /**
     * Amount filter on an öre column.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    protected function whereAmount(Builder $query, string $column, SearchQuery $search): void
    {
        if ($search->amountMin !== null) {
            $query->where($column, '>=', $search->amountMin);
        }

        if ($search->amountMax !== null) {
            $query->where($column, '<=', $search->amountMax);
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    protected function whereDate(Builder $query, string $column, SearchQuery $search): void
    {
        if ($search->dateFrom !== null && $search->dateTo !== null) {
            $query->whereBetween($column, [$search->dateFrom->toDateString(), $search->dateTo->toDateString()]);
        }
    }

    /**
     * Up to $limit records, best first, and how many matched (counted only
     * when the page is full, and only up to the cap).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  with search_rank selected
     * @param  string  $tieBreak  the column that orders equal ranks, normally the record's date (newest first)
     * @param  'asc'|'desc'  $direction
     * @return array{0: Collection<int, TModel>, 1: int}
     */
    protected function fetch(Builder $query, string $tieBreak, int $limit, string $direction = 'desc'): array
    {
        $table = $query->getModel()->getTable();
        $records = (clone $query)->orderByDesc('search_rank')->orderBy($tieBreak, $direction)->limit($limit)->get();

        if ($records->count() < $limit) {
            return [$records, $records->count()];
        }

        $sub = (clone $query)->setEagerLoads([]);
        $sub->getQuery()->columns = null;
        $sub->getQuery()->bindings['select'] = [];
        $total = DB::query()->fromSub($sub->select("{$table}.id")->limit(SearchGroup::COUNT_CAP + 1), 'matches')->count();

        return [$records, $total];
    }

    /**
     * The identifier conditions as an OR group for whereText().
     *
     * @param  list<array{0: literal-string, 1: list<mixed>, 2?: int}>  $identifiers
     * @return null|callable(Builder<covariant Model>): void
     */
    protected function anyOf(array $identifiers): ?callable
    {
        if ($identifiers === []) {
            return null;
        }

        return function (Builder $where) use ($identifiers): void {
            foreach ($identifiers as $identifier) {
                $where->orWhereRaw($identifier[0], $identifier[1]);
            }
        };
    }

    /**
     * An organisation number however it is typed: "556677-8899",
     * "5566778899", or its first six digits or more.
     *
     * @param  literal-string  $table
     * @return list<array{0: literal-string, 1: list<mixed>, 2?: int}>
     */
    protected function orgNumber(string $table, SearchQuery $query): array
    {
        $digits = $query->digits();

        if ($digits === null || strlen($digits) < 6) {
            return [];
        }

        return [["regexp_replace(coalesce({$table}.org_number, ''), '\\D', '', 'g') LIKE ?", [$digits.'%'], 950]];
    }

    /** Whether the user asked for anything this provider can answer. */
    protected function wants(SearchQuery $search, bool $amounts = false, bool $dates = false, bool $statuses = false): bool
    {
        if ($search->excludes($this->key())) {
            return false;
        }

        if ($search->hasAmount() && ! $amounts) {
            return false;
        }

        if ($search->hasDate() && ! $dates) {
            return false;
        }

        if ($search->hasStatus() && ! $statuses) {
            return false;
        }

        // An empty search with no filter only lists records when their type was asked for.
        return trim($search->text) !== '' || $search->hasAmount() || $search->hasDate() || $search->hasStatus() || $search->types !== [];
    }

    /**
     * GREATEST(a, b, …), or the one expression alone.
     *
     * @param  non-empty-list<literal-string>  $parts
     * @return literal-string
     */
    private static function greatest(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        $sql = 'GREATEST(';

        foreach ($parts as $index => $part) {
            $sql .= ($index === 0 ? '' : ', ').$part;
        }

        return $sql.')';
    }

    protected static function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    protected static function rank(Model $record): float
    {
        return (float) $record->getAttribute('search_rank');
    }
}
