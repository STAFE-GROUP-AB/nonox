<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Crm\Actions\CreateWinBackTasks;
use App\Modules\Crm\Actions\LoseDeal;
use App\Modules\Crm\Actions\SetAccountStatus;
use App\Modules\Crm\Actions\SetWinBack;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\LossReason;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * "Vinn tillbaka" (ADR-043 stage 2): on the win-back date of a lost deal or
 * a lost account, crm:win-back makes one task for the owner, however often
 * it runs.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->seller = Bookkeeping::member($this->company, 'sales');
    $this->account = Crm::prospect($this->company, $this->owner, 'Prospektet AB');
    $deal = Crm::deal($this->company, $this->owner, $this->account, 'Ramavtal', extra: ['owner_id' => $this->seller->id]);
    $this->deal = app(LoseDeal::class)($this->owner, $this->company, $deal, ['loss_reason_id' => LossReason::query()->value('id'), 'win_back_at' => '2026-07-01']);
});

it('makes nothing before the date, one task for the deal\'s owner on it, and none the second time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-30 06:00:00'));
    $this->artisan('crm:win-back')->assertSuccessful();
    expect(Activity::query()->where('subject', 'like', 'Vinn tillbaka%')->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-07-01 05:40:00'));
    $this->artisan('crm:win-back')->expectsOutputToContain('1 win-back tasks created')->assertSuccessful();
    $this->artisan('crm:win-back')->expectsOutputToContain('0 win-back tasks created')->assertSuccessful();
    app(CreateWinBackTasks::class)($this->company);

    $task = Activity::query()->where('subject', 'like', 'Vinn tillbaka%')->sole();
    expect($task->subject)->toBe('Vinn tillbaka: Ramavtal')
        ->and($task->type->value)->toBe('TASK')
        ->and($task->assigned_to)->toBe($this->seller->id)
        ->and($task->deal_id)->toBe($this->deal->id)
        ->and($task->due_at?->setTimezone('Europe/Stockholm')->format('Y-m-d H:i'))->toBe('2026-07-01 09:00')
        ->and($this->deal->fresh()?->win_back_task_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'crm.win_back_task')->sole()->payload)->toEqual(['deal_id' => $this->deal->id, 'account_id' => $this->account->id]);
});

it('catches up on a date that passed while it did not run, and makes a new task when a new date is set', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-05 06:00:00'));
    expect(app(CreateWinBackTasks::class)($this->company))->toBe(1);

    app(SetWinBack::class)($this->owner, $this->company, $this->deal->fresh(), '2026-08-01');
    $this->travelTo(CarbonImmutable::parse('2026-08-01 06:00:00'));

    expect(app(CreateWinBackTasks::class)($this->company))->toBe(1)
        ->and(Activity::query()->where('deal_id', $this->deal->id)->where('type', 'TASK')->where('subject', 'like', 'Vinn tillbaka%')->count())->toBe(2);
});

it('makes the task for a lost account too, for its owner, and not once the account is no longer lost', function () {
    $other = Crm::prospect($this->company, $this->owner, 'Annat AB', ['owner_id' => $this->seller->id]);
    $lost = app(SetAccountStatus::class)($this->owner, $this->company, $this->account, 'LOST', 'Valde annan');
    app(SetAccountStatus::class)($this->owner, $this->company, $other, 'LOST', 'Valde annan');
    app(SetAccountStatus::class)($this->owner, $this->company, $other->fresh(), 'PROSPECT');
    app(SetWinBack::class)($this->owner, $this->company, $lost, '2026-07-01');

    $this->travelTo(CarbonImmutable::parse('2026-07-01 06:00:00'));
    expect(app(CreateWinBackTasks::class)($this->company))->toBe(2);

    $task = Activity::query()->where('subject', 'Vinn tillbaka kunden')->sole();
    expect($task->account_id)->toBe($this->account->id)
        ->and($task->deal_id)->toBeNull()
        ->and($task->assigned_to)->toBe($this->owner->id)
        ->and(app(CreateWinBackTasks::class)($this->company))->toBe(0);
});

it('gives the task to the company\'s owner when the deal\'s owner has left, and none for a cleared date', function () {
    $this->company->removeUser($this->seller);
    $cleared = Crm::deal($this->company, $this->owner, $this->account, 'Ingen påminnelse');
    app(LoseDeal::class)($this->owner, $this->company, $cleared, ['loss_reason_id' => LossReason::query()->value('id'), 'win_back_at' => null]);

    $this->travelTo(CarbonImmutable::parse('2027-01-01 06:00:00'));
    expect(app(CreateWinBackTasks::class)($this->company))->toBe(1)
        ->and(Activity::query()->where('subject', 'like', 'Vinn tillbaka%')->sole()->assigned_to)->toBe($this->owner->id);
});

it('leaves companies with CRM off alone', function () {
    app(ModuleManager::class)->disable('crm', $this->company, $this->owner, true);
    $this->travelTo(CarbonImmutable::parse('2026-07-01 06:00:00'));

    $this->artisan('crm:win-back')->assertSuccessful();

    expect(Activity::query()->where('subject', 'like', 'Vinn tillbaka%')->count())->toBe(0);
});
