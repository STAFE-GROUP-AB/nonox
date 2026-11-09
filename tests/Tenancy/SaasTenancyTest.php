<?php

declare(strict_types=1);

use App\Modules\Saas\Jobs\RecomputeSaas;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Models\SaasCustomerMonth;
use App\Modules\Saas\Models\SaasMonth;
use App\Modules\Saas\Support\SaasLedger;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Tests\Support\Bookkeeping;
use Tests\Support\Saas;

/*
 * AGENTS.md §13.2 for NONOX SaaS (ADR-080 §8): company A never sees B's
 * versions, months, customers or corrections through Eloquent, the pages,
 * the drill-down, the CSV files, the ledger or the queued recompute; B's
 * customer answers 404.
 */

beforeEach(function () {
    Saas::at('2026-01-15');

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Bookkeeping::company();
        CurrentCompany::set($company);
        $this->actingAs($owner);
        Saas::enable($company, $owner);
        $customer = Saas::customer($owner, $company, "Hemlig {$name}kund AB");
        Saas::agreement($owner, $company, $customer, $side === 'a' ? '100' : '7777');
        $this->{$side} = compact('owner', 'company', 'customer');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('keeps every model of the module inside the company', function (string $model) {
    expect($model::query()->count())->toBeGreaterThan(0)
        ->and($model::query()->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id]);
})->with([SaasContractVersion::class, SaasMonth::class, SaasCustomerMonth::class]);

it('shows only the own company on every page and in every file', function () {
    foreach (['saas.overview', 'saas.movements', 'saas.customers', 'saas.cohorts', 'saas.scheduled', 'saas.corrections', 'saas.months', 'saas.sources'] as $route) {
        $this->get(route($route))->assertOk()->assertDontSee('Hemlig Betakund AB')->assertDontSee('7 777');
    }

    $this->get(route('saas.movements.show', ['month' => '2026-01', 'kategori' => 'ny']))->assertOk()->assertSee('Hemlig Alfakund AB')->assertDontSee('Hemlig Betakund AB');

    foreach (['rorelser', 'kunder', 'manad', 'kohorter', 'uppsagda', 'rattelser', 'manader'] as $list) {
        $body = $this->get(route('saas.export', ['list' => $list, 'manad' => '2026-01']))->assertOk()->streamedContent();
        expect($body)->not->toContain('Betakund')->not->toContain('7777');
    }
});

it('answers 404 for the other company\'s customer', function () {
    $this->get(route('saas.customers.show', ['customer' => $this->b['customer']->id]))->assertNotFound();
    $this->get(route('saas.customers.show', ['customer' => $this->a['customer']->id]))->assertOk()->assertSee('Hemlig Alfakund AB');
});

it('recomputes and verifies one company without touching the other', function () {
    $before = CompanyScope::bypass(fn () => SaasMonth::query()->where('company_id', $this->b['company']->id)->value('mrr_ore'));

    app(SaasLedger::class)->recompute($this->a['company']);
    RecomputeSaas::dispatchSync($this->a['company']->id);

    expect(Saas::month('2026-01-01')->mrr_ore)->toBe(10_000)
        ->and(CompanyScope::bypass(fn () => SaasMonth::query()->where('company_id', $this->b['company']->id)->value('mrr_ore')))->toBe($before)
        ->and($before)->toBe(777_700)
        ->and(app(SaasLedger::class)->verify($this->a['company']))->toBe([]);
});
