<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Connect\Actions\CreateWebhook;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Livewire\WebhooksManager;
use App\Modules\Connect\Mail\WebhookCreatedMail;
use App\Modules\Connect\Mail\WebhookDisabledMail;
use App\Modules\Connect\Models\ConnectWebhook;
use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Modules\Connect\Webhooks\UnsafeWebhookAddress;
use App\Modules\Connect\Webhooks\WebhookAddress;
use App\Modules\Connect\Webhooks\WebhookDeliveries;
use App\Modules\Connect\Webhooks\WebhookSignature;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;

/*
 * Webhooks (ADR-032): subscriptions under Anslutningar, signed deliveries
 * sent after commit, retries with backoff, automatic disabling and the
 * defence against server-side request forgery.
 */

const WEBHOOK_HOST_IP = '93.184.215.14';

beforeEach(function () {
    Mail::fake();
    Connect::setUp();
    Connect::fakeDns([
        'hooks.example.com' => [WEBHOOK_HOST_IP],
        'internal.example.com' => ['10.0.0.5'],
        'mixed.example.com' => [WEBHOOK_HOST_IP, '127.0.0.1'],
        'metadata.example.com' => ['169.254.169.254'],
        'v6loop.example.com' => ['::1'],
    ]);
});

/** @return array<int, array<string, mixed>> the deliveries of a company, oldest first */
function deliveriesOf(int $companyId): array
{
    return CompanyScope::bypass(fn () => ConnectWebhookDelivery::query()->where('company_id', $companyId)->orderBy('created_at')->get()->toArray());
}

it('adds a webhook only with a fresh passkey, shows the secret once, stores it encrypted and mails the owner', function () {
    [$owner, $company] = Connect::company();
    $authenticator = Connect::passkey($owner);

    $component = Livewire::actingAs($owner)->test(WebhooksManager::class)
        ->set('url', 'https://hooks.example.com/nonox')
        ->set('events', ['voucher.posted', 'invoice.paid'])
        ->call('createWebhook', null)
        ->assertHasErrors('passkey');

    $options = null;
    $component->call('passkeyOptions')->assertReturned(function (mixed $data) use (&$options): bool {
        $options = $data;

        return true;
    });

    $component->call('createWebhook', $authenticator->authenticate((array) $options, $owner->getPasskeyUserHandle()))->assertHasNoErrors()->assertDispatched('toast');

    $secret = (string) $component->get('newSecret');
    $webhook = CurrentCompany::run($company, fn () => ConnectWebhook::query()->sole());
    $raw = DB::table('connect_webhooks')->where('id', $webhook->id)->value('secret');

    expect($secret)->toStartWith('nnxwh_')
        ->and($webhook->secret)->toBe($secret)
        ->and($raw)->not->toContain($secret)
        ->and($webhook->events)->toBe(['voucher.posted', 'invoice.paid'])
        ->and(AuditLog::query()->where('action', 'connect.webhook.created')->where('company_id', $company->id)->exists())->toBeTrue();

    Mail::assertSent(WebhookCreatedMail::class, fn (WebhookCreatedMail $mail): bool => $mail->hasTo($owner->email));

    $component->call('forgetSecret')->assertSet('newSecret', null);
});

it('refuses addresses that are not https or lead to internal networks', function (string $url) {
    expect(fn () => app(WebhookAddress::class)->target($url))->toThrow(UnsafeWebhookAddress::class);
})->with([
    'plain http' => 'http://hooks.example.com/nonox',
    'loopback' => 'https://127.0.0.1/hook',
    'private' => 'https://internal.example.com/hook',
    'one of several addresses private' => 'https://mixed.example.com/hook',
    'cloud metadata' => 'https://metadata.example.com/hook',
    'ipv6 loopback' => 'https://v6loop.example.com/hook',
    'ipv6 literal unique local' => 'https://[fd00::1]/hook',
    'ipv4-mapped ipv6' => 'https://[::ffff:127.0.0.1]/hook',
    '6to4 carrying a private address' => 'https://[2002:0a00:0001::1]/hook',
    'carrier-grade nat' => 'https://100.64.0.10/hook',
    'multicast' => 'https://224.0.0.1/hook',
    'localhost' => 'https://localhost/hook',
    'credentials in the address' => 'https://user:pass@hooks.example.com/hook',
    'low port' => 'https://hooks.example.com:22/hook',
    'unresolvable' => 'https://nowhere.example.com/hook',
]);

it('classifies public and internal addresses', function (string $address, bool $public) {
    expect(WebhookAddress::isPublic($address))->toBe($public);
})->with([
    ['93.184.215.14', true],
    ['2606:4700:4700::1111', true],
    ['10.1.2.3', false],
    ['172.16.0.1', false],
    ['192.168.1.1', false],
    ['127.0.0.1', false],
    ['169.254.169.254', false],
    ['100.64.0.1', false],
    ['0.0.0.0', false],
    ['255.255.255.255', false],
    ['::1', false],
    ['fe80::1', false],
    ['fc00::1', false],
    ['ff02::1', false],
    ['::ffff:10.0.0.1', false],
    ['64:ff9b::a00:1', false],
    ['not-an-ip', false],
]);

it('signs a delivery so that the receiver can verify it, and refuses an old or altered one', function () {
    $body = '{"id":"x","type":"voucher.posted"}';
    $header = WebhookSignature::header('hemlig', 1_772_444_713, $body);

    expect($header)->toStartWith('t=1772444713,v1=')
        ->and(WebhookSignature::verify('hemlig', $header, $body, 1_772_444_713 + 60))->toBeTrue()
        ->and(WebhookSignature::verify('hemlig', $header, $body.' ', 1_772_444_713))->toBeFalse()
        ->and(WebhookSignature::verify('annan', $header, $body, 1_772_444_713))->toBeFalse()
        ->and(WebhookSignature::verify('hemlig', $header, $body, 1_772_444_713 + 301))->toBeFalse();
});

it('sends voucher.posted after the posting commits, signed, with the event id in header and body', function () {
    Http::fake(['https://hooks.example.com/*' => Http::response('', 204)]);
    [$owner, $company] = Connect::company();
    Connect::webhook($owner, $company, ['voucher.posted']);

    $voucher = Bookkeeping::post($owner, $company, 125000);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($voucher, $company): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://hooks.example.com/nonox'
            && $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/json')
            && WebhookSignature::verify('nnxwh_testhemlighet', $request->header('Nonox-Signature')[0], $request->body())
            && $request->header('Nonox-Event-Id')[0] === $body['id']
            && $body['type'] === 'voucher.posted'
            && $body['company_id'] === $company->id
            && $body['data']['voucher_id'] === $voucher->id
            && $body['data']['reference'] === 'A1'
            && $body['data']['amount_ore'] === 125000;
    });

    $delivery = deliveriesOf($company->id)[0];
    expect($delivery['status'])->toBe('delivered')
        ->and($delivery['attempts'])->toBe(1)
        ->and($delivery['last_status_code'])->toBe(204);
});

it('announces nothing that was rolled back', function () {
    Http::fake();
    [$owner, $company] = Connect::company();
    Connect::webhook($owner, $company, ['voucher.posted']);

    try {
        DB::transaction(function () use ($owner, $company): void {
            Bookkeeping::post($owner, $company);

            throw new RuntimeException('Avbruten');
        });
    } catch (RuntimeException) {
    }

    Http::assertNothingSent();
    expect(deliveriesOf($company->id))->toBe([]);
});

it('sends only to subscribers of the event, only in their own company', function () {
    Http::fake(['*' => Http::response('', 200)]);
    [$ownerA, $a] = Connect::company();
    [$ownerB, $b] = Connect::company();
    Connect::webhook($ownerA, $a, ['invoice.paid']);
    Connect::webhook($ownerB, $b, ['voucher.posted'], 'https://hooks.example.com/b');

    Bookkeeping::post($ownerA, $a);

    Http::assertNothingSent();
    expect(deliveriesOf($a->id))->toBe([])->and(deliveriesOf($b->id))->toBe([]);
});

it('stops sending when the creator no longer may see the events', function () {
    Http::fake();
    [$owner, $company] = Connect::company();
    $admin = Bookkeeping::member($company, 'admin');
    Connect::webhook($admin, $company, ['voucher.posted']);

    $company->users()->updateExistingPivot($admin->id, ['role' => 'auditor']);
    Bookkeeping::post($owner, $company);

    Http::assertNothingSent();
});

it('retries with backoff, then turns the webhook off and mails the owner', function () {
    Http::fake(['*' => Http::response('nej', 500)]);
    [$owner, $company] = Connect::company();
    $webhook = Connect::webhook($owner, $company, ['voucher.posted']);
    Connect::webhook($owner, $company, ['voucher.posted'], 'https://hooks.example.com/other');

    Bookkeeping::post($owner, $company);
    Bookkeeping::post($owner, $company);

    $first = collect(deliveriesOf($company->id))->firstWhere('webhook_id', $webhook->id);
    expect($first['status'])->toBe('pending')
        ->and($first['attempts'])->toBe(1)
        ->and($first['last_status_code'])->toBe(500)
        ->and($first['last_error'])->toBe('http_500');

    // Not due yet: the command leaves it alone.
    $this->artisan('connect:webhooks')->assertSuccessful();
    expect(CompanyScope::bypass(fn () => ConnectWebhookDelivery::query()->findOrFail($first['id']))->attempts)->toBe(1);

    $elapsed = 0;

    foreach (WebhookDeliveries::RETRY_DELAYS as $delay) {
        $elapsed += $delay;
        $this->travel($elapsed + 1)->seconds();
        $this->artisan('connect:webhooks')->assertSuccessful();
        $this->travelBack();
    }

    $delivery = CompanyScope::bypass(fn () => ConnectWebhookDelivery::query()->findOrFail($first['id']));
    expect($delivery->status)->toBe('failed')
        ->and($delivery->attempts)->toBe(8)
        ->and($elapsed)->toBeGreaterThan(22 * 3600)->toBeLessThan(25 * 3600)
        ->and($webhook->refresh()->isActive())->toBeFalse()
        ->and($webhook->disabled_reason)->toBe('failing')
        ->and(CompanyScope::bypass(fn () => ConnectWebhookDelivery::query()->where('webhook_id', $webhook->id)->where('status', 'pending')->count()))->toBe(0);

    Mail::assertSent(WebhookDisabledMail::class, fn (WebhookDisabledMail $mail): bool => $mail->hasTo($owner->email) && $mail->lastStatusCode === 500);
});

it('checks the address again before every attempt and never follows DNS to an internal address', function () {
    Http::fake();
    [$owner, $company] = Connect::company();
    Connect::webhook($owner, $company, ['voucher.posted']);
    Connect::fakeDns(['hooks.example.com' => ['127.0.0.1']]);

    Bookkeeping::post($owner, $company);

    Http::assertNothingSent();
    expect(deliveriesOf($company->id)[0]['last_error'])->toBe('address:private_address');
});

it('does not follow redirects', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data'])]);
    [$owner, $company] = Connect::company();
    Connect::webhook($owner, $company, ['voucher.posted']);

    Bookkeeping::post($owner, $company);

    Http::assertSentCount(1);
    expect(deliveriesOf($company->id)[0]['status'])->toBe('pending')
        ->and(deliveriesOf($company->id)[0]['last_error'])->toBe('http_302');
});

it('turns every webhook off and cancels waiting events when Connect is turned off', function () {
    Http::fake(['*' => Http::response('', 503)]);
    [$owner, $company] = Connect::company();
    $webhook = Connect::webhook($owner, $company, ['voucher.posted']);
    Bookkeeping::post($owner, $company);

    app(ModuleManager::class)->disable(ConnectModule::KEY, $company, $owner, true);

    expect($webhook->refresh()->disabled_reason)->toBe('module_disabled')
        ->and(deliveriesOf($company->id)[0]['status'])->toBe('cancelled');
});

it('lets an owner turn a webhook off and on, change its secret and remove it, but not another company\'s', function () {
    Http::fake();
    [$owner, $company] = Connect::company();
    [$ownerB, $b] = Connect::company();
    $webhook = Connect::webhook($owner, $company, ['voucher.posted']);
    $foreign = Connect::webhook($ownerB, $b, ['voucher.posted']);

    $component = Livewire::actingAs($owner)->test(WebhooksManager::class);

    $component->call('setActive', $webhook->id, false)->assertDispatched('toast');
    expect($webhook->refresh()->disabled_reason)->toBe('disabled_by_user');

    $component->call('setActive', $webhook->id, true);
    expect($webhook->refresh()->isActive())->toBeTrue();

    Livewire::actingAs($owner)->test(WebhooksManager::class)->call('setActive', $foreign->id, false)->assertNotFound();
    Livewire::actingAs($owner)->test(WebhooksManager::class)->call('deleteWebhook', $foreign->id)->assertNotFound();
    Livewire::actingAs($owner)->test(WebhooksManager::class)->call('deleteWebhook', 'inte-ett-id')->assertNotFound();
    expect($foreign->refresh()->isActive())->toBeTrue();

    $authenticator = Connect::passkey($owner);
    $options = null;
    $component->call('rotateSecret', $webhook->id, null)->assertHasErrors('passkey');
    $component->call('passkeyOptions')->assertReturned(function (mixed $data) use (&$options): bool {
        $options = $data;

        return true;
    });
    $component->call('rotateSecret', $webhook->id, $authenticator->authenticate((array) $options, $owner->getPasskeyUserHandle()))->assertHasNoErrors();
    expect($component->get('newSecret'))->toBe($webhook->refresh()->secret)->not->toBe('nnxwh_testhemlighet');

    $component->call('deleteWebhook', $webhook->id);
    expect(CompanyScope::bypass(fn () => ConnectWebhook::query()->whereKey($webhook->id)->exists()))->toBeFalse();
});

it('refuses webhooks to those who may not manage Connect, and events the person may not see', function () {
    [$owner, $company] = Connect::company();
    $bookkeeper = Bookkeeping::member($company, 'bookkeeper');

    Livewire::actingAs($bookkeeper)->test(WebhooksManager::class)->assertForbidden();

    expect(fn () => app(CreateWebhook::class)($bookkeeper, $company, ['url' => 'https://hooks.example.com/x', 'events' => ['voucher.posted']]))
        ->toThrow(AuthorizationException::class);
});

it('offers only the events the person may receive', function () {
    [$owner] = Connect::company();

    Livewire::actingAs($owner)->test(WebhooksManager::class)
        ->assertViewHas('choosableEvents', fn (array $events): bool => array_key_exists('voucher.posted', $events)
            && ! array_key_exists('planning.published', $events)
            && ! array_key_exists('planning.attested', $events));
});
