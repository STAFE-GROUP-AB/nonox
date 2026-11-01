<?php

declare(strict_types=1);

namespace App\Support\Search\Providers;

use App\Models\Account;
use App\Support\Search\DatabaseSearchProvider;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;

/**
 * Konton: by number ("1930", or "19" for the whole group) and name ("bank").
 */
final class AccountsProvider extends DatabaseSearchProvider
{
    public const string KEY = 'accounts';

    private const array COLUMNS = ['accounts.name'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Konton');
    }

    public function order(): int
    {
        return 15;
    }

    public function authorize(SearchContext $context): bool
    {
        return $context->allows('viewAny', Account::class);
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        if (! $this->wants($query)) {
            return null;
        }

        $builder = Account::query()->where('accounts.company_id', $context->company->id)->select('accounts.*');
        $identifiers = [];

        if ($query->number !== null && $query->series === null && $query->number <= 9999) {
            $identifiers[] = ['accounts.number = ?', [$query->number]];

            if ($query->number < 1000) {
                $identifiers[] = ['CAST(accounts.number AS text) LIKE ?', [$query->number.'%'], 700];
            }
        }

        if ($query->number !== null && $identifiers === []) {
            return null;
        }

        $text = $query->number === null ? $query : new SearchQuery($query->raw);
        $this->whereText($builder, self::COLUMNS, $text, $this->anyOf($identifiers));
        $this->selectRank($builder, self::COLUMNS, $text, $identifiers);

        [$accounts, $total] = $this->fetch($builder, 'accounts.number', $limit, 'asc');

        $results = $accounts
            ->map(fn (Account $account): SearchResult => $this->result($account)->withScore(self::rank($account)))
            ->values()
            ->all();

        return $this->group($results, $total, route('accounting.accounts', array_filter(['sok' => trim($query->text)])));
    }

    public function find(string $id, SearchContext $context): ?SearchResult
    {
        $account = ctype_digit($id) ? Account::query()->find((int) $id) : null;

        return $account instanceof Account ? $this->result($account) : null;
    }

    private function result(Account $account): SearchResult
    {
        return new SearchResult(
            type: self::KEY,
            id: (string) $account->id,
            title: "{$account->number} {$account->name}",
            url: route('accounting.accounts', ['sok' => (string) $account->number]),
            subtitle: __('Konto i kontoplanen'),
            badge: self::when(! $account->is_active, __('Inaktivt')),
            icon: 'account',
        );
    }
}
