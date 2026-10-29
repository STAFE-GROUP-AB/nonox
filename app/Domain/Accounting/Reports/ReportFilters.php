<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reports;

use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\Project;
use Carbon\CarbonImmutable;

/**
 * The choices on the reports page, read from the URL query string, turned
 * into a LedgerQuery. Anything unreadable falls back to a sensible default:
 * the current fiscal year, its whole length, no filters. Must run inside the
 * company (lookups go through the company scope).
 */
final class ReportFilters
{
    public const array REPORTS = ['saldobalans', 'resultat', 'balans', 'huvudbok', 'verifikationer'];

    private function __construct(
        public readonly string $report,
        public readonly FiscalYear $fiscalYear,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?CostCenter $costCenter,
        public readonly ?Project $project,
        public readonly ?int $account,
        public readonly bool $compare,
    ) {}

    /**
     * @param  array<string, mixed>  $input  rapport, ar, fran, till, kst, projekt, konto, jamfor
     */
    public static function fromInput(array $input): ?self
    {
        $fiscalYear = FiscalYear::query()->find(is_numeric($input['ar'] ?? null) ? (int) $input['ar'] : 0)
            ?? FiscalYear::query()->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->first()
            ?? FiscalYear::query()->orderByDesc('end_date')->first();

        if (! $fiscalYear instanceof FiscalYear) {
            return null;
        }

        $from = self::date($input['fran'] ?? null) ?? $fiscalYear->start_date;
        $to = self::date($input['till'] ?? null) ?? $fiscalYear->end_date;
        $from = $from->max($fiscalYear->start_date)->min($fiscalYear->end_date);
        $to = $to->max($from)->min($fiscalYear->end_date);

        $report = (string) ($input['rapport'] ?? '');
        $costCenter = (string) ($input['kst'] ?? '');
        $project = (string) ($input['projekt'] ?? '');
        $account = (string) ($input['konto'] ?? '');

        return new self(
            report: in_array($report, self::REPORTS, true) ? $report : 'saldobalans',
            fiscalYear: $fiscalYear,
            from: $from,
            to: $to,
            costCenter: $costCenter === '' ? null : CostCenter::query()->where('code', $costCenter)->first(),
            project: $project === '' ? null : Project::query()->where('code', $project)->first(),
            account: preg_match('/^\d{4}$/', $account) === 1 ? (int) $account : null,
            compare: filter_var($input['jamfor'] ?? false, FILTER_VALIDATE_BOOL),
        );
    }

    public function query(): LedgerQuery
    {
        return new LedgerQuery($this->fiscalYear, $this->from, $this->to, $this->costCenter?->id, $this->project?->id);
    }

    public function wholeYear(): bool
    {
        return $this->from->isSameDay($this->fiscalYear->start_date) && $this->to->isSameDay($this->fiscalYear->end_date);
    }

    /**
     * @return array<string, string>
     */
    public function toQueryString(): array
    {
        return array_filter([
            'rapport' => $this->report,
            'ar' => (string) $this->fiscalYear->id,
            'fran' => $this->from->toDateString(),
            'till' => $this->to->toDateString(),
            'kst' => (string) $this->costCenter?->code,
            'projekt' => (string) $this->project?->code,
            'konto' => $this->account === null ? '' : (string) $this->account,
            'jamfor' => $this->compare ? '1' : '',
        ], fn (string $value): bool => $value !== '');
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
