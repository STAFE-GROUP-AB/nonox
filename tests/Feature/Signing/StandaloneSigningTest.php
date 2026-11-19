<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\PruneSigningLinks;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Actions\Signing\SigningReminders;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\Verification\SignerChecks;
use App\Livewire\Signing\SigningPanel;
use App\Mail\SignedDocumentMail;
use App\Mail\SigningEnvelopeMail;
use App\Mail\SigningRequestMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\Crm\Actions\SaveAgreement;
use App\Modules\Crm\CrmModule;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\DeleteSigningDocument;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Livewire\EnvelopePage;
use App\Modules\Signing\Livewire\EnvelopesPage;
use App\Modules\Signing\Livewire\SendWizard;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\PdfEngineFixtures;
use Tests\Support\SigningConsentFixture;

/*
 * The module "Signering" (ADR-046 amendment 2026-11-18, gap 10, package S7):
 * standalone documents of any PDF, the upload checks, the envelope list and
 * page, the send wizard, confidential envelopes and "Fler undertecknare" in
 * the signing panel. The PDF engine is the fake one (no PDF tooling is
 * installed for the tests).
 */

function s7Png(): string
{
    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imageline($image, 10, 50, 290, 60, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

/** Writes bytes to a temporary file the way an upload arrives, and returns its path. */
function s7File(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 's7');
    file_put_contents($path, $bytes);

    return $path;
}

function s7Store(User $user, Company $company, string $title = 'Anställningsavtal', ?string $bytes = null): SigningDocument
{
    $path = s7File($bytes ?? PdfEngineFixtures::pdf(2));

    try {
        return app(StoreSigningDocument::class)($user, $company, $path, 'Avtal 2026.pdf', $title);
    } finally {
        @unlink($path);
    }
}

/** @param  list<array<string, mixed>>  $signers */
function s7Send(User $user, Company $company, SigningDocument $document, array $signers, array $extra = []): SigningEnvelope
{
    return app(SendForSigning::class)->envelope($user, $company, $document, ['signers' => $signers, ...$extra], minSigners: 1);
}

function s7Sign(string $token, string $name = 'Anna Andersson'): SigningRequest
{
    $links = app(SignAtLink::class);
    $request = $links->find($token);
    expect($request)->toBeInstanceOf(SigningRequest::class);

    SigningConsentFixture::give($request);

    return $links->sign($request, ['name' => $name, 'accept' => '1', 'method' => 'DRAWN', 'signature' => s7Png(), 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)], '203.0.113.7', 'Pest');
}

function s7TokenFor(string $email): string
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

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    $this->engine = FakePdfEngine::install();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(ModuleManager::class)->enable(SigningModule::KEY, $this->company, $this->owner);
});

it('gives the roles of ADR-046 gap 10 their permissions, and only the archive permission while the module is off', function () {
    $matrix = [
        'owner' => [true, true, true],
        'admin' => [true, true, true],
        'accountant' => [true, true, true],
        'bookkeeper' => [true, true, false],
        'auditor' => [true, false, false],
        'approver' => [false, false, false],
    ];

    foreach ($matrix as $role => $expected) {
        $user = $role === 'owner' ? $this->owner : Bookkeeping::member($this->company, $role);
        $company = Company::query()->findOrFail($this->company->id);

        expect([$user->hasTeamPermission($company, 'signing:view'), $user->hasTeamPermission($company, 'signing:send'), $user->hasTeamPermission($company, 'signing:manage')])->toBe($expected, $role);
    }

    Crm::enable($this->company, $this->owner);
    $sales = Bookkeeping::member($this->company, 'sales');
    expect([$sales->hasTeamPermission(Company::query()->findOrFail($this->company->id), 'signing:view'), $sales->hasTeamPermission(Company::query()->findOrFail($this->company->id), 'signing:send'), $sales->hasTeamPermission(Company::query()->findOrFail($this->company->id), 'signing:manage')])->toBe([true, true, false]);

    app(ModuleManager::class)->disable(SigningModule::KEY, $this->company, $this->owner, confirmed: true);
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect([$bookkeeper->hasTeamPermission(Company::query()->findOrFail($this->company->id), 'signing:view'), $bookkeeper->hasTeamPermission(Company::query()->findOrFail($this->company->id), 'signing:send'), $bookkeeper->hasTeamPermission(Company::query()->findOrFail($this->company->id), 'signing:manage')])->toBe([true, false, false]);
});

it('stores an uploaded PDF as a standalone document: private, random name, fingerprint, audit without the title', function () {
    $document = s7Store($this->owner, $this->company, 'Anställningsavtal Eva');

    expect($document->page_count)->toBe(2)
        ->and($document->name)->toBe('Avtal 2026.pdf')
        ->and($document->path)->toStartWith("signing-documents/{$this->company->id}/")->and($document->path)->not->toContain('Avtal')
        ->and(Storage::disk('local')->exists($document->path))->toBeTrue()
        ->and($document->sha256)->toBe(hash('sha256', Storage::disk('local')->get($document->path)))
        ->and($document->signingFingerprint())->toBe($document->sha256)
        ->and($document->signingType())->toBe('DOCUMENT')
        ->and($document->signingIsOpen())->toBeTrue();

    $entry = AuditLog::query()->where('action', 'signing.document_uploaded')->sole();
    expect(json_encode($entry->payload))->not->toContain('Eva')->not->toContain('Avtal')->and($entry->payload['pages'])->toBe(2);
});

it('refuses what is not a real, acceptable PDF', function (string $why, string $bytes, string $message) {
    expect(fn () => s7Store($this->owner, $this->company, 'Dokument', $bytes))->toThrow(ValidationException::class, $message);
    expect(SigningDocument::query()->count())->toBe(0);
})->with([
    'text file' => ['text', "Det här är ingen PDF.\nBara text.", 'inte en PDF'],
    'a PDF with something in front of the header' => ['lead', "\n".PdfEngineFixtures::pdf(), 'inte en PDF'],
    'an image' => ['image', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4//8/AAX+Av4N70a4AAAAAElFTkSuQmCC'), 'inte en PDF'],
    'javascript' => ['js', PdfEngineFixtures::withJavaScript(), 'JavaScript'],
    'a launch action' => ['launch', PdfEngineFixtures::withLaunchAction(), 'starta ett annat program'],
    'embedded files' => ['embedded', PdfEngineFixtures::withEmbeddedFile(), 'bifogade filer'],
    'a form that sends data out' => ['submit', PdfEngineFixtures::pdf(1, '/OpenAction 90 0 R', ['<< /S /SubmitForm /F (https://example.com) >>']), 'skickar uppgifter'],
    'encryption' => ['encrypted', str_replace('/Root 1 0 R', '/Root 1 0 R /Encrypt 9 0 R', PdfEngineFixtures::pdf()), 'krypterad'],
    'more than 100 pages' => ['pages', PdfEngineFixtures::pdf(101), 'fler än 100 sidor'],
]);

it('refuses a file over 20 MB before the engine sees it, and a file when the engine is not installed', function () {
    $big = PdfEngineFixtures::pdf().str_repeat('0', 20 * 1024 * 1024);

    expect(fn () => s7Store($this->owner, $this->company, 'Stor', $big))->toThrow(ValidationException::class, 'större än 20 MB')
        ->and($this->engine->calls)->toBe([]);

    $this->engine->installed = false;

    expect(fn () => s7Store($this->owner, $this->company))->toThrow(ValidationException::class, 'Kräver PDF-motorn');
    expect(SigningDocument::query()->count())->toBe(0);
});

it('keeps only a safe file name, and refuses a title with control characters', function () {
    expect(StoreSigningDocument::safeName('../../etc/passwd'))->toBe('passwd.pdf')
        ->and(StoreSigningDocument::safeName("a\"b\r\nc<d>.pdf"))->toBe('abcd.pdf')
        ->and(StoreSigningDocument::safeName('..'))->toBe('dokument.pdf')
        ->and(StoreSigningDocument::safeName('Anställningsavtal åäö (1).PDF'))->toBe('Anställningsavtal åäö (1).pdf');

    expect(fn () => s7Store($this->owner, $this->company, "Rubrik\nmed radbrytning"))->toThrow(ValidationException::class);
});

it('needs signing:send and the module to be on to upload, and the register view permission to link a customer', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $path = s7File(PdfEngineFixtures::pdf());

    expect(fn () => app(StoreSigningDocument::class)($auditor, $this->company, $path, 'a.pdf', 'Titel'))->toThrow(AuthorizationException::class);

    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'inkop@kontorshuset.test', 'payment_terms_days' => 30]);
    $document = app(StoreSigningDocument::class)($this->owner, $this->company, $path, 'a.pdf', 'Med kund', $customer->id);
    expect($document->customer_id)->toBe($customer->id)->and(fn () => app(StoreSigningDocument::class)($this->owner, $this->company, $path, 'a.pdf', 'Med okänd kund', 999_999))->toThrow(ValidationException::class);

    app(ModuleManager::class)->disable(SigningModule::KEY, $this->company, $this->owner, confirmed: true);
    expect(fn () => app(StoreSigningDocument::class)($this->owner, $this->company, $path, 'a.pdf', 'Av'))->toThrow(NotFoundHttpException::class);
    @unlink($path);
});

it('fixes the file of a document in the database, and never deletes a document that has been sent', function () {
    $document = s7Store($this->owner, $this->company);

    expect(fn () => $document->forceFill(['sha256' => str_repeat('a', 64)])->save())->toThrow(LogicException::class);
    expect(fn () => DB::transaction(fn () => DB::table('signing_documents')->where('id', $document->id)->update(['sha256' => str_repeat('a', 64)])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('signing_documents')->where('id', $document->id)->update(['page_count' => 3])))->toThrow(QueryException::class);

    DB::table('signing_documents')->where('id', $document->id)->update(['title' => 'Nytt namn']);
    expect($document->refresh()->title)->toBe('Nytt namn');

    s7Send($this->owner, $this->company, $document, [['email' => 'eva@example.test', 'step' => 1, 'verification' => 'NONE']]);

    expect(fn () => DB::transaction(fn () => DB::table('signing_documents')->where('id', $document->id)->delete()))->toThrow(QueryException::class);

    $unsent = s7Store($this->owner, $this->company, 'Aldrig skickad');
    expect(DB::table('signing_documents')->where('id', $unsent->id)->delete())->toBe(1);
});

it('walks the wizard from a stored document to a one-signer envelope with an e-mail code as the default check', function () {
    $document = s7Store($this->owner, $this->company, 'Hyresavtal');

    $wizard = Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $document->id])
        ->assertSet('step', 2)
        ->assertSet('signers.0.verification', 'EMAIL_CODE')
        ->set('signers.0.email', 'Eva@Example.test')
        ->call('next')->assertSet('step', 3)->assertSee('Kod till e-posten')
        ->call('next')->assertSet('step', 4)->assertSee('Konfidentiellt')
        ->call('next')->assertSet('step', 5)->assertSee('Hyresavtal')->assertSee('Eva@Example.test')
        ->call('send')->assertHasNoErrors();

    $envelope = SigningEnvelope::query()->sole();
    $signer = SigningRequest::query()->sole();

    $wizard->assertRedirect(route('signing.envelope', $envelope->id));
    expect($envelope->document_type)->toBe('DOCUMENT')->and($envelope->signable_id)->toBe($document->id)
        ->and($envelope->title)->toBe('Hyresavtal')
        ->and($envelope->confidential)->toBeFalse()
        ->and($signer->email)->toBe('eva@example.test')
        ->and($signer->verification->value)->toBe('EMAIL_CODE')
        ->and($signer->document_sha256)->toBe($document->sha256);
    Mail::assertQueued(SigningRequestMail::class, 1);
});

it('refuses to go on from the signer step without an address, and opens the step where a server error is', function () {
    $document = s7Store($this->owner, $this->company);

    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $document->id])
        ->call('next')->assertHasErrors('signers.0.email')->assertSet('step', 2)
        ->set('signers.0.email', 'eva@example.test')
        ->set('signers.0.verification', 'ACCESS_CODE')->set('signers.0.access_code', '123')
        ->call('next')->call('next')->call('next')->assertSet('step', 5)
        ->call('send')->assertHasErrors()->assertSet('step', 3);

    expect(SigningEnvelope::query()->count())->toBe(0);
});

it('sends to several signers in order, with copy recipients, and one signer with copies gets the copies at completion', function () {
    $document = s7Store($this->owner, $this->company, 'Samarbetsavtal');

    $envelope = s7Send($this->owner, $this->company, $document, [
        ['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE'],
        ['email' => 'bo@example.test', 'step' => 2, 'verification' => 'NONE'],
    ], ['copies' => [['email' => 'kopia@example.test', 'name' => 'Kopia']]]);

    expect($envelope->status)->toBe(EnvelopeStatus::Out);
    Mail::assertQueued(SigningRequestMail::class, fn (SigningRequestMail $mail): bool => $mail->hasTo('anna@example.test'));
    Mail::assertNotQueued(SigningRequestMail::class, fn (SigningRequestMail $mail): bool => $mail->hasTo('bo@example.test'));

    s7Sign(s7TokenFor('anna@example.test'));
    s7Sign(s7TokenFor('bo@example.test'), 'Bo Berg');

    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Completed);
    Mail::assertQueued(SigningEnvelopeMail::class, fn (SigningEnvelopeMail $mail): bool => $mail->hasTo('kopia@example.test'));

    Mail::fake();
    $single = s7Store($this->owner, $this->company, 'Ensam');
    s7Send($this->owner, $this->company, $single, [['email' => 'eva@example.test', 'step' => 1, 'verification' => 'NONE']], ['copies' => [['email' => 'kopia2@example.test']]]);
    s7Sign(s7TokenFor('eva@example.test'), 'Eva Ek');

    Mail::assertQueued(SigningEnvelopeMail::class, fn (SigningEnvelopeMail $mail): bool => $mail->hasTo('kopia2@example.test'));
    Mail::assertQueued(SignedDocumentMail::class, fn (SignedDocumentMail $mail): bool => $mail->hasTo('eva@example.test'));
    Mail::assertNotQueued(SigningEnvelopeMail::class, fn (SigningEnvelopeMail $mail): bool => $mail->hasTo('eva@example.test'));
});

it('keeps a confidential envelope from members without the right, in the list, on its page and in its files', function () {
    $sender = Bookkeeping::member($this->company, 'bookkeeper');
    $colleague = Bookkeeping::member($this->company, 'bookkeeper');
    $manager = Bookkeeping::member($this->company, 'accountant');
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $approver = Bookkeeping::member($this->company, 'approver');

    $secret = s7Store($sender, $this->company, 'Anställningsavtal Hemligt');
    $open = s7Store($sender, $this->company, 'Öppet avtal');
    $secretEnvelope = s7Send($sender, $this->company, $secret, [['email' => 'eva@example.test', 'step' => 1, 'verification' => 'NONE']], ['confidential' => true]);
    $openEnvelope = s7Send($sender, $this->company, $open, [['email' => 'bo@example.test', 'step' => 1, 'verification' => 'NONE']]);
    $signerId = SigningRequest::query()->where('envelope_id', $secretEnvelope->id)->value('id');

    expect($secretEnvelope->confidential)->toBeTrue()
        ->and(json_encode(AuditLog::query()->where('action', 'crm.signing_envelope_sent')->pluck('payload')->all()))->not->toContain('Hemligt');

    foreach ([[$sender, true], [$manager, true], [$this->owner, true], [$colleague, false], [$auditor, false]] as [$user, $sees]) {
        $this->actingAs($user);
        $list = $this->get(route('signing.envelopes'))->assertOk();
        $sees ? $list->assertSee('Anställningsavtal Hemligt') : $list->assertDontSee('Anställningsavtal Hemligt');
        $list->assertSee('Öppet avtal');

        $page = $this->get(route('signing.envelope', $secretEnvelope->id));
        $file = $this->get(route('signing.file', ['signing' => $signerId, 'file' => 'dokument']));
        $sees ? $page->assertOk()->assertSee('Konfidentiell') : $page->assertNotFound();
        $sees ? $file->assertOk() : $file->assertNotFound();
        $this->get(route('signing.envelope', $openEnvelope->id))->assertOk();
    }

    $this->actingAs($approver);
    $this->get(route('signing.envelopes'))->assertNotFound();
    $this->get(route('signing.envelope', $openEnvelope->id))->assertNotFound();

    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $secretEnvelope->id)->update(['confidential' => false])))->toThrow(QueryException::class);
});

it('lets only the sender and signing:manage remind, move the last day of and withdraw an envelope of a standalone document', function () {
    $sender = Bookkeeping::member($this->company, 'bookkeeper');
    $colleague = Bookkeeping::member($this->company, 'bookkeeper');
    $manager = Bookkeeping::member($this->company, 'accountant');

    $envelope = s7Send($sender, $this->company, s7Store($sender, $this->company, 'Avtal A'), [['email' => 'eva@example.test', 'step' => 1, 'verification' => 'NONE']]);

    $this->actingAs($colleague);
    Livewire::test(EnvelopePage::class, ['envelopeId' => $envelope->id])->assertDontSee('Dra tillbaka')->assertDontSee('Påminn nu')->call('voidEnvelope')->assertForbidden();
    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Out);

    $this->actingAs($sender);
    Livewire::test(EnvelopePage::class, ['envelopeId' => $envelope->id])->assertSee('Dra tillbaka')->assertSee('Påminn nu')
        ->call('editDeadline')->set('newDeadline', '2026-07-10')->call('changeDeadline')->assertHasNoErrors();
    expect($envelope->refresh()->deadline_at?->format('Y-m-d'))->toBe('2026-07-10');

    $this->actingAs($manager);
    Livewire::test(EnvelopePage::class, ['envelopeId' => $envelope->id])->assertSee('Dra tillbaka')->call('voidEnvelope')->assertHasNoErrors();
    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Voided);
    $this->get('/signera/'.s7TokenFor('eva@example.test'))->assertStatus(410);
});

it('answers 404 for another company\'s envelope, document and files', function () {
    [$ownerB, $companyB] = Bookkeeping::company();
    app(ModuleManager::class)->enable(SigningModule::KEY, $companyB, $ownerB);
    CurrentCompany::set($companyB);
    $this->actingAs($ownerB);
    $documentB = s7Store($ownerB, $companyB, 'Betas avtal');
    $envelopeB = s7Send($ownerB, $companyB, $documentB, [['email' => 'b@example.test', 'step' => 1, 'verification' => 'NONE']]);
    $signerB = SigningRequest::query()->where('envelope_id', $envelopeB->id)->sole();

    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $documentA = s7Store($this->owner, $this->company, 'Alfas avtal');
    s7Send($this->owner, $this->company, $documentA, [['email' => 'a@example.test', 'step' => 1, 'verification' => 'NONE']]);

    expect(SigningDocument::query()->pluck('title')->all())->toBe(['Alfas avtal'])
        ->and(SigningEnvelope::query()->pluck('title')->all())->toBe(['Alfas avtal']);
    $this->get(route('signing.envelopes'))->assertOk()->assertSee('Alfas avtal')->assertDontSee('Betas avtal');
    $this->get(route('signing.envelope', $envelopeB->id))->assertNotFound();
    $this->get(route('signing.file', ['signing' => $signerB->id, 'file' => 'dokument']))->assertNotFound();
    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $documentB->id])->assertNotFound();
    expect(fn () => app(SendForSigning::class)->envelope($this->owner, $this->company, $documentB, ['signers' => [['email' => 'x@example.test', 'step' => 1]]], minSigners: 1))->toThrow(NotFoundHttpException::class);
});

it('filters and searches the list from the address, a page of twenty-five at a time', function () {
    $titles = ['Alfa', 'Beta', 'Gamma'];
    $envelopes = collect($titles)->map(fn (string $title): SigningEnvelope => s7Send($this->owner, $this->company, s7Store($this->owner, $this->company, "{$title}avtal"), [['email' => strtolower($title).'@example.test', 'step' => 1, 'verification' => 'NONE']]));

    s7Sign(s7TokenFor('alfa@example.test'));
    app(SendForSigning::class)->void($this->owner, $this->company, $envelopes[1]);

    Livewire::test(EnvelopesPage::class)->assertSee('Alfaavtal')->assertSee('Betaavtal')->assertSee('Gammaavtal')
        ->set('show', 'klara')->assertSee('Alfaavtal')->assertDontSee('Betaavtal')->assertDontSee('Gammaavtal')
        ->set('show', 'tillbakadragna')->assertSee('Betaavtal')->assertDontSee('Alfaavtal')
        ->set('show', 'ute')->assertSee('Gammaavtal')->assertDontSee('Betaavtal')
        ->set('show', 'alla')->set('search', 'gamm')->assertSee('Gammaavtal')->assertDontSee('Alfaavtal')
        ->set('search', '%')->assertDontSee('Gammaavtal');

    $this->get(route('signing.envelopes', ['visa' => 'klara', 'sok' => 'alfa']))->assertOk()->assertSee('Alfaavtal')->assertDontSee('Gammaavtal');

    $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00:00'));
    Livewire::test(EnvelopesPage::class)->set('show', 'utgangna')->assertSee('Gammaavtal')->assertDontSee('Alfaavtal');
});

it('runs a fixed number of queries on the list and the envelope page, however many envelopes and signers there are', function () {
    $count = function (string $url): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $first = s7Send($this->owner, $this->company, s7Store($this->owner, $this->company, 'Ett'), [['email' => 'ett@example.test', 'step' => 1, 'verification' => 'NONE']]);
    $this->get(route('signing.envelopes'))->assertOk();
    $listShort = $count(route('signing.envelopes'));
    $pageShort = $count(route('signing.envelope', $first->id));

    foreach (range(1, 9) as $number) {
        s7Send($this->owner, $this->company, s7Store($this->owner, $this->company, "Avtal {$number}"), [['email' => "n{$number}@example.test", 'step' => 1, 'verification' => 'NONE']]);
    }

    $many = s7Send($this->owner, $this->company, s7Store($this->owner, $this->company, 'Tio signerare'), collect(range(1, 10))->map(fn (int $number): array => ['email' => "s{$number}@example.test", 'step' => $number, 'verification' => 'NONE'])->all(), ['copies' => [['email' => 'k1@example.test'], ['email' => 'k2@example.test']]]);

    expect($count(route('signing.envelopes')))->toBe($listShort)
        ->and($count(route('signing.envelope', $many->id)))->toBe($pageShort);
});

it('shows a quote\'s and an agreement\'s envelopes only to those who may see the document, and an agreement only while CRM is on', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'inkop@kontorshuset.test', 'payment_terms_days' => 30]);
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $customer->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15', 'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]]]);
    app(SendForSigning::class)($this->owner, $this->company, $quote, ['email' => 'inkop@kontorshuset.test']);

    Crm::enable($this->company, $this->owner);
    $account = Crm::accountFor($customer);
    $agreement = app(SaveAgreement::class)($this->owner, $this->company, ['account_id' => $account->id, 'title' => 'Serviceavtalet', 'kind' => 'SUPPORT', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'notice_months' => 1, 'auto_renew' => false, 'value' => '1 000', 'value_period' => 'MONTH', 'body_text' => 'Service.']);
    app(SendForSigning::class)($this->owner, $this->company, $agreement, ['email' => 'inkop@kontorshuset.test']);
    $quoteTitle = SigningEnvelope::query()->where('document_type', 'QUOTE')->value('title');

    $this->get(route('signing.envelopes'))->assertOk()->assertSee($quoteTitle)->assertSee('Serviceavtalet')->assertSee('Offert')->assertSee('Avtal');
    $this->get(route('signing.envelope', SigningEnvelope::query()->where('document_type', 'QUOTE')->value('id')))->assertOk()->assertSee('Öppna offerten');

    $auditor = Bookkeeping::member($this->company, 'auditor');
    $this->actingAs($auditor);
    $this->get(route('signing.envelopes'))->assertOk()->assertSee($quoteTitle)->assertSee('Serviceavtalet');
    $this->get(route('signing.envelope', SigningEnvelope::query()->where('document_type', 'AGREEMENT')->value('id')))->assertOk();

    $this->actingAs($this->owner);
    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, confirmed: true);
    $this->get(route('signing.envelopes'))->assertOk()->assertDontSee('Serviceavtalet')->assertSee($quoteTitle);
});

it('is an archive while the module is off: only signed standalone documents, nothing to send, links closed', function () {
    $done = s7Send($this->owner, $this->company, s7Store($this->owner, $this->company, 'Signerat avtal'), [['email' => 'a@example.test', 'step' => 1, 'verification' => 'NONE']]);
    $signed = s7Sign(s7TokenFor('a@example.test'));
    $out = s7Send($this->owner, $this->company, s7Store($this->owner, $this->company, 'Utestående avtal'), [['email' => 'b@example.test', 'step' => 1, 'verification' => 'NONE']]);
    $outToken = s7TokenFor('b@example.test');
    $outSigner = SigningRequest::query()->where('envelope_id', $out->id)->sole();

    app(ModuleManager::class)->disable(SigningModule::KEY, $this->company, $this->owner, confirmed: true);

    $this->get(route('signing.envelopes'))->assertOk()->assertSee('Signerat avtal')->assertDontSee('Utestående avtal')->assertSee('Modulen Signering är avstängd')->assertDontSee('Skicka dokument');
    $this->get(route('signing.envelope', $done->id))->assertOk()->assertSee('Signeringsbevis');
    $this->get(route('signing.envelope', $out->id))->assertNotFound();
    $this->get(route('signing.new'))->assertNotFound();
    $this->get(route('signing.file', ['signing' => $signed->id, 'file' => 'signeringsbevis']))->assertOk();
    $this->get(route('signing.file', ['signing' => $outSigner->id, 'file' => 'dokument']))->assertNotFound();
    $this->get("/signera/{$outToken}")->assertNotFound();
    expect(fn () => s7Send($this->owner, $this->company, SigningDocument::query()->firstOrFail(), [['email' => 'c@example.test', 'step' => 1]]))->toThrow(NotFoundHttpException::class);

    app(ModuleManager::class)->enable(SigningModule::KEY, $this->company, $this->owner);
    $this->get("/signera/{$outToken}")->assertOk();
});

it('refuses a confidential envelope for a quote, which is not a standalone document', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'inkop@kontorshuset.test', 'payment_terms_days' => 30]);
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $customer->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15', 'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]]]);

    expect(fn () => app(SendForSigning::class)->envelope($this->owner, $this->company, $quote, ['signers' => [['email' => 'a@example.test', 'step' => 1], ['email' => 'b@example.test', 'step' => 2]], 'confidential' => true]))
        ->toThrow(ValidationException::class, 'Bara dokument som laddats upp');
});

it('sends the company copy of a confidential envelope to its sender only, never to the company address', function () {
    $this->company->forceFill(['email' => 'info@foretaget.test'])->save();
    $sender = Bookkeeping::member($this->company, 'bookkeeper');

    s7Send($sender, $this->company, s7Store($sender, $this->company, 'Hemligt'), [['email' => 'eva@example.test', 'step' => 1, 'verification' => 'NONE']], ['confidential' => true]);
    s7Sign(s7TokenFor('eva@example.test'));
    Mail::assertQueued(SignedDocumentMail::class, fn (SignedDocumentMail $mail): bool => $mail->hasTo($sender->email));
    Mail::assertNotQueued(SignedDocumentMail::class, fn (SignedDocumentMail $mail): bool => $mail->hasTo('info@foretaget.test'));

    Mail::fake();
    s7Send($sender, $this->company, s7Store($sender, $this->company, 'Vanligt'), [['email' => 'bo@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s7Sign(s7TokenFor('bo@example.test'), 'Bo Berg');
    Mail::assertQueued(SignedDocumentMail::class, fn (SignedDocumentMail $mail): bool => $mail->hasTo('info@foretaget.test'));
});

it('opens the wizard from "Fler undertecknare" in a quote\'s panel while the module is on, and sends the quote through it', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'email' => 'inkop@kontorshuset.test', 'payment_terms_days' => 30]);
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $customer->id, 'document_date' => '2026-06-15', 'until_date' => '2026-07-15', 'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]]]);

    $url = route('signing.new', ['typ' => 'QUOTE', 'dokument' => $quote->id]);
    Livewire::test(SigningPanel::class, ['quoteId' => $quote->id])->assertSee('Fler undertecknare')->assertSeeHtml('href="'.e($url).'"');

    $this->get($url)->assertOk()->assertSeeLivewire(SendWizard::class);

    Livewire::test(SendWizard::class, ['type' => 'QUOTE', 'documentId' => $quote->id])
        ->assertSet('step', 2)->assertSet('signers.0.email', 'inkop@kontorshuset.test')->assertSet('signers.0.verification', 'NONE')
        ->call('addSigner')->set('signers.1.email', 'bo@kontorshuset.test')->set('signers.1.step', 2)
        ->call('next')->call('next')->call('next')->assertDontSee('Konfidentiellt')
        ->call('next')->call('send')->assertHasNoErrors();

    $envelope = SigningEnvelope::query()->sole();
    expect($envelope->document_type)->toBe('QUOTE')->and(SigningRequest::query()->where('envelope_id', $envelope->id)->count())->toBe(2);

    app(ModuleManager::class)->disable(SigningModule::KEY, $this->company, $this->owner, confirmed: true);
    Livewire::test(SigningPanel::class, ['quoteId' => $quote->id])->assertDontSeeHtml('href="'.e($url).'"');
});

it('posts the first step as an ordinary form: checked, stored, and the wizard continues with the document', function () {
    $upload = UploadedFile::fake()->createWithContent('Hyresavtal.pdf', PdfEngineFixtures::pdf(3));

    $response = $this->post(route('signing.upload'), ['file' => $upload, 'title' => 'Hyresavtal Torget 4']);
    $document = SigningDocument::query()->sole();

    $response->assertRedirect(route('signing.new', ['typ' => 'DOCUMENT', 'dokument' => $document->id]));
    expect($document->title)->toBe('Hyresavtal Torget 4')->and($document->page_count)->toBe(3);

    $this->get(route('signing.new', ['typ' => 'DOCUMENT', 'dokument' => $document->id]))->assertOk()->assertSee('Hyresavtal Torget 4')->assertSee('3 sidor');

    $this->post(route('signing.upload'), ['file' => UploadedFile::fake()->createWithContent('falsk.pdf', 'inte en pdf'), 'title' => 'Falsk'])->assertSessionHasErrors('file');
    $this->post(route('signing.upload'), [])->assertSessionHasErrors('file');
    expect(SigningDocument::query()->count())->toBe(1);

    $this->get(route('signing.new'))->assertOk()->assertSee('Uppladdade dokument som inte är skickade')->assertSee('Hyresavtal Torget 4');

    $this->actingAs(Bookkeeping::member($this->company, 'auditor'));
    $this->post(route('signing.upload'), ['file' => UploadedFile::fake()->createWithContent('a.pdf', PdfEngineFixtures::pdf())])->assertForbidden();
    $this->get(route('signing.new'))->assertNotFound();
});

it('marks a standalone document as open until it is signed, and lets a declined one be sent again', function () {
    $document = s7Store($this->owner, $this->company);
    $envelope = s7Send($this->owner, $this->company, $document, [['email' => 'a@example.test', 'step' => 1, 'verification' => 'NONE']]);

    expect($document->signingIsOpen())->toBeTrue();
    $again = s7Send($this->owner, $this->company, $document, [['email' => 'a@example.test', 'step' => 1, 'verification' => 'NONE']]);
    expect($envelope->refresh()->status)->toBe(EnvelopeStatus::Voided)->and($again->status)->toBe(EnvelopeStatus::Out);

    $signed = s7Sign(s7TokenFor('a@example.test'));

    expect($signed->status)->toBe(SigningStatus::Signed)
        ->and($document->refresh()->signingIsOpen())->toBeFalse()
        ->and(fn () => s7Send($this->owner, $this->company, $document, [['email' => 'a@example.test', 'step' => 1]]))->toThrow(ValidationException::class);
});

it('keeps an upload that has not been sent to its uploader: not in a colleague\'s list, not openable, but open to signing:manage', function () {
    $uploader = Bookkeeping::member($this->company, 'bookkeeper');
    $colleague = Bookkeeping::member($this->company, 'bookkeeper');
    $manager = Bookkeeping::member($this->company, 'accountant');
    $secret = s7Store($uploader, $this->company, 'Anställningsavtal innan det skickas');

    $this->actingAs($colleague);
    $this->get(route('signing.new'))->assertOk()->assertDontSee('Anställningsavtal innan det skickas');
    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $secret->id])->assertNotFound();

    $this->actingAs($uploader);
    $this->get(route('signing.new'))->assertOk()->assertSee('Anställningsavtal innan det skickas');
    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $secret->id])->assertSet('step', 2);

    $this->actingAs($manager);
    $this->get(route('signing.new'))->assertOk()->assertSee('Anställningsavtal innan det skickas');
    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $secret->id])->assertSet('step', 2);
});

it('sends no reminder, automatic or by hand, while the module of the document is off', function () {
    $sender = Bookkeeping::member($this->company, 'bookkeeper');
    $envelope = s7Send($sender, $this->company, s7Store($sender, $this->company, 'Påminnelse'), [['email' => 'eva@example.test', 'step' => 1, 'verification' => 'NONE']], ['auto_reminders' => true, 'reminder_days' => 1]);
    $signer = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();
    app(ModuleManager::class)->disable(SigningModule::KEY, $this->company, $this->owner, confirmed: true);
    Mail::fake();

    $this->travelTo(CarbonImmutable::parse('2026-06-17 10:00:00'));
    app(SigningReminders::class)->run(CarbonImmutable::now());
    Mail::assertNothingQueued();

    $this->actingAs($sender);
    expect(fn () => app(SigningReminders::class)->manual($sender, $this->company, $signer))->toThrow(AuthorizationException::class);
});

it('lets only those who may act unlock a locked-out signer, and never the holder of signing:send on another\'s confidential envelope', function () {
    $sender = Bookkeeping::member($this->company, 'bookkeeper');
    $colleague = Bookkeeping::member($this->company, 'bookkeeper');
    $secret = s7Send($sender, $this->company, s7Store($sender, $this->company, 'Hemligt'), [['email' => 'eva@example.test', 'step' => 1, 'verification' => 'EMAIL_CODE']], ['confidential' => true]);
    $open = s7Send($sender, $this->company, s7Store($sender, $this->company, 'Öppet'), [['email' => 'bo@example.test', 'step' => 1, 'verification' => 'EMAIL_CODE']]);
    $secretSigner = SigningRequest::query()->where('envelope_id', $secret->id)->sole();
    $openSigner = SigningRequest::query()->where('envelope_id', $open->id)->sole();
    DB::table('crm_signing_requests')->whereIn('id', [$secretSigner->id, $openSigner->id])->update(['locked_at' => now(), 'verification_failures' => 10]);

    $this->actingAs($colleague);
    expect(fn () => app(SignerChecks::class)->unlock($colleague, $this->company, $secretSigner))->toThrow(AuthorizationException::class);
    Livewire::test(EnvelopePage::class, ['envelopeId' => $secret->id])->assertNotFound();
    Livewire::test(EnvelopePage::class, ['envelopeId' => $open->id])->assertDontSee('Lås upp')->call('unlock', $openSigner->id)->assertForbidden();
    expect($openSigner->refresh()->locked_at)->not->toBeNull();

    $this->actingAs($sender);
    Livewire::test(EnvelopePage::class, ['envelopeId' => $secret->id])->assertSee('Lås upp')->call('unlock', $secretSigner->id)->assertHasNoErrors();
    expect($secretSigner->refresh()->locked_at)->toBeNull();
});

it('deletes a document that was sent but never signed with its last envelope when the links are pruned, and keeps signed and unsent ones', function () {
    $never = s7Store($this->owner, $this->company, 'Aldrig signerat');
    $signed = s7Store($this->owner, $this->company, 'Signerat');
    $unsent = s7Store($this->owner, $this->company, 'Osänt');
    s7Send($this->owner, $this->company, $never, [['email' => 'a@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s7Send($this->owner, $this->company, $signed, [['email' => 'b@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s7Sign(s7TokenFor('b@example.test'));

    $this->travelTo(CarbonImmutable::parse('2027-09-01 10:00:00'));
    app(PruneSigningLinks::class)(CarbonImmutable::now());

    expect(SigningDocument::query()->whereKey($never->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($never->path))->toBeFalse()
        ->and(SigningDocument::query()->whereKey($signed->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($signed->path))->toBeTrue()
        ->and(SigningDocument::query()->whereKey($unsent->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($unsent->path))->toBeTrue();
});

it('answers 404 for a document key that is not a uuid, and refuses a confidential quote envelope in the database', function () {
    Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => 'inte-ett-uuid'])->assertNotFound();
    Livewire::test(SendWizard::class, ['type' => 'QUOTE', 'documentId' => '12345'])->assertNotFound();
    Livewire::test(SendWizard::class, ['type' => 'AGREEMENT', 'documentId' => 'x'])->assertNotFound();
    Livewire::test(SendWizard::class)->call('deleteDocument', 'inte-ett-uuid')->assertNotFound();

    $envelope = s7Send($this->owner, $this->company, s7Store($this->owner, $this->company), [['email' => 'a@example.test', 'step' => 1, 'verification' => 'NONE']]);
    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['document_type' => 'QUOTE', 'confidential' => true])))->toThrow(QueryException::class);
});

it('caps the unsent uploads of a company', function () {
    foreach (range(1, StoreSigningDocument::MAX_UNSENT) as $number) {
        SigningDocument::create(['title' => "Osänt {$number}", 'path' => "signing-documents/x/{$number}.pdf", 'name' => 'a.pdf', 'sha256' => str_repeat('a', 64), 'bytes' => 1, 'page_count' => 1, 'uploaded_by' => $this->owner->id]);
    }

    expect(fn () => s7Store($this->owner, $this->company))->toThrow(ValidationException::class, 'inte är skickade');
});

it('lets the uploader remove a document that was never sent, with its file, and refuses one that was sent', function () {
    $mine = s7Store($this->owner, $this->company, 'Av misstag');
    $colleague = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => app(DeleteSigningDocument::class)($colleague, $this->company, $mine))->toThrow(AuthorizationException::class);

    app(DeleteSigningDocument::class)($this->owner, $this->company, $mine);
    expect(SigningDocument::query()->count())->toBe(0)->and(Storage::disk('local')->exists($mine->path))->toBeFalse()
        ->and(json_encode(AuditLog::query()->where('action', 'signing.document_deleted')->pluck('payload')->all()))->not->toContain('misstag');

    $sent = s7Store($colleague, $this->company, 'Skickat');
    s7Send($colleague, $this->company, $sent, [['email' => 'eva@example.test', 'step' => 1, 'verification' => 'NONE']]);
    expect(fn () => app(DeleteSigningDocument::class)($this->owner, $this->company, $sent))->toThrow(ValidationException::class, 'kan inte tas bort');
    expect(Storage::disk('local')->exists($sent->path))->toBeTrue();

    $other = s7Store($colleague, $this->company, 'Kollegans');
    app(DeleteSigningDocument::class)($this->owner, $this->company, $other);
    expect(SigningDocument::query()->whereKey($other->id)->exists())->toBeFalse();
});
