<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Models\SalesDocument;
use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Modules\Crm\Actions\SendForSigning;
use App\Modules\Crm\Mail\SigningRequestMail;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\SigningRequest;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;
use Tests\Support\Crm;

/*
 * CRM stage 3 over the API (ADR-046, ADR-032): send a quote or an agreement
 * for signing (the link itself is never returned), read the status, the
 * agreement register, and the webhook quote.signed without personal data.
 */

beforeEach(function () {
    Connect::setUp();
    Connect::fakeDns(['hooks.example.com' => ['93.184.215.14']]);
    Http::fake();
    Storage::fake('local');
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Connect::company();
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB');
    $this->account = Crm::accountFor($this->customer);
    $this->quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $this->customer->id, 'document_date' => '2026-06-15', 'rows' => [['description' => 'Tjänst', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500]]]);
    $this->key = Connect::apiKey($this->owner, $this->company, ['invoices:view', 'invoices:create', 'invoices:send', 'crm:view', 'crm:manage']);
});

it('sends a quote for signing over the API without returning the link, and shows its status', function () {
    $response = $this->withToken($this->key)->withHeader('Idempotency-Key', 'skicka-1')
        ->postJson("/api/v1/quotes/{$this->quote->id}/signing", ['email' => 'anna@kontorshuset.test', 'create_invoice' => true]);

    $response->assertCreated()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.create_invoice', true)->assertJsonPath('data.events.0.event', 'SENT');
    expect($response->getContent())->not->toContain('/signera/')->not->toContain('token');
    Mail::assertQueued(SigningRequestMail::class, 1);

    $this->withToken($this->key)->getJson("/api/v1/quotes/{$this->quote->id}/signing")->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.signer_ip');
});

it('needs invoices:send to send a quote for signing', function () {
    $key = Connect::apiKey($this->owner, $this->company, ['invoices:view', 'invoices:create']);

    $this->withToken($key)->withHeader('Idempotency-Key', 'skicka-2')->postJson("/api/v1/quotes/{$this->quote->id}/signing", ['email' => 'a@b.test'])->assertForbidden();
});

it('creates, lists, changes and terminates agreements, and sends one for signing', function () {
    $created = $this->withToken($this->key)->withHeader('Idempotency-Key', 'avtal-1')->postJson('/api/v1/crm/agreements', [
        'account_id' => $this->account->id, 'title' => 'Support 2026', 'kind' => 'SUPPORT', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'notice_months' => 3, 'auto_renew' => true, 'renewal_months' => 12, 'value_ore' => 150000, 'value_period' => 'MONTH',
    ])->assertCreated()->assertJsonPath('data.notice_deadline', '2026-09-30')->assertJsonPath('data.status', 'ACTIVE');
    $id = $created->json('data.id');

    $this->withToken($this->key)->getJson('/api/v1/crm/agreements?ending_within_days=200')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($this->key)->getJson('/api/v1/crm/agreements?ending_within_days=30')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($this->key)->patchJson("/api/v1/crm/agreements/{$id}", ['title' => 'Support 2026–2027'])->assertOk()->assertJsonPath('data.title', 'Support 2026–2027')->assertJsonPath('data.value_ore', 150000);
    $this->withToken($this->key)->patchJson("/api/v1/crm/agreements/{$id}", ['terminated_on' => '2026-06-15'])->assertOk()->assertJsonPath('data.status', 'TERMINATED');
    $this->withToken($this->key)->patchJson("/api/v1/crm/agreements/{$id}", ['end_date' => '2025-01-01'])->assertUnprocessable();

    $this->withToken($this->key)->withHeader('Idempotency-Key', 'avtal-signera')->postJson("/api/v1/crm/agreements/{$id}/signing", ['email' => 'anna@kontorshuset.test'])->assertCreated()->assertJsonPath('data.document_type', 'AGREEMENT');
    $this->withToken($this->key)->getJson("/api/v1/crm/agreements/{$id}/signing")->assertOk()->assertJsonCount(1, 'data');

    CurrentCompany::set($this->company);
    expect(Agreement::query()->findOrFail($id)->terminated_on?->toDateString())->toBe('2026-06-15');
});

it('sends quote.signed to the company\'s webhooks with ids and the hash, never the signer', function () {
    Connect::webhook($this->owner, $this->company, ['quote.signed']);
    CurrentCompany::set($this->company);
    $this->withToken($this->key)->withHeader('Idempotency-Key', 'skicka-3')->postJson("/api/v1/quotes/{$this->quote->id}/signing", ['email' => 'anna@kontorshuset.test'])->assertCreated();
    CurrentCompany::set($this->company);
    $request = SigningRequest::query()->sole();
    $token = null;
    Mail::assertQueued(SigningRequestMail::class, function (SigningRequestMail $mail) use (&$token): bool {
        $token = basename((string) parse_url($mail->url, PHP_URL_PATH));

        return true;
    });

    $this->post("/signera/{$token}", ['name' => 'Anna Andersson', 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => 'Anna Andersson', 'document_sha256' => $request->document_sha256])->assertOk();

    $delivery = CompanyScope::bypass(fn () => ConnectWebhookDelivery::query()->where('event_type', 'quote.signed')->sole());
    $body = json_decode($delivery->body, true);
    expect($body['data'])->toMatchArray(['signing_request_id' => $request->id, 'quote_id' => $this->quote->id, 'signature_method' => 'TYPED', 'document_sha256' => $request->document_sha256])
        ->and($delivery->body)->not->toContain('Anna')->not->toContain('kontorshuset.test')->not->toContain('127.0.0.1');
});

it('renders the signing mail in the company\'s name with a Swedish preview text and the link', function () {
    $owner = Bookkeeping::member($this->company, 'admin');
    CurrentCompany::set($this->company);
    $sent = app(SendForSigning::class)($owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);

    $mail = new SigningRequestMail($sent['request']->id, $this->company->id, $sent['url']);
    $html = $mail->render();

    expect($html)->toContain('har skickat Offert '.$this->quote->number.' för signering')
        ->and($html)->toContain($sent['url'])
        ->and($html)->toContain('Läs och signera');
    $mail->assertHasSubject('Offert '.$this->quote->number.' från '.($this->company->legal_name ?: $this->company->name).' att signera');
});
