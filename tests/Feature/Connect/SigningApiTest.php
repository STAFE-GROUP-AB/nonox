<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Actions\Signing\SigningReminders;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\SigningPdfs;
use App\Mail\SigningRequestMail;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Modules\Connect\Webhooks\WebhookDispatcher;
use App\Modules\Connect\Webhooks\WebhookEvents;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\ManageSigningTemplates;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Modules\Signing\Support\EnvelopeVisibility;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;
use Tests\Support\PdfEngineFixtures;
use Tests\Support\SigningConsentFixture;

/*
 * ADR-046 S19: documents, templates and envelopes for signing over the API,
 * and the signing.* webhooks. The same Actions as the screens, so these
 * tests check the API's own part: scopes, input and output shapes (never a
 * link, a code or an IP address), idempotency, the visibility of a
 * confidential envelope and of a template, the signed files, and a webhook
 * for each step with ids, counts and times only. The PDF engine is the fake
 * one (A4 pages).
 */

function s19Png(): string
{
    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imageline($image, 10, 50, 290, 60, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

function s19Token(string $email): string
{
    $url = null;

    Mail::assertQueued(SigningRequestMail::class, function (SigningRequestMail $mail) use ($email, &$url): bool {
        if ($mail->hasTo($email)) {
            $url = $mail->url;
        }

        return true;
    });

    return basename((string) parse_url((string) $url, PHP_URL_PATH));
}

function s19Sign(string $email, string $name = 'Anna Andersson'): SigningRequest
{
    $links = app(SignAtLink::class);
    $request = $links->find(s19Token($email));
    expect($request)->toBeInstanceOf(SigningRequest::class);

    SigningConsentFixture::give($request);

    return $links->sign($request, ['name' => $name, 'accept' => '1', 'method' => 'DRAWN', 'signature' => s19Png(), 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)], '203.0.113.7', 'Pest');
}

function s19Upload(User $user, Company $company, string $title = 'Anställningsavtal'): SigningDocument
{
    $path = tempnam(sys_get_temp_dir(), 's19');
    file_put_contents($path, PdfEngineFixtures::pdf(2));

    try {
        return app(StoreSigningDocument::class)($user, $company, $path, 'Avtal 2026.pdf', $title);
    } finally {
        @unlink($path);
    }
}

/** @return array<string, mixed> */
function s19Body(SigningDocument $document, array $extra = []): array
{
    return [
        'document_id' => $document->id,
        'signers' => [
            ['email' => 'anna@example.test', 'step' => 1],
            ['email' => 'bo@example.test', 'step' => 2],
        ],
        ...$extra,
    ];
}

function s19Send(mixed $test, string $key, array $body, string $idempotency = 'send-1'): TestResponse
{
    return $test->withToken($key)->withHeader('Idempotency-Key', $idempotency)->postJson('/api/v1/signing/envelopes', $body);
}

/** @return list<array<string, mixed>> */
function s19Deliveries(Company $company, ?string $type = null): array
{
    return CompanyScope::bypass(fn () => ConnectWebhookDelivery::query()->where('company_id', $company->id)
        ->when($type !== null, fn ($query) => $query->where('event_type', $type))
        ->orderBy('created_at')->orderBy('id')->get()->all());
}

beforeEach(function () {
    Connect::setUp();
    Connect::fakeDns(['hooks.example.com' => ['93.184.215.14']]);
    Http::fake(['https://hooks.example.com/*' => Http::response('', 204)]);
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    $this->engine = FakePdfEngine::install();
    [$this->owner, $this->company] = Connect::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(ModuleManager::class)->enable(SigningModule::KEY, $this->company, $this->owner);

    $this->key = Connect::apiKey($this->owner, $this->company, ['signing:view', 'signing:send', 'signing:manage']);
    $this->view = Connect::apiKey($this->owner, $this->company, ['signing:view'], null, '2026-12-31');
    $this->send = Connect::apiKey($this->owner, $this->company, ['signing:view', 'signing:send']);
});

it('answers 403 without the scope and while the module is off', function () {
    $document = s19Upload($this->owner, $this->company);
    $none = Connect::apiKey($this->owner, $this->company, ['vouchers:view']);

    $this->withToken($none)->getJson('/api/v1/signing/envelopes')->assertForbidden()->assertJsonPath('code', 'insufficient_scope');
    $this->withToken($this->view)->getJson('/api/v1/signing/documents')->assertForbidden();
    $this->withToken($this->view)->getJson('/api/v1/signing/templates')->assertForbidden();
    s19Send($this, $this->view, s19Body($document))->assertForbidden();
    $this->withToken($this->view)->withHeader('Idempotency-Key', 'u-1')->post('/api/v1/signing/documents', ['file' => UploadedFile::fake()->createWithContent('a.pdf', PdfEngineFixtures::pdf(1)), 'title' => 'A'], ['Accept' => 'application/json'])->assertForbidden();

    app(ModuleManager::class)->disable(SigningModule::KEY, $this->company, $this->owner, confirmed: true);

    // signing:send exists only while the module is on; signing:view is an archive permission and stays.
    $this->withToken($this->send)->getJson('/api/v1/signing/documents')->assertForbidden();
    $this->withToken($this->view)->getJson('/api/v1/signing/envelopes')->assertOk()->assertJsonCount(0, 'data');
});

it('uploads a PDF idempotently, checks what the file is, and serves it back byte for byte', function () {
    $pdf = PdfEngineFixtures::pdf(2);
    $upload = fn (string $key, mixed $file, array $extra = []) => $this->withToken($this->send)->withHeader('Idempotency-Key', $key)
        ->post('/api/v1/signing/documents', ['file' => $file, 'title' => 'Hyresavtal', ...$extra], ['Accept' => 'application/json']);

    $created = $upload('doc-1', UploadedFile::fake()->createWithContent('Hyresavtal 2026.pdf', $pdf))
        ->assertCreated()
        ->assertJsonPath('data.title', 'Hyresavtal')
        ->assertJsonPath('data.file_name', 'Hyresavtal 2026.pdf')
        ->assertJsonPath('data.page_count', 2)
        ->assertJsonPath('data.sha256', hash('sha256', $pdf))
        ->assertJsonPath('data.uploaded_by', $this->owner->id);
    $id = $created->json('data.id');
    $created->assertHeader('Location', url("/api/v1/signing/documents/{$id}"));

    $upload('doc-1', UploadedFile::fake()->createWithContent('Hyresavtal 2026.pdf', $pdf))->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect(CurrentCompany::run($this->company, fn () => SigningDocument::query()->count()))->toBe(1);

    $this->withToken($this->send)->getJson("/api/v1/signing/documents/{$id}")->assertOk()->assertJsonPath('data.id', $id);
    $this->withToken($this->send)->getJson('/api/v1/signing/documents')->assertOk()->assertJsonCount(1, 'data');

    $content = $this->withToken($this->send)->get("/api/v1/signing/documents/{$id}/content")->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-SHA256', hash('sha256', $pdf));
    expect($content->getContent())->toBe($pdf);

    // Refused: no file, no title, not a PDF.
    $this->withToken($this->send)->withHeader('Idempotency-Key', 'doc-2')->postJson('/api/v1/signing/documents', ['title' => 'X'])->assertStatus(422)->assertJsonPath('errors.file.0', 'Skicka PDF-filen i fältet file (multipart/form-data).');
    $upload('doc-3', UploadedFile::fake()->createWithContent('a.pdf', PdfEngineFixtures::pdf(1)), ['title' => ''])->assertUnprocessable();
    $upload('doc-4', UploadedFile::fake()->createWithContent('a.pdf', 'inte en pdf'))->assertUnprocessable();
    expect(CurrentCompany::run($this->company, fn () => SigningDocument::query()->count()))->toBe(1);

    $this->withToken($this->send)->withHeader('Idempotency-Key', 'doc-del')->deleteJson("/api/v1/signing/documents/{$id}")->assertNoContent();
    $this->withToken($this->send)->getJson("/api/v1/signing/documents/{$id}")->assertNotFound();
});

it('sends an envelope with two signers in steps, and never returns a link, a code or a hash of one', function () {
    $document = s19Upload($this->owner, $this->company);

    $response = s19Send($this, $this->send, s19Body($document, [
        'copies' => [['email' => 'arkiv@example.test', 'name' => 'Arkivet']],
        'expires_on' => '2026-07-01',
        'signers' => [
            ['email' => 'anna@example.test', 'step' => 1, 'verification' => 'ACCESS_CODE', 'access_code' => 'hemlig-kod-1'],
            ['email' => 'bo@example.test', 'step' => 2, 'language' => 'en'],
        ],
    ]))->assertCreated()
        ->assertJsonPath('data.status', 'OUT')
        ->assertJsonPath('data.document_type', 'DOCUMENT')
        ->assertJsonPath('data.document_id', $document->id)
        ->assertJsonPath('data.current_step', 1)
        ->assertJsonPath('data.confidential', false)
        ->assertJsonPath('data.recipients.0.email', 'anna@example.test')
        ->assertJsonPath('data.recipients.0.verification', 'ACCESS_CODE')
        ->assertJsonPath('data.recipients.0.status', 'PENDING')
        ->assertJsonPath('data.recipients.1.step', 2)
        ->assertJsonPath('data.recipients.1.language', 'en')
        ->assertJsonPath('data.copy_recipients.0.email', 'arkiv@example.test');
    $id = $response->json('data.id');
    $response->assertHeader('Location', url("/api/v1/signing/envelopes/{$id}"));
    $sentBody = fn () => s19Body($document, [
        'copies' => [['email' => 'arkiv@example.test', 'name' => 'Arkivet']],
        'expires_on' => '2026-07-01',
        'signers' => [
            ['email' => 'anna@example.test', 'step' => 1, 'verification' => 'ACCESS_CODE', 'access_code' => 'hemlig-kod-1'],
            ['email' => 'bo@example.test', 'step' => 2, 'language' => 'en'],
        ],
    ]);

    $raw = $response->getContent();
    expect($raw)->not->toContain('hemlig-kod-1')->not->toContain('access_code')->not->toContain('/signera/')->not->toContain('token')->not->toContain('203.0.113');

    // Only the first step's signer has a link yet; a replay sends nothing again.
    Mail::assertQueued(SigningRequestMail::class, 1);
    s19Send($this, $this->send, $sentBody(), 'send-1')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect(CurrentCompany::run($this->company, fn () => SigningEnvelope::query()->count()))->toBe(1);

    $this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$id}")->assertOk()->assertJsonCount(2, 'data.recipients');
    $this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$id}/recipients")->assertOk()->assertJsonCount(2, 'data');
    $recipient = $response->json('data.recipients.0.id');
    $this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$id}/recipients/{$recipient}")->assertOk()->assertJsonPath('data.email', 'anna@example.test');
});

it('places fields in the document and reads them back by recipient, without any value', function () {
    $document = s19Upload($this->owner, $this->company);

    $id = s19Send($this, $this->send, s19Body($document, ['fields' => [
        ['signer' => 0, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 5000, 'y' => 5000, 'width' => 20000, 'height' => 2000, 'label' => 'Annas underskrift', 'required' => true],
        ['signer' => 1, 'type' => 'SIGNATURE', 'page' => 2, 'x' => 5000, 'y' => 9000, 'width' => 20000, 'height' => 2000, 'label' => 'Bos underskrift', 'required' => true],
        ['signer' => 1, 'type' => 'DATE', 'page' => 2, 'x' => 30000, 'y' => 9000, 'width' => 12000, 'height' => 2000, 'label' => 'Bos datum', 'required' => true],
    ]]))->assertCreated()->json('data.id');

    $fields = $this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$id}/fields")->assertOk()->assertJsonCount(3, 'data');
    expect($fields->json('data.0.type'))->toBe('SIGNATURE')
        ->and($fields->json('data.0.label'))->toBe('Annas underskrift')
        ->and($fields->json('data.0.recipient_id'))->toBe($this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$id}")->json('data.recipients.0.id'))
        ->and($fields->json('data.1.page'))->toBe(2)
        ->and($fields->json('data.0'))->not->toHaveKey('value');

    s19Send($this, $this->send, s19Body($document, ['fields' => [['signer' => 5, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 1, 'y' => 1, 'width' => 1, 'height' => 1, 'label' => 'x']]]), 'send-2')->assertUnprocessable();
});

it('refuses an envelope that names no document, two, a document of someone else or bad signers', function () {
    $document = s19Upload($this->owner, $this->company);
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'inkop@kontorshuset.test', 'payment_terms_days' => 30])->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15', 'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]]]);

    s19Send($this, $this->send, ['signers' => [['email' => 'a@example.test', 'step' => 1]]], 'v-1')->assertUnprocessable();
    s19Send($this, $this->send, s19Body($document, ['quote_id' => $quote->id]), 'v-2')->assertUnprocessable();
    s19Send($this, $this->send, s19Body($document, ['signers' => []]), 'v-3')->assertUnprocessable();
    s19Send($this, $this->send, s19Body($document, ['signers' => [['email' => 'inte-en-adress', 'step' => 1]]]), 'v-4')->assertUnprocessable();
    s19Send($this, $this->send, s19Body($document, ['signers' => [['email' => 'a@example.test', 'step' => 1], ['email' => 'A@example.test', 'step' => 2]]]), 'v-5')->assertUnprocessable();
    s19Send($this, $this->send, s19Body($document, ['document_id' => 'inte-ett-uuid']), 'v-6')->assertNotFound();
    s19Send($this, $this->send, s19Body($document, ['document_id' => '9b0a4f0e-4c1f-4f0e-8d1e-1d0c0f0e0a0b']), 'v-7')->assertNotFound();
    s19Send($this, $this->send, s19Body($document, ['template_id' => '9b0a4f0e-4c1f-4f0e-8d1e-1d0c0f0e0a0b']), 'v-8')->assertNotFound();
    s19Send($this, $this->send, s19Body($document, ['expires_on' => '2020-01-01']), 'v-9')->assertUnprocessable();
    // Signing at an external provider (ADR-046 S17) is chosen in the app only.
    s19Send($this, $this->send, s19Body($document, ['signers' => [['email' => 'a@example.test', 'step' => 1, 'verification' => 'PROVIDER:1']]]), 'v-9b')->assertUnprocessable()->assertJsonValidationErrors('signers.0.verification');

    // Nothing was made, mailed or announced by the refused ones.
    expect(CurrentCompany::run($this->company, fn () => SigningEnvelope::query()->count()))->toBe(0);
    Mail::assertNothingQueued();

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $theirs = Connect::apiKey($bookkeeper, $this->company, ['signing:view', 'signing:send']);
    // An uploaded document is its uploader's until it is sent: another member with signing:send gets a 404, not a hint.
    s19Send($this, $theirs, s19Body($document), 'v-10')->assertNotFound();
    $this->withToken($theirs)->getJson("/api/v1/signing/documents/{$document->id}")->assertNotFound();
    $this->withToken($theirs)->getJson('/api/v1/signing/documents')->assertOk()->assertJsonCount(0, 'data');
    // signing:manage sees and sends everyone's.
    s19Send($this, $this->key, s19Body($document), 'v-11')->assertCreated();
});

it('sends a quote for signing to several signers with the quote screen\'s rights, and a key without them cannot see the quote', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'inkop@kontorshuset.test', 'payment_terms_days' => 30]);
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $customer->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15', 'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]]]);
    $body = ['quote_id' => $quote->id, 'signers' => [['email' => 'a@example.test', 'step' => 1], ['email' => 'b@example.test', 'step' => 1]]];

    s19Send($this, $this->send, $body, 'q-1')->assertNotFound();

    $full = Connect::apiKey($this->owner, $this->company, ['signing:view', 'signing:send', 'invoices:view', 'invoices:create', 'invoices:send']);
    $id = s19Send($this, $full, $body, 'q-2')->assertCreated()->assertJsonPath('data.document_type', 'QUOTE')->assertJsonPath('data.quote_id', $quote->id)->assertJsonCount(2, 'data.recipients')->json('data.id');

    $this->withToken($full)->getJson("/api/v1/signing/envelopes?document_type=QUOTE&quote_id={$quote->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    // A quote's envelope is listed only to a person who may see quotes: a key without invoices:view sees nothing of it.
    $this->withToken($this->send)->getJson('/api/v1/signing/envelopes?document_type=QUOTE')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($this->send)->getJson("/api/v1/signing/envelopes/{$id}")->assertNotFound();
});

it('lists envelopes with filters and a fixed number of queries', function () {
    $document = fn (string $title) => s19Upload($this->owner, $this->company, $title);
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($this->view)->getJson('/api/v1/signing/envelopes?limit=50')->assertOk();
        // The key's "last used" stamp is written at most once a minute and is not part of the list.
        $queries = count(array_filter(DB::getQueryLog(), fn (array $query): bool => ! str_contains($query['query'], 'connect_api_keys')));
        DB::disableQueryLog();

        return $queries;
    };

    s19Send($this, $this->send, s19Body($document('Ett')), 'l-1')->assertCreated();
    $few = $count();

    foreach (range(2, 8) as $index) {
        s19Send($this, $this->send, s19Body($document("Dokument {$index}"), ['signers' => [['email' => "a{$index}@example.test", 'step' => 1], ['email' => "b{$index}@example.test", 'step' => 1], ['email' => "c{$index}@example.test", 'step' => 2]], 'copies' => [['email' => "k{$index}@example.test"]]]), "l-{$index}")->assertCreated();
    }

    expect($count())->toBe($few);

    $list = $this->withToken($this->view)->getJson('/api/v1/signing/envelopes?limit=50')->assertOk()->assertJsonCount(8, 'data');
    expect($list->json('meta.has_more'))->toBeFalse();

    $this->withToken($this->view)->getJson('/api/v1/signing/envelopes?status=OUT')->assertOk()->assertJsonCount(8, 'data');
    $this->withToken($this->view)->getJson('/api/v1/signing/envelopes?status=COMPLETED')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($this->view)->getJson('/api/v1/signing/envelopes?status=EXPIRED')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($this->view)->getJson('/api/v1/signing/envelopes?status=KLAR')->assertUnprocessable();
    $this->withToken($this->view)->getJson('/api/v1/signing/envelopes?limit=3')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.has_more', true);

    $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00:00'));
    $this->withToken($this->view)->getJson('/api/v1/signing/envelopes?status=EXPIRED')->assertOk()->assertJsonCount(8, 'data');
    $this->withToken($this->view)->getJson('/api/v1/signing/envelopes?status=OUT')->assertOk()->assertJsonCount(0, 'data');
});

it('hides a confidential envelope from everyone but its sender and signing:manage', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    app(ModuleManager::class)->enable(SigningModule::KEY, $this->company, $this->owner);
    $theirs = Connect::apiKey($bookkeeper, $this->company, ['signing:view', 'signing:send']);
    $document = s19Upload($this->owner, $this->company, 'Sekretessavtal');

    $id = s19Send($this, $this->key, s19Body($document, ['confidential' => true]), 'c-1')->assertCreated()->assertJsonPath('data.confidential', true)->json('data.id');
    $recipient = $this->withToken($this->key)->getJson("/api/v1/signing/envelopes/{$id}")->json('data.recipients.0.id');

    $this->withToken($theirs)->getJson('/api/v1/signing/envelopes')->assertOk()->assertJsonCount(0, 'data');
    foreach (['', '/recipients', "/recipients/{$recipient}", "/recipients/{$recipient}/certificate", '/fields', '/document'] as $path) {
        $this->withToken($theirs)->getJson("/api/v1/signing/envelopes/{$id}{$path}")->assertNotFound();
    }
    $this->withToken($theirs)->withHeader('Idempotency-Key', 'c-2')->postJson("/api/v1/signing/envelopes/{$id}/void")->assertNotFound();
    $this->withToken($theirs)->withHeader('Idempotency-Key', 'c-3')->postJson("/api/v1/signing/envelopes/{$id}/recipients/{$recipient}/remind")->assertNotFound();
    $this->withToken($theirs)->patchJson("/api/v1/signing/envelopes/{$id}", ['expires_on' => '2026-06-30'])->assertNotFound();
    $this->withToken($theirs)->getJson('/api/v1/signing/envelopes?document_id='.$document->id)->assertOk()->assertJsonCount(0, 'data');

    $this->withToken($this->key)->getJson('/api/v1/signing/envelopes')->assertOk()->assertJsonCount(1, 'data');

    // The sender sees their own confidential envelope without signing:manage.
    $own = s19Upload($bookkeeper, $this->company, 'Eget');
    $mine = s19Send($this, $theirs, s19Body($own, ['confidential' => true, 'signers' => [['email' => 'x@example.test', 'step' => 1]]]), 'c-4')->assertCreated()->json('data.id');
    $this->withToken($theirs)->getJson("/api/v1/signing/envelopes/{$mine}")->assertOk();
    $this->withToken($theirs)->getJson('/api/v1/signing/envelopes')->assertOk()->assertJsonCount(1, 'data');
});

it('refuses a confidential envelope for a quote, as the screen does', function () {
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'inkop@kontorshuset.test', 'payment_terms_days' => 30])->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15', 'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]]]);
    $full = Connect::apiKey($this->owner, $this->company, ['signing:view', 'signing:send', 'invoices:view', 'invoices:create', 'invoices:send']);

    s19Send($this, $full, ['quote_id' => $quote->id, 'confidential' => true, 'signers' => [['email' => 'a@example.test', 'step' => 1]]], 'k-1')->assertUnprocessable();
});

it('reminds a recipient, moves the last day and withdraws the envelope, as the screen does', function () {
    $id = s19Send($this, $this->send, s19Body(s19Upload($this->owner, $this->company)), 'r-0')->assertCreated()->json('data.id');
    $first = $this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$id}")->json('data.recipients.0.id');
    $second = $this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$id}")->json('data.recipients.1.id');

    $this->withToken($this->send)->withHeader('Idempotency-Key', 'r-1')->postJson("/api/v1/signing/envelopes/{$id}/recipients/{$first}/remind")
        ->assertOk()->assertJsonPath('data.reminder_count', 1);
    // At most once an hour, and not to someone whose turn has not come.
    $this->withToken($this->send)->withHeader('Idempotency-Key', 'r-2')->postJson("/api/v1/signing/envelopes/{$id}/recipients/{$first}/remind")->assertUnprocessable();
    $this->withToken($this->send)->withHeader('Idempotency-Key', 'r-3')->postJson("/api/v1/signing/envelopes/{$id}/recipients/{$second}/remind")->assertUnprocessable();
    $this->withToken($this->send)->withHeader('Idempotency-Key', 'r-4')->postJson("/api/v1/signing/envelopes/{$id}/recipients/999999/remind")->assertNotFound();
    $this->withToken($this->view)->withHeader('Idempotency-Key', 'r-5')->postJson("/api/v1/signing/envelopes/{$id}/recipients/{$first}/remind")->assertForbidden();

    $this->withToken($this->send)->patchJson("/api/v1/signing/envelopes/{$id}", [])->assertUnprocessable();
    $moved = $this->withToken($this->send)->patchJson("/api/v1/signing/envelopes/{$id}", ['expires_on' => '2026-06-30'])->assertOk();
    expect($moved->json('data.deadline_at'))->toStartWith('2026-06-30');
    $this->withToken($this->send)->patchJson("/api/v1/signing/envelopes/{$id}", ['expires_on' => '2026-06-30'])->assertUnprocessable();
    $this->withToken($this->view)->patchJson("/api/v1/signing/envelopes/{$id}", ['expires_on' => '2026-06-29'])->assertForbidden();

    $this->withToken($this->view)->withHeader('Idempotency-Key', 'r-6')->postJson("/api/v1/signing/envelopes/{$id}/void")->assertForbidden();
    $this->withToken($this->send)->withHeader('Idempotency-Key', 'r-7')->postJson("/api/v1/signing/envelopes/{$id}/void")->assertOk()->assertJsonPath('data.status', 'VOIDED');
    $this->withToken($this->send)->withHeader('Idempotency-Key', 'r-8')->postJson("/api/v1/signing/envelopes/{$id}/void")->assertUnprocessable();
});

it('serves the finished document and each signer\'s certificate, and nothing before a signature', function () {
    $document = s19Upload($this->owner, $this->company);
    $id = s19Send($this, $this->send, s19Body($document), 'f-1')->assertCreated()->json('data.id');
    $first = $this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$id}")->json('data.recipients.0.id');

    $sent = $this->withToken($this->view)->get("/api/v1/signing/envelopes/{$id}/document")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($sent->headers->get('X-Content-SHA256'))->toBe(hash('sha256', $sent->getContent()))
        ->and($sent->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($sent->headers->get('Cache-Control'))->toContain('no-store');
    $this->withToken($this->view)->get("/api/v1/signing/envelopes/{$id}/recipients/{$first}/certificate")->assertNotFound();

    s19Sign('anna@example.test');

    $signed = $this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$id}/recipients/{$first}")->assertOk()->assertJsonPath('data.status', 'SIGNED')->assertJsonPath('data.has_certificate', true);
    $certificate = $this->withToken($this->view)->get("/api/v1/signing/envelopes/{$id}/recipients/{$first}/certificate")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($certificate->headers->get('X-Content-SHA256'))->toBe($signed->json('data.signed_document_sha256'))
        ->and($signed->getContent())->not->toContain('203.0.113.7')->not->toContain('Pest');

    $this->withToken($this->send)->get("/api/v1/signing/envelopes/{$id}/document")->assertOk();
    $this->withToken(Connect::apiKey($this->owner, $this->company, ['vouchers:view']))->get("/api/v1/signing/envelopes/{$id}/document")->assertForbidden();
});

it('answers 404 for a file that retention or an erasure has removed', function () {
    $id = s19Send($this, $this->send, s19Body(s19Upload($this->owner, $this->company), ['signers' => [['email' => 'anna@example.test', 'step' => 1]]]), 'g-1')->assertCreated()->json('data.id');
    $recipient = $this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$id}")->json('data.recipients.0.id');
    s19Sign('anna@example.test');

    $this->withToken($this->view)->get("/api/v1/signing/envelopes/{$id}/recipients/{$recipient}/certificate")->assertOk();

    $path = CurrentCompany::run($this->company, fn () => SigningRequest::query()->findOrFail($recipient)->signed_document_path);
    Storage::disk('local')->delete((string) $path);
    $this->withToken($this->view)->get("/api/v1/signing/envelopes/{$id}/recipients/{$recipient}/certificate")->assertNotFound();
});

it('reads templates, and sends from one with the recipients named by role', function () {
    $document = s19Upload($this->owner, $this->company, 'Samarbetsavtal');
    $template = app(ManageSigningTemplates::class)->create($this->owner, $this->company, [
        'name' => 'Samarbetsavtal',
        'document_type' => 'DOCUMENT',
        'deadline_days' => 14,
        'roles' => [
            ['label' => 'Kund', 'step' => 2, 'verification' => 'NONE', 'internal' => false],
            ['label' => 'Säljare', 'step' => 1, 'verification' => 'NONE', 'internal' => true],
        ],
        'copies' => [['email' => 'arkiv@example.test', 'name' => 'Arkivet', 'language' => 'sv']],
        'fields' => [
            ['signer' => 0, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 5000, 'y' => 5000, 'width' => 20000, 'height' => 2000, 'label' => 'Kundens underskrift', 'required' => true],
            ['signer' => 1, 'type' => 'SIGNATURE', 'page' => 2, 'x' => 5000, 'y' => 9000, 'width' => 20000, 'height' => 2000, 'label' => 'Säljarens underskrift', 'required' => true],
        ],
    ], $document);

    $this->withToken($this->send)->getJson('/api/v1/signing/templates')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Samarbetsavtal');
    $shown = $this->withToken($this->send)->getJson("/api/v1/signing/templates/{$template->id}")->assertOk()
        ->assertJsonPath('data.roles.0.key', 'kund')
        ->assertJsonPath('data.roles.1.key', 'saljare')
        ->assertJsonCount(2, 'data.fields')
        ->assertJsonPath('data.copy_recipients.0.email', 'arkiv@example.test');
    expect($shown->json('data.fields.0.role_key'))->toBe('kund');

    $body = ['template_id' => $template->id, 'recipients' => ['kund' => ['email' => 'kund@example.test'], 'saljare' => ['email' => 'saljare@example.test', 'language' => 'en']]];
    $response = s19Send($this, $this->send, $body, 't-1')->assertCreated()
        ->assertJsonPath('data.template_id', $template->id)
        ->assertJsonPath('data.recipients.0.email', 'saljare@example.test')
        ->assertJsonPath('data.recipients.0.step', 1)
        ->assertJsonPath('data.recipients.0.language', 'en')
        ->assertJsonPath('data.recipients.1.email', 'kund@example.test')
        ->assertJsonPath('data.recipients.1.step', 2)
        ->assertJsonPath('data.copy_recipients.0.email', 'arkiv@example.test');
    $id = $response->json('data.id');
    expect(substr((string) $response->json('data.deadline_at'), 0, 10))->toBe('2026-06-29');
    $this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$id}/fields")->assertOk()->assertJsonCount(2, 'data');

    // The template's file became a document of its own for this envelope; the first signer's step is open.
    expect(CurrentCompany::run($this->company, fn () => SigningDocument::query()->count()))->toBe(2);
    Mail::assertQueued(SigningRequestMail::class, 1);
    Mail::assertQueued(SigningRequestMail::class, fn (SigningRequestMail $mail): bool => $mail->hasTo('saljare@example.test'));

    // Roles: one person for each, none the template does not have, an object and not a list.
    s19Send($this, $this->send, ['template_id' => $template->id, 'recipients' => ['kund' => ['email' => 'kund@example.test']]], 't-2')->assertUnprocessable();
    s19Send($this, $this->send, ['template_id' => $template->id, 'recipients' => [...$body['recipients'], 'chef' => ['email' => 'chef@example.test']]], 't-3')->assertUnprocessable();
    s19Send($this, $this->send, ['template_id' => $template->id, 'recipients' => [['email' => 'a@example.test']]], 't-4')->assertUnprocessable();
    s19Send($this, $this->send, [...$body, 'document_id' => $document->id], 't-5')->assertUnprocessable();

    // A refused send leaves no document behind that the template made for it.
    $documents = CurrentCompany::run($this->company, fn () => SigningDocument::query()->count());
    s19Send($this, $this->send, ['template_id' => $template->id, 'recipients' => ['kund' => ['email' => 'inte-en-adress'], 'saljare' => ['email' => 'saljare2@example.test']]], 't-6')->assertUnprocessable();
    expect(CurrentCompany::run($this->company, fn () => SigningDocument::query()->count()))->toBe($documents);
});

it('answers 404 for a confidential template of another member, on the template routes and when it is named in an envelope', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $theirs = Connect::apiKey($bookkeeper, $this->company, ['signing:view', 'signing:send']);
    $document = s19Upload($this->owner, $this->company, 'Hemligt');
    $secret = app(ManageSigningTemplates::class)->create($this->owner, $this->company, [
        'name' => 'Hemlig mall', 'document_type' => 'DOCUMENT', 'confidential' => true,
        'roles' => [['label' => 'Part', 'step' => 1, 'verification' => 'NONE', 'internal' => false]],
    ], $document);
    $open = app(ManageSigningTemplates::class)->create($this->owner, $this->company, [
        'name' => 'Öppen mall', 'document_type' => 'DOCUMENT',
        'roles' => [['label' => 'Part', 'step' => 1, 'verification' => 'NONE', 'internal' => false]],
    ], s19Upload($this->owner, $this->company, 'Öppet'));

    $this->withToken($theirs)->getJson("/api/v1/signing/templates/{$secret->id}")->assertNotFound();
    $this->withToken($theirs)->getJson('/api/v1/signing/templates')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id);
    s19Send($this, $theirs, ['template_id' => $secret->id, 'recipients' => ['part' => ['email' => 'a@example.test']]], 'h-1')->assertNotFound();
    Mail::assertNothingQueued();

    $this->withToken($this->key)->getJson("/api/v1/signing/templates/{$secret->id}")->assertOk();
    $this->withToken($this->send)->getJson('/api/v1/signing/templates')->assertOk()->assertJsonCount(2, 'data');
    s19Send($this, $this->send, ['template_id' => $secret->id, 'recipients' => ['part' => ['email' => 'a@example.test']]], 'h-2')->assertCreated()->assertJsonPath('data.confidential', true);
});

it('has the four signing events with their own permission, and no one can subscribe to them without it', function () {
    foreach (['signing.recipient_signed', 'signing.completed', 'signing.declined', 'signing.expired'] as $event) {
        expect(WebhookEvents::EVENTS[$event])->toBe(['signing:view'])
            ->and(WebhookEvents::labels())->toHaveKey($event);
    }

    expect(WebhookEvents::filter(['signing.completed', 'signing.bogus']))->toBe(['signing.completed']);
});

it('announces each step with ids, counts and times only', function () {
    Connect::webhook($this->owner, $this->company, ['signing.recipient_signed', 'signing.completed', 'signing.declined', 'signing.expired']);
    $document = s19Upload($this->owner, $this->company, 'Avtal med Anna Andersson');
    $id = s19Send($this, $this->send, s19Body($document), 'w-1')->assertCreated()->json('data.id');
    $first = $this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$id}")->json('data.recipients.0.id');

    expect(s19Deliveries($this->company))->toHaveCount(0);

    $signed = s19Sign('anna@example.test');

    $deliveries = s19Deliveries($this->company);
    expect($deliveries)->toHaveCount(1)->and($deliveries[0]['event_type'])->toBe('signing.recipient_signed');
    $body = json_decode($deliveries[0]['body'], true);
    expect($body['type'])->toBe('signing.recipient_signed')
        ->and($body['company_id'])->toBe($this->company->id)
        ->and($body['data'])->toBe([
            'envelope_id' => $id,
            'document_type' => 'DOCUMENT',
            'quote_id' => null,
            'agreement_id' => null,
            'signable_id' => $document->id,
            'document_sha256' => $body['data']['document_sha256'],
            'recipients' => 2,
            'recipients_signed' => 1,
            'recipient_id' => $first,
            'step' => 1,
            'signed_at' => $signed->signed_at->toAtomString(),
            'signature_method' => 'DRAWN',
            'signed_document_sha256' => $signed->signed_document_sha256,
        ]);
    Http::assertSentCount(1);

    s19Sign('bo@example.test', 'Bo Berg');

    expect(array_map(fn (ConnectWebhookDelivery $delivery): string => $delivery->event_type, s19Deliveries($this->company)))->toBe(['signing.recipient_signed', 'signing.recipient_signed', 'signing.completed']);
    $completed = json_decode(s19Deliveries($this->company, 'signing.completed')[0]->body, true);
    expect($completed['data']['recipients_signed'])->toBe(2)
        ->and($completed['data']['completed_at'])->not->toBeNull()
        ->and($completed['data'])->not->toHaveKeys(['recipient_id', 'signed_at']);

    foreach (s19Deliveries($this->company) as $delivery) {
        expect($delivery->body)->not->toContain('@')->not->toContain('Anna')->not->toContain('Bo Berg')->not->toContain('203.0.113.7')->not->toContain('Avtal med');
    }
});

it('announces a decline without the reason, and an expiry once', function () {
    Connect::webhook($this->owner, $this->company, ['signing.declined', 'signing.expired']);

    $declined = s19Send($this, $this->send, s19Body(s19Upload($this->owner, $this->company)), 'x-1')->assertCreated()->json('data.id');
    $links = app(SignAtLink::class);
    $request = $links->find(s19Token('anna@example.test'));
    SigningConsentFixture::give($request);
    $links->decline($request, 'Jag vill inte skriva under det här.');

    $deliveries = s19Deliveries($this->company, 'signing.declined');
    expect($deliveries)->toHaveCount(1);
    $data = json_decode($deliveries[0]->body, true)['data'];
    expect($data['envelope_id'])->toBe($declined)
        ->and($data['recipient_id'])->toBe($request->id)
        ->and($data['declined_at'])->not->toBeNull()
        ->and($deliveries[0]->body)->not->toContain('vill inte')->not->toContain('@');

    $expiring = s19Send($this, $this->send, s19Body(s19Upload($this->owner, $this->company, 'Ett till'), ['expires_on' => '2026-06-20', 'signers' => [['email' => 'carin@example.test', 'step' => 1]]]), 'x-2')->assertCreated()->json('data.id');

    $this->travelTo(CarbonImmutable::parse('2026-06-22 10:00:00'));
    CurrentCompany::run($this->company, fn () => app(SigningReminders::class)->run(CarbonImmutable::now()));
    CurrentCompany::run($this->company, fn () => app(SigningReminders::class)->run(CarbonImmutable::now()));

    $expired = s19Deliveries($this->company, 'signing.expired');
    expect($expired)->toHaveCount(1);
    $data = json_decode($expired[0]->body, true)['data'];
    expect($data['envelope_id'])->toBe($expiring)
        ->and($data['recipients_pending'])->toBe(1)
        ->and($data['expired_at'])->not->toBeNull()
        ->and($data['deadline_at'])->not->toBeNull();
    $this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$expiring}")->assertJsonPath('data.status', 'EXPIRED');
});

it('sends an envelope\'s events only to a subscriber who may see that envelope', function () {
    // Another member with signing:view but no right to this confidential envelope gets no event; the sender's company-wide managers do.
    $accountant = Bookkeeping::member($this->company, 'accountant');
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    Connect::webhook($this->owner, $this->company, ['signing.completed'], 'https://hooks.example.com/owner');

    $document = s19Upload($bookkeeper, $this->company, 'Konfidentiellt');
    $envelope = app(SendForSigning::class)->envelope($bookkeeper, $this->company, $document, ['signers' => [['email' => 'anna@example.test', 'step' => 1]], 'confidential' => true], minSigners: 1);
    $plain = app(SendForSigning::class)->envelope($bookkeeper, $this->company, s19Upload($bookkeeper, $this->company, 'Öppet'), ['signers' => [['email' => 'bo@example.test', 'step' => 1]]], minSigners: 1);

    $company = $this->company->fresh();
    expect(EnvelopeVisibility::allows($accountant->fresh(), $company, $envelope))->toBeTrue()
        ->and(EnvelopeVisibility::allows(Bookkeeping::member($company, 'bookkeeper'), $company->fresh(), $envelope))->toBeFalse();

    s19Sign('anna@example.test');
    s19Sign('bo@example.test', 'Bo Berg');

    $ids = array_map(fn (ConnectWebhookDelivery $delivery): string => json_decode($delivery->body, true)['data']['envelope_id'], s19Deliveries($this->company, 'signing.completed'));
    expect($ids)->toContain($envelope->id)->toContain($plain->id);
});

it('follows the list\'s rule while the module is off: only signed standalone documents are visible, so a quote\'s envelope sends no signing event', function () {
    Connect::webhook($this->owner, $this->company, ['signing.completed', 'signing.recipient_signed']);
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'inkop@kontorshuset.test', 'payment_terms_days' => 30]);
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $customer->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15', 'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]]]);
    $full = Connect::apiKey($this->owner, $this->company, ['signing:view', 'signing:send', 'invoices:view', 'invoices:create', 'invoices:send']);

    s19Send($this, $full, ['quote_id' => $quote->id, 'signers' => [['email' => 'a@example.test', 'step' => 1]]], 'm-1')->assertCreated();
    app(ModuleManager::class)->disable(SigningModule::KEY, $this->company, $this->owner, confirmed: true);

    s19Sign('a@example.test');

    expect(s19Deliveries($this->company))->toHaveCount(0);
});

it('sends a quote from a quote template, with the quote named and the roles mapped', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'inkop@kontorshuset.test', 'payment_terms_days' => 30]);
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $customer->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15', 'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]]]);
    $template = app(ManageSigningTemplates::class)->create($this->owner, $this->company, [
        'name' => 'Offertmall', 'document_type' => 'QUOTE', 'deadline_days' => 10,
        'roles' => [['label' => 'Kund', 'step' => 1, 'verification' => 'NONE', 'internal' => false]],
    ]);
    $full = Connect::apiKey($this->owner, $this->company, ['signing:view', 'signing:send', 'invoices:view', 'invoices:create', 'invoices:send']);
    $body = ['template_id' => $template->id, 'recipients' => ['kund' => ['email' => 'inkop@kontorshuset.test']]];

    // The quote must be named, and only one document kind.
    s19Send($this, $full, $body, 'qt-1')->assertUnprocessable();
    s19Send($this, $full, [...$body, 'quote_id' => $quote->id, 'agreement_id' => 5], 'qt-2')->assertUnprocessable();

    s19Send($this, $full, [...$body, 'quote_id' => $quote->id], 'qt-3')->assertCreated()
        ->assertJsonPath('data.document_type', 'QUOTE')
        ->assertJsonPath('data.quote_id', $quote->id)
        ->assertJsonPath('data.template_id', $template->id)
        ->assertJsonPath('data.recipients.0.email', 'inkop@kontorshuset.test');
});

it('never lets a failing webhook fan-out break a signature, the next step\'s mail or the reminder run', function () {
    Exceptions::fake();
    Connect::webhook($this->owner, $this->company, ['signing.recipient_signed', 'signing.completed', 'signing.expired']);
    app()->bind(WebhookDispatcher::class, fn () => throw new RuntimeException('Kön är nere.'));

    $document = s19Upload($this->owner, $this->company);
    $id = s19Send($this, $this->send, s19Body($document), 'z-1')->assertCreated()->json('data.id');

    // The first signer signs although the fan-out throws; the second step is opened and mailed.
    $signed = s19Sign('anna@example.test');
    expect($signed->status->value)->toBe('SIGNED');
    Mail::assertQueued(SigningRequestMail::class, fn (SigningRequestMail $mail): bool => $mail->hasTo('bo@example.test'));
    Exceptions::assertReported(RuntimeException::class);
    expect(s19Deliveries($this->company))->toHaveCount(0);

    // The reminder run still expires an envelope whose announcement fails.
    $late = s19Send($this, $this->send, s19Body(s19Upload($this->owner, $this->company, 'Sent'), ['expires_on' => '2026-06-20', 'signers' => [['email' => 'carin@example.test', 'step' => 1]]]), 'z-2')->assertCreated()->json('data.id');
    $this->travelTo(CarbonImmutable::parse('2026-06-22 10:00:00'));
    $result = CurrentCompany::run($this->company, fn () => app(SigningReminders::class)->run(CarbonImmutable::now()));
    expect($result['expired'])->toBe(1);
    $this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$late}")->assertJsonPath('data.status', 'EXPIRED');
});

it('moves the envelope\'s updated_at with every step of a recipient, also when it finishes', function () {
    $id = s19Send($this, $this->send, s19Body(s19Upload($this->owner, $this->company), ['signers' => [['email' => 'anna@example.test', 'step' => 1]]]), 'u-1')->assertCreated()->json('data.id');
    $since = fn (string $time) => $this->withToken($this->view)->getJson('/api/v1/signing/envelopes?updated_since='.urlencode($time))->assertOk();

    $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00'));
    $since('2026-06-15T11:00:00+00:00')->assertJsonCount(0, 'data');

    s19Sign('anna@example.test');

    expect($this->withToken($this->view)->getJson("/api/v1/signing/envelopes/{$id}")->json('data.status'))->toBe('COMPLETED');
    $since('2026-06-15T11:00:00+00:00')->assertJsonCount(1, 'data');
    $since('2026-06-15T13:00:00+00:00')->assertJsonCount(0, 'data');
});

it('answers 422 for the wrong shape of a field, never 500 or a silent drop', function () {
    $document = s19Upload($this->owner, $this->company);
    $template = app(ManageSigningTemplates::class)->create($this->owner, $this->company, [
        'name' => 'Form', 'document_type' => 'DOCUMENT',
        'roles' => [['label' => 'Part', 'step' => 1, 'verification' => 'NONE', 'internal' => false]],
        'fields' => [['signer' => 0, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 5000, 'y' => 5000, 'width' => 20000, 'height' => 2000, 'label' => 'U', 'required' => true]],
    ], s19Upload($this->owner, $this->company, 'Mallfil'));
    $send = fn (array $recipient, string $key) => s19Send($this, $this->send, ['template_id' => $template->id, 'recipients' => ['part' => $recipient]], $key);

    $send(['email' => ['a@example.test']], 'sh-1')->assertUnprocessable();
    $send(['email' => 'a@example.test', 'access_code' => ['x']], 'sh-2')->assertUnprocessable();
    $send(['email' => 'a@example.test', 'language' => ['sv']], 'sh-3')->assertUnprocessable();
    $send(['email' => 'a@example.test', 'language' => 'de'], 'sh-4')->assertUnprocessable();
    s19Send($this, $this->send, ['template_id' => $template->id, 'recipients' => 'a@example.test'], 'sh-5')->assertUnprocessable();
    s19Send($this, $this->send, ['document_id' => [$document->id], 'signers' => [['email' => 'a@example.test', 'step' => 1]]], 'sh-6')->assertUnprocessable();
    s19Send($this, $this->send, ['template_id' => [1]], 'sh-7')->assertUnprocessable();

    $upload = fn (array $extra, string $key) => $this->withToken($this->send)->withHeader('Idempotency-Key', $key)
        ->post('/api/v1/signing/documents', ['file' => UploadedFile::fake()->createWithContent('a.pdf', PdfEngineFixtures::pdf(1)), ...$extra], ['Accept' => 'application/json']);
    $upload(['title' => ['x']], 'sh-8')->assertUnprocessable();
    $upload(['title' => 'A', 'customer_id' => 'abc'], 'sh-9')->assertUnprocessable();
    $upload(['title' => 'A', 'supplier_id' => ['1']], 'sh-10')->assertUnprocessable();
    $upload(['title' => 'A', 'customer_id' => '99999'], 'sh-11')->assertUnprocessable();
    expect(CurrentCompany::run($this->company, fn () => SigningDocument::query()->count()))->toBe(2);
});

it('lets the API switch automatic reminders on, and leaves them off otherwise', function () {
    $on = s19Send($this, $this->send, s19Body(s19Upload($this->owner, $this->company), ['auto_reminders' => true, 'reminder_days' => 7]), 'ar-1')->assertCreated()
        ->assertJsonPath('data.auto_reminders', true)->assertJsonPath('data.reminder_interval_days', 7);
    s19Send($this, $this->send, s19Body(s19Upload($this->owner, $this->company, 'Två')), 'ar-2')->assertCreated()->assertJsonPath('data.auto_reminders', false);
    expect($on->json('data.id'))->not->toBeNull();
});
