<?php

declare(strict_types=1);

use App\Actions\Payables\SaveSupplier;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Modules\Inbox\Actions\ApproveInboxMessage;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Models\InboxTemplate;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Support\Peppol\PeppolDocumentRules;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Peppol;

/*
 * E-invoice receipt end to end (ADR-050, addendum 2026-11-06): from the
 * access point's webhook and polling, through the Qvalia driver, into the
 * inbox and on to a supplier invoice draft, against recorded fixtures.
 *
 * The fixtures are synthetic: tests/Fixtures/peppol/qvalia/*.json are
 * shaped after Qvalia's public API documentation (api.qvalia.io, read
 * 2026-11-04), not recorded from the real service (no agreement or keys
 * yet; TODO(provider-verify) in the sandbox), and the UBL is the golden
 * BIS Billing 3.0 file tests/Fixtures/peppol/inrikes-25-12-6.xml with
 * made-up companies. HTTP is faked; nothing leaves the test.
 */

const RECEIPT_URL = 'https://api-test.qvalia.example';

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    config([
        'services.peppol.driver' => 'qvalia',
        'services.peppol.qvalia.url' => RECEIPT_URL,
        'services.peppol.qvalia.partner_reg_no' => 'PARTNER1',
        'services.peppol.qvalia.api_key' => 'test-api-key',
        'services.peppol.qvalia.webhook_secret' => null,
    ]);

    // Company A is the buyer in the golden invoice (0007:5563343689); company B is another receiver on the same installation.
    [$this->ownerA, $this->companyA] = receiptCompany('556334-3689', 'SE5563343689', 'secret-A');
    [$this->ownerB, $this->companyB] = receiptCompany('556036-0793', 'SE5560360793', 'secret-B');

    // The supplier is in A's register, so the proposal names it.
    $this->supplier = app(SaveSupplier::class)($this->ownerA, $this->companyA, [
        'name' => 'Exempelbolaget AB', 'org_number' => '559999-6013', 'vat_number' => 'SE559999601301',
        'iban' => 'SE4550000000058398257466', 'bic' => 'ESSESESS', 'payment_terms_days' => 30, 'default_account' => 4010,
    ]);

    $this->xml = (string) file_get_contents(base_path('tests/Fixtures/peppol/inrikes-25-12-6.xml'));
    $this->event = json_decode((string) file_get_contents(base_path('tests/Fixtures/peppol/qvalia/webhook-new-document.json')), true);
    $this->actingAs($this->ownerA);
});

/** @return array{0: User, 1: Company} */
function receiptCompany(string $org, string $account, string $secret): array
{
    [$owner, $company] = Bookkeeping::company();
    $company->forceFill(['org_number' => $org, 'vat_number' => 'SE'.str_replace('-', '', $org).'01'])->save();
    $company = $company->fresh();
    Peppol::ready($company, $owner, $account, receive: true);
    CurrentCompany::run($company, fn () => PeppolParticipant::query()->update(['provider' => 'qvalia']));
    CurrentCompany::run($company, fn () => PeppolParticipant::query()->sole()->forceFill(['webhook_secret' => $secret])->save());

    return [$owner, $company];
}

/** A webhook request signed as Qvalia signs it (HMAC-SHA256 of "t.body"). */
function receiptWebhook(object $test, array $payload, string $secret): TestResponse
{
    $body = (string) json_encode($payload);
    $time = time();

    return $test->call('POST', '/webhooks/peppol/qvalia', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_QVALIA_SIGNATURE' => "t={$time},v1=".hash_hmac('sha256', "{$time}.{$body}", $secret),
    ], $body);
}

/**
 * The access point as recorded: the status lists from the fixtures and the
 * UBL document for every fetch by integrationId.
 *
 * @param  array<string, string>  $documents  integrationId => UBL per provider account
 */
function receiptAccessPoint(array $documents, ?Closure $fetch = null): void
{
    Http::fake(function (Request $request) use ($documents, $fetch) {
        $url = $request->url();

        if (str_contains($url, '/creditnotes/incoming/status')) {
            return Http::response((string) file_get_contents(base_path('tests/Fixtures/peppol/qvalia/incoming-creditnotes-status.json')));
        }

        if (str_contains($url, '/invoices/incoming/status')) {
            return Http::response((string) file_get_contents(base_path('tests/Fixtures/peppol/qvalia/incoming-invoices-status.json')));
        }

        if (str_contains($url, '/invoices/incoming')) {
            if ($fetch !== null && ($answer = $fetch($request)) !== null) {
                return $answer;
            }

            $account = (string) preg_replace('#^.*/transaction/([^/]+)/.*$#', '$1', $url);

            return isset($documents[$account])
                ? Http::response($documents[$account], 200, ['Content-Type' => 'application/xml'])
                : Http::response(['status' => 'error', 'type' => 'E_NOT_FOUND'], 404);
        }

        return Http::response(['status' => 'error', 'type' => 'E_UNEXPECTED'], 500);
    });
}

function receiptMessageCount(Company $company): int
{
    return CurrentCompany::run($company, fn (): int => InboxMessage::query()->count());
}

function receiptSupplierInvoiceCount(Company $company): int
{
    return CurrentCompany::run($company, fn (): int => SupplierInvoice::query()->count());
}

it('uses a valid Peppol BIS Billing 3.0 document as its fixture', function () {
    expect(PeppolDocumentRules::check($this->xml))->toBe([]);
});

it('takes an e-invoice from the webhook to a supplier invoice draft in the company that owns the account', function () {
    receiptAccessPoint(['SE5563343689' => $this->xml]);

    receiptWebhook($this, $this->event, 'secret-A')->assertOk();

    $message = CurrentCompany::run($this->companyA, fn () => InboxMessage::query()->with('attachments')->sole());
    expect($message->channel)->toBe(Channel::Peppol)
        ->and($message->status)->toBe(MessageStatus::NeedsReview)
        ->and($message->message_id)->toBe('peppol:int-in-4711')
        ->and(Storage::disk('local')->get($message->eml_path))->toBe($this->xml)
        ->and($message->proposal['kind'])->toBe('supplier_invoice')
        ->and($message->proposal['invoice']['supplier_id'])->toBe($this->supplier->id)
        ->and(collect($message->warnings)->pluck('code'))->not->toContain('NO_SUPPLIER')
        // The öresavrundning in the e-invoice (BT-114, 0,27 kr) is its own row, so the sum adds up without editing.
        ->and(collect($message->warnings)->pluck('code'))->not->toContain('PEPPOL_SUM')
        ->and(collect($message->proposal['invoice']['rows'])->firstWhere('account', '3740')['amount'] ?? null)->toBe('0,27');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), RECEIPT_URL.'/partner/PARTNER1/transaction/SE5563343689/invoices/incoming')
        && $request->header('Authorization')[0] === 'test-api-key'
        && $request->header('Accept') === ['application/xml']);

    $approved = app(ApproveInboxMessage::class)($this->ownerA, $message, $message->proposal, ApproveInboxMessage::DRAFT, warningsConfirmed: true);
    $invoice = CurrentCompany::run($this->companyA, fn () => SupplierInvoice::query()->findOrFail($approved->supplier_invoice_id));

    expect($approved->status)->toBe(MessageStatus::DraftCreated)
        ->and($invoice->status)->toBe(SupplierInvoiceStatus::Draft)
        // The rounding row is not learnt: a later 0 % line from this supplier must not be proposed on 3740.
        ->and(CurrentCompany::run($this->companyA, fn () => InboxTemplate::query()->findOrFail($approved->template_id)->mapping['accounts_by_rate']))->toEqualCanonicalizing([25 => 4010, 12 => 4010, 6 => 4010])
        ->and(CurrentCompany::run($this->companyA, fn () => InboxTemplate::query()->findOrFail($approved->template_id)->mapping['accounts_by_rate']))->not->toContain(3740)
        ->and($invoice->supplier_id)->toBe($this->supplier->id)
        ->and($invoice->invoice_number)->toBe('1')
        ->and($invoice->ocr)->toBe('18')
        ->and($invoice->total_ore->ore)->toBe(941_900)
        ->and($invoice->due_date->toDateString())->toBe('2026-04-01')
        ->and($invoice->voucher_id)->toBeNull()
        ->and(receiptMessageCount($this->companyB))->toBe(0)
        ->and(receiptSupplierInvoiceCount($this->companyB))->toBe(0);
});

it('takes a credit note the same way, as a supplier credit draft', function () {
    $credit = (string) file_get_contents(base_path('tests/Fixtures/peppol/kreditnota.xml'));
    Http::fake(fn (Request $request) => str_contains($request->url(), '/creditnotes/incoming')
        ? Http::response($credit, 200, ['Content-Type' => 'application/xml'])
        : Http::response(['status' => 'error', 'type' => 'E_UNEXPECTED'], 500));

    receiptWebhook($this, [...$this->event, 'eventId' => 'evt-credit', 'documentType' => 'CreditNote', 'integrationId' => 'int-cn-1'], 'secret-A')->assertOk();

    $message = CurrentCompany::run($this->companyA, fn () => InboxMessage::query()->sole());
    $approved = app(ApproveInboxMessage::class)($this->ownerA, $message, $message->proposal, ApproveInboxMessage::DRAFT, warningsConfirmed: true);
    $invoice = CurrentCompany::run($this->companyA, fn () => SupplierInvoice::query()->findOrFail($approved->supplier_invoice_id));

    expect(PeppolDocumentRules::check($credit))->toBe([])
        ->and($message->proposal['invoice']['credit'])->toBeTrue()
        ->and($invoice->status)->toBe(SupplierInvoiceStatus::Draft)
        ->and($invoice->total_ore->ore)->toBeLessThan(0)
        ->and(receiptSupplierInvoiceCount($this->companyB))->toBe(0);
});

describe('tenancy', function () {
    it('never puts a document fetched through one company\'s account into another company', function () {
        // Delivered to A's account, but addressed to B's Peppol ID: refused, and B gets nothing either.
        $toB = str_replace(['<cbc:EndpointID schemeID="0007">5563343689</cbc:EndpointID>', '<cbc:CompanyID schemeID="0007">5563343689</cbc:CompanyID>'], ['<cbc:EndpointID schemeID="0007">5560360793</cbc:EndpointID>', '<cbc:CompanyID schemeID="0007">5560360793</cbc:CompanyID>'], $this->xml);
        receiptAccessPoint(['SE5563343689' => $toB]);

        receiptWebhook($this, $this->event, 'secret-A')->assertOk();

        expect(receiptMessageCount($this->companyA))->toBe(0)
            ->and(receiptMessageCount($this->companyB))->toBe(0)
            ->and(AuditLog::query()->where('action', 'peppol.dropped')->where('company_id', $this->companyA->id)->count())->toBe(1);
    });

    it('takes each company\'s document into that company only', function () {
        $toB = str_replace(['<cbc:EndpointID schemeID="0007">5563343689</cbc:EndpointID>', '<cbc:CompanyID schemeID="0007">5563343689</cbc:CompanyID>'], ['<cbc:EndpointID schemeID="0007">5560360793</cbc:EndpointID>', '<cbc:CompanyID schemeID="0007">5560360793</cbc:CompanyID>'], $this->xml);
        receiptAccessPoint(['SE5563343689' => $this->xml, 'SE5560360793' => $toB]);

        receiptWebhook($this, [...$this->event, 'eventId' => 'evt-B', 'accountRegNo' => 'SE5560360793'], 'secret-B')->assertOk();

        expect(receiptMessageCount($this->companyB))->toBe(1)
            ->and(receiptMessageCount($this->companyA))->toBe(0)
            ->and(CurrentCompany::run($this->companyB, fn () => InboxMessage::query()->sole()->recipient))->toBe('0007:5560360793');
    });

    it('refuses an event signed with another company\'s secret', function () {
        receiptAccessPoint(['SE5563343689' => $this->xml]);

        receiptWebhook($this, $this->event, 'secret-B')->assertStatus(401);

        Http::assertNothingSent();
        expect(receiptMessageCount($this->companyA))->toBe(0)->and(receiptMessageCount($this->companyB))->toBe(0);
    });
});

describe('idempotency', function () {
    it('makes one inbox message and one supplier invoice of the same document, however often it arrives', function () {
        receiptAccessPoint(['SE5563343689' => $this->xml]);

        receiptWebhook($this, $this->event, 'secret-A')->assertOk();
        // Delivered again with a new event id (at least once), and listed again by the poll.
        receiptWebhook($this, [...$this->event, 'eventId' => 'evt-0f2c1a7e-0002'], 'secret-A')->assertOk();
        $this->artisan('peppol:sync')->assertSuccessful();

        $message = CurrentCompany::run($this->companyA, fn () => InboxMessage::query()->sole());
        app(ApproveInboxMessage::class)($this->ownerA, $message, $message->proposal, ApproveInboxMessage::DRAFT, warningsConfirmed: true);

        $this->artisan('peppol:sync')->assertSuccessful();

        expect(receiptMessageCount($this->companyA))->toBe(1)
            ->and(receiptSupplierInvoiceCount($this->companyA))->toBe(1)
            ->and(fn () => app(ApproveInboxMessage::class)($this->ownerA, $message->fresh(), $message->proposal, ApproveInboxMessage::DRAFT, warningsConfirmed: true))
            ->toThrow(ValidationException::class, 'redan hanterat');

        expect(receiptSupplierInvoiceCount($this->companyA))->toBe(1);
    });
});

describe('the kill switch and the webhook', function () {
    it('verifies the signature before it tells anyone Peppol is paused, and handles the event again after the pause', function () {
        receiptAccessPoint(['SE5563343689' => $this->xml]);
        config(['services.peppol.paused' => true]);

        $this->postJson('/webhooks/peppol/qvalia', $this->event)->assertStatus(401);
        receiptWebhook($this, $this->event, 'wrong-secret')->assertStatus(401);
        receiptWebhook($this, $this->event, 'secret-A')->assertStatus(503)->assertHeader('Retry-After', '900');

        Http::assertNothingSent();
        expect(receiptMessageCount($this->companyA))->toBe(0);

        // The same event, delivered again after the pause, is not taken for a replay.
        config(['services.peppol.paused' => false]);
        receiptWebhook($this, $this->event, 'secret-A')->assertOk();
        expect(receiptMessageCount($this->companyA))->toBe(1);
    });
});

describe('an access point that fails', function () {
    it('loses nothing when the fetch fails: the poll takes the document later, once', function () {
        $calls = 0;
        receiptAccessPoint(['SE5563343689' => $this->xml], function () use (&$calls) {
            $calls++;

            return $calls === 1 ? Http::response((string) file_get_contents(base_path('tests/Fixtures/peppol/qvalia/error-rate-limited.json')), 429) : null;
        });

        receiptWebhook($this, $this->event, 'secret-A')->assertOk();
        expect(receiptMessageCount($this->companyA))->toBe(0);

        $this->artisan('peppol:sync')->assertSuccessful();
        $this->artisan('peppol:sync')->assertSuccessful();

        expect(receiptMessageCount($this->companyA))->toBe(1)
            ->and(CurrentCompany::run($this->companyA, fn () => PeppolParticipant::query()->sole()->last_error))->toBeNull();
    });

    it('keeps the poll window when the access point is down, and says so in Swedish', function () {
        Http::fake(['*' => Http::response(['status' => 'error', 'type' => 'E_UNAVAILABLE'], 503)]);

        $this->artisan('peppol:sync')->assertSuccessful();

        $participant = CurrentCompany::run($this->companyA, fn () => PeppolParticipant::query()->sole());
        expect($participant->last_polled_at)->toBeNull()
            ->and($participant->last_error)->toContain('503')
            ->and($participant->supportText())->toContain('Peppol-operatören svarade med ett fel')
            ->and(receiptMessageCount($this->companyA))->toBe(0);
    });
});
