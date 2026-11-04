<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Domain\Invoicing\SalesDocumentStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\SalesDocument;
use App\Modules\Crm\Actions\SendForSigning;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Domain\SigningStatus;
use App\Modules\Crm\Domain\SigningToken;
use App\Modules\Crm\Events\QuoteSigned;
use App\Modules\Crm\Mail\SignedDocumentMail;
use App\Modules\Crm\Mail\SigningRequestMail;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\SigningEvent;
use App\Modules\Crm\Models\SigningRequest;
use App\Modules\Crm\Notifications\SigningNotice;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * CRM stage 3 (ADR-046): a quote sent for signing with a signature pad. The
 * link is a 256-bit token stored as a hash, used once; the signature binds
 * to the exact PDF shown; a signed quote becomes an order, the deal is won,
 * a note is logged, the seller is told and both sides get a copy.
 */

/** A 1×1 PNG, as the signature pad would send it (but tiny). */
function signaturePng(int $width = 300, int $height = 100): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imageline($image, 10, 50, $width - 10, 60, imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB', ['email' => 'inköp@kontorshuset.test']);
    $this->account = Crm::accountFor($this->customer);
    $this->deal = Crm::deal($this->company, $this->owner, $this->account, 'Ramavtal');
    $this->quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-06-15',
        'until_date' => '2026-07-15',
        'notes' => 'Betalning 30 dagar.',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]],
    ]);
});

/** Sends the quote and returns the token from the queued mail. */
function sendQuote(object $test, array $extra = []): array
{
    $sent = app(SendForSigning::class)($test->owner, $test->company, $test->quote, ['email' => 'Anna@Kontorshuset.test', 'deal_id' => $test->deal->id, ...$extra]);
    $token = basename(parse_url($sent['url'], PHP_URL_PATH) ?: '');

    return [$sent['request'], $token];
}

function signInput(SigningRequest $request, array $extra = []): array
{
    return ['name' => 'Anna Andersson', 'title' => 'Inköpschef', 'accept' => '1', 'method' => 'DRAWN', 'signature' => signaturePng(), 'document_sha256' => $request->document_sha256, ...$extra];
}

it('sends a quote for signing: the exact PDF with its hash, a hashed 256-bit token, a SENT event and a queued mail', function () {
    [$request, $token] = sendQuote($this);

    expect(SigningToken::isWellFormed($token))->toBeTrue()
        ->and(strlen(base64_decode(strtr($token, '-_', '+/'), true) ?: ''))->toBe(32)
        ->and($request->token_hash)->toBe(hash('sha256', $token))
        ->and(SigningRequest::query()->where('token_hash', $token)->exists())->toBeFalse()
        ->and($request->email)->toBe('anna@kontorshuset.test')
        ->and($request->status)->toBe(SigningStatus::Pending)
        ->and($request->expires_at->setTimezone('Europe/Stockholm')->format('Y-m-d H:i'))->toBe('2026-07-15 23:59')
        ->and(hash('sha256', (string) Storage::disk('local')->get($request->document_path)))->toBe($request->document_sha256)
        ->and(SigningEvent::query()->where('signing_request_id', $request->id)->pluck('event')->map->value->all())->toBe(['SENT']);

    Mail::assertQueued(SigningRequestMail::class, fn (SigningRequestMail $mail): bool => $mail->hasTo('anna@kontorshuset.test') && str_contains($mail->url, $token));

    $payload = AuditLog::query()->where('action', 'crm.signing_sent')->sole()->payload;
    expect(json_encode($payload))->not->toContain('kontorshuset.test');
});

it('shows the public page without login, never indexed, never framed and without a referrer; the first visit is OPENED once', function () {
    [$request, $token] = sendQuote($this);
    auth()->logout();

    $response = $this->get("/signera/{$token}");

    $response->assertOk()
        ->assertSee('Offert '.$this->quote->number.' att signera')
        ->assertSee('Konsulttimme')
        ->assertSee('Betalning 30 dagar.')
        ->assertSee($request->document_sha256)
        ->assertSee('Jag har läst och godkänner offerten.')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")->toContain("script-src 'self'")->not->toContain('unsafe-inline\' \'unsafe-eval')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $this->get("/signera/{$token}")->assertOk();
    CurrentCompany::set($this->company);
    expect(SigningEvent::query()->where('signing_request_id', $request->id)->where('event', 'OPENED')->count())->toBe(1);

    $pdf = $this->get("/signera/{$token}/dokument");
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(hash('sha256', (string) $pdf->getContent()))->toBe($request->document_sha256);
});

it('answers an unknown or malformed token with the same message and nothing about any document', function () {
    sendQuote($this);
    auth()->logout();

    $this->get('/signera/'.str_repeat('A', 43))->assertNotFound()->assertSee('Länken gäller inte')->assertDontSee('Kontorshuset');
    $this->get('/signera/kort')->assertNotFound()->assertSee('Länken gäller inte');
    $this->post('/signera/'.str_repeat('B', 43), [])->assertNotFound();
});

it('signs: evidence kept, a certificate made, the quote becomes an order, the deal is won, a note, a notice and two copies', function () {
    Event::fake([QuoteSigned::class]);
    [$request, $token] = sendQuote($this);
    auth()->logout();

    $this->post("/signera/{$token}", signInput($request), ['User-Agent' => 'Testläsare/1.0'])->assertOk()->assertSee('Tack! Dokumentet är signerat.');
    CurrentCompany::set($this->company);

    $signed = SigningRequest::query()->findOrFail($request->id);
    expect($signed->status)->toBe(SigningStatus::Signed)
        ->and($signed->signer_name)->toBe('Anna Andersson')
        ->and($signed->signer_title)->toBe('Inköpschef')
        ->and($signed->signature_method)->toBe('DRAWN')
        ->and(str_starts_with((string) base64_decode((string) $signed->signature_image), "\x89PNG"))->toBeTrue()
        ->and(DB::table('crm_signing_requests')->where('id', $request->id)->value('signature_image'))->not->toStartWith('iVBOR')
        ->and($signed->signer_ip)->toBe('127.0.0.1')
        ->and($signed->signer_user_agent)->toBe('Testläsare/1.0')
        ->and(hash('sha256', (string) Storage::disk('local')->get((string) $signed->signed_document_path)))->toBe($signed->signed_document_sha256)
        ->and($signed->followed_up_at)->not->toBeNull()
        ->and($signed->order_id)->not->toBeNull()
        ->and($signed->invoice_id)->toBeNull();

    $order = SalesDocument::query()->findOrFail($signed->order_id);
    expect($order->kind)->toBe(SalesDocument::ORDER)
        ->and($order->from_quote_id)->toBe($this->quote->id)
        ->and($this->quote->refresh()->status)->toBe(SalesDocumentStatus::Accepted)
        ->and($this->deal->refresh()->status)->toBe(DealStatus::Won)
        ->and($this->deal->quote_id)->toBe($this->quote->id)
        ->and($this->deal->order_id)->toBe($order->id)
        ->and(Activity::query()->where('account_id', $this->account->id)->where('subject', 'Offert '.$this->quote->number.' signerades')->whereNotNull('done_at')->exists())->toBeTrue()
        ->and($this->owner->notifications()->where('type', SigningNotice::class)->count())->toBe(1)
        ->and(SigningEvent::query()->where('signing_request_id', $request->id)->pluck('event')->map->value->all())->toBe(['SENT', 'SIGNED']);

    Mail::assertQueued(SignedDocumentMail::class, 2);
    Mail::assertQueued(SignedDocumentMail::class, fn (SignedDocumentMail $mail): bool => $mail->hasTo('anna@kontorshuset.test') && $mail->toSigner);
    Event::assertDispatched(QuoteSigned::class, fn (QuoteSigned $event): bool => $event->signingRequestId === $request->id);

    $audit = AuditLog::query()->whereIn('action', ['crm.signing_signed', 'crm.signing_followed_up'])->pluck('payload')->toJson();
    expect($audit)->not->toContain('Anna')->not->toContain('kontorshuset.test');
});

it('makes an invoice draft too when the sender chose so', function () {
    [$request, $token] = sendQuote($this, ['create_invoice' => true]);

    $this->post("/signera/{$token}", signInput($request))->assertOk();

    $signed = SigningRequest::query()->findOrFail($request->id);
    $invoice = Invoice::query()->findOrFail($signed->invoice_id);
    expect($invoice->isDraft())->toBeTrue()
        ->and($this->deal->refresh()->invoice_id)->toBe($invoice->id);
});

it('signs once: a second submit, or a replay, makes no second order', function () {
    [$request, $token] = sendQuote($this);

    $this->post("/signera/{$token}", signInput($request))->assertOk();
    $this->post("/signera/{$token}", signInput($request, ['name' => 'Någon Annan']))->assertStatus(410)->assertSee('redan signerat');

    expect(SalesDocument::query()->where('kind', SalesDocument::ORDER)->count())->toBe(1)
        ->and(SigningRequest::query()->findOrFail($request->id)->signer_name)->toBe('Anna Andersson');
});

it('binds the signature to the PDF shown: another hash is refused, and a changed quote cannot be signed', function () {
    [$request, $token] = sendQuote($this);

    $this->post("/signera/{$token}", signInput($request, ['document_sha256' => str_repeat('0', 64)]))->assertRedirect("/signera/{$token}");
    expect(SigningRequest::query()->findOrFail($request->id)->status)->toBe(SigningStatus::Pending);

    app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '12', 'price' => '1000', 'vat_rate' => 2500]],
    ], $this->quote->refresh());

    $this->post("/signera/{$token}", signInput($request))->assertStatus(410)->assertSee('har ändrats sedan länken skickades');
    expect(SigningRequest::query()->findOrFail($request->id)->status)->toBe(SigningStatus::Revoked)
        ->and(SalesDocument::query()->where('kind', SalesDocument::ORDER)->exists())->toBeFalse();
    $this->get("/signera/{$token}")->assertStatus(410)->assertSee('har återkallat länken');
});

it('refuses an expired link and notes EXPIRED once', function () {
    [$request, $token] = sendQuote($this, ['expires_on' => '2026-06-20']);
    $this->travelTo(CarbonImmutable::parse('2026-06-21 08:00:00'));

    $this->get("/signera/{$token}")->assertStatus(410)->assertSee('Länken har gått ut');
    $this->get("/signera/{$token}")->assertStatus(410);
    $this->post("/signera/{$token}", signInput($request))->assertStatus(410);

    expect(SigningEvent::query()->where('signing_request_id', $request->id)->where('event', 'EXPIRED')->count())->toBe(1)
        ->and($request->refresh()->effectiveStatus())->toBe(SigningStatus::Expired);
});

it('declines with a reason: a note and a notice, and the quote stays open', function () {
    [$request, $token] = sendQuote($this);

    $this->post("/signera/{$token}/avboj", ['reason' => 'För dyrt just nu.'])->assertOk()->assertSee('Du har avböjt dokumentet.');

    expect($request->refresh()->status)->toBe(SigningStatus::Declined)
        ->and($request->decline_reason)->toBe('För dyrt just nu.')
        ->and($this->quote->refresh()->isOpen())->toBeTrue()
        ->and(Activity::query()->where('account_id', $this->account->id)->where('body', 'Skäl: För dyrt just nu.')->exists())->toBeTrue()
        ->and($this->owner->notifications()->where('type', SigningNotice::class)->count())->toBe(1);
    $this->post("/signera/{$token}", signInput($request))->assertStatus(410);
});

it('takes a typed name as signature only when it repeats the name', function () {
    [$request, $token] = sendQuote($this);

    $this->post("/signera/{$token}", signInput($request, ['method' => 'TYPED', 'signature' => '', 'typed_signature' => 'Anna']))
        ->assertRedirect()->assertSessionHasErrors('typed_signature');
    $this->post("/signera/{$token}", signInput($request, ['method' => 'TYPED', 'signature' => '', 'typed_signature' => ' anna  andersson ']))->assertOk();

    expect($request->refresh()->signature_method)->toBe('TYPED')->and($request->signature_image)->toBeNull();
});

it('refuses a signature that is not a small PNG, and a missing confirmation', function () {
    [$request, $token] = sendQuote($this);

    $this->post("/signera/{$token}", signInput($request, ['signature' => 'data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"/>')]))->assertSessionHasErrors('signature');
    $this->post("/signera/{$token}", signInput($request, ['signature' => 'data:image/png;base64,'.base64_encode('not a png')]))->assertSessionHasErrors('signature');
    $this->post("/signera/{$token}", signInput($request, ['signature' => signaturePng(3000, 100)]))->assertSessionHasErrors('signature');
    $this->post("/signera/{$token}", signInput($request, ['accept' => null]))->assertSessionHasErrors('accept');

    expect($request->refresh()->status)->toBe(SigningStatus::Pending);
});

it('escapes the signer\'s name everywhere it is shown', function () {
    [$request, $token] = sendQuote($this);
    $this->post("/signera/{$token}", signInput($request, ['name' => '<script>alert(1)</script>']))->assertOk();

    $this->actingAs($this->owner);
    $this->get(route('invoicing.quotes.show', $this->quote))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('protects the evidence: a signed request cannot be changed, and events are append-only', function () {
    [$request, $token] = sendQuote($this);
    $this->post("/signera/{$token}", signInput($request))->assertOk();

    // Each attempt in its own savepoint, so the first refusal does not abort the test's transaction.
    expect(fn () => DB::transaction(fn () => DB::table('crm_signing_requests')->where('id', $request->id)->update(['signer_name' => 'Någon annan'])))->toThrow(QueryException::class, 'cannot be changed')
        ->and(fn () => DB::transaction(fn () => DB::table('crm_signing_events')->where('signing_request_id', $request->id)->update(['event' => 'REVOKED'])))->toThrow(QueryException::class, 'append-only');
});

it('lets only those who make and send quotes send them for signing, and revokes the earlier link', function () {
    $seller = Bookkeeping::member($this->company, 'sales');
    $auditor = Bookkeeping::member($this->company, 'auditor');

    expect(fn () => app(SendForSigning::class)($seller, $this->company, $this->quote, ['email' => 'a@b.test']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SendForSigning::class)($auditor, $this->company, $this->quote, ['email' => 'a@b.test']))->toThrow(AuthorizationException::class);

    [$first, $firstToken] = sendQuote($this);
    [$second] = sendQuote($this);

    expect($first->refresh()->status)->toBe(SigningStatus::Revoked)
        ->and($second->status)->toBe(SigningStatus::Pending);
    $this->get("/signera/{$firstToken}")->assertStatus(410)->assertSee('har återkallat länken');

    app(SendForSigning::class)->revoke($this->owner, $this->company, $second);
    expect($second->refresh()->status)->toBe(SigningStatus::Revoked);
});

it('refuses a deal of another account, an expiry beyond 90 days, and a closed quote', function () {
    $other = Crm::prospect($this->company, $this->owner, 'Annat AB');
    $otherDeal = Crm::deal($this->company, $this->owner, $other, 'Annat');

    expect(fn () => sendQuote($this, ['deal_id' => $otherDeal->id]))->toThrow(ValidationException::class, 'Affären finns inte')
        ->and(fn () => sendQuote($this, ['expires_on' => '2026-12-31']))->toThrow(ValidationException::class, 'inom 90 dagar');

    $this->quote->forceFill(['status' => SalesDocumentStatus::Cancelled])->save();
    expect(fn () => sendQuote($this))->toThrow(AuthorizationException::class);
});

it('closes every link while CRM is off', function () {
    [$request, $token] = sendQuote($this);
    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, confirmed: true);

    $this->get("/signera/{$token}")->assertNotFound()->assertSee('Länken gäller inte');
    $this->post("/signera/{$token}", signInput($request))->assertStatus(410);
    expect($request->refresh()->status)->toBe(SigningStatus::Pending);
});
