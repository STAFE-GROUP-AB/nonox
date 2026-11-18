<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Planning;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\ForecastPeriodKind;
use App\Modules\Inventory\Domain\ForecastSource;
use App\Modules\Inventory\Models\DemandForecast;
use App\Modules\Inventory\Planning\DemandHistory;
use App\Modules\Inventory\Planning\ForecastPeriod;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Computes the forecast from history (ADR-081 gap 5): for each chosen stocked
 * article, every period of the horizon gets the average of the last N complete
 * months (DemandHistory has the rule). The figures are labelled HISTORY and
 * stay editable. Figures a person has typed (MANUAL) are kept unless
 * $overwriteManual is set; an article with no consumption in the window gets
 * no computed figures, and its earlier computed ones in the horizon are
 * removed. One write per chunk of rows, whatever the number of articles.
 */
class GenerateDemandForecast
{
    use GuardsInventory;

    /** The most articles one run handles; more than this is split by the person with a search. */
    public const int MAX_ARTICLES = 2000;

    /**
     * @param  ?list<int>  $articleIds  the articles to compute for, or null for every stocked article
     * @return array{articles: int, written: int, removed: int, kept_manual: int}
     */
    public function __invoke(User $user, Company $company, ?array $articleIds, ForecastPeriodKind $kind, int $periodCount, int $months = DemandHistory::DEFAULT_MONTHS, bool $overwriteManual = false, ?CarbonImmutable $today = null): array
    {
        $this->guard($user, $company, 'planRun');

        if ($months < 1 || $months > DemandHistory::MAX_MONTHS) {
            throw ValidationException::withMessages(['months' => __('Historiken ska vara mellan 1 och 24 månader.')]);
        }

        if ($periodCount < 1 || $periodCount > $kind->maxCount()) {
            throw ValidationException::withMessages(['periods' => __('Antalet perioder ska vara mellan 1 och :max.', ['max' => $kind->maxCount()])]);
        }

        $today ??= CarbonImmutable::today();

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $articleIds, $kind, $periodCount, $months, $overwriteManual, $today): array {
            $articles = array_values(Article::query()->where('is_stock_item', true)
                ->when($articleIds !== null, fn ($query) => $query->whereIn('id', $articleIds))
                ->orderBy('id')
                ->limit(self::MAX_ARTICLES + 1)
                ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

            if (count($articles) > self::MAX_ARTICLES) {
                throw ValidationException::withMessages(['articles' => __('Det är fler än :max artiklar. Sök fram färre och beräkna i omgångar.', ['max' => self::MAX_ARTICLES])]);
            }

            $window = DemandHistory::window($today, $months);
            $periods = ForecastPeriod::horizon($kind, $today, $periodCount);
            $first = $periods[0]->start->toDateString();
            $last = $periods[count($periods) - 1]->start->toDateString();
            $consumption = $window->consumption($articles);

            $manual = [];

            foreach (DemandForecast::query()->whereIn('article_id', $articles)
                ->where('period_kind', $kind->value)->whereBetween('period_start', [$first, $last])
                ->where('source', ForecastSource::Manual->value)
                ->get(['article_id', 'period_start']) as $row) {
                $manual[$row->article_id][$row->period_start->toDateString()] = true;
            }

            $now = now();
            $rows = [];
            $keptManual = 0;
            $withoutHistory = [];

            foreach ($articles as $id) {
                $consumed = $consumption[$id] ?? 0;

                if ($consumed <= 0) {
                    $withoutHistory[] = $id;

                    continue;
                }

                $quantity = $kind === ForecastPeriodKind::Week ? $window->perWeek($consumed) : $window->perMonth($consumed);

                foreach ($periods as $period) {
                    $key = $period->start->toDateString();

                    if (! $overwriteManual && isset($manual[$id][$key])) {
                        $keptManual++;

                        continue;
                    }

                    $rows[] = [
                        'company_id' => $company->id,
                        'article_id' => $id,
                        'period_start' => $key,
                        'period_kind' => $kind->value,
                        'quantity_milli' => $quantity,
                        'source' => ForecastSource::History->value,
                        'created_by' => $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            $removed = 0;

            foreach (array_chunk($withoutHistory, 1000) as $chunk) {
                $removed += DemandForecast::query()->whereIn('article_id', $chunk)
                    ->where('period_kind', $kind->value)->whereBetween('period_start', [$first, $last])
                    ->where('source', ForecastSource::History->value)
                    ->delete();
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DemandForecast::query()->upsert($chunk, ['company_id', 'article_id', 'period_kind', 'period_start'], ['quantity_milli', 'source', 'created_by', 'updated_at']);
            }

            $result = ['articles' => count($articles), 'written' => count($rows), 'removed' => $removed, 'kept_manual' => $keptManual];

            Audit::record('demand_forecast.generated', null, [
                'period_kind' => $kind->value,
                'periods' => $periodCount,
                'history_months' => $months,
                'overwrite_manual' => $overwriteManual,
                ...$result,
            ], $company, $user);

            return $result;
        }));
    }
}
