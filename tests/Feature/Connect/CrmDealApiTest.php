<?php

declare(strict_types=1);

use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\LossReason;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Tests\Support\Connect;
use Tests\Support\Crm;

/*
 * CRM stage 2 over the API (ADR-043): deals read with crm:view and created,
 * changed, moved, won and lost with crm:manage through the same Actions as
 * the screens; pipelines, stages and loss reasons read only; the next step
 * required on a new deal and on completing a deal's last step.
 */

beforeEach(function () {
    Connect::setUp();
    [$this->owner, $this->company] = Connect::company();
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->account = Crm::prospect($this->company, $this->owner, 'Prospektet AB');
    $this->key = Connect::apiKey($this->owner, $this->company, ['crm:view', 'crm:manage']);
});

it('reads pipelines with their stages, the stages and the loss reasons with crm:view', function () {
    $view = Connect::apiKey($this->owner, $this->company, ['crm:view']);
    CurrentCompany::set($this->company);
    $pipeline = Crm::pipeline();

    $this->withToken($view)->getJson('/api/v1/crm/pipelines')->assertOk()
        ->assertJsonPath('data.0.name', 'Försäljning')
        ->assertJsonPath('data.0.is_default', true)
        ->assertJsonCount(4, 'data.0.stages')
        ->assertJsonPath('data.0.stages.2.probability', 50);
    $this->withToken($view)->getJson("/api/v1/crm/pipelines/{$pipeline->id}")->assertOk()->assertJsonPath('data.stages.0.name', 'Ny kontakt');
    $this->withToken($view)->getJson("/api/v1/crm/stages?pipeline_id={$pipeline->id}")->assertOk()->assertJsonCount(4, 'data');
    $this->withToken($view)->getJson('/api/v1/crm/loss-reasons')->assertOk()->assertJsonCount(6, 'data');
});

it('creates a deal only with its next step, idempotently, and reads it back', function () {
    $body = ['account_id' => $this->account->id, 'title' => 'Ramavtal', 'value_ore' => 12_500_000, 'expected_close_date' => '2026-12-31'];

    $this->withToken($this->key)->withHeader('Idempotency-Key', 'affar-0')->postJson('/api/v1/crm/deals', $body)
        ->assertUnprocessable()->assertSee('En ny affär behöver ett nästa steg');

    $created = $this->withToken($this->key)->withHeader('Idempotency-Key', 'affar-1')
        ->postJson('/api/v1/crm/deals', [...$body, 'next_step' => ['type' => 'CALL', 'subject' => 'Ring', 'due_at' => '2026-12-01T09:00']])
        ->assertCreated()
        ->assertJsonPath('data.status', 'OPEN')
        ->assertJsonPath('data.value_ore', 12_500_000)
        ->assertJsonPath('data.expected_close_date', '2026-12-31');
    $id = $created->json('data.id');

    $this->withToken($this->key)->withHeader('Idempotency-Key', 'affar-1')
        ->postJson('/api/v1/crm/deals', [...$body, 'next_step' => ['type' => 'CALL', 'subject' => 'Ring', 'due_at' => '2026-12-01T09:00']])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    CurrentCompany::set($this->company);
    expect(Deal::query()->count())->toBe(1);

    $this->withToken($this->key)->getJson("/api/v1/crm/deals/{$id}")->assertOk()->assertJsonPath('data.title', 'Ramavtal');
    $this->withToken($this->key)->getJson('/api/v1/crm/deals?status=open')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($this->key)->getJson("/api/v1/crm/activities?deal_id={$id}")->assertOk()->assertJsonPath('data.0.deal_id', $id);
});

it('moves, changes, wins and loses deals with PATCH, and changes a lost deal\'s win-back date', function () {
    CurrentCompany::set($this->company);
    $deal = Crm::deal($this->company, $this->owner, $this->account);
    $other = Crm::deal($this->company, $this->owner, $this->account, 'Andra');
    $reason = LossReason::query()->value('id');
    $offert = Crm::stage(2)->id;

    $this->withToken($this->key)->patchJson("/api/v1/crm/deals/{$deal->id}", ['stage_id' => $offert, 'title' => 'Nytt'])->assertOk()
        ->assertJsonPath('data.stage_id', $offert)
        ->assertJsonPath('data.title', 'Nytt');
    $this->withToken($this->key)->patchJson("/api/v1/crm/deals/{$deal->id}", ['status' => 'WON', 'value_ore' => 900_000])->assertOk()
        ->assertJsonPath('data.status', 'WON')
        ->assertJsonPath('data.value_ore', 900_000);
    $this->withToken($this->key)->patchJson("/api/v1/crm/deals/{$deal->id}", ['title' => 'Igen'])->assertUnprocessable();

    $this->withToken($this->key)->patchJson("/api/v1/crm/deals/{$other->id}", ['status' => 'LOST'])->assertUnprocessable();
    $this->withToken($this->key)->patchJson("/api/v1/crm/deals/{$other->id}", ['status' => 'LOST', 'loss_reason_id' => $reason, 'win_back_at' => '2027-03-01'])->assertOk()
        ->assertJsonPath('data.status', 'LOST')
        ->assertJsonPath('data.win_back_at', '2027-03-01');
    $this->withToken($this->key)->patchJson("/api/v1/crm/deals/{$other->id}", ['win_back_at' => null])->assertOk()->assertJsonPath('data.win_back_at', null);
    $this->withToken($this->key)->patchJson("/api/v1/crm/deals/{$other->id}", ['status' => 'OPEN'])->assertUnprocessable();
});

it('completes a deal\'s last step over the API only together with the next one', function () {
    CurrentCompany::set($this->company);
    $deal = Crm::deal($this->company, $this->owner, $this->account);
    $step = Activity::query()->where('deal_id', $deal->id)->sole();

    $this->withToken($this->key)->patchJson("/api/v1/crm/activities/{$step->id}", ['done' => true])->assertUnprocessable();

    $this->withToken($this->key)->patchJson("/api/v1/crm/activities/{$step->id}", ['done' => true, 'call_outcome' => 'REACHED', 'next_step' => ['type' => 'MEETING', 'due_at' => '2026-12-05T10:00']])->assertOk()
        ->assertJsonPath('data.call_outcome', 'REACHED');

    CurrentCompany::set($this->company);
    expect($step->fresh()?->isOpen())->toBeFalse()
        ->and(Activity::query()->where('deal_id', $deal->id)->whereNull('done_at')->sole()->type->value)->toBe('MEETING');
});

it('needs crm:manage to write deals and gives nothing while the module is off', function () {
    $view = Connect::apiKey($this->owner, $this->company, ['crm:view']);

    $this->withToken($view)->withHeader('Idempotency-Key', 'nej-1')->postJson('/api/v1/crm/deals', ['account_id' => $this->account->id])->assertForbidden();

    app(ModuleManager::class)->disable('crm', $this->company, $this->owner, confirmed: true);
    $this->withToken($this->key)->getJson('/api/v1/crm/deals')->assertForbidden();
});
