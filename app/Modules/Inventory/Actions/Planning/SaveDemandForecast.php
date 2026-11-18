<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Planning;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\ForecastPeriodKind;
use App\Modules\Inventory\Domain\ForecastSource;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\DemandForecast;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Sets or clears one forecast figure: one article in one period (ADR-081
 * gap 5). A figure typed by a person is MANUAL and replaces a computed one;
 * an empty value removes the figure. Only stocked articles are forecast, and
 * only for periods from the current one to the end of the 52-week (12-month)
 * horizon.
 */
class SaveDemandForecast
{
    use GuardsInventory;

    /** The largest figure accepted, a thousand million units. */
    private const int MAX_MILLI = 1_000_000_000_000;

    /**
     * @param  ?string  $quantity  the expected demand in the article's stock unit as written, or null/empty to clear
     */
    public function __invoke(User $user, Company $company, Article $article, ForecastPeriodKind $kind, CarbonImmutable $start, ?string $quantity, ?CarbonImmutable $today = null): ?DemandForecast
    {
        $this->guard($user, $company, 'planRun');

        $today ??= CarbonImmutable::today();
        $start = $start->startOfDay();

        if (! $kind->isStart($start)) {
            throw ValidationException::withMessages(['quantity' => $kind === ForecastPeriodKind::Week
                ? __('En veckoprognos börjar på en måndag.')
                : __('En månadsprognos börjar den första i månaden.')]);
        }

        if ($start->lessThan($kind->startOf($today)) || $start->greaterThan($kind->lastStart($today))) {
            throw ValidationException::withMessages(['quantity' => $kind === ForecastPeriodKind::Week
                ? __('Prognosen kan läggas från den här veckan och 52 veckor framåt.')
                : __('Prognosen kan läggas från den här månaden och 12 månader framåt.')]);
        }

        $text = trim((string) $quantity);
        $milli = $text === '' ? null : $this->parse($text);

        return CurrentCompany::run($company, fn (): ?DemandForecast => DB::transaction(function () use ($user, $company, $article, $kind, $start, $milli): ?DemandForecast {
            $article = Article::query()->findOrFail($article->id);

            $existing = DemandForecast::query()
                ->where('article_id', $article->id)
                ->where('period_kind', $kind->value)
                ->where('period_start', $start->toDateString())
                ->lockForUpdate()
                ->first();

            if ($milli === null) {
                if ($existing !== null) {
                    $existing->delete();
                    Audit::record('demand_forecast.deleted', $existing, $this->payload($existing), $company, $user);
                }

                return null;
            }

            if (! $article->is_stock_item) {
                throw ValidationException::withMessages(['quantity' => __('Prognos kan bara läggas på lagerförda artiklar.')]);
            }

            $forecast = $existing ?? new DemandForecast([
                'article_id' => $article->id,
                'period_kind' => $kind,
                'period_start' => $start,
            ]);

            $forecast->fill(['quantity_milli' => $milli, 'source' => ForecastSource::Manual, 'created_by' => $user->id])->save();

            Audit::record('demand_forecast.saved', $forecast, $this->payload($forecast), $company, $user);

            return $forecast;
        }));
    }

    private function parse(string $text): int
    {
        try {
            $milli = Quantity::parse($text);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['quantity' => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
        }

        if ($milli < 0) {
            throw ValidationException::withMessages(['quantity' => __('Antalet kan inte vara negativt.')]);
        }

        if ($milli > self::MAX_MILLI) {
            throw ValidationException::withMessages(['quantity' => __('Antalet är för stort.')]);
        }

        return $milli;
    }

    /** @return array<string, mixed> */
    private function payload(DemandForecast $forecast): array
    {
        return [
            'article_id' => $forecast->article_id,
            'period_kind' => $forecast->period_kind->value,
            'period_start' => $forecast->period_start->toDateString(),
            'quantity_milli' => $forecast->quantity_milli,
            'source' => $forecast->source->value,
        ];
    }
}
