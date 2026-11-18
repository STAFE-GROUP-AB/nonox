<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Datasets;

use App\Domain\Analysis\AnalysisContext;
use App\Domain\Analysis\AnalysisRunner;
use App\Domain\Analysis\Dataset;
use App\Domain\Analysis\Dimension;
use App\Domain\Analysis\DimensionSql;
use App\Domain\Analysis\Measure;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Database\Query\Builder;

/**
 * Customer invoices and credit notes, one record per invoice (ADR-081 gap 7,
 * E17): customer, month, status and kind; net, VAT, total and what is still
 * open. Drafts are not read. Invoice rows have no cost centre or project until
 * ADR-081 E6, so a member limited to cost centres or projects is refused the
 * dataset (invoices:* is not scope-aware, ADR-081 E1).
 */
final class CustomerInvoicesDataset implements Dataset
{
    public function key(): string
    {
        return 'customer_invoices';
    }

    public function label(): string
    {
        return __('Kundfakturor');
    }

    public function description(): string
    {
        return __('En post per faktura eller kreditfaktura, utan utkast: kund, månad, status och sort. Netto, moms, totalt och obetalt.');
    }

    public function allows(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'invoices:view') && ! DimensionScope::limits($user, $company);
    }

    public function dimensions(): array
    {
        return [
            'customer' => new Dimension('customer', __('Kund'), fn () => new DimensionSql('customers.id', 'customers.name'), fn (): array => Customer::query()->orderBy('name')->limit(500)->get(['id', 'name'])
                ->mapWithKeys(fn (Customer $customer): array => [(string) $customer->id => $customer->name])->all()),
            'period' => new Dimension('period', __('Månad'), fn () => new DimensionSql("to_char(invoices.invoice_date, 'YYYY-MM')", "to_char(invoices.invoice_date, 'YYYY-MM')"), fn (): array => []),
            'status' => new Dimension('status', __('Status'), fn () => new DimensionSql('invoices.status', 'invoices.status'), fn (): array => collect([InvoiceStatus::Issued, InvoiceStatus::Paid, InvoiceStatus::Credited, InvoiceStatus::WrittenOff])
                ->mapWithKeys(fn (InvoiceStatus $status): array => [$status->value => $status->label()])->all()),
            'kind' => new Dimension('kind', __('Sort'), fn () => new DimensionSql('invoices.kind', "CASE invoices.kind WHEN 'CREDIT' THEN 'Kreditfaktura' ELSE 'Faktura' END"), fn (): array => ['INVOICE' => __('Faktura'), 'CREDIT' => __('Kreditfaktura')], true),
        ];
    }

    public function measures(): array
    {
        return [
            'net' => new Measure('net', __('Netto'), Measure::MONEY, fn ($f) => "COALESCE(SUM(invoices.net_ore) FILTER (WHERE {$f}), 0)"),
            'vat' => new Measure('vat', __('Moms'), Measure::MONEY, fn ($f) => "COALESCE(SUM(invoices.vat_ore) FILTER (WHERE {$f}), 0)"),
            'total' => new Measure('total', __('Totalt'), Measure::MONEY, fn ($f) => "COALESCE(SUM(invoices.total_ore) FILTER (WHERE {$f}), 0)"),
            'open' => new Measure('open', __('Obetalt'), Measure::MONEY, fn ($f) => "COALESCE(SUM(invoices.total_ore - invoices.paid_ore) FILTER (WHERE {$f} AND invoices.status = 'ISSUED' AND invoices.kind = 'INVOICE'), 0)"),
            'count' => new Measure('count', __('Antal fakturor'), Measure::COUNT, fn ($f) => "COUNT(*) FILTER (WHERE {$f})"),
        ];
    }

    public function defaultRows(): array
    {
        return ['customer'];
    }

    public function dateExpression(): string
    {
        return 'invoices.invoice_date';
    }

    public function listRoute(): string
    {
        return 'invoicing.invoices.index';
    }

    public function drillable(): bool
    {
        return true;
    }

    public function query(AnalysisContext $context): Builder
    {
        return Invoice::query()->toBase()
            ->join('customers', fn ($join) => $join->on('customers.id', '=', 'invoices.customer_id')->on('customers.company_id', '=', 'invoices.company_id'))
            ->where('invoices.company_id', $context->company->id)
            ->where('invoices.status', '<>', InvoiceStatus::Draft->value);
    }

    public function detail(AnalysisContext $context, Builder $query): array
    {
        $rows = $query
            ->select('invoices.id AS invoice_id', 'invoices.invoice_date', 'invoices.number', 'invoices.kind', 'customers.name AS customer', 'invoices.status', 'invoices.net_ore', 'invoices.total_ore')
            ->orderBy('invoices.invoice_date')->orderBy('invoices.number')
            ->limit(AnalysisRunner::DRILL_LIMIT + 1)
            ->get();

        return [
            'columns' => [__('Datum'), __('Nummer'), __('Kund'), __('Status'), __('Netto'), __('Totalt')],
            'rows' => $rows->map(fn (object $row): array => [
                'cells' => [
                    (string) $row->invoice_date,
                    (string) $row->number,
                    (string) $row->customer,
                    InvoiceStatus::from((string) $row->status)->label(),
                    Money::ofOre((int) $row->net_ore)->format(),
                    Money::ofOre((int) $row->total_ore)->format(),
                ],
                'url' => route('invoicing.invoices.show', $row->invoice_id),
            ])->all(),
        ];
    }
}
