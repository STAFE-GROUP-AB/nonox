<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Actions\Signing\SignInSession;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\SignedDocuments;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\SigningToken;
use App\Livewire\Signing\SigningPanel;
use App\Mail\SignedDocumentMail;
use App\Mail\SigningEnvelopeMail;
use App\Mail\SigningRequestMail;
use App\Models\AuditLog;
use App\Models\SalesDocument;
use App\Models\SigningCopyRecipient;
use App\Models\SigningEnvelope;
use App\Models\SigningLinkToken;
use App\Models\SigningRequest;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Models\Agreement;
use App\Support\Branding\Brand;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * Envelopes (ADR-046 amendment 2026-11-18, gap 1, package S1): several
 * signers in a set order (steps; the same step signs in parallel), copy
 * recipients, a decline ends the envelope, the sender can withdraw it, and
 * the document's follow-up runs once when everyone has signed. A quote with
 * several signers becomes an order only then (owner decision 21). Every
 * one-signer send is a one-signer envelope that behaves as a link always
 * has.
 */

function s1Png(): string
{
    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imageline($image, 10, 50, 290, 60, imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

/** @return array<string, mixed> */
function s1SignInput(SigningRequest $request, string $name = 'Anna Andersson'): array
{
    return ['name' => $name, 'accept' => '1', 'method' => 'DRAWN', 'signature' => s1Png(), 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)];
}

/**
 * The link mailed to an address, from the queued mails.
 */
function s1LinkFor(string $email): ?string
{
    $url = null;

    Mail::assertQueued(SigningRequestMail::class, function (SigningRequestMail $mail) use ($email, &$url): bool {
        if ($mail->hasTo($email)) {
            $url = $mail->url;
        }

        return true;
    });

    return $url === null ? null : basename((string) parse_url($url, PHP_URL_PATH));
}

/** @param  list<array{email: string, step: int}>  $signers */
function s1Send(object $test, array $signers, array $extra = []): SigningEnvelope
{
    return app(SendForSigning::class)->envelope($test->owner, $test->company, $test->quote, ['signers' => $signers, 'deal_id' => $test->deal->id, ...$extra]);
}

function s1Sign(string $token, string $name = 'Anna Andersson'): SigningRequest
{
    $links = app(SignAtLink::class);
    $request = $links->find($token);
    expect($request)->toBeInstanceOf(SigningRequest::class);

    return $links->sign($request, s1SignInput($request, $name), '203.0.113.7', 'Pest');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB', ['email' => 'inkop@kontorshuset.test']);
    $this->account = Crm::accountFor($this->customer);
    $this->deal = Crm::deal($this->company, $this->owner, $this->account, 'Ramavtal');
    $this->quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-06-15',
        'until_date' => '2026-07-15',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]],
    ]);
});

it('sends one signer as a one-signer envelope that behaves as a link always has', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    $request = $sent['request'];
    $envelope = SigningEnvelope::query()->sole();

    expect($request->envelope_id)->toBe($envelope->id)
        ->and($request->step)->toBe(1)
        ->and($request->token_hash)->toBe(SigningToken::hash(basename((string) parse_url($sent['url'], PHP_URL_PATH))))
        ->and($envelope->status)->toBe(EnvelopeStatus::Out)
        ->and($envelope->document_sha256)->toBe($request->document_sha256)
        ->and(SigningLinkToken::query()->count())->toBe(0);

    s1Sign(basename((string) parse_url($sent['url'], PHP_URL_PATH)));

    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Completed)
        ->and($envelope->followed_up_at)->not->toBeNull()
        ->and($this->quote->refresh()->status->value)->not->toBe('SENT')
        ->and(AuditLog::query()->where('action', 'like', 'crm.signing_envelope%')->count())->toBe(0);
});

it('signs in order: the second step gets its link only when the first has signed, and the order is made when both have', function () {
    $envelope = s1Send($this, [['email' => 'Anna@Kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 2]]);

    expect($envelope->status)->toBe(EnvelopeStatus::Out)->and($envelope->current_step)->toBe(1);
    Mail::assertQueuedCount(1);
    expect(s1LinkFor('bo@kontorshuset.test'))->toBeNull();

    s1Sign((string) s1LinkFor('anna@kontorshuset.test'));

    $quote = SalesDocument::query()->findOrFail($this->quote->id);
    expect($envelope->refresh()->current_step)->toBe(2)
        ->and($envelope->status)->toBe(EnvelopeStatus::Out)
        ->and(SalesDocument::query()->where('kind', SalesDocument::ORDER)->count())->toBe(0)
        ->and($this->deal->refresh()->status)->toBe(DealStatus::Open)
        ->and(SignedDocuments::isQuoteSigned($quote->id))->toBeTrue();

    // The quote stays fixed while the envelope is out: the next signer signs what the first signed.
    expect(fn () => app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $this->customer->id, 'document_date' => '2026-06-15', 'rows' => [['description' => 'Annat', 'quantity' => '1', 'price' => '1', 'vat_rate' => 2500]]], $quote))
        ->toThrow(ValidationException::class);

    s1Sign((string) s1LinkFor('bo@kontorshuset.test'), 'Bo Berg');

    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Completed)
        ->and($envelope->followed_up_at)->not->toBeNull()
        ->and(SalesDocument::query()->where('kind', SalesDocument::ORDER)->count())->toBe(1)
        ->and($this->deal->refresh()->status)->toBe(DealStatus::Won)
        ->and(SigningRequest::query()->where('envelope_id', $envelope->id)->where('status', SigningStatus::Signed->value)->count())->toBe(2);

    $payloads = AuditLog::query()->where('action', 'like', 'crm.signing_envelope%')->pluck('payload')->map(fn ($payload): string => (string) json_encode($payload))->implode(' ');
    expect($payloads)->not->toContain('kontorshuset')->not->toContain('Anna')->not->toContain('203.0.113');
});

it('signs in parallel: signers on the same step get their links at once, and the next step waits for both', function () {
    $envelope = s1Send($this, [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 1], ['email' => 'cia@kontorshuset.test', 'step' => 2]]);

    expect(s1LinkFor('anna@kontorshuset.test'))->not->toBeNull()
        ->and(s1LinkFor('bo@kontorshuset.test'))->not->toBeNull()
        ->and(s1LinkFor('cia@kontorshuset.test'))->toBeNull();

    s1Sign((string) s1LinkFor('bo@kontorshuset.test'), 'Bo Berg');
    expect($envelope->refresh()->current_step)->toBe(1)->and(s1LinkFor('cia@kontorshuset.test'))->toBeNull();

    s1Sign((string) s1LinkFor('anna@kontorshuset.test'));
    expect($envelope->refresh()->current_step)->toBe(2)->and(s1LinkFor('cia@kontorshuset.test'))->not->toBeNull();

    s1Sign((string) s1LinkFor('cia@kontorshuset.test'), 'Cia Ceder');
    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Completed);
});

it('mails the copy recipients and every signer only a summary when all have signed; the full certificates go to the company', function () {
    $envelope = s1Send($this, [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 1]], [
        'copies' => [['email' => 'revisor@kontorshuset.test', 'name' => 'Revisorn']],
        'notify_copies' => true,
    ]);

    Mail::assertQueued(SigningEnvelopeMail::class, fn (SigningEnvelopeMail $mail): bool => $mail->hasTo('revisor@kontorshuset.test') && $mail->kind === SigningEnvelopeMail::NOTICE);
    expect(SigningCopyRecipient::query()->sole()->notified_at)->not->toBeNull()
        ->and((new SigningEnvelopeMail($envelope->id, $this->company->id, SigningEnvelopeMail::NOTICE))->render())->toContain('Personuppgiftsansvarig är')->toContain('Integritetsskyddsmyndigheten');

    s1Sign((string) s1LinkFor('anna@kontorshuset.test'));
    Mail::assertNotQueued(SigningEnvelopeMail::class, fn (SigningEnvelopeMail $mail): bool => $mail->kind === SigningEnvelopeMail::COPY);

    s1Sign((string) s1LinkFor('bo@kontorshuset.test'), 'Bo Berg');

    /** @var array<string, SigningEnvelopeMail> $copies */
    $copies = [];
    $company = null;
    Mail::assertQueued(SigningEnvelopeMail::class, function (SigningEnvelopeMail $mail) use (&$copies, &$company): bool {
        foreach (['revisor@kontorshuset.test', 'anna@kontorshuset.test', 'bo@kontorshuset.test'] as $email) {
            if ($mail->kind === SigningEnvelopeMail::COPY && $mail->hasTo($email)) {
                $copies[$email] = $mail;
            }
        }

        if ($mail->kind === SigningEnvelopeMail::COMPANY) {
            $company = $mail;
        }

        return true;
    });

    // Copy recipients and signers: the document and one summary, never another signer's full certificate.
    expect(array_keys($copies))->toEqualCanonicalizing(['revisor@kontorshuset.test', 'anna@kontorshuset.test', 'bo@kontorshuset.test'])
        ->and(count($copies['revisor@kontorshuset.test']->attachments()))->toBe(2)
        ->and($copies['anna@kontorshuset.test']->toSigner)->toBeTrue()
        ->and($copies['revisor@kontorshuset.test']->render())->toContain('Personuppgiftsansvarig är')
        ->and(SigningCopyRecipient::query()->sole()->sent_copy_at)->not->toBeNull();

    // The company: the document and one full certificate per signer.
    expect($company)->not->toBeNull()->and(count($company->attachments()))->toBe(3);

    $summary = view('signing.summary', ['envelope' => $envelope->refresh(), 'signers' => SigningRequest::query()->where('envelope_id', $envelope->id)->get(), 'company' => $this->company, 'brand' => Brand::for($this->company), 'title' => (string) $envelope->title])->render();
    expect($summary)->toContain('Anna Andersson')->toContain('Bo Berg')->toContain($envelope->document_sha256)
        ->not->toContain('203.0.113.7')->not->toContain('Pest')->not->toContain('kontorshuset.test')->not->toContain('data:image/png');
    expect(SigningPdfs::envelopeSummary($envelope))->toStartWith('%PDF');

    // Each signer got their own full certificate when they signed, as a single signer always has.
    Mail::assertQueued(SignedDocumentMail::class, fn (SignedDocumentMail $mail): bool => $mail->hasTo('anna@kontorshuset.test'));
    Mail::assertQueued(SignedDocumentMail::class, fn (SignedDocumentMail $mail): bool => $mail->hasTo('bo@kontorshuset.test'));
    expect($envelope->status)->toBe(EnvelopeStatus::Completed);
});

it('tells a signer on the page that the others get the names and times when several sign', function () {
    s1Send($this, [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 2]]);
    auth()->logout();

    $this->get('/signera/'.s1LinkFor('anna@kontorshuset.test'))->assertOk()->assertSee('Fler än du signerar dokumentet');
});

it('refuses acceptance in the customer portal while an envelope with several signers is out', function () {
    s1Send($this, [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 2]]);

    expect(fn () => app(SignInSession::class)->accept($this->quote->refresh(), ['email' => 'anna@kontorshuset.test', 'name' => 'Anna Andersson', 'contact_id' => null, 'ip' => null, 'user_agent' => null], 'Jag godkänner.', $this->quote->signingFingerprint()))
        ->toThrow(ValidationException::class, 'Offerten väntar på signering av flera.');

    expect(SigningEnvelope::query()->sole()->status)->toBe(EnvelopeStatus::Out)
        ->and(SigningRequest::query()->where('status', SigningStatus::Revoked->value)->count())->toBe(0);
});

it('frees a partly signed quote again once the envelope\'s last day has passed', function () {
    s1Send($this, [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 2]], ['expires_on' => '2026-06-20']);
    s1Sign((string) s1LinkFor('anna@kontorshuset.test'));

    expect(SignedDocuments::isQuoteSigned($this->quote->id))->toBeTrue()
        ->and(fn () => app(SendForSigning::class)($this->owner, $this->company, $this->quote->refresh(), ['email' => 'ny@kontorshuset.test']))->toThrow(ValidationException::class, 'Offerten väntar på signering av flera');

    $this->travelTo(CarbonImmutable::parse('2026-06-21 10:00:00'));

    expect(SignedDocuments::isQuoteSigned($this->quote->id))->toBeFalse();
    app(SendForSigning::class)($this->owner, $this->company, $this->quote->refresh(), ['email' => 'ny@kontorshuset.test', 'expires_on' => '2026-06-30']);
    expect(SigningEnvelope::query()->where('status', EnvelopeStatus::Voided->value)->count())->toBe(1);
});

it('ends the envelope for everyone when one signer declines, and tells those who signed without the reason', function () {
    $envelope = s1Send($this, [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 2], ['email' => 'cia@kontorshuset.test', 'step' => 2], ['email' => 'dan@kontorshuset.test', 'step' => 3]]);
    s1Sign((string) s1LinkFor('anna@kontorshuset.test'));

    $bo = app(SignAtLink::class)->find((string) s1LinkFor('bo@kontorshuset.test'));
    app(SignAtLink::class)->decline($bo, 'Fel belopp');

    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Declined)
        ->and(SigningRequest::query()->where('envelope_id', $envelope->id)->whereIn('email', ['cia@kontorshuset.test', 'dan@kontorshuset.test'])->pluck('status')->all())->toBe([SigningStatus::Revoked, SigningStatus::Revoked])
        ->and(s1LinkFor('dan@kontorshuset.test'))->toBeNull()
        ->and(SignedDocuments::isQuoteSigned($this->quote->id))->toBeFalse()
        ->and(SalesDocument::query()->where('kind', SalesDocument::ORDER)->count())->toBe(0);
    Mail::assertQueued(SigningEnvelopeMail::class, fn (SigningEnvelopeMail $mail): bool => $mail->hasTo('anna@kontorshuset.test') && $mail->kind === SigningEnvelopeMail::DECLINED);
    $html = (new SigningEnvelopeMail($envelope->id, $this->company->id, SigningEnvelopeMail::DECLINED))->render();
    expect($html)->not->toContain('Fel belopp');

    auth()->logout();
    $this->get('/signera/'.s1LinkFor('bo@kontorshuset.test'))->assertStatus(410)->assertSee('Du har avböjt dokumentet');
    $this->get('/signera/'.s1LinkFor('anna@kontorshuset.test'))->assertStatus(410)->assertSee('Dokumentet är redan signerat');
    $this->get('/signera/'.s1LinkFor('cia@kontorshuset.test'))->assertStatus(410)->assertSee('En annan undertecknare har avböjt dokumentet');
});

it('withdraws an envelope from the panel: every open link answers that the document has been withdrawn', function () {
    $envelope = s1Send($this, [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 1]]);
    $token = (string) s1LinkFor('anna@kontorshuset.test');

    Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id])
        ->assertSee('Dra tillbaka')
        ->call('voidEnvelope', $envelope->id)
        ->assertHasNoErrors();

    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Voided)
        ->and($envelope->voided_by)->toBe($this->owner->id)
        ->and(SigningRequest::query()->where('envelope_id', $envelope->id)->where('status', SigningStatus::Revoked->value)->count())->toBe(2)
        ->and(SigningLinkToken::query()->whereNull('revoked_at')->count())->toBe(0);

    auth()->logout();
    $this->get("/signera/{$token}")->assertStatus(410)->assertSee('Dokumentet har dragits tillbaka av avsändaren');
});

it('sends several signers from the panel through "Fler undertecknare"', function () {
    Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id])
        ->assertSee('Fler undertecknare')
        ->call('severalSigners')
        ->assertSee('Lägg till undertecknare')
        ->set('signers', [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 2]])
        ->call('addCopy')
        ->set('copies.0.email', 'revisor@kontorshuset.test')
        ->call('sendSeveral')
        ->assertHasNoErrors()
        ->assertSee('2 undertecknare')
        ->assertSee('steg 1 är öppet')
        ->assertSee('Kopia till revisor@kontorshuset.test');

    expect(SigningEnvelope::query()->sole()->status)->toBe(EnvelopeStatus::Out)
        ->and(SigningRequest::query()->count())->toBe(2);
});

it('signs an agreement with two signers: it is marked signed only when both have signed', function () {
    $agreement = app(SaveAgreement::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'title' => 'Serviceavtal', 'kind' => 'SERVICE', 'start_date' => '2026-07-01', 'notice_months' => 0, 'value_period' => 'MONTH']);
    app(SendForSigning::class)->envelope($this->owner, $this->company, $agreement, ['signers' => [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 2]]]);

    s1Sign((string) s1LinkFor('anna@kontorshuset.test'));
    expect(Agreement::query()->findOrFail($agreement->id)->signed_at)->toBeNull();

    s1Sign((string) s1LinkFor('bo@kontorshuset.test'), 'Bo Berg');
    expect(Agreement::query()->findOrFail($agreement->id)->signed_at)->not->toBeNull();
});

it('refuses more than ten signers or copy recipients, a repeated address and a missing second signer', function (array $input, string $error) {
    expect(fn () => s1Send($this, $input['signers'], ['copies' => $input['copies'] ?? []]))
        ->toThrow(ValidationException::class);

    try {
        s1Send($this, $input['signers'], ['copies' => $input['copies'] ?? []]);
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toContain($error);
    }

    expect(SigningEnvelope::query()->count())->toBe(0);
})->with([
    'eleven signers' => [['signers' => array_map(fn (int $i): array => ['email' => "s{$i}@kontorshuset.test", 'step' => 1], range(1, 11))], 'signers'],
    'eleven copies' => [['signers' => [['email' => 'a@kontorshuset.test', 'step' => 1], ['email' => 'b@kontorshuset.test', 'step' => 1]], 'copies' => array_map(fn (int $i): array => ['email' => "c{$i}@kontorshuset.test"], range(1, 11))], 'copies'],
    'one signer' => [['signers' => [['email' => 'a@kontorshuset.test', 'step' => 1]]], 'signers'],
    'same address twice' => [['signers' => [['email' => 'a@kontorshuset.test', 'step' => 1], ['email' => 'A@kontorshuset.test', 'step' => 2]]], 'signers'],
    'copy also a signer' => [['signers' => [['email' => 'a@kontorshuset.test', 'step' => 1], ['email' => 'b@kontorshuset.test', 'step' => 2]], 'copies' => [['email' => 'a@kontorshuset.test']]], 'copies'],
    'step eleven' => [['signers' => [['email' => 'a@kontorshuset.test', 'step' => 1], ['email' => 'b@kontorshuset.test', 'step' => 11]]], 'signers.1.step'],
]);

it('keeps the limits and the order in the database: ten signers, ten copies, fixed steps and envelopes', function () {
    $envelope = s1Send($this, array_map(fn (int $i): array => ['email' => "s{$i}@kontorshuset.test", 'step' => $i], range(1, 10)), [
        'copies' => array_map(fn (int $i): array => ['email' => "c{$i}@kontorshuset.test"], range(1, 10)),
    ]);
    $first = SigningRequest::query()->where('envelope_id', $envelope->id)->orderBy('id')->firstOrFail();

    expect(fn () => DB::transaction(fn () => SigningRequest::query()->create([...$first->only(['document_type', 'sales_document_id', 'expires_at', 'document_path', 'document_name', 'document_sha256']), 'envelope_id' => $envelope->id, 'token_hash' => str_repeat('a', 64), 'email' => 'elfte@kontorshuset.test'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => SigningCopyRecipient::query()->create(['envelope_id' => $envelope->id, 'email' => 'elfte@kontorshuset.test'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('crm_signing_requests')->where('id', $first->id)->update(['step' => 5])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('crm_signing_requests')->where('id', $first->id)->update(['envelope_id' => SigningEnvelope::query()->create([...$envelope->only(['document_type', 'sales_document_id', 'document_path', 'document_name', 'document_sha256'])])->id])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['document_sha256' => str_repeat('0', 64)])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['current_step' => 0])))->toThrow(QueryException::class);
});

it('never changes or deletes a completed envelope, and never changes a link token', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    s1Sign(basename((string) parse_url($sent['url'], PHP_URL_PATH)));
    $envelope = SigningEnvelope::query()->sole();

    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['status' => 'VOIDED'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['completed_at' => now()->addDay()])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->delete()))->toThrow(QueryException::class);

    $multi = app(SendForSigning::class)->envelope($this->owner, $this->company, app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $this->customer->id, 'document_date' => '2026-06-15', 'rows' => [['description' => 'Timme', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]]), ['signers' => [['email' => 'a@kontorshuset.test', 'step' => 1], ['email' => 'b@kontorshuset.test', 'step' => 1]]]);
    $token = SigningLinkToken::query()->whereIn('signing_request_id', SigningRequest::query()->where('envelope_id', $multi->id)->select('id'))->firstOrFail();

    expect(fn () => DB::transaction(fn () => DB::table('signing_link_tokens')->where('id', $token->id)->update(['token_hash' => str_repeat('b', 64)])))->toThrow(QueryException::class);
});

it('backfills one envelope per existing request without touching the evidence', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    $signed = s1Sign(basename((string) parse_url($sent['url'], PHP_URL_PATH)));
    $before = DB::table('crm_signing_requests')->where('id', $signed->id)->first();

    // As before S1: a signed request without an envelope. The column may be set once from null, so a request made
    // before the migration is simulated by a fresh signed row written without one.
    $legacy = DB::table('crm_signing_requests')->insertGetId([
        ...collect((array) $before)->except(['id', 'envelope_id', 'token_hash', 'sales_document_id'])->all(),
        'envelope_id' => null,
        'token_hash' => str_repeat('c', 64),
        'sales_document_id' => $this->quote->id,
    ]);

    $pending = DB::table('crm_signing_requests')->insertGetId([
        ...collect((array) $before)->except(['id', 'envelope_id', 'token_hash', 'sales_document_id', 'status', 'signed_at', 'signer_name', 'signature_image', 'signer_ip', 'signer_user_agent', 'signature_method', 'acceptance_text', 'signed_document_path', 'signed_document_sha256', 'followed_up_at', 'order_id', 'invoice_id'])->all(),
        'envelope_id' => null,
        'status' => 'REVOKED',
        'revoked_at' => '2026-06-14 10:00:00+00',
        'token_hash' => str_repeat('d', 64),
        'sales_document_id' => $this->quote->id,
    ]);
    $legacyBefore = (array) DB::table('crm_signing_requests')->where('id', $legacy)->first();
    $existing = SigningEnvelope::query()->count();

    (require base_path('database/migrations/2027_01_25_100000_create_signing_envelopes.php'))->backfill();

    $row = DB::table('crm_signing_requests')->where('id', $legacy)->first();
    $envelope = SigningEnvelope::query()->findOrFail($row->envelope_id);
    $revoked = SigningEnvelope::query()->findOrFail(DB::table('crm_signing_requests')->where('id', $pending)->value('envelope_id'));

    expect(SigningEnvelope::query()->count())->toBe($existing + 2)
        ->and($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($envelope->document_sha256)->toBe($row->document_sha256)
        ->and($envelope->completed_at?->toIso8601String())->toBe(CarbonImmutable::parse($row->signed_at)->toIso8601String())
        ->and($revoked->status)->toBe(EnvelopeStatus::Voided)
        ->and(collect((array) $row)->except(['envelope_id'])->all())->toBe(collect($legacyBefore)->except(['envelope_id'])->all())
        ->and($row->signer_name)->toBe('Anna Andersson')
        ->and(DB::table('crm_signing_requests')->where('id', $signed->id)->value('envelope_id'))->toBe($signed->envelope_id);
});

it('renders the panel in a fixed number of queries with one signer and with ten', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    s1Send($this, [['email' => 'a@kontorshuset.test', 'step' => 1], ['email' => 'b@kontorshuset.test', 'step' => 2]], ['copies' => [['email' => 'c@kontorshuset.test']]]);
    $count();
    $short = $count();

    s1Send($this, array_map(fn (int $i): array => ['email' => "s{$i}@kontorshuset.test", 'step' => $i], range(1, 10)), ['copies' => array_map(fn (int $i): array => ['email' => "c{$i}@kontorshuset.test"], range(1, 10))]);
    $long = $count();

    expect($long)->toBe($short);
});

it('keeps an envelope\'s life in order in the database: no way back to a draft, completed only when all signed, copies and creator kept', function () {
    $envelope = s1Send($this, [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 1]], ['copies' => [['email' => 'revisor@kontorshuset.test']]]);

    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['status' => 'DRAFT'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['status' => 'COMPLETED', 'completed_at' => now()])))->toThrow(QueryException::class);

    s1Sign((string) s1LinkFor('anna@kontorshuset.test'));
    s1Sign((string) s1LinkFor('bo@kontorshuset.test'), 'Bo Berg');
    $other = Bookkeeping::member($this->company, 'admin');

    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Completed)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_copy_recipients')->where('envelope_id', $envelope->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['created_by' => $other->id])))->toThrow(QueryException::class)
        ->and(DB::table('signing_envelopes')->where('id', $envelope->id)->update(['created_by' => null]))->toBe(1);
});

it('revokes a changed document\'s link with the document locked first, and withdraws its envelope', function () {
    $envelope = s1Send($this, [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 1]]);
    $anna = app(SignAtLink::class)->find((string) s1LinkFor('anna@kontorshuset.test'));

    app(SignAtLink::class)->revokeStale($anna);

    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Voided)
        ->and(SigningRequest::query()->where('envelope_id', $envelope->id)->where('status', SigningStatus::Revoked->value)->count())->toBe(2);
});
