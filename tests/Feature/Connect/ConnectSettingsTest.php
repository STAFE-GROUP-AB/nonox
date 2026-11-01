<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Connect\Livewire\ConnectSettingsForm;
use App\Modules\Connect\Support\ConnectSettings;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;

beforeEach(function () {
    Mail::fake();
    Connect::setUp();
});

it('lets an owner limit the company to listed apps, which shuts out the others at once', function () {
    [$owner, $company] = Connect::company();
    $tokens = Connect::tokens($this, $owner, $company);
    [$listed] = Connect::app('Listad app');

    Livewire::actingAs($owner)->test(ConnectSettingsForm::class, ['company' => $company])
        ->set('appPolicy', 'listed')
        ->set('allowedClients', [$listed->getKey()])
        ->set('ipAllowlist', "203.0.113.0/24\n198.51.100.7")
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect(ConnectSettings::for($company->fresh()))->toBe([
        'app_policy' => 'listed',
        'allowed_clients' => [$listed->getKey()],
        'ip_allowlist' => ['203.0.113.0/24', '198.51.100.7'],
    ])->and(AuditLog::query()->where('company_id', $company->id)->where('action', 'module.settings_updated')->exists())->toBeTrue();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withToken($tokens['access_token'])->getJson('/api/v1/company')
        ->assertForbidden()->assertJsonPath('code', 'app_not_allowed');
});

it('refuses an invalid address and anyone who may not manage Connect', function () {
    [$owner, $company] = Connect::company();
    $bookkeeper = Bookkeeping::member($company, 'bookkeeper');

    Livewire::actingAs($owner)->test(ConnectSettingsForm::class, ['company' => $company])
        ->set('ipAllowlist', 'inte-en-adress')
        ->call('save')
        ->assertHasErrors('ip_allowlist');

    Livewire::actingAs($bookkeeper)->test(ConnectSettingsForm::class, ['company' => $company])->assertForbidden();
});
