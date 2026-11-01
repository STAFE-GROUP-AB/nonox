<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Search\GlobalSearch;
use App\Support\Search\QueryParser;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchResult;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Helpers for the spotlight tests (ADR-026).
 */
final class Spotlight
{
    /** @return array<string, SearchGroup> by group key */
    public static function search(User $user, Company $company, string $text): array
    {
        $query = (new QueryParser(CarbonImmutable::parse('2026-11-01')))->parse($text);
        $groups = [];

        foreach (app(GlobalSearch::class)->search($query, new SearchContext($user->fresh(), $company->fresh())) as $group) {
            $groups[$group->key] = $group;
        }

        return $groups;
    }

    /** @return list<string> titles of one group, in order */
    public static function titles(User $user, Company $company, string $text, string $group): array
    {
        $found = self::search($user, $company, $text)[$group] ?? null;

        return $found === null ? [] : array_map(fn (SearchResult $result): string => $result->title, $found->results);
    }

    public static function customer(Company $company, int $number, string $name, array $attributes = []): Customer
    {
        return CurrentCompany::run($company, fn (): Customer => Customer::create(['number' => $number, 'name' => $name, 'payment_terms_days' => 30, ...$attributes]));
    }

    public static function supplier(Company $company, int $number, string $name, array $attributes = []): Supplier
    {
        return CurrentCompany::run($company, fn (): Supplier => Supplier::create(['number' => $number, 'name' => $name, 'payment_terms_days' => 30, ...$attributes]));
    }

    /** An issued invoice, written directly: the search only reads. */
    public static function invoice(Company $company, Customer $customer, int $number, int $totalOre, string $date = '2026-10-14', string $due = '2026-11-13', string $status = 'ISSUED', ?string $ocr = null): Invoice
    {
        return CurrentCompany::run($company, function () use ($customer, $number, $totalOre, $date, $due, $status, $ocr): Invoice {
            $invoice = new Invoice(['customer_id' => $customer->id, 'invoice_date' => $date, 'due_date' => $due]);
            $invoice->forceFill(['number' => $number, 'status' => $status, 'total_ore' => $totalOre, 'net_ore' => $totalOre, 'ocr' => $ocr ?? (string) ($number * 10 + 3), 'customer_snapshot' => ['name' => $customer->name], 'issued_at' => $status === 'DRAFT' ? null : now()])->save();

            return $invoice;
        });
    }

    public static function supplierInvoice(Company $company, Supplier $supplier, int $number, int $totalOre, string $status = 'REGISTERED', string $description = 'El oktober'): SupplierInvoice
    {
        $approver = in_array($status, ['APPROVED', 'PAID'], true) ? $company->owner : null;

        return CurrentCompany::run($company, function () use ($supplier, $number, $totalOre, $status, $description, $approver): SupplierInvoice {
            $invoice = new SupplierInvoice(['supplier_id' => $supplier->id, 'invoice_date' => '2026-10-05', 'due_date' => '2026-11-04']);
            $invoice->forceFill(['number' => $number, 'status' => $status, 'total_ore' => $totalOre, 'invoice_number' => 'F'.$number, 'ocr' => (string) (90000 + $number), 'description' => $description, 'registered_at' => $status === 'DRAFT' ? null : now(), 'approved_at' => $approver ? now() : null, 'approved_by' => $approver?->id])->save();

            return $invoice;
        });
    }
}
