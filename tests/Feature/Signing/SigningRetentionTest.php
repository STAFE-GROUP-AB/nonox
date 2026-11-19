<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\ProgressSalesDocument;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Invoicing\WriteOffInvoice;
use App\Actions\Signing\EraseSigner;
use App\Actions\Signing\ManageSigningRetention;
use App\Actions\Signing\PruneSigningLinks;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Actions\Signing\SigningReminders;
use App\Domain\Documents\LegalHoldReason;
use App\Domain\Signing\Chain\ChainVerifier;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\Retention\EnvelopeDeletion;
use App\Domain\Signing\Retention\SignerRecords;
use App\Domain\Signing\Retention\SigningRetention;
use App\Domain\Signing\SigningPdfs;
use App\Livewire\Company\SigningPrivacy;
use App\Livewire\Company\SigningRetentionSettings;
use App\Mail\SigningRequestMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\SalesDocument;
use App\Models\SigningEnvelope;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Livewire\EnvelopePage;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Modules\WorkOrders\Actions\CreateInvoiceBasis;
use App\Support\Privacy\DataSubjectExport;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\PdfEngineFixtures;
use Tests\Support\WorkOrders;

/*
 * Retention, legal hold, a signer's register extract and erasure (ADR-046
 * amendment 2026-11-18, gap 11, package S16). The database's own clock
 * decides in the delete triggers, so an envelope that is to be past its
 * retention is signed years back in time and pruned at today's date.
 */

function s16Store(User $user, Company $company, string $title = 'Avtal'): SigningDocument
{
    $path = tempnam(sys_get_temp_dir(), 's16');
    file_put_contents($path, PdfEngineFixtures::pdf(1));

    try {
        return app(StoreSigningDocument::class)($user, $company, $path, 'Avtal.pdf', $title);
    } finally {
        @unlink($path);
    }
}

/**
 * @param  list<string>  $emails
 * @param  list<string>  $copies
 */
function s16Send(User $user, Company $company, SigningDocument $document, array $emails, array $copies = [], bool $confidential = false): SigningEnvelope
{
    $signers = array_map(fn (string $email): array => ['email' => $email, 'step' => 1, 'verification' => 'NONE'], $emails);

    return app(SendForSigning::class)->envelope($user, $company, $document, [
        'signers' => $signers,
        'copies' => array_map(fn (string $email): array => ['email' => $email], $copies),
        'confidential' => $confidential,
    ], minSigners: 1);
}

/** The token of the latest signing link mailed to the address (or the first, with $latest false). */
function s16Token(string $email, bool $latest = true): string
{
    $mails = Mail::queued(SigningRequestMail::class, fn (SigningRequestMail $mail): bool => $mail->hasTo($email));
    $mail = $latest ? $mails->last() : $mails->first();
    expect($mail)->toBeInstanceOf(SigningRequestMail::class);

    return basename((string) parse_url((string) $mail->url, PHP_URL_PATH));
}

function s16Sign(string $email, string $name = 'Anna Andersson'): SigningRequest
{
    $links = app(SignAtLink::class);
    $request = $links->find(s16Token($email));
    expect($request)->toBeInstanceOf(SigningRequest::class);

    return $links->sign($request, ['name' => $name, 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => $name, 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)], '203.0.113.7', 'Pest');
}

function s16Decline(string $email): SigningRequest
{
    $links = app(SignAtLink::class);

    return $links->decline($links->find(s16Token($email)), 'Inte nu');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    FakePdfEngine::install();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(ModuleManager::class)->enable(SigningModule::KEY, $this->company, $this->owner);
});

it('gives signing:settings and signing:privacy to the owner and the admin only', function () {
    foreach (['owner' => true, 'admin' => true, 'accountant' => false, 'bookkeeper' => false, 'auditor' => false, 'approver' => false] as $role => $expected) {
        $user = $role === 'owner' ? $this->owner : Bookkeeping::member($this->company, $role);
        $company = Company::query()->findOrFail($this->company->id);

        expect([$user->hasTeamPermission($company, 'signing:settings'), $user->hasTeamPermission($company, 'signing:privacy')])->toBe([$expected, $expected], $role);
    }
});

it('dates a completed quote as räkenskapsinformation, with the BFL floor, and never earlier', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'inkop@kontorshuset.test', 'payment_terms_days' => 30]);
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $customer->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]],
    ]);
    // Seven years: the floor (31 December seven years after the 2026 fiscal year) is later than completion + 7 years.
    app(ManageSigningRetention::class)->saveYears($this->owner, $this->company, 7);
    app(SendForSigning::class)($this->owner, $this->company, $quote, ['email' => 'anna@kontorshuset.test']);
    s16Sign('anna@kontorshuset.test');
    $envelope = SigningEnvelope::query()->sole();

    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($envelope->retention_class)->toBe('ACCOUNTING')
        ->and($envelope->retention_until?->toDateString())->toBe('2033-12-31');

    // The database keeps the floor and never moves the date earlier or the class down.
    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['retention_until' => '2033-06-30'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['retention_class' => 'OTHER'])))->toThrow(QueryException::class);

    DB::table('signing_envelopes')->where('id', $envelope->id)->update(['retention_until' => '2040-01-01']);
    expect($envelope->refresh()->retention_until?->toDateString())->toBe('2040-01-01');
});

it('dates a signed standalone document by the company\'s period, which applies from now on only', function () {
    $first = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company), ['eva@example.test']);
    s16Sign('eva@example.test');

    app(ManageSigningRetention::class)->saveYears($this->owner, $this->company, 15);
    $second = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company), ['bo@example.test']);
    s16Sign('bo@example.test');

    expect($first->refresh()->retention_class)->toBe('OTHER')
        ->and($first->retention_until?->toDateString())->toBe('2036-06-15')
        ->and($second->refresh()->retention_until?->toDateString())->toBe('2041-06-15')
        ->and(AuditLog::query()->where('action', 'signing.retention_years_changed')->sole()->payload)->toMatchArray(['from' => 10, 'to' => 15]);

    expect(fn () => app(ManageSigningRetention::class)->saveYears($this->owner, $this->company, 6))->toThrow(ValidationException::class)
        ->and(fn () => app(ManageSigningRetention::class)->saveYears($this->owner, $this->company, 26))->toThrow(ValidationException::class)
        ->and(fn () => app(ManageSigningRetention::class)->saveYears(Bookkeeping::member($this->company, 'accountant'), $this->company, 12))->toThrow(AuthorizationException::class);
});

it('marks a standalone document as räkenskapsinformation, only upwards', function () {
    app(ManageSigningRetention::class)->saveYears($this->owner, $this->company, 7);
    $envelope = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company), ['eva@example.test']);
    s16Sign('eva@example.test');

    expect($envelope->refresh()->retention_until?->toDateString())->toBe('2033-06-15');

    $marked = app(ManageSigningRetention::class)->markAccounting($this->owner, $this->company, $envelope);

    expect($marked->retention_class)->toBe('ACCOUNTING')
        ->and($marked->retention_until?->toDateString())->toBe('2033-12-31')
        ->and(fn () => app(ManageSigningRetention::class)->markAccounting($this->owner, $this->company, $marked))->toThrow(ValidationException::class);
});

it('gives declined, withdrawn and expired envelopes twelve months', function () {
    $declined = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company), ['a@example.test']);
    s16Decline('a@example.test');
    $voided = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company), ['b@example.test']);
    app(SendForSigning::class)->void($this->owner, $this->company, $voided);
    $expiring = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company), ['c@example.test']);

    $this->travelTo(CarbonImmutable::parse('2026-08-20 10:00:00'));
    app(SigningReminders::class)->run(CarbonImmutable::now());

    expect($declined->refresh()->retention_class)->toBe('UNSIGNED')
        ->and($declined->retention_until?->toDateString())->toBe('2027-06-15')
        ->and($voided->refresh()->retention_until?->toDateString())->toBe('2027-06-15')
        ->and($expiring->refresh()->expired_at)->not->toBeNull()
        ->and($expiring->retention_class)->toBe('UNSIGNED')
        ->and($expiring->retention_until?->toDateString())->toBe('2027-08-20');

    // Only a finished or expired envelope has a retention date, and the two columns go together.
    $out = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company), ['d@example.test']);
    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $out->id)->update(['retention_class' => 'UNSIGNED', 'retention_until' => '2020-01-01'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $declined->id)->update(['retention_class' => null])))->toThrow(QueryException::class);
});

it('prunes a signed envelope whole once its retention has passed, files after the commit, and records it', function () {
    $this->travelTo(CarbonImmutable::parse('2012-03-01 10:00:00'));
    $document = s16Store($this->owner, $this->company, 'Gammalt avtal');
    $old = s16Send($this->owner, $this->company, $document, ['gammal@example.test'], ['kopia@example.test']);
    $signer = s16Sign('gammal@example.test');
    $this->travelBack();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    $kept = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company, 'Nytt avtal'), ['ny@example.test']);
    s16Sign('ny@example.test');

    expect($old->refresh()->retention_until?->toDateString())->toBe('2022-03-01')
        ->and(Storage::disk('local')->exists((string) $signer->refresh()->signed_document_path))->toBeTrue();

    // A signed envelope is refused before its date; a signed row is never deleted on its own.
    expect(fn () => DB::transaction(fn () => SigningEnvelope::query()->whereKey($kept->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('crm_signing_requests')->where('id', $signer->id)->delete()))->toThrow(QueryException::class);

    $this->travelBack();
    $result = app(PruneSigningLinks::class)->run(CarbonImmutable::now());

    expect($result['envelopes'])->toBe(1)
        ->and(SigningEnvelope::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(SigningRequest::query()->whereKey($signer->id)->exists())->toBeFalse()
        ->and(SigningEvent::query()->where('envelope_id', $old->id)->exists())->toBeFalse()
        ->and(DB::table('signing_copy_recipients')->where('envelope_id', $old->id)->exists())->toBeFalse()
        ->and(SigningDocument::query()->whereKey($document->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists((string) $signer->signed_document_path))->toBeFalse()
        ->and(Storage::disk('local')->exists($signer->document_path))->toBeFalse()
        ->and(Storage::disk('local')->exists($document->path))->toBeFalse()
        ->and(SigningEnvelope::query()->whereKey($kept->id)->exists())->toBeTrue();

    $entry = AuditLog::query()->where('action', EnvelopeDeletion::AUDIT)->sole();
    expect($entry->payload)->toMatchArray(['envelope_id' => $old->id, 'reason' => 'RETENTION', 'retention_class' => 'OTHER', 'retention_until' => '2022-03-01', 'signers' => 1])
        ->and(json_encode($entry->payload))->not->toContain('gammal@')->not->toContain('Gammalt');
});

it('never prunes or deletes an envelope on legal hold, and records the hold', function () {
    $this->travelTo(CarbonImmutable::parse('2012-03-01 10:00:00'));
    $envelope = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company), ['gammal@example.test']);
    s16Sign('gammal@example.test');
    $this->travelBack();

    expect(fn () => app(ManageSigningRetention::class)->hold($this->owner, $this->company, $envelope, true))->toThrow(ValidationException::class)
        ->and(fn () => app(ManageSigningRetention::class)->hold(Bookkeeping::member($this->company, 'bookkeeper'), $this->company, $envelope, true, LegalHoldReason::Dispute))->toThrow(AuthorizationException::class);

    app(ManageSigningRetention::class)->hold($this->owner, $this->company, $envelope, true, LegalHoldReason::Dispute);
    app(PruneSigningLinks::class)->run(CarbonImmutable::now());

    expect(SigningEnvelope::query()->whereKey($envelope->id)->exists())->toBeTrue()
        ->and(fn () => DB::transaction(fn () => SigningEnvelope::query()->whereKey($envelope->id)->delete()))->toThrow(QueryException::class)
        ->and(AuditLog::query()->where('action', 'signing.retention_hold_set')->sole()->payload)->toMatchArray(['envelope_id' => $envelope->id, 'reason' => 'TVIST']);

    app(ManageSigningRetention::class)->hold($this->owner, $this->company, $envelope, false);
    app(PruneSigningLinks::class)->run(CarbonImmutable::now());

    expect(SigningEnvelope::query()->whereKey($envelope->id)->exists())->toBeFalse();
});

it('prunes a declined envelope where someone had signed twelve months after it closed, signed row and all', function () {
    $this->travelTo(CarbonImmutable::parse('2024-01-10 10:00:00'));
    $envelope = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company), ['a@example.test', 'b@example.test']);
    $signed = s16Sign('a@example.test');
    s16Decline('b@example.test');
    $this->travelBack();

    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Declined)
        ->and($envelope->retention_class)->toBe('UNSIGNED')
        ->and($envelope->retention_until?->toDateString())->toBe('2025-01-10');

    app(PruneSigningLinks::class)->run(CarbonImmutable::now());

    expect(SigningEnvelope::query()->whereKey($envelope->id)->exists())->toBeFalse()
        ->and(SigningRequest::query()->whereKey($signed->id)->exists())->toBeFalse();
});

it('deletes an uploaded document that was never sent after ninety days', function () {
    $old = s16Store($this->owner, $this->company, 'Glömt');
    $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00'));
    $recent = s16Store($this->owner, $this->company, 'Nyligen');
    $sent = s16Store($this->owner, $this->company, 'Skickat');
    s16Send($this->owner, $this->company, $sent, ['a@example.test']);

    $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00'));
    $result = app(PruneSigningLinks::class)->run(CarbonImmutable::now());

    expect($result['documents'])->toBe(1)
        ->and(SigningDocument::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($old->path))->toBeFalse()
        ->and(SigningDocument::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(SigningDocument::query()->whereKey($sent->id)->exists())->toBeTrue();
});

it('finds a signer\'s envelopes by exact address and says what may be erased', function () {
    $out = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company, 'Ute'), ['Eva@Example.test']);
    $declined = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company, 'Avböjt'), ['eva@example.test']);
    s16Decline('eva@example.test');
    $signed = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company, 'Signerat'), ['eva@example.test']);
    s16Sign('eva@example.test');
    $copy = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company, 'Kopia'), ['bo@example.test'], ['eva@example.test']);
    s16Sign('bo@example.test');
    s16Send($this->owner, $this->company, s16Store($this->owner, $this->company, 'Annan'), ['evan@example.test']);

    $records = collect(SignerRecords::find(' EVA@example.test '))->keyBy(fn (array $record): string => $record['envelope']->id);

    expect($records->keys()->sort()->values()->all())->toBe(collect([$out->id, $declined->id, $signed->id, $copy->id])->sort()->values()->all())
        ->and($records[$out->id]['blocker'])->toBeNull()
        ->and($records[$declined->id]['blocker'])->toBeNull()
        ->and($records[$signed->id]['blocker'])->toBe('EVIDENCE')
        ->and($records[$copy->id]['roles'])->toBe(['COPY'])
        ->and($records[$copy->id]['blocker'])->toBe('EVIDENCE');
});

it('erases what nobody signed, keeps what is signed with the legal basis, and logs a hash, not the address', function () {
    $out = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company, 'Ute'), ['eva@example.test']);
    $declined = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company, 'Avböjt'), ['eva@example.test']);
    s16Decline('eva@example.test');
    $signed = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company, 'Signerat'), ['eva@example.test']);
    s16Sign('eva@example.test');
    $outToken = s16Token('eva@example.test', latest: false);

    expect(fn () => app(EraseSigner::class)(Bookkeeping::member($this->company, 'accountant'), $this->company, 'eva@example.test'))->toThrow(AuthorizationException::class);

    $result = app(EraseSigner::class)($this->owner, $this->company, 'eva@example.test');

    expect($result['deleted'])->toBe(2)
        ->and($result['refused'])->toHaveCount(1)
        ->and($result['refused'][0]['envelope_id'])->toBe($signed->id)
        ->and($result['refused'][0]['reason'])->toContain('17.3 e')->toContain('2036-06-15')
        ->and(SigningEnvelope::query()->whereKey($out->id)->exists())->toBeFalse()
        ->and(SigningEnvelope::query()->whereKey($declined->id)->exists())->toBeFalse()
        ->and(SigningEnvelope::query()->whereKey($signed->id)->exists())->toBeTrue()
        ->and(app(SignAtLink::class)->find($outToken))->toBeNull();

    $refusal = AuditLog::query()->where('action', 'signing.erasure_refused')->sole();
    $summary = AuditLog::query()->where('action', 'privacy.erased')->sole();

    expect($refusal->payload)->toMatchArray(['envelope_id' => $signed->id, 'reason' => 'EVIDENCE', 'article' => '17.3.e'])
        ->and($summary->payload)->toMatchArray(['subject' => 'signer', 'subject_hash' => EraseSigner::hash('eva@example.test'), 'envelopes_deleted' => 2, 'envelopes_kept' => 1])
        ->and(AuditLog::query()->where('action', EnvelopeDeletion::AUDIT)->count())->toBe(2)
        ->and(AuditLog::query()->get()->contains(fn (AuditLog $entry): bool => str_contains((string) json_encode($entry->payload), 'eva@')))->toBeFalse();
});

it('refuses to erase räkenskapsinformation under art. 17.3 b and a held envelope while the hold lasts', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'inkop@kontorshuset.test', 'payment_terms_days' => 30]);
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $customer->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500]],
    ]);
    app(SendForSigning::class)($this->owner, $this->company, $quote, ['email' => 'anna@kontorshuset.test']);
    s16Sign('anna@kontorshuset.test');
    $held = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company), ['anna@kontorshuset.test']);
    app(ManageSigningRetention::class)->hold($this->owner, $this->company, $held, true, LegalHoldReason::TaxAudit);

    $result = app(EraseSigner::class)($this->owner, $this->company, 'anna@kontorshuset.test');
    $reasons = collect($result['refused'])->pluck('reason', 'envelope_id');

    expect($result['deleted'])->toBe(0)
        ->and($reasons[$held->id])->toContain('bevarandespärr')->toContain('17.3 b')
        ->and($reasons->first(fn (string $reason, string $id): bool => $id !== $held->id))->toContain('bokföringslagen 7 kap. 2 §')->toContain('17.3 b');
});

it('downloads a signer\'s register extract as a ZIP with their evidence and files, never another signer\'s', function () {
    $envelope = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company, 'Hyresavtal'), ['eva@example.test', 'bo@example.test']);
    s16Sign('eva@example.test', 'Eva Ek');
    s16Sign('bo@example.test', 'Bo Berg');

    $this->actingAs(Bookkeeping::member($this->company, 'bookkeeper'))->post(route('privacy.export.signer'), ['e_post' => 'eva@example.test'])->assertForbidden();
    $this->actingAs($this->owner);

    $response = $this->post(route('privacy.export.signer'), ['e_post' => 'EVA@example.test', 'foretag' => $this->company->id])->assertOk();
    $file = $response->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    $zip->open($file);
    $json = json_decode((string) $zip->getFromName('registerutdrag.json'), true);
    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    $signer = $json['signeringar'][0]['som_undertecknare'][0];

    expect($json['e_post'])->toBe('eva@example.test')
        ->and($json['signeringar'])->toHaveCount(1)
        ->and($json['signeringar'][0]['dokument'])->toBe('Hyresavtal')
        ->and($signer['namn'])->toBe('Eva Ek')
        ->and($signer['ip_adress'])->toBe('203.0.113.7')
        ->and(collect($signer['handelser'])->pluck('handelse')->all())->toContain('SIGNED')
        ->and((string) json_encode($json))->not->toContain('Bo Berg')->not->toContain('bo@example.test')
        ->and($names)->toContain('registerutdrag.json')
        ->and(collect($names)->filter(fn (string $name): bool => str_ends_with($name, '-signeringsbevis.pdf'))->count())->toBe(1)
        ->and(collect($names)->filter(fn (string $name): bool => str_ends_with($name, '-dokument.pdf'))->count())->toBe(1)
        ->and($json['saknade_filer'])->toBe([]);

    $entry = AuditLog::query()->where('action', 'privacy.exported')->sole();
    expect($entry->payload)->toMatchArray(['subject' => 'signer', 'subject_hash' => EraseSigner::hash('eva@example.test'), 'envelopes' => 1])
        ->and((string) json_encode($entry->payload))->not->toContain('eva@');
    unset($envelope);
});

it('shows the Signering tab with retention and privacy to the owner, saves the period and searches an address', function () {
    $this->get(route('teams.show', ['company' => $this->company->id, 'flik' => 'signering']))->assertOk()
        ->assertSeeLivewire(SigningRetentionSettings::class)->assertSeeLivewire(SigningPrivacy::class);
    $this->actingAs(Bookkeeping::member($this->company, 'bookkeeper'))
        ->get(route('teams.show', ['company' => $this->company->id, 'flik' => 'signering']))->assertOk()->assertDontSee('Bevarande av signeringar');
    $this->actingAs($this->owner);

    Livewire::test(SigningRetentionSettings::class, ['company' => $this->company])
        ->assertSet('years', '10')
        ->set('years', '6')->call('save')->assertHasErrors('years')
        ->set('years', '12')->call('save')->assertHasNoErrors();
    expect(SigningRetention::years($this->company->id))->toBe(12);

    s16Send($this->owner, $this->company, s16Store($this->owner, $this->company, 'Ute'), ['eva@example.test']);
    Livewire::test(SigningPrivacy::class, ['company' => $this->company])
        ->set('email', 'eva@example.test')->call('search')
        ->assertSee('Ute')->assertSee('Raderas')
        ->call('erase')
        ->assertSee('Inga signeringar i företaget har gått till eva@example.test.');
    expect(SigningEnvelope::query()->count())->toBe(0);
});

it('sets and lifts a legal hold from the envelope page with signing:settings only', function () {
    $envelope = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company), ['eva@example.test']);
    s16Sign('eva@example.test');

    Livewire::test(EnvelopePage::class, ['envelopeId' => $envelope->id])
        ->assertSee('Sparas till och med 2036-06-15')
        ->call('setHold')->assertHasErrors('holdReason')
        ->set('holdReason', 'TVIST')->call('setHold')->assertHasNoErrors()
        ->assertSee('Bevarandespärr: Tvist');
    expect($envelope->refresh()->retention_hold)->toBeTrue();

    $this->actingAs(Bookkeeping::member($this->company, 'accountant'));
    Livewire::test(EnvelopePage::class, ['envelopeId' => $envelope->id])->assertDontSee('Häv bevarandespärr')->call('liftHold')->assertForbidden();
    expect($envelope->refresh()->retention_hold)->toBeTrue();
});

it('runs a fixed number of queries for the search and the extract, whatever the number of envelopes', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        SignerRecords::find('eva@example.test');
        CurrentCompany::run($this->company, fn () => DataSubjectExport::signer('eva@example.test'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    s16Send($this->owner, $this->company, s16Store($this->owner, $this->company), ['eva@example.test']);
    s16Sign('eva@example.test');
    $one = $count();

    foreach (range(1, 9) as $number) {
        s16Send($this->owner, $this->company, s16Store($this->owner, $this->company, "Avtal {$number}"), ['eva@example.test'], ["kopia{$number}@example.test"]);
        s16Sign('eva@example.test');
    }

    expect($count())->toBe($one);
});

it('lets an ÄTA signer row go with its on-site record', function () {
    $rule = DB::selectOne("select confdeltype from pg_constraint where conname = 'change_order_on_site_codes_signing_request_id_foreign'");

    expect($rule->confdeltype)->toBe('c');
});

/*
 * Review round (2026-11-19).
 */

function s16Quote(User $owner, Company $company, string $date): SalesDocument
{
    $customer = app(SaveCustomer::class)($owner, $company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'inkop@kontorshuset.test', 'payment_terms_days' => 30, 'address_line1' => 'Exempelgatan 1', 'postal_code' => '11122', 'city' => 'Stockholm']);

    return app(SaveSalesDocument::class)($owner, $company, SalesDocument::QUOTE, [
        'customer_id' => $customer->id, 'document_date' => $date, 'until_date' => CarbonImmutable::parse($date)->addDays(20)->toDateString(),
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]],
    ]);
}

it('anchors räkenskapsinformation to the fiscal year of the latest booking, and moves the date later when an invoice is booked', function () {
    app(ManageSigningRetention::class)->saveYears($this->owner, $this->company, 7);
    FiscalYear::query()->create(['company_id' => $this->company->id, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00'));
    $quote = s16Quote($this->owner, $this->company, '2026-12-20');
    app(SendForSigning::class)($this->owner, $this->company, $quote, ['email' => 'anna@kontorshuset.test']);
    $signer = s16Sign('anna@kontorshuset.test');
    $envelope = SigningEnvelope::query()->sole();

    expect($envelope->retention_until?->toDateString())->toBe('2033-12-31');

    // The order is invoiced in the next fiscal year: the business event is booked in 2027, so BFL keeps it until 2034.
    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));
    $order = SalesDocument::query()->findOrFail($signer->refresh()->order_id);
    app(IssueInvoice::class)($this->owner, app(ProgressSalesDocument::class)->toInvoice($this->owner, $order));

    expect($envelope->refresh()->retention_class)->toBe('ACCOUNTING')
        ->and($envelope->retention_until?->toDateString())->toBe('2034-12-31')
        ->and(SigningRetention::requiredUntil($envelope)?->toDateString())->toBe('2034-12-31');
});

it('never prunes the evidence of an agreement while it runs, and counts the years from its end', function () {
    Crm::enable($this->company, $this->owner);
    $account = Crm::accountFor(Crm::customer($this->company, $this->owner));
    $this->travelTo(CarbonImmutable::parse('2012-03-01 10:00:00'));
    $agreement = app(SaveAgreement::class)($this->owner, $this->company, ['account_id' => $account->id, 'title' => 'Hyresavtal tolv år', 'kind' => 'SUPPORT', 'start_date' => '2012-03-01', 'end_date' => null, 'notice_months' => 3, 'auto_renew' => false, 'value' => '1 000', 'value_period' => 'MONTH', 'body_text' => 'Hyra.']);
    app(SendForSigning::class)($this->owner, $this->company, $agreement, ['email' => 'hyresgast@kontorshuset.test']);
    s16Sign('hyresgast@kontorshuset.test');
    $envelope = SigningEnvelope::query()->where('document_type', 'AGREEMENT')->sole();
    $this->travelBack();

    expect($envelope->refresh()->retention_until?->toDateString())->toBe('2022-03-01');

    app(PruneSigningLinks::class)->run(CarbonImmutable::now());
    expect(SigningEnvelope::query()->whereKey($envelope->id)->exists())->toBeTrue()
        ->and(SigningRetention::requiredUntil($envelope))->toBeNull();

    // Ended in 2020: kept ten years from the end, and the date moves later on the next prune.
    DB::table('crm_agreements')->where('id', $agreement->id)->update(['end_date' => '2020-06-30']);
    app(PruneSigningLinks::class)->run(CarbonImmutable::now());

    expect(SigningEnvelope::query()->whereKey($envelope->id)->exists())->toBeTrue()
        ->and($envelope->refresh()->retention_until?->toDateString())->toBe('2030-06-30');
});

it('sets and lifts a legal hold from the core settings with the module off, with signing:settings only', function () {
    $quote = s16Quote($this->owner, $this->company, '2026-06-15');
    app(SendForSigning::class)($this->owner, $this->company, $quote, ['email' => 'anna@kontorshuset.test']);
    s16Sign('anna@kontorshuset.test');
    $envelope = SigningEnvelope::query()->sole();
    app(ModuleManager::class)->disable(SigningModule::KEY, $this->company, $this->owner, confirmed: true);

    Livewire::test(SigningRetentionSettings::class, ['company' => $this->company])
        ->set('holdSearch', (string) $envelope->title)->call('searchHolds')->assertSee((string) $envelope->title)
        ->call('setHold', $envelope->id)->assertHasErrors('holdReason')
        ->set('holdReason', 'SKATTEREVISION')->call('setHold', $envelope->id)->assertHasNoErrors()
        ->assertSee('Häv');
    expect($envelope->refresh()->retention_hold)->toBeTrue()->and($envelope->retention_hold_reason)->toBe('SKATTEREVISION');

    Livewire::test(SigningRetentionSettings::class, ['company' => $this->company])->call('liftHold', $envelope->id)->assertHasNoErrors();
    expect($envelope->refresh()->retention_hold)->toBeFalse();

    $this->actingAs(Bookkeeping::member($this->company, 'accountant'));
    Livewire::test(SigningRetentionSettings::class, ['company' => $this->company])->assertForbidden();
});

it('erases one person without wiping the other people\'s signing', function () {
    $shared = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company, 'Delat'), ['eva@example.test', 'bo@example.test']);
    $copied = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company, 'Kopia'), ['bo@example.test'], ['eva@example.test']);
    $boLink = s16Token('bo@example.test');

    $result = app(EraseSigner::class)($this->owner, $this->company, 'eva@example.test');

    expect($result['deleted'])->toBe(2)
        ->and(SigningEnvelope::query()->whereKey($shared->id)->exists())->toBeTrue()
        ->and($shared->refresh()->status)->toBe(EnvelopeStatus::Voided)
        ->and(SigningRequest::query()->where('envelope_id', $shared->id)->pluck('email')->all())->toBe(['bo@example.test'])
        ->and($copied->refresh()->status)->toBe(EnvelopeStatus::Out)
        ->and(DB::table('signing_copy_recipients')->where('envelope_id', $copied->id)->exists())->toBeFalse()
        ->and(app(SignAtLink::class)->find($boLink))->not->toBeNull()
        ->and(app(ChainVerifier::class)->verify($this->company)['ok'])->toBeTrue()
        ->and(AuditLog::query()->where('action', 'signing.subject_erased')->count())->toBe(2);
});

it('refuses a retention date written on insert', function () {
    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $this->company->id, 'document_type' => 'DOCUMENT', 'status' => 'COMPLETED',
        'document_path' => 'x.pdf', 'document_name' => 'x.pdf', 'document_sha256' => str_repeat('a', 64), 'completed_at' => '2010-01-01 10:00:00+01',
        'retention_class' => 'OTHER', 'retention_until' => '2011-01-01', 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('accepts an empty period without an error page, and says a completed envelope without a date is kept', function () {
    Livewire::test(SigningRetentionSettings::class, ['company' => $this->company])->set('years', '')->call('save')->assertHasErrors('years');

    $envelope = s16Send($this->owner, $this->company, s16Store($this->owner, $this->company), ['eva@example.test']);
    s16Sign('eva@example.test');
    $envelope->refresh()->setAttribute('retention_until', null);

    expect(SignerRecords::reason('EVIDENCE', $envelope))->not->toContain('fortfarande ute');
});

/*
 * Re-check round (2026-11-19).
 */

/** A quote signed on 2026-12-20 with the 7-year period and the 2027 fiscal year open; returns [quote, signer, envelope]. */
function s16DecemberQuote(User $owner, Company $company): array
{
    app(ManageSigningRetention::class)->saveYears($owner, $company, 7);
    FiscalYear::query()->create(['company_id' => $company->id, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    test()->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00'));
    $quote = s16Quote($owner, $company, '2026-12-20');
    app(SendForSigning::class)($owner, $company, $quote, ['email' => 'anna@kontorshuset.test']);
    $signer = s16Sign('anna@kontorshuset.test');

    return [$quote, $signer, SigningEnvelope::query()->sole()];
}

it('counts a quote invoiced through its work order in the next fiscal year', function () {
    WorkOrders::enable($this->company, $this->owner);
    [$quote, , $envelope] = s16DecemberQuote($this->owner, $this->company);
    expect($envelope->retention_until?->toDateString())->toBe('2033-12-31');

    $workOrder = WorkOrders::workOrder($this->company, $this->owner, Customer::query()->findOrFail($quote->customer_id));
    DB::table('work_orders')->where('id', $workOrder->id)->update(['sales_document_id' => $quote->id]);
    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));
    WorkOrders::hours($this->company, $this->owner, $workOrder->refresh(), '4', '2027-01-12');
    $invoice = app(CreateInvoiceBasis::class)($this->owner, $this->company, $workOrder->refresh());
    app(IssueInvoice::class)($this->owner, $invoice);

    expect($envelope->refresh()->retention_until?->toDateString())->toBe('2034-12-31')
        ->and(SigningRetention::requiredUntil($envelope)?->toDateString())->toBe('2034-12-31');
});

it('counts a credit note and a write-off booked in a later fiscal year', function () {
    [, $signer, $envelope] = s16DecemberQuote($this->owner, $this->company);
    $invoice = app(IssueInvoice::class)($this->owner, app(ProgressSalesDocument::class)->toInvoice($this->owner, SalesDocument::query()->findOrFail($signer->refresh()->order_id)));
    expect($envelope->refresh()->retention_until?->toDateString())->toBe('2033-12-31');

    // A credit note of the invoice in 2027.
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
    app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $invoice->refresh()));
    expect($envelope->refresh()->retention_until?->toDateString())->toBe('2034-12-31');

    // A write-off of the same kind of invoice, in 2027, moves the floor too (checked through requiredUntil on a second envelope).
    $other = s16Quote($this->owner, $this->company, '2027-02-10');
    app(SendForSigning::class)($this->owner, $this->company, $other, ['email' => 'bo@kontorshuset.test']);
    $second = s16Sign('bo@kontorshuset.test');
    $otherInvoice = app(IssueInvoice::class)($this->owner, app(ProgressSalesDocument::class)->toInvoice($this->owner, SalesDocument::query()->findOrFail($second->refresh()->order_id)));
    $otherEnvelope = SigningEnvelope::query()->where('sales_document_id', $other->id)->sole();
    expect($otherEnvelope->retention_until?->toDateString())->toBe('2034-12-31');
    FiscalYear::query()->create(['company_id' => $this->company->id, 'start_date' => '2028-01-01', 'end_date' => '2028-12-31']);
    $this->travelTo(CarbonImmutable::parse('2028-03-01 10:00:00'));
    app(WriteOffInvoice::class)($this->owner, $otherInvoice->refresh(), CarbonImmutable::parse('2028-03-01'), 'Kunden har gått i konkurs.');

    expect($otherEnvelope->refresh()->retention_until?->toDateString())->toBe('2035-12-31');
});

it('checks what keeps a batch of due envelopes in a fixed number of queries', function () {
    Crm::enable($this->company, $this->owner);
    $account = Crm::accountFor(Crm::customer($this->company, $this->owner));
    $make = function (int $count) use ($account): void {
        $this->travelTo(CarbonImmutable::parse('2012-03-01 10:00:00'));

        foreach (range(1, $count) as $number) {
            $agreement = app(SaveAgreement::class)($this->owner, $this->company, ['account_id' => $account->id, 'title' => "Löpande {$number}", 'kind' => 'SUPPORT', 'start_date' => '2012-03-01', 'end_date' => null, 'notice_months' => 3, 'auto_renew' => false, 'value' => '1 000', 'value_period' => 'MONTH', 'body_text' => 'Avtal.']);
            app(SendForSigning::class)($this->owner, $this->company, $agreement, ['email' => "kund{$number}@kontorshuset.test"]);
            s16Sign("kund{$number}@kontorshuset.test");
        }

        $this->travelBack();
    };
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(PruneSigningLinks::class)->run(CarbonImmutable::now());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $make(1);
    $one = $count();
    $make(9);

    expect($count())->toBe($one)
        ->and(SigningEnvelope::query()->where('document_type', 'AGREEMENT')->count())->toBe(10);
});

it('lets a signed quote\'s envelope be deleted directly once its date has passed', function () {
    $this->travelTo(CarbonImmutable::parse('2012-03-01 10:00:00'));
    $quote = s16Quote($this->owner, $this->company, '2012-03-01');
    app(SendForSigning::class)($this->owner, $this->company, $quote, ['email' => 'anna@kontorshuset.test']);
    s16Sign('anna@kontorshuset.test');
    $this->travelBack();

    expect(SigningEnvelope::query()->sole()->retention_until?->lessThan(CarbonImmutable::today()))->toBeTrue()
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('sales_document_id', $quote->id)->delete()))->not->toThrow(QueryException::class);
});

it('refuses the cascade from a deleted quote or agreement to a signed envelope, even after its date', function () {
    $this->travelTo(CarbonImmutable::parse('2012-03-01 10:00:00'));
    $quote = s16Quote($this->owner, $this->company, '2012-03-01');
    app(SendForSigning::class)($this->owner, $this->company, $quote, ['email' => 'anna@kontorshuset.test']);
    s16Sign('anna@kontorshuset.test');
    $this->travelBack();
    // The signed signer rows and the envelope both refuse the cascade (whichever PostgreSQL reaches first); the order's
    // link to the quote is cut first so the foreign key from the order is not what stops it.
    DB::table('sales_documents')->where('from_quote_id', $quote->id)->update(['from_quote_id' => null]);

    expect(fn () => DB::transaction(fn () => DB::table('sales_documents')->where('id', $quote->id)->delete()))->toThrow(QueryException::class, 'cannot be deleted');
});

it('lets the sender hold a confidential standalone document from core settings with the module off, and nobody else', function () {
    $colleague = Bookkeeping::member($this->company, 'admin');
    $this->actingAs($colleague);
    $secret = s16Send($colleague, $this->company, s16Store($colleague, $this->company, 'Anställningsavtal Hemligt'), ['eva@example.test'], [], confidential: true);
    s16Sign('eva@example.test');
    app(ModuleManager::class)->disable(SigningModule::KEY, $this->company, $this->owner, confirmed: true);

    // The owner did not send it: the title is not searched and an id from elsewhere is refused.
    $this->actingAs($this->owner);
    Livewire::test(SigningRetentionSettings::class, ['company' => $this->company])
        ->set('holdSearch', 'Hemligt')->call('searchHolds')->assertDontSee('Anställningsavtal Hemligt')
        ->set('holdReason', 'TVIST')->call('setHold', $secret->id)->assertNotFound();

    $this->actingAs($colleague);
    Livewire::test(SigningRetentionSettings::class, ['company' => $this->company])
        ->set('holdSearch', 'Hemligt')->call('searchHolds')->assertSee('Anställningsavtal Hemligt')
        ->set('holdReason', 'TVIST')->call('setHold', $secret->id)->assertHasNoErrors();

    expect($secret->refresh()->retention_hold)->toBeTrue();

    $this->actingAs($this->owner);
    Livewire::test(SigningRetentionSettings::class, ['company' => $this->company])->assertSee('Konfidentiell signering')->assertDontSee('Anställningsavtal Hemligt');
});
