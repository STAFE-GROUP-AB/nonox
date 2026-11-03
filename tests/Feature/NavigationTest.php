<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavLink;
use App\Support\Navigation\NavSection;
use App\Support\Tenancy\CurrentCompany;
use Tests\Support\Bookkeeping;
use Tests\Support\StaffLedger;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
});

/** @return list<NavSection> */
function navigationFor(User $user, Company $company, ?string $route): array
{
    return app(Navigation::class)->for($user->fresh(), $company, $route);
}

function openSection(User $user, Company $company, ?string $route): ?NavSection
{
    return collect(navigationFor($user, $company, $route))->first(fn (NavSection $section): bool => $section->active);
}

/** @return list<string> */
function pageLabels(?NavSection $section): array
{
    return array_map(fn (NavLink $link): string => $link->label, $section->children ?? []);
}

it('lists the sections in order and opens none on a page outside them', function () {
    $sections = navigationFor($this->owner, $this->company, 'profile.show');

    expect(array_map(fn (NavSection $section): string => $section->label, $sections))
        ->toBe(['Översikt', 'Försäljning', 'Inköp', 'Register', 'Bokföring', 'Uppgifter'])
        ->and(collect($sections)->contains(fn (NavSection $section): bool => $section->active || $section->hasChildren()))->toBeFalse();
});

it('shows the pages of the open section only, grouped in Bokföring', function () {
    $sections = navigationFor($this->owner, $this->company, 'accounting.reports');
    $open = collect($sections)->filter(fn (NavSection $section): bool => $section->active);

    expect($open)->toHaveCount(1)
        ->and($open->first()->label)->toBe('Bokföring')
        ->and(collect($sections)->reject(fn (NavSection $section): bool => $section->active)->contains(fn (NavSection $section): bool => $section->hasChildren()))->toBeFalse()
        ->and(array_map(fn (array $group): ?string => $group['label'], $open->first()->groups()))->toBe(['Löpande', 'Rapporter och moms', 'Bokslut', 'Inställningar'])
        ->and(pageLabels($open->first()))->toBe([
            'Verifikationer', 'Bankavstämning', 'Periodiseringar', 'Anläggningar', 'Läs in SIE-fil',
            'Rapporter', 'Reskontra', 'Moms', 'EU-rapporter',
            'Räkenskapsår', 'Bokslut', 'Årsredovisning', 'Inkomstdeklaration',
            'Kontoplan', 'Verifikationsserier', 'Kostnadsställen', 'Projekt',
        ])
        ->and($open->first()->current()?->label)->toBe('Rapporter');
});

it('filters the pages of Bokföring by permission, as the page links did', function (string $role, array $expected) {
    $member = Bookkeeping::member($this->company, $role);

    expect(pageLabels(openSection($member, $this->company, 'accounting.vouchers.index')))->toBe($expected);
})->with([
    'bookkeeper' => ['bookkeeper', ['Verifikationer', 'Bankavstämning', 'Rapporter', 'Reskontra', 'Räkenskapsår', 'Kontoplan', 'Verifikationsserier', 'Kostnadsställen', 'Projekt']],
    'approver' => ['approver', ['Verifikationer', 'Räkenskapsår', 'Kontoplan', 'Verifikationsserier', 'Kostnadsställen', 'Projekt']],
    'auditor' => ['auditor', ['Verifikationer', 'Rapporter', 'Reskontra', 'Räkenskapsår', 'Kontoplan', 'Verifikationsserier', 'Kostnadsställen', 'Projekt']],
    'accountant' => ['accountant', [
        'Verifikationer', 'Bankavstämning', 'Periodiseringar', 'Anläggningar', 'Läs in SIE-fil',
        'Rapporter', 'Reskontra', 'Moms', 'EU-rapporter',
        'Räkenskapsår', 'Bokslut', 'Årsredovisning', 'Inkomstdeklaration',
        'Kontoplan', 'Verifikationsserier', 'Kostnadsställen', 'Projekt',
    ]],
]);

it('lists the pages of Försäljning, Inköp and Register', function (string $route, string $section, array $pages, string $current) {
    $open = openSection($this->owner, $this->company, $route);

    expect($open?->label)->toBe($section)
        ->and(pageLabels($open))->toBe($pages)
        ->and($open?->current()?->label)->toBe($current);
})->with([
    'a quote' => ['invoicing.quotes.show', 'Försäljning', ['Offerter', 'Order', 'Fakturor', 'Avtal', 'Inbetalningar'], 'Offerter'],
    'a new invoice' => ['invoicing.invoices.create', 'Försäljning', ['Offerter', 'Order', 'Fakturor', 'Avtal', 'Inbetalningar'], 'Fakturor'],
    'payments in' => ['invoicing.incomingPayments', 'Försäljning', ['Offerter', 'Order', 'Fakturor', 'Avtal', 'Inbetalningar'], 'Inbetalningar'],
    'a supplier invoice' => ['payables.invoices.show', 'Inköp', ['Leverantörsfakturor', 'Betalfiler'], 'Leverantörsfakturor'],
    'payment files' => ['payables.batches.index', 'Inköp', ['Leverantörsfakturor', 'Betalfiler'], 'Betalfiler'],
    'suppliers' => ['payables.suppliers', 'Register', ['Kunder', 'Artiklar', 'Leverantörer'], 'Leverantörer'],
    'EU reports' => ['vat.euReports', 'Bokföring', [
        'Verifikationer', 'Bankavstämning', 'Periodiseringar', 'Anläggningar', 'Läs in SIE-fil',
        'Rapporter', 'Reskontra', 'Moms', 'EU-rapporter',
        'Räkenskapsår', 'Bokslut', 'Årsredovisning', 'Inkomstdeklaration',
        'Kontoplan', 'Verifikationsserier', 'Kostnadsställen', 'Projekt',
    ], 'EU-rapporter'],
]);

it('marks exactly one page as current', function () {
    $open = openSection($this->owner, $this->company, 'vat.euReports');

    expect(collect($open?->children)->filter(fn (NavLink $link): bool => $link->active)->map(fn (NavLink $link): string => $link->label)->values()->all())->toBe(['EU-rapporter']);
});

it('renders the pages in the sidebar instead of a row of links on the page', function () {
    $this->actingAs($this->owner);

    $html = $this->get(route('accounting.closing'))->assertOk()->getContent();

    expect($html)
        ->toMatch('#href="'.preg_quote(route('accounting.closing'), '#').'"\s+aria-current="page"#')
        ->toContain('href="'.route('accounting.incomeTax').'"')
        ->toContain('aria-label="Rapporter och moms"')
        ->not->toContain('<nav class="flex flex-wrap gap-2" aria-label="Bokföring">')
        ->not->toContain('aria-label="Nyckeltal"');

    $this->get(route('accounting.vouchers.index'))->assertOk()->assertSee('aria-label="Nyckeltal"', false);
});

it('leaves out the pages a member may not open', function () {
    $this->actingAs(Bookkeeping::member($this->company, 'bookkeeper'));

    $this->get(route('accounting.vouchers.index'))->assertOk()
        ->assertSee('href="'.route('accounting.bankReconciliation').'"', false)
        ->assertDontSee('href="'.route('accounting.closing').'"', false)
        ->assertDontSee('href="'.route('vat.index').'"', false)
        ->assertDontSee('href="'.route('accounting.sieImports.index').'"', false);
});

it('shows the pages of Lön while payroll is on', function () {
    app(ModuleManager::class)->enable(PayrollModule::KEY, $this->company, $this->owner);

    $open = openSection($this->owner, $this->company, 'payroll.employees');

    expect($open?->label)->toBe('Lön')
        ->and(pageLabels($open))->toBe(['Lönekörningar', 'Ansökningar', 'Anställda', 'Arbetsgivardeklaration', 'Rapporter', 'Semesterår', 'Semesterskuld', 'Komptid', 'Regler', 'Flytt av lönedata', 'Kom igång'])
        ->and($open?->current()?->label)->toBe('Anställda')
        ->and(openSection($this->owner, $this->company, 'payroll.index')?->current()?->label)->toBe('Kom igång');

    $this->actingAs($this->owner)->get(route('payroll.runs.index'))->assertOk()->assertSee('aria-label="Nyckeltal för lön"', false);
    $this->get(route('payroll.employees'))->assertOk()->assertDontSee('aria-label="Nyckeltal för lön"', false);
});

it('shows the pages of Personalliggare, and keeps Min incheckning its own item', function () {
    StaffLedger::enable($this->company, $this->owner);

    $open = openSection($this->owner, $this->company, 'staff-ledger.people');

    expect($open?->label)->toBe('Personalliggare')
        ->and(pageLabels($open))->toBe(['Översikt', 'Liggaren', 'Personal', 'Lokaler och kiosker', 'Kontrollbesök'])
        ->and($open?->current()?->label)->toBe('Personal')
        ->and(openSection(Bookkeeping::member($this->company, 'staff_ledger_staff'), $this->company, 'staff-ledger.mine')?->label)->toBe('Min incheckning');

    app(ModuleManager::class)->enable(PayrollModule::KEY, $this->company, $this->owner);

    expect(pageLabels(openSection($this->owner, $this->company, 'staff-ledger.overview')))->toContain('Tidrapporter');
});

it('adds HQ with Kö and Kunder last for an HQ admin, also without a company', function () {
    config(['nonox.hq_admins' => [$this->owner->email], 'app.admin_email' => null]);

    $sections = navigationFor($this->owner, $this->company, 'hq.customers.company');
    $hq = collect($sections)->last();

    expect($hq->label)->toBe('HQ')
        ->and($hq->active)->toBeTrue()
        ->and(pageLabels($hq))->toBe(['Kö', 'Kunder', 'Inställningar'])
        ->and($hq->current()?->label)->toBe('Kunder')
        ->and(collect($sections)->filter(fn (NavSection $section): bool => $section->active))->toHaveCount(1);

    $withoutCompany = app(Navigation::class)->for($this->owner->fresh(), null, 'hq.waitlist.show');

    expect(collect($withoutCompany)->last()->label)->toBe('HQ')
        ->and(collect($withoutCompany)->last()->current()?->label)->toBe('Kö')
        ->and(collect(navigationFor($this->owner, $this->company, 'dashboard'))->last()->hasChildren())->toBeFalse();
});

it('never shows HQ to anyone who is not an HQ admin', function (string $role) {
    config(['nonox.hq_admins' => ['someone-else@example.test'], 'app.admin_email' => null]);
    $user = $role === 'owner' ? $this->owner : Bookkeeping::member($this->company, $role);

    expect(array_map(fn (NavSection $section): string => $section->label, navigationFor($user, $this->company, 'hq.customers')))->not->toContain('HQ');
})->with(['owner', 'admin', 'accountant', 'auditor']);
