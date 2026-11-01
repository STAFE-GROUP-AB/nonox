<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Search;

use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Support\Search\DatabaseSearchProvider;
use App\Support\Search\PersonalNumberSuffix;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;

/**
 * Personal (Personalliggare): by name, email and employer. The personal
 * number is only matched on its last four digits, only for those with
 * staff-ledger:view-sensitive, and is never shown.
 */
final class PeopleSearchProvider extends DatabaseSearchProvider
{
    public const string KEY = 'staff';

    /** The same expression as the trigram index on staff_ledger_people. */
    public const string NAME = "(coalesce(staff_ledger_people.first_name, '') || ' ' || coalesce(staff_ledger_people.last_name, ''))";

    private const array COLUMNS = [self::NAME, 'staff_ledger_people.email', 'staff_ledger_people.employer_name'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Personal i personalliggaren');
    }

    public function module(): string
    {
        return StaffLedgerModule::KEY;
    }

    public function order(): int
    {
        return 42;
    }

    public function authorize(SearchContext $context): bool
    {
        return $context->allows('viewAny', Person::class);
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        if ($query->impliedAmount !== null && PersonalNumberSuffix::of($query) === null) {
            return null;
        }

        if (! $this->wants($query)) {
            return null;
        }

        $builder = Person::query()->where('staff_ledger_people.company_id', $context->company->id)->select('staff_ledger_people.*');
        $suffix = $context->can('staff-ledger:view-sensitive') ? PersonalNumberSuffix::of($query) : null;
        $bySuffix = $suffix === null ? [] : PersonalNumberSuffix::matching(Person::query()->where('company_id', $context->company->id), $suffix);
        $identifiers = $bySuffix === [] ? [] : [['staff_ledger_people.id = ANY(CAST(? AS bigint[]))', ['{'.implode(',', $bySuffix).'}'], 900]];

        if ($query->number !== null && $identifiers === []) {
            return null;
        }

        $text = $query->number === null ? $query : new SearchQuery($query->raw);
        $this->whereText($builder, self::COLUMNS, $text, $this->anyOf($identifiers));
        $this->selectRank($builder, self::COLUMNS, $text, $identifiers);

        [$people, $total] = $this->fetch($builder, 'staff_ledger_people.updated_at', $limit);

        return $this->group(
            $people->map(fn (Person $person): SearchResult => $this->result($person, in_array($person->id, $bySuffix, true) ? $suffix : null)->withScore(self::rank($person)))->all(),
            $total,
            route('staff-ledger.people'),
        );
    }

    public function find(string $id, SearchContext $context): ?SearchResult
    {
        $person = ctype_digit($id) ? Person::query()->find((int) $id) : null;

        return $person instanceof Person ? $this->result($person) : null;
    }

    private function result(Person $person, ?string $suffix = null): SearchResult
    {
        return new SearchResult(
            type: self::KEY,
            id: (string) $person->id,
            title: $person->name(),
            url: route('staff-ledger.people', ['redigera' => $person->id]),
            subtitle: self::join(
                $person->employer_name,
                $suffix === null ? null : PersonalNumberSuffix::label($suffix),
            ) ?: __('Personalliggare'),
            badge: self::when($person->archived_at !== null, __('Arkiverad')),
            icon: 'person',
        );
    }
}
