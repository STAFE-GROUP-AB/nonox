<?php

declare(strict_types=1);

use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Privacy\EraseRegisterEntry;
use App\Models\AuditLog;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LoseDeal;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\DealStageChange;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Person;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * Deals and GDPR (ADR-043 stage 2): a deal belongs to its account. Erasing
 * a person leaves the deal without them; an anonymised customer's deals
 * lose their free text; a prospect's deals go with it; the register
 * extracts include the deals; pruning keeps accounts and people with open
 * deals; the audit log holds ids only.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
});

it('removes an erased person from their deals and keeps the deals', function () {
    $account = Crm::prospect($this->company, $this->owner);
    $person = Crm::person($this->company, $this->owner, 'Anna Andersson');
    app(LinkPerson::class)($this->owner, $this->company, $account, $person, PersonRole::DecisionMaker);
    $deal = Crm::deal($this->company, $this->owner, $account, extra: ['person_id' => $person->id]);

    app(EraseCrmData::class)->person($this->owner, $this->company, $person);

    expect($deal->fresh()?->person_id)->toBeNull()
        ->and($deal->fresh()?->title)->toBe('Ramavtal');
});

it('clears the title and loss note of an anonymised customer\'s deals', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Privat Person', ['personal_number' => '811218-9876']);
    $invoice = Crm::invoice($this->owner, $this->company, $customer, '2026-06-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]);
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-06-10'), Money::ofOre(12500));
    $deal = Crm::deal($this->company, $this->owner, Crm::accountFor($customer), 'Köket hos Privat Person');
    app(LoseDeal::class)($this->owner, $this->company, $deal, ['loss_reason_id' => LossReason::query()->value('id'), 'loss_note' => 'Privat Person valde en annan']);

    app(EraseRegisterEntry::class)->customer($this->owner, $this->company, $customer->fresh());

    expect($deal->fresh()?->title)->toBe('Anonymiserad affär')
        ->and($deal->fresh()?->loss_note)->toBeNull()
        ->and(AuditLog::query()->where('action', 'privacy.erased')->where('payload->subject', 'crm_account')->sole()->payload['deals_anonymised'])->toBe(1);
});

it('erases a prospect\'s deals and their history with the prospect', function () {
    $account = Crm::prospect($this->company, $this->owner);
    $deal = Crm::deal($this->company, $this->owner, $account);

    app(EraseCrmData::class)->prospect($this->owner, $this->company, $account);

    expect(Deal::query()->whereKey($deal->id)->exists())->toBeFalse()
        ->and(DealStageChange::query()->where('deal_id', $deal->id)->exists())->toBeFalse();
});

it('includes the deals in the customer\'s and the contact person\'s register extracts', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB');
    $account = Crm::accountFor($customer);
    $person = Crm::person($this->company, $this->owner, 'Anna Andersson');
    app(LinkPerson::class)($this->owner, $this->company, $account, $person, PersonRole::DecisionMaker);
    Crm::deal($this->company, $this->owner, $account, 'Ramavtal', 12_500_000, ['person_id' => $person->id, 'expected_close_date' => '2026-07-31']);

    $customerJson = $this->get(route('privacy.export.customer', $customer))->assertOk()->json();
    $personJson = $this->get(route('privacy.export.crmPerson', $person))->assertOk()->json();

    expect($customerJson['crm']['affarer'][0])->toMatchArray(['rubrik' => 'Ramavtal', 'foretag' => 'Kontorshuset AB', 'varde_ore_exkl_moms' => 12_500_000, 'steg' => 'Ny kontakt', 'status' => 'Öppen', 'forvantat_avslut' => '2026-07-31'])
        ->and($personJson['affarer'][0]['rubrik'])->toBe('Ramavtal');
});

it('keeps prospects with an open deal and the contact person of an open deal from privacy:prune', function () {
    $withDeal = Crm::prospect($this->company, $this->owner, 'Med affär AB');
    $closed = Crm::prospect($this->company, $this->owner, 'Avslutad affär AB');
    $person = Crm::person($this->company, $this->owner, 'Kontakten');
    app(LinkPerson::class)($this->owner, $this->company, $withDeal, $person, PersonRole::DecisionMaker);
    Crm::deal($this->company, $this->owner, $withDeal, extra: ['person_id' => $person->id]);
    $lost = Crm::deal($this->company, $this->owner, $closed);
    app(LoseDeal::class)($this->owner, $this->company, $lost, ['loss_reason_id' => LossReason::query()->value('id')]);

    $old = '2024-01-01 10:00:00';
    Account::query()->update(['last_activity_at' => $old, 'updated_at' => $old]);
    Person::query()->update(['last_activity_at' => $old, 'updated_at' => $old]);

    $this->artisan('privacy:prune')->assertSuccessful();

    expect(Account::query()->pluck('name')->all())->toBe(['Med affär AB'])
        ->and(Person::query()->pluck('name')->all())->toBe(['Kontakten']);
});
