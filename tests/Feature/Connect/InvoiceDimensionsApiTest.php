<?php

declare(strict_types=1);

use App\Actions\Accounting\SaveDimensionObject;
use App\Domain\Accounting\Dimension;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Connect;

/*
 * ADR-081 gap 6, E6: cost centre and project on invoice rows over the API.
 * A draft keeps them when another field is changed, and an unknown code
 * answers 422.
 */

beforeEach(function () {
    Mail::fake();
    Connect::setUp();
});

it('takes and shows cost centre and project on invoice rows, and keeps them on a later change', function () {
    [$owner, $company] = Connect::company();
    CurrentCompany::set($company);
    app(SaveDimensionObject::class)($owner, $company, Dimension::CostCenter, ['code' => '10', 'name' => 'Verkstad']);
    app(SaveDimensionObject::class)($owner, $company, Dimension::Project, ['code' => 'P1', 'name' => 'Ombyggnad']);
    $key = Connect::apiKey($owner, $company, ['invoices:view', 'invoices:create']);

    $customer = $this->withToken($key)->withHeader('Idempotency-Key', 'e6-c')
        ->postJson('/api/v1/customers', ['name' => 'Kund i Exempelstad AB', 'payment_terms_days' => 30])->assertCreated()->json('data.id');

    $id = $this->withToken($key)->withHeader('Idempotency-Key', 'e6-i')->postJson('/api/v1/invoices', [
        'customer_id' => $customer, 'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Arbete', 'quantity' => 1, 'unit_price_ore' => 10000, 'vat_rate' => 2500, 'cost_center' => '10', 'project' => 'P1']],
    ])->assertCreated()
        ->assertJsonPath('data.rows.0.cost_center', '10')
        ->assertJsonPath('data.rows.0.project', 'P1')
        ->json('data.id');

    $this->withToken($key)->patchJson("/api/v1/invoices/{$id}", ['your_reference' => 'Anna Exempel'])->assertOk()
        ->assertJsonPath('data.rows.0.project', 'P1')
        ->assertJsonPath('data.rows.0.cost_center', '10');

    $this->withToken($key)->patchJson("/api/v1/invoices/{$id}", [
        'rows' => [['description' => 'Arbete', 'quantity' => 1, 'unit_price_ore' => 10000, 'vat_rate' => 2500, 'project' => 'NOPE']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['rows.0.project']);

    // Anything but text answers 422, not 500.
    $this->withToken($key)->patchJson("/api/v1/invoices/{$id}", [
        'rows' => [['description' => 'Arbete', 'quantity' => 1, 'unit_price_ore' => 10000, 'vat_rate' => 2500, 'cost_center' => ['10']]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['rows.0.cost_center']);
});
