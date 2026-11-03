<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Modules\Crm\Actions\EnsurePipelineDefaults;
use App\Modules\Crm\Actions\LoseDeal;
use App\Modules\Crm\Actions\MoveDeal;
use App\Modules\Crm\Actions\SaveDeal;
use App\Modules\Crm\Actions\SetAccountStatus;
use App\Modules\Crm\Actions\SetWinBack;
use App\Modules\Crm\Actions\WinDeal;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\DealStageChange;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * CRM stage 2's deal actions (ADR-043): a deal is created with its next
 * step, moved with a history, won with links to the ledger and lost with a
 * reason and a win-back date. Every action writes ids only to the audit log.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->account = Crm::prospect($this->company, $this->owner, 'Prospektet AB');
});

it('gives a company the pipeline Försäljning with four stages and the loss reasons when CRM is turned on, once', function () {
    $pipeline = Crm::pipeline();

    expect($pipeline->name)->toBe('Försäljning')
        ->and($pipeline->stages()->pluck('probability', 'name')->all())->toBe(['Ny kontakt' => 10, 'Behov' => 25, 'Offert' => 50, 'Förhandling' => 75])
        ->and(LossReason::query()->count())->toBe(6);

    app(EnsurePipelineDefaults::class)($this->company);

    expect(Pipeline::query()->count())->toBe(1)
        ->and(PipelineStage::query()->count())->toBe(4)
        ->and(LossReason::query()->count())->toBe(6);
});

it('creates a deal in the default pipeline\'s first stage with its next step, a history row and an audit entry without names', function () {
    $deal = app(SaveDeal::class)($this->owner, $this->company, [
        'account_id' => $this->account->id,
        'title' => 'Ramavtal med Anna',
        'value' => '125 000,50',
        'expected_close_date' => '2026-07-31',
        'next_step' => ['type' => 'MEETING', 'subject' => 'Möte med Anna', 'due_at' => '2026-06-17T10:00'],
    ]);

    expect($deal->value_ore)->toBe(12_500_050)
        ->and($deal->stage_id)->toBe(Crm::stage(0)->id)
        ->and($deal->owner_id)->toBe($this->owner->id)
        ->and($deal->status->value)->toBe('OPEN')
        ->and($deal->expected_close_date?->toDateString())->toBe('2026-07-31');

    $step = Activity::query()->where('deal_id', $deal->id)->sole();
    expect($step->isOpen())->toBeTrue()
        ->and($step->type->value)->toBe('MEETING')
        ->and($step->account_id)->toBe($this->account->id)
        ->and($step->assigned_to)->toBe($this->owner->id)
        ->and(DealStageChange::query()->where('deal_id', $deal->id)->sole()->from_stage_id)->toBeNull();

    $payloads = AuditLog::query()->whereIn('action', ['crm.deal_created', 'crm.activity_created'])->pluck('payload')->toJson();
    expect($payloads)->toContain((string) $deal->id)->not->toContain('Anna')->not->toContain('Ramavtal');
});

it('refuses a deal without a next step, without a title, with a negative value or an unreadable one', function () {
    $save = app(SaveDeal::class);
    $base = ['account_id' => $this->account->id, 'title' => 'Affär', 'next_step' => ['type' => 'TASK', 'due_at' => '2026-06-16T09:00']];

    expect(fn () => $save($this->owner, $this->company, [...$base, 'next_step' => null]))->toThrow(ValidationException::class, 'nästa steg')
        ->and(fn () => $save($this->owner, $this->company, [...$base, 'next_step' => ['type' => 'NOTE']]))->toThrow(ValidationException::class)
        ->and(fn () => $save($this->owner, $this->company, [...$base, 'title' => '']))->toThrow(ValidationException::class)
        ->and(fn () => $save($this->owner, $this->company, [...$base, 'value_ore' => -1]))->toThrow(ValidationException::class, 'negativt')
        ->and(fn () => $save($this->owner, $this->company, [...$base, 'value' => 'tio kronor']))->toThrow(ValidationException::class, 'i kronor')
        ->and(fn () => $save($this->owner, $this->company, [...$base, 'account_id' => 999_999]))->toThrow(ValidationException::class, 'Kunden finns inte')
        ->and(Deal::query()->count())->toBe(0)
        ->and(Activity::query()->whereNotNull('deal_id')->count())->toBe(0);
});

it('refuses a contact person not at the account, an owner outside the company and a stage of another pipeline or an archived one', function () {
    $save = app(SaveDeal::class);
    $base = ['account_id' => $this->account->id, 'title' => 'Affär', 'next_step' => ['type' => 'TASK']];
    $stranger = Crm::person($this->company, $this->owner, 'Någon Annan');
    [$outsider] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $other = Pipeline::create(['name' => 'Service']);
    $otherStage = PipelineStage::create(['pipeline_id' => $other->id, 'name' => 'Ny', 'probability' => 20]);

    expect(fn () => $save($this->owner, $this->company, [...$base, 'person_id' => $stranger->id]))->toThrow(ValidationException::class, 'hör inte till kunden')
        ->and(fn () => $save($this->owner, $this->company, [...$base, 'owner_id' => $outsider->id]))->toThrow(ValidationException::class, 'med i företaget')
        ->and(fn () => $save($this->owner, $this->company, [...$base, 'pipeline_id' => Crm::pipeline()->id, 'stage_id' => $otherStage->id]))->toThrow(ValidationException::class, 'Steget finns inte');

    $other->forceFill(['archived_at' => now()])->save();
    expect(fn () => $save($this->owner, $this->company, [...$base, 'stage_id' => $otherStage->id]))->toThrow(ValidationException::class, 'arkiverad');
});

it('moves a deal between stages with a history row and an audit entry, and not a closed one', function () {
    $deal = Crm::deal($this->company, $this->owner, $this->account);
    $move = app(MoveDeal::class);

    $move($this->owner, $this->company, $deal, Crm::stage(2));
    $move($this->owner, $this->company, $deal, Crm::stage(2)->id);

    expect($deal->fresh()?->stage_id)->toBe(Crm::stage(2)->id)
        ->and(DealStageChange::query()->where('deal_id', $deal->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'crm.deal_moved')->sole()->payload)->toEqual(['deal_id' => $deal->id, 'from_stage_id' => Crm::stage(0)->id, 'to_stage_id' => Crm::stage(2)->id]);

    app(LoseDeal::class)($this->owner, $this->company, $deal, ['loss_reason_id' => LossReason::query()->value('id')]);
    expect(fn () => $move($this->owner, $this->company, $deal->fresh(), Crm::stage(1)))->toThrow(ValidationException::class, 'avslutad');
});

it('changes an open deal through SaveDeal, a change of stage included, and refuses changing a closed one', function () {
    $deal = Crm::deal($this->company, $this->owner, $this->account);

    $saved = app(SaveDeal::class)($this->owner, $this->company, ['title' => 'Nytt namn', 'value' => '5 000', 'stage_id' => Crm::stage(1)->id], $deal);

    expect($saved->title)->toBe('Nytt namn')
        ->and($saved->value_ore)->toBe(500_000)
        ->and($saved->stage_id)->toBe(Crm::stage(1)->id)
        ->and(AuditLog::query()->where('action', 'crm.deal_updated')->sole()->payload['fields'])->toEqualCanonicalizing(['title', 'value_ore'])
        ->and(AuditLog::query()->where('action', 'crm.deal_moved')->count())->toBe(1);

    app(WinDeal::class)($this->owner, $this->company, $saved);
    expect(fn () => app(SaveDeal::class)($this->owner, $this->company, ['title' => 'Igen'], $saved->fresh()))->toThrow(ValidationException::class, 'avslutad');
});

it('wins a deal with an agreed value, turns the prospect into a customer and links its quote', function () {
    $deal = Crm::deal($this->company, $this->owner, $this->account);

    $won = app(WinDeal::class)($this->owner, $this->company, $deal, ['value_ore' => 900_000, 'make_customer' => true]);
    $account = $this->account->fresh();

    expect($won->status->value)->toBe('WON')
        ->and($won->won_at)->not->toBeNull()
        ->and($won->value_ore)->toBe(900_000)
        ->and($account?->customer_id)->not->toBeNull()
        ->and($account?->status->value)->toBe('PROSPECT');

    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, 'QUOTE', ['customer_id' => $account?->customer_id, 'document_date' => '2026-06-15', 'rows' => [['description' => 'Tjänst', 'quantity' => '1', 'price' => '9000', 'vat_rate' => 2500]]]);
    $second = Crm::deal($this->company, $this->owner, $account, 'Tillägg');
    $other = Crm::customer($this->company, $this->owner, 'Annan kund AB');
    $foreignQuote = app(SaveSalesDocument::class)($this->owner, $this->company, 'QUOTE', ['customer_id' => $other->id, 'document_date' => '2026-06-15', 'rows' => [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]]);

    expect(fn () => app(WinDeal::class)($this->owner, $this->company, $second, ['quote_id' => $foreignQuote->id]))->toThrow(ValidationException::class, 'Offerten finns inte hos kunden')
        ->and(fn () => app(WinDeal::class)($this->owner, $this->company, $second, ['order_id' => $quote->id]))->toThrow(ValidationException::class, 'Ordern finns inte hos kunden');

    $linked = app(WinDeal::class)($this->owner, $this->company, $second, ['quote_id' => $quote->id]);
    expect($linked->quote_id)->toBe($quote->id)
        ->and(fn () => app(WinDeal::class)($this->owner, $this->company, $linked))->toThrow(ValidationException::class, 'redan avslutad')
        ->and(AuditLog::query()->where('action', 'crm.deal_won')->latest('id')->first()?->payload)->toEqual(['deal_id' => $linked->id, 'account_id' => $account?->id, 'stage_id' => $linked->stage_id, 'quote_id' => $quote->id]);
});

it('lets a seller win a deal without making a customer, since a seller may not add customers', function () {
    $seller = Bookkeeping::member($this->company, 'sales');
    $deal = Crm::deal($this->company, $this->owner, $this->account);

    app(WinDeal::class)($seller, $this->company, $deal, ['make_customer' => true]);

    expect($deal->fresh()?->status->value)->toBe('WON')
        ->and($this->account->fresh()?->customer_id)->toBeNull()
        ->and(Customer::query()->count())->toBe(0);
});

it('loses a deal with a required reason, a note kept out of the audit log and a win-back date six months on', function () {
    $deal = Crm::deal($this->company, $this->owner, $this->account);
    $reason = LossReason::query()->where('name', 'Priset')->firstOrFail();

    expect(fn () => app(LoseDeal::class)($this->owner, $this->company, $deal, []))->toThrow(ValidationException::class, 'Välj varför');

    $reason->forceFill(['archived_at' => now()])->save();
    expect(fn () => app(LoseDeal::class)($this->owner, $this->company, $deal, ['loss_reason_id' => $reason->id]))->toThrow(ValidationException::class, 'från listan');
    $reason->forceFill(['archived_at' => null])->save();

    $lost = app(LoseDeal::class)($this->owner, $this->company, $deal, ['loss_reason_id' => $reason->id, 'loss_note' => 'Anna tyckte det var dyrt']);

    expect($lost->status->value)->toBe('LOST')
        ->and($lost->loss_note)->toBe('Anna tyckte det var dyrt')
        ->and($lost->win_back_at?->toDateString())->toBe('2026-12-15')
        ->and(AuditLog::query()->where('action', 'crm.deal_lost')->sole()->payload)->toMatchArray(['deal_id' => $deal->id, 'loss_reason_id' => $reason->id, 'note_given' => true, 'win_back' => true])
        ->and(AuditLog::query()->where('action', 'crm.deal_lost')->pluck('payload')->toJson())->not->toContain('Anna');
});

it('follows the company\'s win-back months, and takes an explicit date or none', function () {
    app(ModuleManager::class)->updateSettings('crm', $this->company, $this->owner, ['win_back_months' => 3]);
    $reason = LossReason::query()->value('id');

    $default = app(LoseDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $this->account, 'A'), ['loss_reason_id' => $reason]);
    $given = app(LoseDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $this->account, 'B'), ['loss_reason_id' => $reason, 'win_back_at' => '2027-01-10']);
    $none = app(LoseDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $this->account, 'C'), ['loss_reason_id' => $reason, 'win_back_at' => '']);

    expect($default->win_back_at?->toDateString())->toBe('2026-09-15')
        ->and($given->win_back_at?->toDateString())->toBe('2027-01-10')
        ->and($none->win_back_at)->toBeNull();

    $changed = app(SetWinBack::class)($this->owner, $this->company, $none, '2026-08-01');
    expect($changed->win_back_at?->toDateString())->toBe('2026-08-01')
        ->and(fn () => app(SetWinBack::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $this->account, 'D'), '2026-08-01'))->toThrow(ValidationException::class, 'Bara en förlorad');
});

it('gives a lost account a win-back date, keeps it when the reason changes and clears it when the account is active again', function () {
    $set = app(SetAccountStatus::class);

    $lost = $set($this->owner, $this->company, $this->account, 'LOST', 'Valde en annan');
    expect($lost->win_back_at?->toDateString())->toBe('2026-12-15');

    app(SetWinBack::class)($this->owner, $this->company, $lost, '2027-02-01');
    $again = $set($this->owner, $this->company, $lost->fresh(), 'LOST', 'Ny orsak');
    expect($again->win_back_at?->toDateString())->toBe('2027-02-01');

    expect($set($this->owner, $this->company, $again, 'PROSPECT')->win_back_at)->toBeNull();
});

it('lets only crm:manage create, move, win and lose deals', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $deal = Crm::deal($this->company, $this->owner, $this->account);

    expect(fn () => Crm::deal($this->company, $auditor, $this->account))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MoveDeal::class)($auditor, $this->company, $deal, Crm::stage(1)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(WinDeal::class)($auditor, $this->company, $deal))->toThrow(AuthorizationException::class)
        ->and(fn () => app(LoseDeal::class)($auditor, $this->company, $deal, ['loss_reason_id' => LossReason::query()->value('id')]))->toThrow(AuthorizationException::class);
});
