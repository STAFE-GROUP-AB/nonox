<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reports;

use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Support\Tenancy\DimensionScope;
use Carbon\CarbonImmutable;

/**
 * The choices on the reports page, read from the URL query string, turned
 * into a LedgerQuery. Anything unreadable falls back to a sensible default:
 * the current fiscal year, its whole length, no filters. Must run inside the
 * company (lookups go through the company scope).
 *
 * For a member limited to cost centres or projects (ADR-081 gap 10) only the
 * reports that can be read per unit are offered (SCOPED_REPORTS), always with
 * one of the member's cost centres or projects as filter: the whole-company
 * reports (saldobalans, balance sheet, the voucher list, the general ledger
 * without a filter) are refused.
 */
final class ReportFilters
{
    public const array REPORTS = ['saldobalans', 'resultat', 'balans', 'huvudbok', 'verifikationer'];

    /** The reports a member limited to cost centres or projects may read, for one of them at a time. */
    public const array SCOPED_REPORTS = ['resultat', 'huvudbok'];

    private function __construct(
        public readonly string $report,
        public readonly FiscalYear $fiscalYear,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?CostCenter $costCenter,
        public readonly ?Project $project,
        public readonly ?int $account,
        public readonly bool $compare,
        public readonly bool $limited = false,
    ) {}

    /**
     * @param  array<string, mixed>  $input  rapport, ar, fran, till, kst, projekt, konto, jamfor
     */
    public static function fromInput(array $input, ?DimensionScope $scope = null): ?self
    {
        $scope ??= DimensionScope::unrestricted();

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

        $costCenterModel = $costCenter === '' ? null : CostCenter::query()->where('code', $costCenter)
            ->when($scope->restricted, fn ($query) => $query->whereIn('id', $scope->costCenterIds))->first();
        $projectModel = $project === '' ? null : Project::query()->where('code', $project)
            ->when($scope->restricted, fn ($query) => $query->whereIn('id', $scope->projectIds))->first();
        $report = in_array($report, self::REPORTS, true) ? $report : 'saldobalans';

        if ($scope->restricted) {
            $report = in_array($report, self::SCOPED_REPORTS, true) ? $report : 'resultat';

            // Never the whole company: without a unit of their own chosen, the member's first one.
            if ($costCenterModel === null && $projectModel === null) {
                $costCenterModel = CostCenter::query()->whereIn('id', $scope->costCenterIds)->orderBy('code')->first();
                $projectModel = $costCenterModel === null ? Project::query()->whereIn('id', $scope->projectIds)->orderBy('code')->first() : null;
            }

            if ($costCenterModel === null && $projectModel === null) {
                return null;
            }
        }

        return new self(
            report: $report,
            fiscalYear: $fiscalYear,
            from: $from,
            to: $to,
            costCenter: $costCenterModel,
            project: $projectModel,
            account: preg_match('/^\d{4}$/', $account) === 1 ? (int) $account : null,
            compare: filter_var($input['jamfor'] ?? false, FILTER_VALIDATE_BOOL),
            limited: $scope->restricted,
        );
    }

    /**
     * Whether these filters are exactly what the input asked for: a limited
     * member's request that had to be narrowed (another report, another unit)
     * is refused where it cannot be shown narrowed, as in the CSV file.
     *
     * @param  array<string, mixed>  $input
     */
    public function answers(array $input): bool
    {
        return $this->report === (string) ($input['rapport'] ?? 'saldobalans')
            && (string) $this->costCenter?->code === (string) ($input['kst'] ?? '')
            && (string) $this->project?->code === (string) ($input['projekt'] ?? '');
    }

    public function query(): LedgerQuery
    {
        return new LedgerQuery($this->fiscalYear, $this->from, $this->to, $this->costCenter?->id, $this->project?->id, withoutPayroll: $this->limited);
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

    /**
     * The Huvudbok of one account (Kontoanalys), with the same year, period,
     * cost centre and project, for a link from an account in any report.
     *
     * @return array<string, string>
     */
    public function accountLedgerQuery(int $accountNumber): array
    {
        $query = $this->toQueryString();
        unset($query['jamfor']);

        return [...$query, 'rapport' => 'huvudbok', 'konto' => (string) $accountNumber];
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
