<?php

declare(strict_types=1);

use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\CreatePaymentBatch;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Models\FiscalYear;
use App\Models\SupplierPaymentBatch;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoIndustries;
use Database\Seeders\Demo\Extras\PurchasesExtras;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

/*
 * The demo's payment file (betalfil) is made by the demo seeder for its owner,
 * who has reviewed the synthetic figures. The file must come out without the
 * "Bekräfta att du har granskat siffrorna" warning, and the guard for real
 * payment files stays as it is.
 */

it('makes the demo payment file without the figures review warning', function () {
    [$owner, $company] = Bookkeeping::company();
    $company->forceFill(['separate_supplier_approval' => false])->save();

    CurrentCompany::run($company, function () use ($owner, $company): void {
        $supplier = app(SaveSupplier::class)($owner, $company, ['name' => 'Grossisten AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010]);
        $draft = app(SaveSupplierInvoice::class)($owner, $company, [
            'supplier_id' => $supplier->id,
            'invoice_number' => 'F-1',
            'invoice_date' => '2026-03-05',
            'total' => '1620,00',
            'rows' => [['account' => '', 'amount' => '1620,00']],
        ]);
        app(ApproveSupplierInvoice::class)($owner, app(RegisterSupplierInvoice::class)($owner, $draft));
    });

    $warnings = [];
    $fiscalYear = CurrentCompany::run($company, fn (): FiscalYear => FiscalYear::query()->orderBy('start_date')->firstOrFail());
    $today = CarbonImmutable::today();
    $context = new DemoContext(
        slug: 'bokforing-for-konsulter',
        demo: DemoIndustries::all()['bokforing-for-konsulter'],
        user: $owner,
        company: $company,
        fiscalYear: $fiscalYear,
        start: CarbonImmutable::parse($fiscalYear->start_date->toDateString()),
        end: $today->subDay(),
        today: $today,
        warn: function (string $level, string $message) use (&$warnings): void {
            $warnings[] = $message;
        },
    );

    CurrentCompany::run($company, fn () => app(PurchasesExtras::class)->afterAll($context));

    expect($warnings)->toBe([]);

    CurrentCompany::run($company, function (): void {
        expect(SupplierPaymentBatch::query()->count())->toBe(1);
    });
});

it('still refuses a payment file without the figures review', function () {
    [$owner, $company] = Bookkeeping::company();

    expect(fn () => app(CreatePaymentBatch::class)($owner, $company, [], CarbonImmutable::today(), figuresReviewed: false))
        ->toThrow(ValidationException::class);
});
