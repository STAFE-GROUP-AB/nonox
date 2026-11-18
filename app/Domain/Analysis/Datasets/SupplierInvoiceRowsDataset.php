<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Datasets;

use App\Domain\Analysis\AnalysisContext;
use App\Domain\Analysis\AnalysisRunner;
use App\Domain\Analysis\Dimension;
use App\Domain\Analysis\DimensionSql;
use App\Domain\Analysis\Measure;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Models\User;
use App\Policies\SupplierInvoicePolicy;
use App\Support\Money\Money;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * The rows of registered supplier invoices, cost excluding VAT (ADR-081
 * gap 7, E17). Drafts are not read. A member limited to cost centres or
 * projects reads only the invoices their lists show (DimensionScope::constrain:
 * every dimensioned row inside the scope), without personnel accounts.
 */
final class SupplierInvoiceRowsDataset extends DimensionedRowsDataset
{
    public function key(): string
    {
        return 'supplier_invoices';
    }

    public function label(): string
    {
        return __('Leverantörsfakturor');
    }

    public function description(): string
    {
        return __('Raderna på registrerade leverantörsfakturor, utan moms: leverantör, konto, månad, status, kostnadsställe och projekt.');
    }

    protected function rowTable(): string
    {
        return 'supplier_invoice_rows';
    }

    /** Required by the parent; unused, allows() applies the payables rule instead. */
    protected function permission(): string
    {
        return 'supplier-invoices:create';
    }

    public function allows(User $user, Company $company): bool
    {
        return SupplierInvoicePolicy::canSeePayables($user, $company);
    }

    public function dimensions(): array
    {
        return [
            'supplier' => new Dimension('supplier', __('Leverantör'), fn () => new DimensionSql('suppliers.id', 'suppliers.name'), fn (): array => Supplier::query()->orderBy('name')->limit(500)->get(['id', 'name'])
                ->mapWithKeys(fn (Supplier $supplier): array => [(string) $supplier->id => $supplier->name])->all()),
        ] + $this->accountDimensions() + [
            'period' => $this->periodDimension('supplier_invoices.invoice_date'),
            'status' => new Dimension('status', __('Status'), fn () => new DimensionSql('supplier_invoices.status', 'supplier_invoices.status'), fn (): array => collect([SupplierInvoiceStatus::Registered, SupplierInvoiceStatus::Approved, SupplierInvoiceStatus::Paid])
                ->mapWithKeys(fn (SupplierInvoiceStatus $status): array => [$status->value => $status->label()])->all()),
            'cost_center' => $this->costCenterDimension(),
            'project' => $this->projectDimension(),
        ];
    }

    public function measures(): array
    {
        return [
            'net' => new Measure('net', __('Kostnad utan moms'), Measure::MONEY, fn ($f) => "COALESCE(SUM(supplier_invoice_rows.amount_ore) FILTER (WHERE {$f}), 0)"),
            'rows' => new Measure('rows', __('Antal rader'), Measure::COUNT, fn ($f) => "COUNT(*) FILTER (WHERE {$f})"),
        ];
    }

    public function defaultRows(): array
    {
        return ['supplier'];
    }

    public function dateExpression(): string
    {
        return 'supplier_invoices.invoice_date';
    }

    public function listRoute(): string
    {
        return 'payables.invoices.index';
    }

    public function query(AnalysisContext $context): Builder
    {
        $query = SupplierInvoiceRow::query()->toBase()
            ->join('supplier_invoices', fn (JoinClause $join) => $join->on('supplier_invoices.id', '=', 'supplier_invoice_rows.supplier_invoice_id')->on('supplier_invoices.company_id', '=', 'supplier_invoice_rows.company_id'))
            ->join('suppliers', fn (JoinClause $join) => $join->on('suppliers.id', '=', 'supplier_invoices.supplier_id')->on('suppliers.company_id', '=', 'supplier_invoices.company_id'))
            ->where('supplier_invoice_rows.company_id', $context->company->id)
            ->where('supplier_invoices.status', '<>', SupplierInvoiceStatus::Draft->value);

        $this->joinDimensions($query);

        if (! $context->scope->restricted) {
            return $query;
        }

        $visible = $context->scope->constrain(SupplierInvoice::query()->select('supplier_invoices.id'), 'supplier_invoice_rows', 'supplier_invoice_id', 'supplier_invoices.id');

        return $query
            ->whereIn('supplier_invoices.id', $visible->toBase())
            ->whereNotBetween('accounts.number', [self::PERSONNEL_FROM, self::PERSONNEL_TO]);
    }

    public function detail(AnalysisContext $context, Builder $query): array
    {
        $rows = $query
            ->select('supplier_invoices.id AS invoice_id', 'supplier_invoices.invoice_date', 'supplier_invoices.number', 'supplier_invoices.invoice_number', 'suppliers.name AS supplier', 'accounts.number AS account', 'supplier_invoice_rows.description', 'supplier_invoice_rows.amount_ore')
            ->orderBy('supplier_invoices.invoice_date')->orderBy('supplier_invoices.number')->orderBy('supplier_invoice_rows.position')
            ->limit(AnalysisRunner::DRILL_LIMIT + 1)
            ->get();

        return [
            'columns' => [__('Datum'), __('Nummer'), __('Leverantör'), __('Konto'), __('Text'), __('Kostnad utan moms')],
            'rows' => $rows->map(fn (object $row): array => [
                'cells' => [
                    (string) $row->invoice_date,
                    (string) ($row->number ?? $row->invoice_number ?? ''),
                    (string) $row->supplier,
                    (string) $row->account,
                    (string) $row->description,
                    Money::ofOre((int) $row->amount_ore)->format(),
                ],
                // The invoices read here are the ones the member's list shows, and the policy answers 404 for any other.
                'url' => route('payables.invoices.show', $row->invoice_id),
            ])->all(),
        ];
    }
}
