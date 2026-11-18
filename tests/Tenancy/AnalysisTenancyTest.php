<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDimensionObject;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Accounting\Dimension;
use App\Livewire\Analysis\AnalysisPage;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * AGENTS.md §13.2 for the analysis view (ADR-081 E17): company A never sees
 * B's figures, names, cost centres or ids in any dataset, a filter or a
 * drill-down, also when both companies use the same codes.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    foreach (['a' => 1_000_000, 'b' => 7_000_000] as $side => $amount) {
        [$owner, $company] = Bookkeeping::company();
        CurrentCompany::set($company);
        $unit = app(SaveDimensionObject::class)($owner, $company, Dimension::CostCenter, ['code' => '10', 'name' => "Hemlig enhet {$side}"]);
        app(PostVoucher::class)($owner, app(SaveDraftVoucher::class)($owner, $company, [
            'voucher_date' => '2026-03-01', 'description' => "Försäljning {$side}", 'series' => 'A',
            'rows' => [['account' => 1510, 'debit_ore' => $amount], ['account' => 3001, 'credit_ore' => $amount, 'cost_center' => '10', 'description' => "Rad {$side}"]],
        ]));
        $customer = Crm::customer($company, $owner, "Kund {$side}");
        Crm::invoice($owner, $company, $customer, '2026-03-02', [['description' => "Vara {$side}", 'quantity' => '1', 'price' => (string) ($amount / 100), 'vat_rate' => 2500]]);
        $this->{$side} = compact('owner', 'company', 'unit', 'customer');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

function e17Tenant(array $set = [])
{
    $page = Livewire::test(AnalysisPage::class)->set('from', '2026-01')->set('to', '2026-12');

    foreach ($set as $property => $value) {
        $page->set($property, $value);
    }

    return $page;
}

it('adds up only the company\'s own ledger rows, also with the same cost centre code', function () {
    $page = e17Tenant(['source' => 'ledger', 'rows' => ['cost_center'], 'metric' => 'credit']);
    $result = $page->viewData('result');

    $unit = collect($result->rows)->firstWhere('label', '10 Hemlig enhet a');

    expect(array_column($result->rows, 'label'))->toBe(['10 Hemlig enhet a', 'Ingen enhet'])
        ->and($result->rowTotals[$unit['key']]['v'])->toBe(1_000_000);

    $page->assertSee('Hemlig enhet a')->assertDontSee('Hemlig enhet b');
});

it('keeps another company\'s unit out of the filter picker and finds nothing for its id', function () {
    $page = e17Tenant(['rows' => ['account'], 'filterDimension1' => 'cost_center']);

    expect(array_keys($page->viewData('filterOptions')[1]))->toBe([0, $this->a['unit']->id]);

    $other = e17Tenant(['rows' => ['account'], 'metric' => 'credit', 'filterDimension1' => 'cost_center', 'filterValues1' => [(string) $this->b['unit']->id]])->viewData('result');

    expect($other->rows)->toBe([]);
});

it('drills down only into the company\'s own records', function () {
    $drill = e17Tenant(['rows' => ['account'], 'metric' => 'credit'])->call('drill', '3001', '')->viewData('drill');

    $texts = array_map(fn (array $row): string => $row['cells'][3], $drill['rows']);

    expect($texts)->toContain('Rad a')
        ->and(collect($texts)->filter(fn (string $text): bool => str_ends_with($text, ' b'))->all())->toBe([]);
});

it('keeps customer invoices of another company out', function () {
    $result = e17Tenant(['source' => 'customer_invoices', 'rows' => ['customer'], 'metric' => 'net'])->viewData('result');

    expect(array_column($result->rows, 'label'))->toBe(['Kund a'])
        ->and($result->grandTotal['v'])->toBe(1_000_000);

    $drill = e17Tenant(['source' => 'customer_invoices', 'rows' => ['customer']])->call('drill', (string) $this->b['customer']->id, '')->viewData('drill');

    expect($drill['rows'])->toBe([]);

    $rows = e17Tenant(['source' => 'invoice_rows', 'rows' => ['customer'], 'metric' => 'net'])->viewData('result');

    expect($rows->grandTotal['v'])->toBe(1_000_000);
});

it('shows B\'s own figures to B and nothing of A\'s', function () {
    CurrentCompany::set($this->b['company']);
    $this->actingAs($this->b['owner']);

    $result = e17Tenant(['rows' => ['cost_center'], 'metric' => 'credit'])->viewData('result');

    $unit = collect($result->rows)->firstWhere('label', '10 Hemlig enhet b');

    expect($result->rowTotals[$unit['key']]['v'])->toBe(7_000_000)
        ->and(array_column($result->rows, 'label'))->toBe(['10 Hemlig enhet b', 'Ingen enhet']);
});
