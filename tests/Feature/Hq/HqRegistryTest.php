<?php

declare(strict_types=1);

use App\Livewire\Hq\Registry;
use App\Models\AuditLog;
use App\Models\RegistryCompany;
use App\Models\RegistryExclusion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Företagsregistret in /hq (ADR-047): only HQ admins, an exclusion after
 * an objection or erasure needs a passkey step, removes the data at once
 * and is audited with ids only.
 */

beforeEach(function () {
    config(['nonox.hq_admins' => ['hq@example.test'], 'app.admin_email' => null]);
    DB::table('company_registry_exclusions')->delete();
    DB::table('company_registry')->delete();
    $this->admin = User::factory()->withCompany()->create(['email' => 'hq@example.test']);
    RegistryCompany::create(['org_number' => '5599996013', 'name' => 'Kodverket Exempel AB', 'phone' => '08-46500401', 'sources' => ['scb-api']]);
});

it('answers 404 to everyone but an HQ admin', function () {
    $this->get(route('hq.registry'))->assertNotFound();

    $member = User::factory()->withCompany()->create();
    $this->actingAs($member)->get(route('hq.registry'))->assertNotFound();
    Livewire::test(Registry::class)->assertStatus(404);

    $this->actingAs($this->admin)->get(route('hq.registry'))->assertOk()->assertSee('Undanta uppgifter')->assertSee('href="'.route('hq.registry').'"', false);
});

it('excludes after a passkey step, removes the data at once and audits ids only', function () {
    $this->actingAs($this->admin);

    Livewire::test(Registry::class)->set('orgNumber', '559999-6013')->set('field', 'PHONE')->call('exclude', null)->assertHasErrors('passkey');
    expect(RegistryExclusion::query()->count())->toBe(0);

    session()->put('hq.step_up', ['user_id' => $this->admin->id, 'at' => now()->subMinute()->getTimestamp()]);
    Livewire::test(Registry::class)->set('orgNumber', '559999-6013')->set('field', 'PHONE')->call('exclude', null)->assertHasNoErrors()
        ->assertSee('559999-6013');

    $audit = AuditLog::query()->where('action', 'hq.registry_exclusion_added')->sole();
    expect(RegistryCompany::query()->where('org_number', '5599996013')->value('phone'))->toBeNull()
        ->and($audit->company_id)->toBeNull()
        ->and($audit->payload)->toEqual(['exclusion_id' => RegistryExclusion::query()->value('id'), 'field' => 'PHONE', 'reason' => 'OBJECTION']);

    Livewire::test(Registry::class)->call('remove', null, (int) RegistryExclusion::query()->value('id'))->assertHasNoErrors();
    expect(RegistryExclusion::query()->count())->toBe(0);
});
