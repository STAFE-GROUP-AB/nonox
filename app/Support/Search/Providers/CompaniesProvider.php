<?php

declare(strict_types=1);

namespace App\Support\Search\Providers;

use App\Support\Search\BaseSearchProvider;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;
use App\Support\Search\TextScore;

/**
 * "Mina företag": the user's other companies by name or organisation
 * number. Choosing one switches company through the existing PUT route
 * (current-team.update), which checks membership again.
 */
final class CompaniesProvider extends BaseSearchProvider
{
    public const string KEY = 'companies';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Mina företag');
    }

    public function order(): int
    {
        return 3;
    }

    public function authorize(SearchContext $context): bool
    {
        return true;
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        if ($query->excludes(self::KEY) || $query->hasAmount() || $query->hasDate() || $query->hasStatus()) {
            return null;
        }

        $text = trim($query->text);
        $digits = $query->digits();

        if ($text === '' && $query->types === []) {
            return null;
        }

        $results = [];

        foreach ($context->user->companies() as $company) {
            if ($company->id === $context->company->id) {
                continue;
            }

            $score = match (true) {
                $text === '' => 100.0,
                $digits !== null && strlen($digits) >= 4 && str_contains((string) preg_replace('/\D/', '', (string) $company->org_number), $digits) => 900.0,
                default => TextScore::score($company->name, $text, [(string) $company->legal_name]),
            };

            if ($score === null) {
                continue;
            }

            $results[] = new SearchResult(
                type: self::KEY,
                id: (string) $company->id,
                title: __('Byt till :company', ['company' => $company->name]),
                url: route('dashboard'),
                score: $score,
                subtitle: $company->org_number,
                icon: 'building',
                action: SearchResult::SWITCH_COMPANY,
                payload: ['team_id' => $company->id],
                remember: false,
            );
        }

        return $this->best($results, $limit);
    }
}
