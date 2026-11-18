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
use App\Models\Account;
use App\Models\Article;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceRow;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * The rows of customer invoices and credit notes (ADR-081 gap 7, E17): article,
 * revenue account, customer and month, with the net amount. A row without an
 * article is one group, "Utan artikel". Refused to a member limited to cost
 * centres or projects, as the invoice dataset (no dimensions on invoice rows
 * until ADR-081 E6).
 */
final class InvoiceRowsDataset implements Dataset
{
    public function key(): string
    {
        return 'invoice_rows';
    }

    public function label(): string
    {
        return __('Fakturarader');
    }

    public function description(): string
    {
        return __('Raderna på kundfakturor och kreditfakturor, utan utkast: artikel, intäktskonto, kund och månad. Nettobelopp.');
    }

    public function allows(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'invoices:view') && ! DimensionScope::limits($user, $company);
    }

    public function dimensions(): array
    {
        return [
            'article' => new Dimension('article', __('Artikel'), fn () => new DimensionSql('COALESCE(articles.id, 0)', "COALESCE(articles.number || ' ' || articles.name, 'Utan artikel')"), fn (): array => ['0' => __('Utan artikel')] + Article::query()->orderBy('number')->limit(500)->get(['id', 'number', 'name'])
                ->mapWithKeys(fn (Article $article): array => [(string) $article->id => "{$article->number} {$article->name}"])->all(), true),
            'account' => new Dimension('account', __('Intäktskonto'), fn () => new DimensionSql('accounts.number', "accounts.number || ' ' || accounts.name"), fn (): array => Account::query()->where('number', '>=', 3000)->where('number', '<', 4000)->orderBy('number')->limit(500)->get(['number', 'name'])
                ->mapWithKeys(fn (Account $account): array => [(string) $account->number => "{$account->number} {$account->name}"])->all()),
            'customer' => new Dimension('customer', __('Kund'), fn () => new DimensionSql('customers.id', 'customers.name'), fn (): array => Customer::query()->orderBy('name')->limit(500)->get(['id', 'name'])
                ->mapWithKeys(fn (Customer $customer): array => [(string) $customer->id => $customer->name])->all()),
            'period' => new Dimension('period', __('Månad'), fn () => new DimensionSql("to_char(invoices.invoice_date, 'YYYY-MM')", "to_char(invoices.invoice_date, 'YYYY-MM')"), fn (): array => []),
            'status' => new Dimension('status', __('Status'), fn () => new DimensionSql('invoices.status', 'invoices.status'), fn (): array => collect([InvoiceStatus::Issued, InvoiceStatus::Paid, InvoiceStatus::Credited, InvoiceStatus::WrittenOff])
                ->mapWithKeys(fn (InvoiceStatus $status): array => [$status->value => $status->label()])->all()),
        ];
    }

    public function measures(): array
    {
        return [
            'net' => new Measure('net', __('Netto'), Measure::MONEY, fn ($f) => "COALESCE(SUM(invoice_rows.net_ore) FILTER (WHERE {$f}), 0)"),
            'rows' => new Measure('rows', __('Antal rader'), Measure::COUNT, fn ($f) => "COUNT(*) FILTER (WHERE {$f})"),
        ];
    }

    public function defaultRows(): array
    {
        return ['article'];
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
        return InvoiceRow::query()->toBase()
            ->join('invoices', fn (JoinClause $join) => $join->on('invoices.id', '=', 'invoice_rows.invoice_id')->on('invoices.company_id', '=', 'invoice_rows.company_id'))
            ->join('customers', fn (JoinClause $join) => $join->on('customers.id', '=', 'invoices.customer_id')->on('customers.company_id', '=', 'invoices.company_id'))
            ->join('accounts', fn (JoinClause $join) => $join->on('accounts.id', '=', 'invoice_rows.account_id')->on('accounts.company_id', '=', 'invoice_rows.company_id'))
            ->leftJoin('articles', fn (JoinClause $join) => $join->on('articles.id', '=', 'invoice_rows.article_id')->on('articles.company_id', '=', 'invoice_rows.company_id'))
            ->where('invoice_rows.company_id', $context->company->id)
            ->where('invoices.status', '<>', InvoiceStatus::Draft->value);
    }

    public function detail(AnalysisContext $context, Builder $query): array
    {
        $rows = $query
            ->select('invoices.id AS invoice_id', 'invoices.invoice_date', 'invoices.number', 'customers.name AS customer', 'invoice_rows.description', 'invoice_rows.net_ore')
            ->orderBy('invoices.invoice_date')->orderBy('invoices.number')->orderBy('invoice_rows.position')
            ->limit(AnalysisRunner::DRILL_LIMIT + 1)
            ->get();

        return [
            'columns' => [__('Datum'), __('Nummer'), __('Kund'), __('Text'), __('Netto')],
            'rows' => $rows->map(fn (object $row): array => [
                'cells' => [(string) $row->invoice_date, (string) $row->number, (string) $row->customer, (string) $row->description, Money::ofOre((int) $row->net_ore)->format()],
                'url' => route('invoicing.invoices.show', $row->invoice_id),
            ])->all(),
        ];
    }
}
