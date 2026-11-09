<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Structures;

use App\Models\Article;
use App\Models\Company;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockValuationReport;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * The cost per unit of every article on a day, for costing structures
 * (ADR-073 §4, Lead decision O4): first the valuation cost under the
 * company's method (FIFO or weighted average, the same figure as the
 * books), else the latest receipt cost, else the article's purchase price.
 * The latest receipt cost is kept beside it for comparison. A management
 * figure, not the stock valuation (RS-R4). A fixed number of queries.
 */
final class UnitCosts
{
    public const string VALUATION = 'valuation';

    public const string LATEST = 'latest';

    public const string PURCHASE = 'purchase';

    /**
     * @param  array<int, int>  $valuation  article => cost per unit by the method, when on hand
     * @param  array<int, array{ore: int, date: string}>  $latest  article => latest receipt cost
     * @param  array<int, int>  $purchase  article => purchase price
     */
    public function __construct(
        public readonly CarbonImmutable $date,
        private readonly array $valuation,
        private readonly array $latest,
        private readonly array $purchase,
    ) {}

    public static function at(Company $company, ?CarbonImmutable $date = null): self
    {
        $date ??= CarbonImmutable::today();

        return CurrentCompany::run($company, function () use ($company, $date): self {
            $valuation = [];

            foreach (StockValuationReport::at($company, $date)->lines as $line) {
                if ($line['unit_cost_ore'] !== null) {
                    $valuation[$line['article_id']] = $line['unit_cost_ore'];
                }
            }

            $latest = [];

            StockMovement::query()
                ->where('type', MovementType::Receipt->value)
                ->where('quantity_milli', '>', 0)
                ->whereNotNull('unit_cost_ore')
                ->whereDate('occurred_on', '<=', $date->toDateString())
                ->orderBy('article_id')->orderByDesc('occurred_on')->orderByDesc('id')
                ->selectRaw('DISTINCT ON (article_id) article_id, unit_cost_ore, occurred_on')
                ->toBase()
                ->get()
                ->each(function (object $row) use (&$latest): void {
                    $latest[(int) $row->article_id] = ['ore' => (int) $row->unit_cost_ore, 'date' => substr((string) $row->occurred_on, 0, 10)];
                });

            $purchase = Article::query()->whereNotNull('purchase_price_ore')->pluck('purchase_price_ore', 'id')->map(fn (mixed $ore): int => (int) $ore)->all();

            return new self($date, $valuation, $latest, $purchase);
        });
    }

    /** @return array{ore: ?int, source: ?string} */
    public function of(int $articleId): array
    {
        if (isset($this->valuation[$articleId])) {
            return ['ore' => $this->valuation[$articleId], 'source' => self::VALUATION];
        }

        if (isset($this->latest[$articleId])) {
            return ['ore' => $this->latest[$articleId]['ore'], 'source' => self::LATEST];
        }

        if (isset($this->purchase[$articleId])) {
            return ['ore' => $this->purchase[$articleId], 'source' => self::PURCHASE];
        }

        return ['ore' => null, 'source' => null];
    }

    /** @return ?array{ore: int, date: string} */
    public function latest(int $articleId): ?array
    {
        return $this->latest[$articleId] ?? null;
    }
}
