<?php

declare(strict_types=1);

use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\SigningPdfs;
use App\Mail\SigningRequestMail;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\ManageSigningTemplates;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Connect;
use Tests\Support\PdfEngineFixtures;
use Tests\Support\SigningConsentFixture;

/*
 * AGENTS.md §13.2 for the signing API (ADR-046 S19): a key of company A never
 * sees B's documents, templates, envelopes, recipients, fields or files; B's
 * ids answer 404 on every route, never 403; a webhook of A hears nothing of
 * B's signing.
 */

function s19tDocument(User $user, Company $company, string $title): SigningDocument
{
    $path = tempnam(sys_get_temp_dir(), 's19t');
    file_put_contents($path, PdfEngineFixtures::pdf(2));

    try {
        return app(StoreSigningDocument::class)($user, $company, $path, 'Avtal.pdf', $title);
    } finally {
        @unlink($path);
    }
}

beforeEach(function () {
    Connect::setUp();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    FakePdfEngine::install();

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Connect::company();
        $this->actingAs($owner);
        CurrentCompany::set($company);
        app(ModuleManager::class)->enable(SigningModule::KEY, $company, $owner);

        $document = s19tDocument($owner, $company, "{$name}avtal");
        $template = app(ManageSigningTemplates::class)->create($owner, $company, [
            'name' => "{$name}mall", 'document_type' => 'DOCUMENT',
            'roles' => [['label' => 'Part', 'step' => 1, 'verification' => 'NONE', 'internal' => false]],
            'fields' => [['signer' => 0, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 5000, 'y' => 5000, 'width' => 20000, 'height' => 2000, 'label' => 'Underskrift', 'required' => true]],
        ], s19tDocument($owner, $company, "{$name}mallfil"));
        $envelope = app(SendForSigning::class)->envelope($owner, $company, $document, ['signers' => [['email' => "{$side}@example.test", 'step' => 1]]], minSigners: 1);
        $recipient = CurrentCompany::run($company, fn () => SigningRequest::query()->where('envelope_id', $envelope->id)->firstOrFail());
        $key = Connect::apiKey($owner, $company, ['signing:view', 'signing:send', 'signing:manage'], null, '2026-12-31');
        $this->{$side} = compact('owner', 'company', 'document', 'template', 'envelope', 'recipient', 'key');
    }
});

it('lists only the key\'s own company\'s documents, templates and envelopes', function () {
    $key = $this->a['key'];

    $envelopes = $this->withToken($key)->getJson('/api/v1/signing/envelopes')->assertOk();
    expect(collect($envelopes->json('data'))->pluck('id')->all())->toBe([$this->a['envelope']->id]);

    $documents = $this->withToken($key)->getJson('/api/v1/signing/documents')->assertOk();
    expect(collect($documents->json('data'))->pluck('title')->sort()->values()->all())->toBe(['Alfaavtal', 'Alfamallfil']);

    $templates = $this->withToken($key)->getJson('/api/v1/signing/templates')->assertOk();
    expect(collect($templates->json('data'))->pluck('name')->all())->toBe(['Alfamall']);
});

it('answers 404 for the other company\'s ids on every route, never 403', function () {
    $key = $this->a['key'];
    $envelope = $this->b['envelope']->id;
    $recipient = $this->b['recipient']->id;
    $document = $this->b['document']->id;
    $template = $this->b['template']->id;

    foreach (['', '/recipients', "/recipients/{$recipient}", "/recipients/{$recipient}/certificate", '/fields', '/document'] as $path) {
        $this->withToken($key)->getJson("/api/v1/signing/envelopes/{$envelope}{$path}")->assertNotFound();
    }

    $this->withToken($key)->patchJson("/api/v1/signing/envelopes/{$envelope}", ['expires_on' => '2026-06-30'])->assertNotFound();
    $this->withToken($key)->withHeader('Idempotency-Key', 't-1')->postJson("/api/v1/signing/envelopes/{$envelope}/void")->assertNotFound();
    $this->withToken($key)->withHeader('Idempotency-Key', 't-2')->postJson("/api/v1/signing/envelopes/{$envelope}/recipients/{$recipient}/remind")->assertNotFound();
    // The other company's recipient under one's own envelope is not found either.
    $this->withToken($key)->getJson("/api/v1/signing/envelopes/{$this->a['envelope']->id}/recipients/{$recipient}")->assertNotFound();

    $this->withToken($key)->getJson("/api/v1/signing/documents/{$document}")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/signing/documents/{$document}/content")->assertNotFound();
    $this->withToken($key)->withHeader('Idempotency-Key', 't-3')->deleteJson("/api/v1/signing/documents/{$document}")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/signing/templates/{$template}")->assertNotFound();

    $body = ['signers' => [['email' => 'x@example.test', 'step' => 1]]];
    $this->withToken($key)->withHeader('Idempotency-Key', 't-4')->postJson('/api/v1/signing/envelopes', [...$body, 'document_id' => $document])->assertNotFound();
    $this->withToken($key)->withHeader('Idempotency-Key', 't-5')->postJson('/api/v1/signing/envelopes', ['template_id' => $template, 'recipients' => ['part' => ['email' => 'x@example.test']]])->assertNotFound();

    CurrentCompany::set($this->b['company']);
    expect($this->b['envelope']->fresh()->status->value)->toBe('OUT')
        ->and(SigningDocument::query()->count())->toBe(2);
    Mail::assertQueued(SigningRequestMail::class, 2);
});

it('lets a webhook hear only of its own company\'s signing', function () {
    Http::fake(['*' => Http::response('', 200)]);
    Connect::fakeDns(['hooks.example.com' => ['93.184.216.34']]);
    Connect::webhook($this->a['owner'], $this->a['company'], ['signing.recipient_signed', 'signing.completed']);

    // Company B's signer signs; company A's subscriber hears nothing.
    $links = app(SignAtLink::class);
    $url = null;
    Mail::assertQueued(SigningRequestMail::class, function (SigningRequestMail $mail) use (&$url): bool {
        if ($mail->hasTo('b@example.test')) {
            $url = $mail->url;
        }

        return true;
    });
    $request = $links->find(basename((string) parse_url((string) $url, PHP_URL_PATH)));
    SigningConsentFixture::give($request);
    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    ob_start();
    imagepng($image);
    $png = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    $links->sign($request, ['name' => 'Bo Berg', 'accept' => '1', 'method' => 'DRAWN', 'signature' => $png, 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)], '203.0.113.7', 'Pest');

    Http::assertNothingSent();
    expect(CompanyScope::bypass(fn () => ConnectWebhookDelivery::query()->count()))->toBe(0)
        ->and(CurrentCompany::run($this->b['company'], fn () => SigningEnvelope::query()->where('status', 'COMPLETED')->count()))->toBe(1);
});
