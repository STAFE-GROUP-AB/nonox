<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Datasets;

use App\Domain\Accounting\VoucherStatus;
use App\Domain\Analysis\AnalysisContext;
use App\Domain\Analysis\AnalysisRunner;
use App\Domain\Analysis\Dimension;
use App\Domain\Analysis\DimensionSql;
use App\Domain\Analysis\Measure;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Money\Money;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The posted ledger, row by row (ADR-081 gap 7, E17), read like LedgerQuery and
 * the Resultatrapport: every posted voucher counts, closing vouchers included
 * (depreciation, accruals, tax and the year's result are booked as such), so
 * a period's figures equal the reports'. For a member limited to cost centres or projects the
 * payroll vouchers and their corrections are never read either (E1), nor the
 * personnel accounts 7000-7699, and only rows on the member's own units
 * (DimensionedRowsDataset).
 */
final class LedgerDataset extends DimensionedRowsDataset
{
    public function key(): string
    {
        return 'ledger';
    }

    public function label(): string
    {
        return __('Bokföringsrader');
    }

    public function description(): string
    {
        return __('De bokförda raderna: konto, månad, serie, kostnadsställe och projekt. Alla bokförda verifikationer räknas med.');
    }

    protected function rowTable(): string
    {
        return 'voucher_rows';
    }

    protected function permission(): string
    {
        return 'reports:view';
    }

    public function dimensions(): array
    {
        return $this->accountDimensions() + [
            'period' => $this->periodDimension('vouchers.voucher_date'),
            'series' => new Dimension('series', __('Verifikationsserie'), fn () => new DimensionSql('voucher_series.code', "voucher_series.code || ' ' || voucher_series.name"), fn (AnalysisContext $context): array => DB::table('voucher_series')
                ->where('company_id', $context->company->id)->orderBy('code')->get(['code', 'name'])
                ->mapWithKeys(fn (object $series): array => [(string) $series->code => "{$series->code} {$series->name}"])->all()),
            'cost_center' => $this->costCenterDimension(),
            'project' => $this->projectDimension(),
        ];
    }

    public function measures(): array
    {
        return [
            'net' => new Measure('net', __('Debet minus kredit'), Measure::MONEY, fn ($f) => "COALESCE(SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) FILTER (WHERE {$f}), 0)"),
            'debit' => new Measure('debit', __('Debet'), Measure::MONEY, fn ($f) => "COALESCE(SUM(voucher_rows.debit_ore) FILTER (WHERE {$f}), 0)"),
            'credit' => new Measure('credit', __('Kredit'), Measure::MONEY, fn ($f) => "COALESCE(SUM(voucher_rows.credit_ore) FILTER (WHERE {$f}), 0)"),
            'rows' => new Measure('rows', __('Antal rader'), Measure::COUNT, fn ($f) => "COUNT(*) FILTER (WHERE {$f})"),
        ];
    }

    public function defaultRows(): array
    {
        return ['account'];
    }

    public function dateExpression(): string
    {
        return 'vouchers.voucher_date';
    }

    public function listRoute(): string
    {
        return 'accounting.reports';
    }

    public function query(AnalysisContext $context): Builder
    {
        $query = VoucherRow::query()->toBase()
            ->join('vouchers', fn ($join) => $join->on('vouchers.id', '=', 'voucher_rows.voucher_id')->on('vouchers.company_id', '=', 'voucher_rows.company_id'))
            ->join('voucher_series', 'voucher_series.id', '=', 'vouchers.series_id')
            ->where('voucher_rows.company_id', $context->company->id)
            ->where('vouchers.status', VoucherStatus::Posted->value);

        $this->joinDimensions($query);

        if ($context->scope->restricted) {
            DimensionScope::withoutPayroll($query);
        }

        return $this->applyScope($query, $context);
    }

    public function detail(AnalysisContext $context, Builder $query): array
    {
        $rows = $query
            ->select('vouchers.id AS voucher_id', 'vouchers.voucher_date', 'voucher_series.code AS series', 'vouchers.number', 'accounts.number AS account', 'voucher_rows.description AS row_text', 'vouchers.description AS voucher_text', 'voucher_rows.debit_ore', 'voucher_rows.credit_ore')
            ->selectRaw("COALESCE(cost_centers.code, '') AS cost_center, COALESCE(projects.code, '') AS project")
            ->orderBy('vouchers.voucher_date')->orderBy('vouchers.number')->orderBy('voucher_rows.id')
            ->limit(AnalysisRunner::DRILL_LIMIT + 1)
            ->get();

        $limited = $context->scope->restricted;
        $ownCostCenters = $limited ? $this->ownCodes('cost_centers', $context->scope->costCenterIds, $context) : [];
        $ownProjects = $limited ? $this->ownCodes('projects', $context->scope->projectIds, $context) : [];
        $visible = $limited ? $this->visibleVouchers($rows->pluck('voucher_id')->unique()->values()->all(), $context) : null;
        $show = fn (string $code, array $own): string => ! $limited || in_array($code, $own, true) ? $code : '';

        return [
            'columns' => [__('Datum'), __('Verifikation'), __('Konto'), __('Text'), __('Kostnadsställe'), __('Projekt'), __('Debet minus kredit')],
            'rows' => $rows->map(fn (object $row): array => [
                'cells' => [
                    (string) $row->voucher_date,
                    "{$row->series}{$row->number}",
                    (string) $row->account,
                    (string) ($visible === null || isset($visible[(string) $row->voucher_id]) ? ($row->row_text ?: $row->voucher_text) : $row->row_text),
                    $show((string) $row->cost_center, $ownCostCenters),
                    $show((string) $row->project, $ownProjects),
                    Money::ofOre((int) $row->debit_ore - (int) $row->credit_ore)->format(),
                ],
                // A limited member opens vouchers from the voucher list, which applies their scope; the row's own text is all they get here (as in Huvudbok).
                'url' => $limited ? null : route('accounting.vouchers.show', $row->voucher_id),
            ])->all(),
        ];
    }

    /**
     * The vouchers a limited member may open (the whole voucher inside the scope, no payroll): one query. The
     * voucher's description is shown only for those, as in Huvudbok; otherwise only the row's own text.
     *
     * @param  array<int, mixed>  $ids
     * @return array<string, bool>
     */
    private function visibleVouchers(array $ids, AnalysisContext $context): array
    {
        if ($ids === []) {
            return [];
        }

        $query = Voucher::query()->whereIn('vouchers.id', $ids);

        return $context->scope->constrainVouchers($query)->pluck('vouchers.id')->mapWithKeys(fn ($id): array => [(string) $id => true])->all();
    }

    /**
     * The codes of the member's own units: one query.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function ownCodes(string $table, array $ids, AnalysisContext $context): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($table)->where('company_id', $context->company->id)->whereIn('id', $ids)->pluck('code')->map(fn ($code): string => (string) $code)->values()->all();
    }
}
