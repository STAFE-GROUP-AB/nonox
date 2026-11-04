<?php

declare(strict_types=1);

use App\Actions\Guide\IssueDeadlineFeed;
use App\Domain\Company\VatPeriod;
use App\Livewire\Guide\MyDates;
use App\Models\DeadlineCalendarFeed;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Mina datum (ADR-051, stage 2) belongs to one company: a calendar link
 * shows only its own company's dates and name, links are per company, a
 * user cannot make one for a company they are not in, and a page kept open
 * after switching company answers 404.
 */

beforeEach(function () {
    [$this->ownerA, $this->companyA] = Bookkeeping::company();
    [$this->ownerB, $this->companyB] = Bookkeeping::company();
    $this->companyA->forceFill(['name' => 'Alfa AB', 'vat_registered' => true, 'vat_period' => VatPeriod::Quarterly])->save();
    $this->companyB->forceFill(['name' => 'Beta AB', 'vat_registered' => true, 'vat_period' => VatPeriod::Monthly])->save();
});

it('shows only its own company in a calendar link', function () {
    $ics = (string) $this->get(app(IssueDeadlineFeed::class)($this->ownerA, $this->companyA))->assertOk()->getContent();

    expect($ics)->toContain('Alfa AB')->not->toContain('Beta AB')
        ->toContain('UID:deadline-'.$this->companyA->id.'-')
        ->not->toContain('UID:deadline-'.$this->companyB->id.'-');
});

it('refuses a link for a company the user is not in, and keeps links per company', function () {
    expect(fn () => app(IssueDeadlineFeed::class)($this->ownerA, $this->companyB))->toThrow(AuthorizationException::class);

    app(IssueDeadlineFeed::class)($this->ownerA, $this->companyA);
    CurrentCompany::set($this->companyB);

    expect(DeadlineCalendarFeed::query()->count())->toBe(0);
});

it('answers 404 to a Mina datum page kept open after switching company', function () {
    $this->companyB->users()->attach($this->ownerA, ['role' => 'admin']);
    CurrentCompany::set($this->companyA);
    $this->actingAs($this->ownerA);

    $page = Livewire::test(MyDates::class)->assertOk()->assertSee('Alfa AB');
    $this->ownerA->switchCompany($this->companyB);

    $page->call('issueLink')->assertNotFound();
    expect(CompanyScope::bypass(fn (): int => DeadlineCalendarFeed::query()->count()))->toBe(0);
});
