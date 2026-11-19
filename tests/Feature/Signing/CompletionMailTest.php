<?php

declare(strict_types=1);

use App\Actions\Signing\PruneSigningLinks;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\CompletionFiles;
use App\Domain\Signing\Fields\SigningFields;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningToken;
use App\Mail\SignedDocumentMail;
use App\Mail\SigningEnvelopeMail;
use App\Mail\SigningRequestMail;
use App\Models\Company;
use App\Models\SigningCopyRecipient;
use App\Models\SigningDownloadLink;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\PdfEngineFixtures;
use Tests\Support\SigningConsentFixture;

/*
 * The completion mail to everyone (ADR-046 amendment 2026-11-18, gap 5,
 * package S13): signers, copy recipients and the company, each in their
 * language; the finished version; a file above the mail limit goes as a link
 * with its own token (hashed at rest, 30 days) instead of attachments.
 */

function s13Png(): string
{
    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imageline($image, 10, 50, 290, 60, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

function s13Store(User $user, Company $company, string $title = 'Samarbetsavtal'): SigningDocument
{
    $path = tempnam(sys_get_temp_dir(), 's13');
    file_put_contents($path, PdfEngineFixtures::pdf(2));

    try {
        return app(StoreSigningDocument::class)($user, $company, $path, 'Avtal 2026.pdf', $title);
    } finally {
        @unlink($path);
    }
}

/** @param  list<array<string, mixed>>  $signers */
function s13Send(User $user, Company $company, array $signers, array $extra = []): SigningEnvelope
{
    return app(SendForSigning::class)->envelope($user, $company, s13Store($user, $company), ['signers' => $signers, ...$extra], minSigners: 1);
}

function s13Sign(string $email, string $name = 'Anna Andersson'): SigningRequest
{
    $url = null;

    Mail::assertQueued(SigningRequestMail::class, function (SigningRequestMail $mail) use ($email, &$url): bool {
        if ($mail->hasTo($email)) {
            $url = $mail->url;
        }

        return true;
    });

    $links = app(SignAtLink::class);
    $request = $links->find(basename((string) parse_url((string) $url, PHP_URL_PATH)));
    expect($request)->toBeInstanceOf(SigningRequest::class);

    SigningConsentFixture::give($request);

    return $links->sign($request, ['name' => $name, 'accept' => '1', 'method' => 'DRAWN', 'signature' => s13Png(), 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)], '203.0.113.7', 'Pest');
}

/** The subject as the mailer builds it: under the mail's own locale. */
function s13Subject(SignedDocumentMail|SigningEnvelopeMail $mail): string
{
    $before = app()->getLocale();
    app()->setLocale((string) $mail->locale);

    try {
        return (string) $mail->envelope()->subject;
    } finally {
        app()->setLocale($before);
    }
}

/** @return list<SignedDocumentMail|SigningEnvelopeMail> */
function s13Mails(string $email): array
{
    $found = [];

    foreach ([SignedDocumentMail::class, SigningEnvelopeMail::class] as $class) {
        foreach (Mail::queued($class) as $mail) {
            if ($mail->hasTo($email)) {
                $found[] = $mail;
            }
        }
    }

    return $found;
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
    $this->company->forceFill(['email' => 'info@foretaget.test'])->save();
});

it('mails the copy in each recipient\'s language: English where chosen, Swedish otherwise', function () {
    $envelope = s13Send($this->owner, $this->company, [
        ['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE', 'language' => 'en'],
        ['email' => 'bo@example.test', 'step' => 1, 'verification' => 'NONE'],
    ], ['copies' => [['email' => 'revisor@example.test', 'name' => 'Rut', 'language' => 'en'], ['email' => 'chef@example.test']]]);

    expect(SigningRequest::query()->where('envelope_id', $envelope->id)->orderBy('id')->pluck('language')->all())->toBe(['en', 'sv'])
        ->and(SigningCopyRecipient::query()->orderBy('id')->pluck('language')->all())->toBe(['en', 'sv']);

    s13Sign('anna@example.test');
    s13Sign('bo@example.test', 'Bo Berg');

    $own = s13Mails('anna@example.test');
    $english = collect($own)->first(fn ($mail): bool => $mail->language === 'en');
    expect($english)->not->toBeNull()
        ->and(collect($own)->every(fn ($mail): bool => $mail->language === 'en'))->toBeTrue()
        ->and($english->locale)->toBe('en');

    $html = $english->render();
    expect($html)->toContain('<html lang="en">')->toContain('is signed')->not->toContain('är signerad');
    expect(s13Subject($english))->toContain('Signed');

    $copyEn = collect(s13Mails('revisor@example.test'))->sole();
    $copySv = collect(s13Mails('chef@example.test'))->sole();
    expect($copyEn->render())->toContain('<html lang="en">')->toContain('signed by everyone')
        ->and(s13Subject($copyEn))->toBe('Signed by everyone: '.$envelope->title)
        ->and($copySv->locale)->toBe('sv')
        ->and($copySv->render())->toContain('<html lang="sv">')->toContain('signerat av alla')
        ->and(s13Subject($copySv))->toBe('Signerat av alla: '.$envelope->title);

    // The company's copy stays in Swedish, and the other signer's copy is in their own language.
    $company = collect(s13Mails('info@foretaget.test'))->first(fn ($mail): bool => $mail instanceof SigningEnvelopeMail && $mail->kind === SigningEnvelopeMail::COMPANY);
    expect($company->language)->toBe('sv');
    expect(collect(s13Mails('bo@example.test'))->every(fn ($mail): bool => $mail->language === 'sv'))->toBeTrue();
});

it('refuses a language that is not Swedish or English', function () {
    expect(fn () => s13Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'language' => 'de']]))->toThrow(ValidationException::class);
    expect(fn () => s13Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1]], ['copies' => [['email' => 'x@example.test', 'language' => 'fr']]]))->toThrow(ValidationException::class);
    expect(fn () => DB::table('crm_signing_requests')->where('id', 0)->update(['language' => 'de']))->not->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('signing_copy_recipients')->insert(['company_id' => $this->company->id, 'envelope_id' => (string) fake()->uuid(), 'email' => 'x@example.test', 'language' => 'de', 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class);
});

it('attaches the finished files while they fit, with no link', function () {
    s13Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s13Sign('anna@example.test');

    $mail = collect(s13Mails('anna@example.test'))->sole();
    expect($mail->downloadToken)->toBeNull()
        ->and(count($mail->attachments()))->toBe(2)
        ->and($mail->render())->not->toContain('/signera/kopia/');
    expect(SigningDownloadLink::query()->count())->toBe(0);
});

it('sends a link instead of the files when they are above the mail limit, and the link hands out exactly those files', function () {
    config(['nonox.signing.mail_attachment_limit_bytes' => 1]);

    $envelope = s13Send($this->owner, $this->company, [
        ['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE', 'language' => 'en'],
        ['email' => 'bo@example.test', 'step' => 2, 'verification' => 'NONE'],
    ], ['copies' => [['email' => 'revisor@example.test']]]);
    s13Sign('anna@example.test');
    s13Sign('bo@example.test', 'Bo Berg');

    // Everyone gets a mail with a token and no attachments: signers, the copy recipient and the company.
    foreach (['anna@example.test', 'bo@example.test', 'revisor@example.test', 'info@foretaget.test'] as $email) {
        foreach (s13Mails($email) as $mail) {
            expect($mail->downloadToken)->not->toBeNull()->and($mail->attachments())->toBe([]);
        }
    }

    $copy = collect(s13Mails('revisor@example.test'))->sole();
    $url = route('signing.copy', ['token' => $copy->downloadToken]);
    expect($copy->render())->toContain($url)->toContain('Ladda ner filerna')->toContain('fungerar till och med 2026-06-29');

    // Hashed at rest: the token is nowhere in the table.
    $row = SigningDownloadLink::query()->where('email', 'revisor@example.test')->sole();
    expect($row->token_hash)->toBe(SigningToken::hash($copy->downloadToken))
        ->and(json_encode(DB::table('signing_download_links')->get()))->not->toContain($copy->downloadToken)
        ->and($row->expires_at->toDateString())->toBe('2026-06-29');

    // The copy recipient's page lists the finished version and the summary: never a signer's full certificate.
    $this->get($url)->assertOk()->assertSee('Avtal 2026.pdf')->assertSee('Sammanstallning-av-signeringen.pdf')->assertDontSee('Signeringsbevis-1.pdf')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Cache-Control', 'no-store, private');
    $download = $this->get(route('signing.copy.file', ['token' => $copy->downloadToken, 'file' => 1]))->assertOk();
    expect($download->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($download->streamedContent())->toBe((string) Storage::disk(SigningRequest::DISK)->get(CurrentCompany::run($this->company, fn (): string => SigningFields::finalVersion($envelope))))
        ->and($row->refresh()->downloads)->toBe(1);
    $this->get(route('signing.copy.file', ['token' => $copy->downloadToken, 'file' => 2]))->assertOk();
    $this->get(route('signing.copy.file', ['token' => $copy->downloadToken, 'file' => 3]))->assertNotFound();

    // The company's link carries a certificate per signer.
    $companyMail = collect(s13Mails('info@foretaget.test'))->first(fn ($mail): bool => $mail instanceof SigningEnvelopeMail && $mail->kind === SigningEnvelopeMail::COMPANY);
    // The company's link is valid 7 days, the others 14.
    expect(SigningDownloadLink::query()->where('kind', SigningDownloadLink::COMPANY)->sole()->expires_at->toDateString())->toBe('2026-06-22');
    $this->get(route('signing.copy', ['token' => $companyMail->downloadToken]))->assertOk()->assertSee('Signeringsbevis-1.pdf')->assertSee('Signeringsbevis-2.pdf');

    // The English signer's page is in English.
    $anna = collect(s13Mails('anna@example.test'))->first(fn ($mail): bool => $mail instanceof SigningEnvelopeMail);
    $this->get(route('signing.copy', ['token' => $anna->downloadToken]))->assertOk()->assertSee('is signed')->assertSee('Download');
});

it('gives a single signer\'s link their own finished version and certificate', function () {
    config(['nonox.signing.mail_attachment_limit_bytes' => 1]);

    s13Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    $signed = s13Sign('anna@example.test');

    $mail = collect(s13Mails('anna@example.test'))->sole();
    expect($mail->downloadToken)->not->toBeNull();
    $this->get(route('signing.copy', ['token' => $mail->downloadToken]))->assertOk()->assertSee($signed->document_name)->assertSee(SigningPdfs::certificateFilename($signed));
    $this->get(route('signing.copy.file', ['token' => $mail->downloadToken, 'file' => 2]))->assertOk();

    $company = collect(s13Mails('info@foretaget.test'))->sole();
    expect($company->downloadToken)->not->toBeNull()->and($company->downloadToken)->not->toBe($mail->downloadToken);
});

it('closes an expired link, says nothing about an unknown one, and refuses a malformed one', function () {
    config(['nonox.signing.mail_attachment_limit_bytes' => 1]);
    s13Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s13Sign('anna@example.test');
    $token = (string) collect(s13Mails('anna@example.test'))->sole()->downloadToken;

    $this->get(route('signing.copy', ['token' => $token]))->assertOk();

    $this->travelTo(CarbonImmutable::parse('2026-07-01 10:00:00'));
    $this->get(route('signing.copy', ['token' => $token]))->assertStatus(410)->assertSee('Länken har gått ut');
    $this->get(route('signing.copy.file', ['token' => $token, 'file' => 1]))->assertStatus(410);

    $this->get(route('signing.copy', ['token' => SigningToken::generate()['token']]))->assertNotFound()->assertDontSee($this->company->name);
    $this->get('/signera/kopia/kort')->assertNotFound();

    // An expired row goes 30 days after it expired.
    CurrentCompany::run($this->company, function (): void {
        (new PruneSigningLinks)(CarbonImmutable::parse('2026-07-20'));
        expect(SigningDownloadLink::query()->count())->toBe(2);
        (new PruneSigningLinks)(CarbonImmutable::parse('2026-08-10'));
        expect(SigningDownloadLink::query()->count())->toBe(0);
    });
});

it('cannot change a download link except to count a download', function () {
    config(['nonox.signing.mail_attachment_limit_bytes' => 1]);
    s13Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s13Sign('anna@example.test');
    $link = SigningDownloadLink::query()->where('email', 'anna@example.test')->sole();

    expect(DB::table('signing_download_links')->where('id', $link->id)->update(['downloads' => 3]))->toBe(1);
    expect(fn () => DB::transaction(fn () => DB::table('signing_download_links')->where('id', $link->id)->update(['expires_at' => now()->addYear()])))->toThrow(QueryException::class, 'cannot be changed');
    expect(fn () => DB::transaction(fn () => DB::table('signing_download_links')->where('id', $link->id)->update(['email' => 'annan@example.test'])))->toThrow(QueryException::class);

    foreach ([['company_id' => $this->company->id + 1000], ['language' => 'en'], ['created_at' => now()->subYear()]] as $set) {
        expect(fn () => DB::transaction(fn () => DB::table('signing_download_links')->where('id', $link->id)->update($set)))->toThrow(QueryException::class, 'cannot be changed');
    }

    expect(fn () => DB::transaction(fn () => DB::table('signing_download_links')->where('id', $link->id)->update(['downloads' => 1])))->toThrow(QueryException::class, 'only grows');
    expect(DB::table('signing_download_links')->where('id', $link->id)->update(['revoked_at' => now()]))->toBe(1);
    expect(fn () => DB::transaction(fn () => DB::table('signing_download_links')->where('id', $link->id)->update(['revoked_at' => null])))->toThrow(QueryException::class, 'stays closed');
});

it('sends the company copy of a confidential envelope to the sender only, also as a link', function () {
    config(['nonox.signing.mail_attachment_limit_bytes' => 1]);
    $sender = Bookkeeping::member($this->company, 'bookkeeper');

    s13Send($sender, $this->company, [['email' => 'eva@example.test', 'step' => 1, 'verification' => 'NONE']], ['confidential' => true]);
    s13Sign('eva@example.test');

    $toSender = collect(s13Mails($sender->email))->first(fn ($mail): bool => $mail instanceof SignedDocumentMail && ! $mail->toSigner);
    expect($toSender)->not->toBeNull()->and($toSender->downloadToken)->not->toBeNull();
    Mail::assertNotQueued(SignedDocumentMail::class, fn (SignedDocumentMail $mail): bool => $mail->hasTo('info@foretaget.test'));
    expect(SigningDownloadLink::query()->where('email', 'info@foretaget.test')->count())->toBe(0);
});

it('hands out the finished version with every signer\'s values, as in the mail', function () {
    $envelope = s13Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s13Sign('anna@example.test');

    $files = CurrentCompany::run($this->company, fn (): array => CompletionFiles::forEnvelope($envelope->refresh(), SigningDownloadLink::COPY));
    expect($files[0]['path'])->toBe(CurrentCompany::run($this->company, fn (): string => SigningFields::finalVersion($envelope)))
        ->and($files[1]['make'])->not->toBeNull();
});
