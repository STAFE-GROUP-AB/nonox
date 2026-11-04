<?php

declare(strict_types=1);

use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Actions\SendInvoiceViaPeppol;
use App\Modules\Peppol\Domain\AccessPointFailed;
use App\Modules\Peppol\Domain\RegistrationStatus;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Drivers\QvaliaAccessPoint;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Support\Peppol\PeppolIdentifier;
use App\Support\Peppol\UblInvoiceWriter;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Peppol;
use Tests\Support\PeppolFixtures;

/*
 * The Qvalia driver against a faked API (Http::fake): never the real one.
 * Paths, headers and the webhook signature follow Qvalia's public
 * documentation (api.qvalia.io, read 2026-11-04).
 */
const QVALIA_SECRET = 'test-signing-secret';

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    config([
        'services.peppol.driver' => 'qvalia',
        'services.peppol.qvalia.url' => 'https://api-test.qvalia.example',
        'services.peppol.qvalia.partner_reg_no' => 'PARTNER1',
        'services.peppol.qvalia.api_key' => 'test-api-key',
        'services.peppol.qvalia.webhook_secret' => QVALIA_SECRET,
    ]);
    [$this->owner, $this->company] = PeppolFixtures::company();
});

/** A webhook request signed as Qvalia signs it. */
function qvaliaWebhook(object $test, array $payload, ?int $time = null, string $secret = QVALIA_SECRET, ?string $eventId = null): TestResponse
{
    $body = (string) json_encode($payload);
    $time ??= time();
    $signature = hash_hmac('sha256', "{$time}.{$body}", $secret);

    return $test->call('POST', '/webhooks/peppol/qvalia', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_QVALIA_SIGNATURE' => "t={$time},v1={$signature}",
        'HTTP_X_QVALIA_EVENT_ID' => $eventId ?? (string) ($payload['eventId'] ?? 'e1'),
    ], $body);
}

it('sends the UBL to the account with the API key and an idempotency key', function () {
    Http::fake(['api-test.qvalia.example/partner/PARTNER1/transaction/SE5599000006/invoices/outgoing*' => Http::response(['status' => 'success', 'data' => ['message' => 'Invoice 1 sent', 'integrationId' => 'int-123']])]);
    Peppol::ready($this->company, $this->owner, 'SE5599000006');
    CurrentCompany::run($this->company, fn () => PeppolParticipant::query()->update(['provider' => 'qvalia']));
    $customer = PeppolFixtures::customer($this->owner, $this->company, ['peppol_id' => '0007:'.PeppolFixtures::BUYER_ORG]);
    $invoice = PeppolFixtures::invoice($this->owner, $this->company, $customer, [['description' => 'Arbete', 'quantity' => '1', 'unit' => 'st', 'price' => '1 000', 'vat_rate' => 2500]]);

    $transmission = app(SendInvoiceViaPeppol::class)($this->owner, $invoice)->fresh();

    expect($transmission->status)->toBe(TransmissionStatus::Sent)
        ->and($transmission->provider_reference)->toBe('int-123')
        ->and($transmission->driver)->toBe('qvalia');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), 'delivery_method=peppol')
        && $request->header('Authorization')[0] === 'test-api-key'
        && $request->header('Idempotency-Key')[0] === $transmission->id
        && str_starts_with($request->header('Content-Type')[0], 'application/xml')
        && str_contains($request->body(), UblInvoiceWriter::CUSTOMIZATION));
});

it('marks a 5xx or 429 as worth retrying and a 422 as not', function () {
    $participant = new PeppolParticipant;
    $participant->provider_account = 'SE5599000006';
    Http::fake(['*' => Http::sequence()->push(['status' => 'error', 'type' => 'E_RATE_LIMITED'], 429)->push(['status' => 'error', 'type' => 'E_VALIDATION'], 422)]);
    $driver = QvaliaAccessPoint::fromConfig();

    $first = null;
    $second = null;

    try {
        $driver->send($participant, '<Invoice/>', false, 'key-1');
    } catch (AccessPointFailed $exception) {
        $first = $exception;
    }

    try {
        $driver->send($participant, '<Invoice/>', false, 'key-1');
    } catch (AccessPointFailed $exception) {
        $second = $exception;
    }

    expect($first?->retryable)->toBeTrue()
        ->and($second?->retryable)->toBeFalse()
        ->and($second?->getMessage())->toContain('E_VALIDATION')
        ->and($second?->getMessage())->not->toContain('test-api-key');
});

it('looks a participant up and checks that it takes BIS Billing 3.0 invoices', function () {
    Http::fake(['api-test.qvalia.example/partner/PARTNER1/peppol/lookup/*' => Http::response(['status' => 'success', 'data' => [
        'exists' => true,
        'matches' => [['participantID' => ['scheme' => 'iso6523-actorid-upis', 'value' => '0007:5566778808'], 'docTypes' => [
            ['scheme' => 'busdox-docid-qns', 'value' => UblInvoiceWriter::DOCTYPE_INVOICE],
        ]]],
    ]])]);

    $result = QvaliaAccessPoint::fromConfig()->lookup(PeppolIdentifier::make('0007', PeppolFixtures::BUYER_ORG) ?? throw new LogicException);

    expect($result->acceptsBilling())->toBeTrue()
        ->and($result->acceptsCreditNote)->toBeFalse();
});

it('registers the company as a receiver of invoices and credit notes', function () {
    Http::fake(['api-test.qvalia.example/partner/PARTNER1/account/SE5599000006/peppol/*' => Http::response(['status' => 'registered', 'peppolId' => '0007:5599000006'])]);
    $participant = new PeppolParticipant(['identifier' => '0007:5599000006']);
    $participant->provider_account = 'SE5599000006';

    expect(QvaliaAccessPoint::fromConfig()->register($participant, $this->company))->toBe(RegistrationStatus::Registered);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request['businessCard']['orgNr'] === '5599000006'
        && count($request['docTypes']) === 2);
});

describe('the webhook', function () {
    beforeEach(function () {
        $this->company->forceFill(['org_number' => '556677-8808', 'vat_number' => 'SE556677880801'])->save();
        Peppol::ready($this->company->fresh(), $this->owner, 'SE5566778808', receive: true);
        CurrentCompany::run($this->company, fn () => PeppolParticipant::query()->update(['provider' => 'qvalia']));
        $this->event = ['eventId' => 'evt-1', 'eventType' => 'new_document', 'accountRegNo' => 'SE5566778808', 'documentType' => 'Invoice', 'direction' => 'incoming', 'integrationId' => 'in-1', 'occurredAt' => now()->toIso8601String()];
    });

    it('fetches a new incoming document into the inbox when the signature is right', function () {
        Http::fake(['api-test.qvalia.example/partner/PARTNER1/transaction/SE5566778808/invoices/incoming*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/peppol/inrikes-25-12-6.xml')), 200, ['Content-Type' => 'application/xml'])]);

        qvaliaWebhook($this, $this->event)->assertOk();

        $message = CurrentCompany::run($this->company, fn () => InboxMessage::query()->sole());
        expect($message->channel)->toBe(Channel::Peppol)
            ->and($message->message_id)->toBe('peppol:in-1');

        // The same event again is acknowledged and not handled twice.
        qvaliaWebhook($this, $this->event)->assertOk();
        Http::assertSentCount(1);
    });

    it('refuses a wrong signature, an old timestamp and an unknown driver', function () {
        Http::fake();

        qvaliaWebhook($this, $this->event, secret: 'wrong')->assertStatus(401);
        qvaliaWebhook($this, $this->event, time: time() - 600)->assertStatus(401);
        $this->postJson('/webhooks/peppol/log', $this->event)->assertNotFound();

        Http::assertNothingSent();
    });

    it('ignores an account no company has, without telling', function () {
        Http::fake();

        qvaliaWebhook($this, [...$this->event, 'eventId' => 'evt-9', 'accountRegNo' => 'SE0000000000'])->assertOk();

        Http::assertNothingSent();
        expect(CurrentCompany::run($this->company, fn () => InboxMessage::query()->count()))->toBe(0);
    });

    it('records the delivery of a document the company sent', function () {
        Http::fake(['*/invoices/outgoing*' => Http::response(['status' => 'success', 'data' => ['integrationId' => 'out-1']])]);
        $customer = PeppolFixtures::customer($this->owner, $this->company, ['peppol_id' => '0007:5599000006']);
        $invoice = PeppolFixtures::invoice($this->owner, $this->company, $customer, [['description' => 'Arbete', 'quantity' => '1', 'unit' => 'st', 'price' => '1 000', 'vat_rate' => 2500]]);
        $transmission = app(SendInvoiceViaPeppol::class)($this->owner, $invoice);

        qvaliaWebhook($this, ['eventId' => 'evt-2', 'eventType' => 'document_delivery', 'accountRegNo' => 'SE5566778808', 'documentType' => 'Invoice', 'direction' => 'outgoing', 'integrationId' => 'out-1', 'status' => ['status' => 'processed']])->assertOk();
        expect($transmission->fresh()->status)->toBe(TransmissionStatus::Delivered);
    });
});

it('links an account, subscribes the webhook and verifies its events with the account\'s own secret', function () {
    config(['services.peppol.qvalia.webhook_secret' => null]);
    Http::fake([
        'api-test.qvalia.example/partner/PARTNER1/account/SE5599000006/webhook/configure' => Http::response(['id' => 'wh-1', 'url' => 'x', 'types' => [], 'accountRegNo' => 'SE5599000006', 'signingSecret' => 'account-secret', 'signatureAlgorithm' => 'hmac-sha256']),
        '*' => Http::response(['status' => 'success', 'data' => []]),
    ]);
    app(ModuleManager::class)->enable('peppol', $this->company, $this->owner);

    $this->artisan('peppol:link', ['company' => $this->company->id, 'account' => 'SE5599000006'])->assertSuccessful();

    $participant = CurrentCompany::run($this->company, fn () => PeppolParticipant::query()->sole());
    expect($participant->webhook_id)->toBe('wh-1')
        ->and($participant->webhook_secret)->toBe('account-secret')
        ->and(DB::table('peppol_participants')->where('id', $participant->id)->value('webhook_secret'))->not->toBe('account-secret')
        ->and(json_encode($participant->toArray()))->not->toContain('account-secret');

    $event = ['eventId' => 'evt-7', 'eventType' => 'document_delayed', 'accountRegNo' => 'SE5599000006', 'documentType' => 'Invoice', 'direction' => 'outgoing', 'integrationId' => 'x'];
    qvaliaWebhook($this, $event, secret: 'account-secret')->assertOk();
    qvaliaWebhook($this, [...$event, 'eventId' => 'evt-8'], secret: QVALIA_SECRET)->assertStatus(401);
});

it('reads the signature header with several secrets during rotation', function () {
    expect(QvaliaAccessPoint::parseSignature('t=1757846400,v1=abc,v1=def,v0=old'))->toBe([1757846400, ['abc', 'def']])
        ->and(QvaliaAccessPoint::parseSignature('garbage'))->toBe([null, []]);
});
