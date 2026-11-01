<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Resources;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Models\VoucherRow;
use App\Models\VoucherSeries;
use DateTimeInterface;

/**
 * How records look in the API (ADR-032). Money is whole öre as integers,
 * with the currency beside it; dates are ISO 8601 (YYYY-MM-DD for
 * accounting dates). Personal numbers are never included: only whether one
 * is on file.
 */
final class ApiPresenter
{
    /** @return array<string, mixed> */
    public static function company(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'org_number' => $company->org_number,
            'legal_form' => $company->legal_form->value,
            'vat_number' => $company->vat_number,
            'vat_registered' => (bool) $company->vat_registered,
            'vat_period' => $company->vat_period->value,
            'accounting_method' => $company->accounting_method->value,
            'reporting_framework' => $company->reporting_framework->value,
            'fiscal_year_start_month' => $company->fiscal_year_start_month,
            'base_currency' => $company->base_currency,
            'locked_until_date' => self::date($company->locked_until_date),
            'archived' => $company->archived_at !== null,
            'address' => [
                'line1' => $company->address_line1,
                'line2' => $company->address_line2,
                'postal_code' => $company->postal_code,
                'city' => $company->city,
                'country' => $company->country,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function account(Account $account): array
    {
        return [
            'number' => $account->number,
            'name' => $account->name,
            'type' => $account->type->value,
            'is_active' => $account->is_active,
            'sru_code' => $account->sru_code,
            'requires_cost_center' => (bool) $account->requires_cost_center,
            'requires_project' => (bool) $account->requires_project,
            'updated_at' => self::time($account->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function fiscalYear(FiscalYear $year): array
    {
        return [
            'id' => $year->id,
            'start_date' => self::date($year->start_date),
            'end_date' => self::date($year->end_date),
            'status' => $year->status->value,
            'updated_at' => self::time($year->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function series(VoucherSeries $series): array
    {
        return [
            'code' => $series->code,
            'name' => $series->name,
            'is_default' => $series->is_default,
        ];
    }

    /**
     * Needs rows.account, rows.costCenter, rows.project, series and attachments loaded.
     *
     * @return array<string, mixed>
     */
    public static function voucher(Voucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'series' => $voucher->series?->code,
            'number' => $voucher->number,
            'reference' => $voucher->isPosted() ? $voucher->series?->code.$voucher->number : null,
            'status' => $voucher->status->value,
            'fiscal_year_id' => $voucher->fiscal_year_id,
            'voucher_date' => self::date($voucher->voucher_date),
            'description' => $voucher->description,
            'source' => $voucher->source->value,
            'corrects_voucher_id' => $voucher->corrects_voucher_id,
            'corrected_by_voucher_id' => $voucher->corrected_by_voucher_id,
            'posted_at' => self::time($voucher->posted_at),
            'hash' => $voucher->hash,
            'currency' => 'SEK',
            'rows' => $voucher->rows->map(fn (VoucherRow $row): array => [
                'account' => $row->account?->number,
                'debit_ore' => $row->debit_ore->ore,
                'credit_ore' => $row->credit_ore->ore,
                'description' => $row->description,
                'cost_center' => $row->costCenter?->code,
                'project' => $row->project?->code,
            ])->values()->all(),
            'attachments' => $voucher->attachments->map(fn (VoucherAttachment $attachment): array => self::attachment($attachment))->values()->all(),
            'created_at' => self::time($voucher->created_at),
            'updated_at' => self::time($voucher->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function attachment(VoucherAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'sha256' => $attachment->sha256,
            'created_at' => self::time($attachment->created_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function customer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'number' => $customer->number,
            'name' => $customer->name,
            'org_number' => $customer->org_number,
            // Never the number itself (ADR-032): only that the customer has one.
            'has_personal_number' => $customer->getRawOriginal('personal_number') !== null,
            'vat_number' => $customer->vat_number,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => [
                'line1' => $customer->address_line1,
                'line2' => $customer->address_line2,
                'postal_code' => $customer->postal_code,
                'city' => $customer->city,
                'country' => $customer->country,
            ],
            'payment_terms_days' => $customer->payment_terms_days,
            'is_active' => $customer->is_active,
            'reverse_charge_construction' => (bool) $customer->reverse_charge_construction,
            'foreign_sale' => $customer->foreign_sale?->value,
            'processing_restricted' => $customer->processing_restricted_at !== null,
            'created_at' => self::time($customer->created_at),
            'updated_at' => self::time($customer->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function supplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'number' => $supplier->number,
            'name' => $supplier->name,
            'org_number' => $supplier->org_number,
            'vat_number' => $supplier->vat_number,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'address' => [
                'line1' => $supplier->address_line1,
                'postal_code' => $supplier->postal_code,
                'city' => $supplier->city,
                'country' => $supplier->country,
            ],
            'bankgiro' => $supplier->bankgiro,
            'plusgiro' => $supplier->plusgiro,
            'iban' => $supplier->iban,
            'bic' => $supplier->bic,
            'payment_terms_days' => $supplier->payment_terms_days,
            'default_account' => $supplier->defaultAccount?->number,
            'is_active' => $supplier->is_active,
            'processing_restricted' => $supplier->processing_restricted_at !== null,
            'created_at' => self::time($supplier->created_at),
            'updated_at' => self::time($supplier->updated_at),
        ];
    }

    private static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    private static function time(?DateTimeInterface $time): ?string
    {
        return $time?->format(DateTimeInterface::ATOM);
    }
}
