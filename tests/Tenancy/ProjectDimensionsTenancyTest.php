<?php

declare(strict_types=1);

use App\Actions\Accounting\SaveDimensionObject;
use App\Actions\Accounting\SaveProjectDetails;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Accounting\Dimension;
use App\Livewire\Accounting\Dimensions;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Models\Customer;
use App\Models\InvoiceRow;
use App\Models\Project;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for the project register and the dimensions on sales rows
 * (ADR-081 gap 6, E6): company A never sees B's projects, their details or
 * the customers' defaults, and B's ids answer 404.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    foreach (['a', 'b'] as $side) {
        [$owner, $company] = Bookkeeping::company();
        CurrentCompany::set($company);
        $this->actingAs($owner);
        $project = app(SaveDimensionObject::class)($owner, $company, Dimension::Project, ['code' => strtoupper($side).'1', 'name' => "Hemligt projekt {$side}"]);
        $customer = app(SaveCustomer::class)($owner, $company, ['name' => "Kund {$side}", 'payment_terms_days' => 30, 'default_project' => $project->code]);
        app(SaveProjectDetails::class)($owner, $company, $project, ['status' => 'ACTIVE', 'customer_id' => (string) $customer->id, 'contract_sum' => '1000']);
        app(SaveDraftInvoice::class)($owner, $company, ['customer_id' => $customer->id, 'invoice_date' => '2026-06-15', 'rows' => [['description' => "Rad {$side}", 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]]);
        $this->{$side} = compact('owner', 'company', 'project', 'customer');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('never shows B\'s projects, details or defaults to A', function () {
    expect(Project::query()->pluck('code')->all())->toBe(['A1'])
        ->and(Customer::query()->whereNotNull('default_project_id')->pluck('name')->all())->toBe(['Kund a'])
        ->and(InvoiceRow::query()->with('project')->get()->map(fn (InvoiceRow $row): ?string => $row->project?->code)->all())->toBe(['A1']);

    Livewire::test(Dimensions::class, ['dimension' => 'projekt'])->assertSee('Hemligt projekt a')->assertDontSee('Hemligt projekt b')->assertDontSee('Kund b');
    Livewire::test(InvoiceEditor::class)->assertSee('A1 Hemligt projekt a')->assertDontSee('Hemligt projekt b');
});

it('answers 404 for B\'s project id', function () {
    Livewire::test(Dimensions::class, ['dimension' => 'projekt'])->call('startDetails', $this->b['project']->id)->assertNotFound();
    Livewire::test(Dimensions::class, ['dimension' => 'projekt'])->call('toggleActive', $this->b['project']->id)->assertNotFound();
});
