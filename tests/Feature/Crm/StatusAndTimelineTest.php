<?php

declare(strict_types=1);

use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Invoicing\SendReminder;
use App\Models\AuditLog;
use App\Models\SalesDocument;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SetAccountStatus;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\AccountStatus;
use App\Modules\Crm\Domain\StatusRules;
use App\Modules\Crm\Domain\Timeline;
use App\Modules\Crm\Models\Account;
use App\Modules\ModuleManager;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * The status rules and the daily command (ADR-043), and the timeline of
 * activities and ledger events with a fixed number of queries.
 */

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-02-01 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
});

it('follows the rules: prospect until the first invoice, active, then inactive after the set months', function () {
    $today = CarbonImmutable::parse('2026-12-01');
    $rule = fn (?string $last, ?string $manual = null, AccountStatus $current = AccountStatus::Prospect, ?string $issued = null) => StatusRules::statusFor(
        $current,
        $manual === null ? null : CarbonImmutable::parse($manual),
        $last === null ? null : CarbonImmutable::parse($last),
        $last === null ? null : CarbonImmutable::parse($issued ?? $last),
        $today,
        12,
    );

    expect($rule(null))->toBe(AccountStatus::Prospect)
        ->and($rule('2026-11-30'))->toBe(AccountStatus::Active)
        ->and($rule('2025-12-02'))->toBe(AccountStatus::Active)
        ->and($rule('2025-12-01'))->toBe(AccountStatus::Inactive)
        // A status set by hand stands until an invoice is issued after it.
        ->and($rule('2026-03-01', '2026-06-01', AccountStatus::Lost))->toBe(AccountStatus::Lost)
        ->and($rule('2026-07-01', '2026-06-01', AccountStatus::Lost))->toBe(AccountStatus::Active)
        ->and($rule(null, null, AccountStatus::Lost))->toBe(AccountStatus::Lost);
});

it('moves accounts with the daily command and audits each change', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB');
    $account = Crm::accountFor($customer);
    $prospect = Crm::prospect($this->company, $this->owner);

    Crm::invoice($this->owner, $this->company, $customer, '2026-02-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]);

    $this->artisan('crm:refresh-statuses')->assertSuccessful();
    expect($account->fresh()->status)->toBe(AccountStatus::Active)
        ->and($prospect->fresh()->status)->toBe(AccountStatus::Prospect);

    // Thirteen months later without an invoice: inactive.
    $this->travelTo(CarbonImmutable::parse('2027-03-02 10:00:00'));
    $this->artisan('crm:refresh-statuses', ['company' => $this->company->id])->assertSuccessful();
    expect($account->fresh()->status)->toBe(AccountStatus::Inactive)
        ->and(AuditLog::query()->where('action', 'crm.status_changed')->where('subject_id', $account->id)->count())->toBe(2);
});

it('keeps a status set by hand until the next invoice, and lets the shorter setting decide', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB');
    $account = Crm::accountFor($customer);
    Crm::invoice($this->owner, $this->company, $customer, '2026-02-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]);

    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00'));
    app(SetAccountStatus::class)($this->owner, $this->company, $account->fresh(), 'LOST', 'Flyttade utomlands');
    $this->artisan('crm:refresh-statuses')->assertSuccessful();
    expect($account->fresh()->status)->toBe(AccountStatus::Lost);

    $this->travelTo(CarbonImmutable::parse('2026-04-01 10:00:00'));
    Crm::invoice($this->owner, $this->company, $customer, '2026-04-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]);
    $this->artisan('crm:refresh-statuses')->assertSuccessful();
    expect($account->fresh()->status)->toBe(AccountStatus::Active)
        ->and($account->fresh()->status_set_manually_at)->toBeNull()
        ->and($account->fresh()->lost_reason)->toBeNull();

    app(ModuleManager::class)->updateSettings(CrmModule::KEY, $this->company, $this->owner, ['months_to_inactive' => 2]);
    $this->travelTo(CarbonImmutable::parse('2026-06-02 10:00:00'));
    $this->artisan('crm:refresh-statuses')->assertSuccessful();
    expect($account->fresh()->status)->toBe(AccountStatus::Inactive);
});

it('skips companies with the module off', function () {
    $customer = Crm::customer($this->company, $this->owner);
    Crm::invoice($this->owner, $this->company, $customer, '2026-02-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]);
    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, confirmed: true);

    $this->artisan('crm:refresh-statuses', ['company' => $this->company->id])->assertSuccessful();

    expect(Crm::accountFor($customer)->status)->toBe(AccountStatus::Prospect);
});

/**
 * One customer with $n of each: activities, quotes, invoices, payments and
 * reminders.
 */
function timelineFor(object $test, int $n): Account
{
    $customer = Crm::customer($test->company, $test->owner, "Kund med {$n}");
    $account = Crm::accountFor($customer);

    for ($i = 0; $i < $n; $i++) {
        app(LogActivity::class)($test->owner, $test->company, ['account_id' => $account->id, 'type' => 'NOTE', 'subject' => "Anteckning {$i}"]);
        app(SaveSalesDocument::class)($test->owner, $test->company, SalesDocument::QUOTE, ['customer_id' => $customer->id, 'document_date' => '2026-02-01', 'rows' => [['description' => 'Offererat', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]]);
        $invoice = Crm::invoice($test->owner, $test->company, $customer, '2026-01-02', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]], '2026-01-10');
        app(SendReminder::class)($test->owner, $invoice, 'kund@example.test');
        app(RegisterPayment::class)($test->owner, $invoice, CarbonImmutable::parse('2026-02-01'), Money::ofOre(5000));
    }

    return $account;
}

it('shows activities and the ledger newest first, with the same number of queries for 1 and 30 of each', function () {
    $small = timelineFor($this, 1);
    $large = timelineFor($this, 30);

    $count = function ($account): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = app(Timeline::class)->for($account, 50);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $page];
    };

    [$smallQueries, $smallPage] = $count($small);
    [$largeQueries, $largePage] = $count($large);

    expect($smallQueries)->toBe(2)
        ->and($largeQueries)->toBe($smallQueries)
        ->and($smallPage->total())->toBe(5)
        ->and(collect($smallPage->items())->pluck('kind')->sort()->values()->all())->toBe(['activity', 'invoice', 'payment', 'quote', 'reminder'])
        ->and($largePage->total())->toBe(150)
        ->and($largePage->count())->toBe(50);

    $dates = collect($largePage->items())->pluck('at')->map(fn ($at) => CarbonImmutable::parse($at)->getTimestamp())->all();
    $sorted = $dates;
    rsort($sorted);
    expect($dates)->toBe($sorted);
});

it('shows only activities on a prospect\'s timeline, and never another account\'s', function () {
    $prospect = Crm::prospect($this->company, $this->owner);
    $other = Crm::prospect($this->company, $this->owner, 'Annat AB');
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $prospect->id, 'type' => 'NOTE', 'subject' => 'Min anteckning']);
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $other->id, 'type' => 'NOTE', 'subject' => 'Inte min']);

    $page = app(Timeline::class)->for($prospect);

    expect(collect($page->items())->pluck('subject')->all())->toBe(['Min anteckning']);
});
