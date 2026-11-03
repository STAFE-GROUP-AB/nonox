<?php

declare(strict_types=1);

use App\Modules\Crm\Actions\CompleteActivity;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\LoseDeal;
use App\Modules\Crm\Actions\WinDeal;
use App\Modules\Crm\Domain\DealNextStep;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Support\CrmTodos;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * "Nästa steg är obligatoriskt" (ADR-043 stage 2): an open deal always has
 * an open activity. The last one is completed only with the next one, is
 * not closed or moved away by an edit, and a deal without one is listed in
 * Att göra.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->account = Crm::prospect($this->company, $this->owner);
    $this->deal = Crm::deal($this->company, $this->owner, $this->account);
    $this->step = Activity::query()->where('deal_id', $this->deal->id)->sole();
});

it('refuses to complete the last open step of an open deal without the next one', function () {
    expect(DealNextStep::isLastStep($this->step))->toBeTrue()
        ->and(fn () => app(CompleteActivity::class)($this->owner, $this->company, $this->step))->toThrow(ValidationException::class, 'behöver ett nästa steg')
        ->and($this->step->fresh()?->isOpen())->toBeTrue();
});

it('completes the last step together with the next one, linked to the deal, in one go', function () {
    app(CompleteActivity::class)($this->owner, $this->company, $this->step, ['call_outcome' => 'REACHED', 'next' => ['type' => 'MEETING', 'subject' => 'Möte', 'due_at' => '2026-06-20T13:00']]);

    $open = Activity::query()->where('deal_id', $this->deal->id)->whereNull('done_at')->sole();
    expect($this->step->fresh()?->isOpen())->toBeFalse()
        ->and($open->type->value)->toBe('MEETING')
        ->and($open->account_id)->toBe($this->account->id)
        ->and(DealNextStep::missing()->count())->toBe(0);
});

it('rolls back the completion when the next step is not valid, and refuses a note as the next step', function () {
    expect(fn () => app(CompleteActivity::class)($this->owner, $this->company, $this->step, ['next' => ['type' => 'TASK', 'due_at' => 'inte ett datum']]))->toThrow(ValidationException::class)
        ->and(fn () => app(CompleteActivity::class)($this->owner, $this->company, $this->step, ['next' => ['type' => 'NOTE']]))->toThrow(ValidationException::class)
        ->and($this->step->fresh()?->isOpen())->toBeTrue()
        ->and(Activity::query()->where('deal_id', $this->deal->id)->count())->toBe(1);
});

it('completes a step freely while the deal has another one, or once the deal is won or lost', function () {
    $second = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'deal_id' => $this->deal->id, 'type' => 'TASK', 'subject' => 'Skicka offert']);

    app(CompleteActivity::class)($this->owner, $this->company, $this->step);
    expect(DealNextStep::isLastStep($second->fresh()))->toBeTrue();

    app(WinDeal::class)($this->owner, $this->company, $this->deal);
    app(CompleteActivity::class)($this->owner, $this->company, $second->fresh());

    expect(Activity::query()->whereNull('done_at')->count())->toBe(0);
});

it('does not let an edit close the last step, turn it into a note or move it off the deal', function () {
    $log = app(LogActivity::class);

    expect(fn () => $log($this->owner, $this->company, ['done' => true], $this->step))->toThrow(ValidationException::class, 'behöver ett nästa steg')
        ->and(fn () => $log($this->owner, $this->company, ['type' => 'NOTE'], $this->step))->toThrow(ValidationException::class, 'behöver ett nästa steg')
        ->and(fn () => $log($this->owner, $this->company, ['deal_id' => null], $this->step))->toThrow(ValidationException::class, 'behöver ett nästa steg');

    // Other changes are fine.
    $saved = $log($this->owner, $this->company, ['subject' => 'Ring på fredag', 'due_at' => '2026-06-19T09:00'], $this->step);
    expect($saved->subject)->toBe('Ring på fredag')->and($saved->isOpen())->toBeTrue();
});

it('refuses a step on a deal of another account', function () {
    $other = Crm::prospect($this->company, $this->owner, 'Annat AB');

    expect(fn () => app(LogActivity::class)($this->owner, $this->company, ['account_id' => $other->id, 'deal_id' => $this->deal->id, 'type' => 'TASK']))->toThrow(ValidationException::class, 'annan kund');
});

it('lists open deals without a next step in Att göra: the owner\'s own for a seller, all for a manager', function () {
    $seller = Bookkeeping::member($this->company, 'sales');
    $sellersDeal = Crm::deal($this->company, $this->owner, $this->account, 'Säljarens', extra: ['owner_id' => $seller->id]);
    // Data from elsewhere: the steps closed behind the rule's back.
    Activity::query()->whereIn('deal_id', [$this->deal->id, $sellersDeal->id])->update(['done_at' => now()]);
    $lost = Crm::deal($this->company, $this->owner, $this->account, 'Förlorad');
    app(LoseDeal::class)($this->owner, $this->company, $lost, ['loss_reason_id' => LossReason::query()->value('id')]);
    Activity::query()->where('deal_id', $lost->id)->update(['done_at' => now()]);

    $titles = fn ($user): array => collect(app(CrmTodos::class)->todos($user, $this->company, CarbonImmutable::today()))->pluck('title')->all();

    expect(DealNextStep::missing()->count())->toBe(2)
        ->and($titles($this->owner))->toContain('2 affärer saknar nästa steg')
        ->and($titles($seller))->toContain('1 affär saknar nästa steg');
});
