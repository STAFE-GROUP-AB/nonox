<?php

declare(strict_types=1);

use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\Activity;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Tests\Support\Connect;
use Tests\Support\Crm;

/*
 * CRM over the API (ADR-043): accounts, people and activities with
 * crm:view and crm:manage, through the same Actions as the screens, and
 * nothing while the module is off.
 */

beforeEach(function () {
    Connect::setUp();
    [$this->owner, $this->company] = Connect::company();
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB');
});

it('reads accounts, people and activities with crm:view', function () {
    $account = Crm::accountFor($this->customer);
    $person = Crm::person($this->company, $this->owner, 'Anna Andersson', ['account_id' => $account->id, 'role' => 'DECISION_MAKER']);
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'person_id' => $person->id, 'type' => 'MEETING', 'subject' => 'Visning', 'due_at' => '2026-11-20T10:00']);
    $view = Connect::apiKey($this->owner, $this->company, ['crm:view']);

    $this->withToken($view)->getJson('/api/v1/crm/accounts')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Kontorshuset AB')
        ->assertJsonPath('data.0.customer_id', $this->customer->id)
        ->assertJsonPath('data.0.status', 'PROSPECT')
        ->assertJsonPath('data.0.people.0.role', 'DECISION_MAKER');
    $this->withToken($view)->getJson("/api/v1/crm/accounts/{$account->id}")->assertOk()->assertJsonPath('data.id', $account->id);
    $this->withToken($view)->getJson("/api/v1/crm/people?account_id={$account->id}")->assertOk()
        ->assertJsonPath('data.0.name', 'Anna Andersson')
        ->assertJsonPath('data.0.legal_basis', 'LEGITIMATE_INTEREST')
        ->assertJsonPath('data.0.accounts.0.account_id', $account->id);
    $this->withToken($view)->getJson("/api/v1/crm/people/{$person->id}")->assertOk();
    $this->withToken($view)->getJson('/api/v1/crm/activities?open=1')->assertOk()
        ->assertJsonPath('data.0.type', 'MEETING')
        ->assertJsonPath('data.0.done_at', null);
    CurrentCompany::set($this->company);
    $activity = Activity::query()->firstOrFail();
    $this->withToken($view)->getJson("/api/v1/crm/activities/{$activity->id}")->assertOk()->assertJsonPath('data.subject', 'Visning');

    $this->withToken($view)->withHeader('Idempotency-Key', 'nej-1')->postJson('/api/v1/crm/accounts', ['name' => 'X AB'])->assertForbidden();
});

it('creates and changes accounts, people and activities with crm:manage', function () {
    $key = Connect::apiKey($this->owner, $this->company, ['crm:view', 'crm:manage']);

    $account = $this->withToken($key)->withHeader('Idempotency-Key', 'konto-1')
        ->postJson('/api/v1/crm/accounts', ['name' => 'Prospektet AB', 'org_number' => '556036-0793', 'city' => 'Lund', 'tags' => ['varm']])
        ->assertCreated()
        ->assertJsonPath('data.status', 'PROSPECT')
        ->assertJsonPath('data.tags', ['varm'])
        ->json('data.id');

    $this->withToken($key)->patchJson("/api/v1/crm/accounts/{$account}", ['source' => 'Mässa', 'status' => 'LOST', 'lost_reason' => 'För dyrt'])->assertOk()
        ->assertJsonPath('data.source', 'Mässa')
        ->assertJsonPath('data.city', 'Lund')
        ->assertJsonPath('data.status', 'LOST')
        ->assertJsonPath('data.lost_reason', 'För dyrt');
    $this->withToken($key)->patchJson("/api/v1/crm/accounts/{$account}", ['status' => 'LOST'])->assertUnprocessable();

    $person = $this->withToken($key)->withHeader('Idempotency-Key', 'person-1')
        ->postJson('/api/v1/crm/people', ['name' => 'Bo Berg', 'legal_basis' => 'AGREEMENT', 'account_id' => $account, 'role' => 'USER'])
        ->assertCreated()
        ->assertJsonPath('data.accounts.0.role', 'USER')
        ->json('data.id');
    $this->withToken($key)->withHeader('Idempotency-Key', 'person-2')->postJson('/api/v1/crm/people', ['name' => 'Utan grund'])->assertUnprocessable();
    $this->withToken($key)->patchJson("/api/v1/crm/people/{$person}", ['title' => 'Inköpschef'])->assertOk()
        ->assertJsonPath('data.title', 'Inköpschef')
        ->assertJsonPath('data.legal_basis', 'AGREEMENT');

    $activity = $this->withToken($key)->withHeader('Idempotency-Key', 'aktivitet-1')
        ->postJson('/api/v1/crm/activities', ['account_id' => $account, 'person_id' => $person, 'type' => 'CALL', 'subject' => 'Ring Bo', 'due_at' => '2026-11-20T09:00'])
        ->assertCreated()
        ->assertJsonPath('data.done_at', null)
        ->json('data.id');
    $this->withToken($key)->patchJson("/api/v1/crm/activities/{$activity}", ['done' => true, 'call_outcome' => 'REACHED'])->assertOk()
        ->assertJsonPath('data.call_outcome', 'REACHED')
        ->assertJsonPath('data.subject', 'Ring Bo');
    CurrentCompany::set($this->company);
    expect(Activity::query()->whereKey($activity)->whereNotNull('done_at')->exists())->toBeTrue();
});

it('answers 403 for every CRM endpoint while the module is off', function () {
    $key = Connect::apiKey($this->owner, $this->company, ['crm:view', 'crm:manage']);
    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, confirmed: true);
    CurrentCompany::forget();

    $this->withToken($key)->getJson('/api/v1/crm/accounts')->assertForbidden();
    $this->withToken($key)->withHeader('Idempotency-Key', 'av-1')->postJson('/api/v1/crm/accounts', ['name' => 'X AB'])->assertForbidden();
});
