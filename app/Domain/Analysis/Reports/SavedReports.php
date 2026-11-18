<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Reports;

use App\Domain\Analysis\AnalysisContext;
use App\Domain\Analysis\AnalysisDefinition;
use App\Domain\Analysis\AnalysisResult;
use App\Domain\Analysis\AnalysisRunner;
use App\Domain\Analysis\Dataset;
use App\Domain\Analysis\DatasetCatalogue;
use App\Models\Company;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Support\Tenancy\DimensionScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads and runs saved reports (ADR-081 gap 7, E18). A saved report holds the
 * choices of an analysis as keys; running it builds the definition again from
 * the dataset's catalogue and runs it as the viewer, with the viewer's own
 * dataset permission and dimension scope. Nothing the owner saw is stored, so
 * sharing a report can never show a viewer more than they may read themselves.
 */
final class SavedReports
{
    /** The most a person can have saved in one company. */
    public const int MAX_PER_OWNER = 50;

    /** The most tiles the overview shows; each one is one query. */
    public const int MAX_TILES = 4;

    /** The list shows at most this many; the page says so when it is cut. */
    public const int LIST_LIMIT = 200;

    /**
     * What a saved report stores: the normalised choices, and whether the
     * period rolls (the last N months up to the current one) or stays fixed.
     *
     * @return array<string, mixed>
     */
    public static function stored(AnalysisDefinition $definition, bool $rolling): array
    {
        return [
            ...$definition->toArray(),
            'rolling' => $rolling,
            'months' => (int) round($definition->from->diffInMonths($definition->to)) + 1,
        ];
    }

    /**
     * The input for AnalysisDefinition::normalise: a rolling period ends at
     * the current month, so "Kör igen" always shows the latest months.
     *
     * @return array<string, mixed>
     */
    public static function input(ReportDefinition $report, ?CarbonImmutable $today = null): array
    {
        $input = $report->definition;

        if (($input['rolling'] ?? false) === true) {
            $months = max(1, min(AnalysisDefinition::MAX_MONTHS, (int) ($input['months'] ?? 12)));
            $to = ($today ?? CarbonImmutable::now())->startOfMonth();
            $input['to'] = $to->format('Y-m');
            $input['from'] = $to->subMonths($months - 1)->format('Y-m');
        }

        return $input;
    }

    /**
     * The reports the member may open: their own and the company's, and only
     * those whose dataset they may use. One query.
     *
     * @return Collection<int, ReportDefinition>
     */
    public static function openableBy(User $user, Company $company): Collection
    {
        $datasets = DatasetCatalogue::for($user, $company);

        if ($datasets === [] || ! $user->hasTeamPermission($company, 'analysis:view')) {
            return new Collection;
        }

        return ReportDefinition::query()
            ->where('company_id', $company->id)
            ->where(fn ($query) => $query->where('owner_user_id', $user->id)->orWhere('visibility', ReportVisibility::Company->value))
            ->whereIn('dataset', array_keys($datasets))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /**
     * The reports pinned to the overview that the member may open: their own
     * pinned reports and the company's pinned ones, newest first, in a query of
     * their own so the tiles never depend on how many reports there are.
     *
     * @return Collection<int, ReportDefinition>
     */
    public static function pinnedFor(User $user, Company $company): Collection
    {
        $datasets = DatasetCatalogue::for($user, $company);

        if ($datasets === [] || ! $user->hasTeamPermission($company, 'analysis:view')) {
            return new Collection;
        }

        return ReportDefinition::query()
            ->where('company_id', $company->id)
            ->where('pinned', true)
            ->where(fn ($query) => $query->where('owner_user_id', $user->id)->orWhere('visibility', ReportVisibility::Company->value))
            ->whereIn('dataset', array_keys($datasets))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::MAX_TILES)
            ->get();
    }

    /**
     * Runs the report as the viewer. Null when the viewer may not use the
     * report's dataset.
     *
     * @return array{dataset: Dataset, definition: AnalysisDefinition, result: AnalysisResult}|null
     */
    public static function run(ReportDefinition $report, User $user, Company $company, AnalysisRunner $runner): ?array
    {
        $dataset = DatasetCatalogue::for($user, $company)[$report->dataset] ?? null;

        if ($dataset === null) {
            return null;
        }

        $definition = AnalysisDefinition::normalise($dataset, self::input($report));
        $context = new AnalysisContext($company, $user, DimensionScope::for($user, $company), $definition->from, $definition->periodEnd(), $definition->compare);

        return ['dataset' => $dataset, 'definition' => $definition, 'result' => $runner->run($dataset, $definition, $context)];
    }
}
