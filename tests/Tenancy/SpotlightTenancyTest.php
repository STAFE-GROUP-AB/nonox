<?php

declare(strict_types=1);

use App\Models\RecentItem;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchResult;
use App\Support\Tenancy\CurrentCompany;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;
use Tests\Support\Spotlight;
use Tests\Support\StaffLedger;

/*
 * AGENTS.md §13.2 for the spotlight (ADR-026): nothing of company B is ever
 * found by a user of company A, not even by its exact number, OCR, amount,
 * organisation number or the last digits of a personal number.
 */
beforeEach(function () {
    [$this->ownerA, $this->companyA] = Bookkeeping::company();
    [$this->ownerB, $this->companyB] = Bookkeeping::company();
});

/** @param  array<string, SearchGroup>  $groups */
function everything(array $groups): string
{
    return json_encode(array_map(fn (SearchGroup $group): array => array_map(fn (SearchResult $result): array => $result->jsonSerialize(), $group->results), $groups), JSON_UNESCAPED_UNICODE);
}

it('never finds company B\'s records for a user of company A, even by exact number', function () {
    CurrentCompany::set($this->companyB);
    $voucher = Bookkeeping::post($this->ownerB, $this->companyB, 4711_00);
    $customer = Spotlight::customer($this->companyB, 4242, 'Hemlig Kund B AB', ['org_number' => '559900-1122', 'email' => 'hemlig@b.example']);
    Spotlight::invoice($this->companyB, $customer, 4243, 99_00, ocr: '4243999');
    $supplier = Spotlight::supplier($this->companyB, 4244, 'Hemlig Leverantör B AB');
    Spotlight::supplierInvoice($this->companyB, $supplier, 4245, 98_00, description: 'Hemlig el B');
    CurrentCompany::set($this->companyA);

    foreach (['Hemlig', $voucher->reference(), '4711', '4711 kr', '4242', '4243', '4243999', '4244', '4245', '5599001122', 'hemlig@b.example', 'faktura 4243', 'lev Hemlig', 'obetald', 'attestera', 'oktober', '2026-03-01'] as $typed) {
        $found = everything(Spotlight::search($this->ownerA, $this->companyA, $typed));

        expect($found)->not->toContain('Hemlig')
            ->not->toContain((string) $voucher->id);
    }
});

it('never shows company B\'s employees or staff ledger people, nor matches their personal numbers', function () {
    CurrentCompany::set($this->companyB);
    Payroll::setUp($this->companyB, $this->ownerB);
    Payroll::employee($this->ownerB, $this->companyB, ['first_name' => 'Hemlig', 'last_name' => 'Anställd']);
    StaffLedger::enable($this->companyB, $this->ownerB);
    StaffLedger::person($this->ownerB, $this->companyB, overrides: ['first_name' => 'Hemlig', 'last_name' => 'Personal']);

    CurrentCompany::set($this->companyA);
    app(ModuleManager::class)->enable(PayrollModule::KEY, $this->companyA, $this->ownerA);
    StaffLedger::enable($this->companyA, $this->ownerA);
    $payrollAdmin = Bookkeeping::member($this->companyA, 'payroll_admin');

    foreach (['Hemlig', 'anställd Hemlig', 'personal Hemlig'] as $typed) {
        expect(everything(Spotlight::search($payrollAdmin, $this->companyA, $typed)))->not->toContain('Hemlig')
            ->and(everything(Spotlight::search($this->ownerA, $this->companyA, $typed)))->not->toContain('Hemlig');
    }
});

it('does not remember, nor list, another company\'s record by its id', function () {
    CurrentCompany::set($this->companyB);
    $voucher = Bookkeeping::post($this->ownerB, $this->companyB);
    $customer = Spotlight::customer($this->companyB, 1, 'Hemlig Kund B AB');
    CurrentCompany::set($this->companyA);

    $this->actingAs($this->ownerA)->post(route('search.recent'), ['type' => 'vouchers', 'id' => $voucher->id])->assertNoContent();
    $this->actingAs($this->ownerA)->post(route('search.recent'), ['type' => 'customers', 'id' => (string) $customer->id])->assertNoContent();

    expect(RecentItem::query()->withoutGlobalScopes()->where('user_id', $this->ownerA->id)->count())->toBe(0)
        ->and(Spotlight::search($this->ownerA, $this->companyA, ''))->not->toHaveKey('recent');
});

it('does not offer a company the user is not a member of', function () {
    $this->companyB->forceFill(['name' => 'Hemligt Bolag B AB'])->save();

    expect(Spotlight::search($this->ownerA, $this->companyA, 'hemligt'))->not->toHaveKey('companies');

    // And the switch route refuses it, as it always has.
    $this->actingAs($this->ownerA)->put(route('current-team.update'), ['team_id' => $this->companyB->id])->assertNotFound();
});
