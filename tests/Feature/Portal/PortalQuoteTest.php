<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\SendForSigning;
use App\Domain\Invoicing\SalesDocumentStatus;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Mail\SignedDocumentMail;
use App\Models\SalesDocument;
use App\Models\SigningRequest;
use App\Modules\Portal\Domain\EventType;
use App\Modules\Portal\Models\PortalEvent;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Bookkeeping;
use Tests\Support\Portal;

/*
 * Accepting quotes in the portal (ADR-075 §7) through the shared signing
 * domain: the same evidence and certificate as a link, the same follow-up
 * (an order), and open links revoked under the same locks, both ways.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Mail::fake();
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    Portal::enable($this->company, $this->owner);
    $this->customer = Portal::customer($this->company, $this->owner);
    $this->key = Portal::key($this->company);
    $this->contact = Portal::contact($this->company, $this->owner, $this->customer);
    $this->quote = CurrentCompany::run($this->company, fn (): SalesDocument => app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]],
    ]));
    $this->quote->forceFill(['sent_at' => now(), 'sent_to' => 'anna@kontorshuset.test'])->save();
});

function decide(object $test, array $input): TestResponse
{
    return $test->post(route('portal.quotes.decide', ['portalKey' => $test->key, 'document' => $test->quote->id]), [
        'fingerprint' => CurrentCompany::run($test->company, fn (): string => $test->quote->fresh()->signingFingerprint()),
        ...$input,
    ]);
}

it('shows only sent quotes, and accepts one with the same evidence and follow-up as a signing link', function () {
    $unsent = CurrentCompany::run($this->company, fn (): SalesDocument => app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $this->customer->id, 'document_date' => '2026-06-15', 'rows' => [['description' => 'Utkast', 'quantity' => '1', 'price' => '10', 'vat_rate' => 2500]]]));
    Portal::signIn($this, $this->contact);

    $this->get(route('portal.quotes', ['portalKey' => $this->key]))->assertOk()->assertSee($this->quote->title())->assertDontSee('>'.$unsent->title().'<', false);
    $this->get(route('portal.quotes.show', ['portalKey' => $this->key, 'document' => $unsent->id]))->assertNotFound();
    $this->get(route('portal.quotes.show', ['portalKey' => $this->key, 'document' => $this->quote->id]))->assertOk()
        ->assertSee('Jag har läst och godkänner offerten och har rätt att godkänna den för Kontorshuset AB.');

    decide($this, ['decision' => 'accept', 'name' => 'Anna Andersson'])->assertSessionHasErrors('accept');
    decide($this, ['decision' => 'accept', 'name' => 'Anna Andersson', 'accept' => '1'])->assertRedirect();
    CurrentCompany::set($this->company);

    $request = SigningRequest::query()->where('sales_document_id', $this->quote->id)->sole();
    expect($request->status)->toBe(SigningStatus::Signed)
        ->and($request->channel)->toBe('PORTAL')
        ->and($request->portal_contact_id)->toBe($this->contact->id)
        ->and($request->email)->toBe('anna@kontorshuset.test')
        ->and($request->acceptance_text)->toBe('Jag har läst och godkänner offerten och har rätt att godkänna den för Kontorshuset AB.')
        ->and($request->signer_ip)->not->toBeNull()
        ->and(hash('sha256', (string) Storage::disk('local')->get($request->document_path)))->toBe($request->document_sha256)
        ->and($request->signed_document_path)->not->toBeNull()
        ->and($request->order_id)->not->toBeNull()
        ->and($this->quote->refresh()->status)->toBe(SalesDocumentStatus::Accepted)
        ->and(PortalEvent::query()->where('event', EventType::AcceptedQuote->value)->exists())->toBeTrue();
    Mail::assertQueued(SignedDocumentMail::class);

    $certificate = $this->get(route('signing.file', ['signing' => $request->id, 'file' => 'signeringsbevis']));
    expect($certificate)->not->toBeNull();
});

it('refuses a quote that changed after the page showed it', function () {
    Portal::signIn($this, $this->contact);
    $old = CurrentCompany::run($this->company, fn (): string => $this->quote->signingFingerprint());
    CurrentCompany::run($this->company, fn () => app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $this->customer->id, 'document_date' => '2026-06-15', 'rows' => [['description' => 'Konsulttimme', 'quantity' => '12', 'price' => '1000', 'vat_rate' => 2500]]], $this->quote));

    $this->post(route('portal.quotes.decide', ['portalKey' => $this->key, 'document' => $this->quote->id]), ['decision' => 'accept', 'name' => 'Anna', 'accept' => '1', 'fingerprint' => $old])
        ->assertSessionHasErrors('document');
    CurrentCompany::set($this->company);
    expect(SigningRequest::query()->count())->toBe(0);
});

it('revokes an open signing link when the quote is accepted in the portal, and the reverse', function () {
    $sent = CurrentCompany::run($this->company, fn () => app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']));
    Portal::signIn($this, $this->contact);

    decide($this, ['decision' => 'accept', 'name' => 'Anna Andersson', 'accept' => '1'])->assertRedirect();
    CurrentCompany::set($this->company);
    expect($sent['request']->refresh()->status)->toBe(SigningStatus::Revoked);

    $token = basename((string) parse_url($sent['url'], PHP_URL_PATH));
    $this->get("/signera/{$token}")->assertStatus(410);
});

it('cannot accept in the portal what was signed at a link', function () {
    $sent = CurrentCompany::run($this->company, fn () => app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']));
    $token = basename((string) parse_url($sent['url'], PHP_URL_PATH));
    $this->post("/signera/{$token}", ['name' => 'Anna Andersson', 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => 'Anna Andersson', 'document_sha256' => $sent['request']->document_sha256, ...CurrentCompany::run($this->company, fn () => SigningPdfs::acceptanceFields($sent['request']))])->assertOk();

    Portal::signIn($this, $this->contact);
    $this->get(route('portal.quotes.show', ['portalKey' => $this->key, 'document' => $this->quote->id]))->assertOk()->assertSee('Godkänd')->assertDontSee('name="accept"', false);
    decide($this, ['decision' => 'accept', 'name' => 'Anna', 'accept' => '1'])->assertSessionHasErrors('document');
});

it('declines with a reason, keeping no IP address, and a contact without the right cannot decide', function () {
    Portal::signIn($this, $this->contact);
    decide($this, ['decision' => 'decline', 'reason' => 'För dyrt'])->assertRedirect();
    CurrentCompany::set($this->company);

    $request = SigningRequest::query()->sole();
    expect($request->status)->toBe(SigningStatus::Declined)->and($request->signer_ip)->toBeNull()->and($request->decline_reason)->toBe('För dyrt');

    $this->contact->forceFill(['can_accept_quotes' => false])->save();
    decide($this, ['decision' => 'accept', 'name' => 'Anna', 'accept' => '1'])->assertForbidden();
});
