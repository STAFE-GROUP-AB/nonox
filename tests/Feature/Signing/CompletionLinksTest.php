<?php

declare(strict_types=1);

use App\Actions\Signing\ManageDownloadLinks;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\CompletionFiles;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\SigningPdfs;
use App\Mail\SignedDocumentMail;
use App\Mail\SigningDeadlineMail;
use App\Mail\SigningEnvelopeMail;
use App\Mail\SigningReminderMail;
use App\Mail\SigningRequestMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SigningDocumentVersion;
use App\Models\SigningDownloadEvent;
use App\Models\SigningDownloadLink;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Livewire\EnvelopePage;
use App\Modules\Signing\SigningModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\PdfEngineFixtures;

/*
 * The download links of the completion mail (ADR-046 S13), second part:
 * validity, per-download records, closing and sending a new link, and the
 * header and queue details.
 */

function s13bPng(): string
{
    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

/** @param  list<array<string, mixed>>  $signers */
function s13bSend(User $user, Company $company, array $signers, array $extra = [], string $name = 'Avtal 2026.pdf'): SigningEnvelope
{
    $path = tempnam(sys_get_temp_dir(), 's13b');
    file_put_contents($path, PdfEngineFixtures::pdf(2));

    try {
        $document = app(StoreSigningDocument::class)($user, $company, $path, $name, 'Samarbetsavtal');
    } finally {
        @unlink($path);
    }

    return app(SendForSigning::class)->envelope($user, $company, $document, ['signers' => $signers, ...$extra], minSigners: 1);
}

function s13bSign(string $email, string $name = 'Anna Andersson'): SigningRequest
{
    $url = null;

    foreach (Mail::queued(SigningRequestMail::class) as $mail) {
        if ($mail->hasTo($email)) {
            $url = $mail->url;
        }
    }

    $links = app(SignAtLink::class);
    $request = $links->find(basename((string) parse_url((string) $url, PHP_URL_PATH)));
    expect($request)->toBeInstanceOf(SigningRequest::class);

    return $links->sign($request, ['name' => $name, 'accept' => '1', 'method' => 'DRAWN', 'signature' => s13bPng(), 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)], '203.0.113.7', 'Pest');
}

/** The token mailed to an address in a completion mail. */
function s13bToken(string $email, ?string $kind = null): string
{
    foreach ([SignedDocumentMail::class, SigningEnvelopeMail::class] as $class) {
        foreach (Mail::queued($class) as $mail) {
            if ($mail->hasTo($email) && $mail->downloadToken !== null && ($kind === null || ! property_exists($mail, 'kind') || $mail->kind === $kind)) {
                return $mail->downloadToken;
            }
        }
    }

    return '';
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    FakePdfEngine::install();
    config(['nonox.signing.mail_attachment_limit_bytes' => 1]);
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(ModuleManager::class)->enable(SigningModule::KEY, $this->company, $this->owner);
    $this->company->forceFill(['email' => 'info@foretaget.test'])->save();
});

it('records the time of every download, without an address, and refuses to change the record', function () {
    s13bSend($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s13bSign('anna@example.test');
    $token = s13bToken('anna@example.test');

    $this->get(route('signing.copy.file', ['token' => $token, 'file' => 1]))->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-06-16 08:30:00'));
    $this->get(route('signing.copy.file', ['token' => $token, 'file' => 2]))->assertOk();

    $events = SigningDownloadEvent::query()->orderBy('id')->get();
    expect($events->pluck('file_index')->all())->toBe([1, 2])
        ->and($events->last()->downloaded_at->setTimezone('UTC')->toDateTimeString())->toBe('2026-06-16 06:30:00')
        ->and(SigningDownloadLink::query()->where('email', 'anna@example.test')->sole()->downloads)->toBe(2)
        ->and(Schema::getColumnListing('signing_download_events'))->toBe(['id', 'company_id', 'download_link_id', 'file_index', 'downloaded_at']);
    expect(fn () => DB::transaction(fn () => DB::table('signing_download_events')->where('id', $events->first()->id)->update(['file_index' => 9])))->toThrow(QueryException::class, 'cannot be changed');
});

it('lets the sender send a recipient a new link, closing the old one, and close every link', function () {
    $envelope = s13bSend($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']], ['copies' => [['email' => 'revisor@example.test']]]);
    s13bSign('anna@example.test');
    $old = s13bToken('revisor@example.test');
    $this->get(route('signing.copy', ['token' => $old]))->assertOk();

    $link = SigningDownloadLink::query()->where('email', 'revisor@example.test')->sole();
    Mail::fake();
    app(ManageDownloadLinks::class)->resend($this->owner, $this->company, $envelope, $link);
    $new = s13bToken('revisor@example.test');
    expect($new)->not->toBe('')->not->toBe($old);
    $this->get(route('signing.copy', ['token' => $old]))->assertStatus(410)->assertSee('Länken är stängd');
    $this->get(route('signing.copy.file', ['token' => $old, 'file' => 1]))->assertStatus(410);
    $this->get(route('signing.copy', ['token' => $new]))->assertOk();
    expect(AuditLog::query()->where('action', 'signing.download_link_resent')->sole()->payload)->not->toHaveKey('email');

    // Every open link is closed; the audit entry has ids and a count only.
    $open = SigningDownloadLink::query()->whereNull('revoked_at')->count();
    expect(app(ManageDownloadLinks::class)->close($this->owner, $this->company, $envelope))->toBe($open);
    $this->get(route('signing.copy', ['token' => $new]))->assertStatus(410);
    expect(AuditLog::query()->where('action', 'signing.download_links_closed')->sole()->payload)->toEqual(['envelope_id' => $envelope->id, 'links' => $open])
        ->and(app(ManageDownloadLinks::class)->close($this->owner, $this->company, $envelope))->toBe(0);
});

it('lets only someone who may act on the envelope manage its links', function () {
    $envelope = s13bSend($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s13bSign('anna@example.test');
    $auditor = Bookkeeping::member($this->company, 'auditor');

    expect(fn () => app(ManageDownloadLinks::class)->close($auditor, $this->company, $envelope))->toThrow(AuthorizationException::class);
    expect(SigningDownloadLink::query()->whereNull('revoked_at')->count())->toBeGreaterThan(0);
});

it('shows the links on the envelope page, with a query count that does not grow with the links, and the sender closes them there', function () {
    $envelope = s13bSend($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s13bSign('anna@example.test');
    $this->get(route('signing.copy.file', ['token' => s13bToken('anna@example.test'), 'file' => 1]))->assertOk();

    $queries = function () use ($envelope): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(EnvelopePage::class, ['envelopeId' => $envelope->id])->assertSee('Länkar till filerna')->assertSee('anna@example.test')->assertSee('Skicka ny länk');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $few = $queries();

    foreach (range(1, 6) as $i) {
        SigningDownloadLink::create(['envelope_id' => $envelope->id, 'kind' => 'COPY', 'email' => "extra{$i}@example.test", 'language' => 'sv', 'token_hash' => hash('sha256', "extra{$i}"), 'expires_at' => now()->addDays(14)]);
    }
    expect($queries())->toBe($few);

    Livewire::test(EnvelopePage::class, ['envelopeId' => $envelope->id])->call('closeLinks')->assertSee('Stängd');
    expect(SigningDownloadLink::query()->whereNull('revoked_at')->count())->toBe(0);
});

it('closes the links of an envelope that is withdrawn', function () {
    $envelope = s13bSend($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE'], ['email' => 'bo@example.test', 'step' => 2, 'verification' => 'NONE']]);
    $link = SigningDownloadLink::create(['envelope_id' => $envelope->id, 'kind' => 'COPY', 'email' => 'x@example.test', 'language' => 'sv', 'token_hash' => hash('sha256', 'x'), 'expires_at' => now()->addDays(14)]);

    app(SendForSigning::class)->void($this->owner, $this->company, $envelope);

    expect($link->refresh()->isRevoked())->toBeTrue();
});

it('gives the company\'s link and a confidential envelope 7 days, and an ordinary link 14', function () {
    $sender = Bookkeeping::member($this->company, 'bookkeeper');
    s13bSend($sender, $this->company, [['email' => 'eva@example.test', 'step' => 1, 'verification' => 'NONE']], ['confidential' => true, 'copies' => [['email' => 'chef@example.test']]]);
    s13bSign('eva@example.test');

    expect(SigningDownloadLink::query()->where('email', 'eva@example.test')->sole()->expires_at->toDateString())->toBe('2026-06-22')
        ->and(SigningDownloadLink::query()->where('email', 'chef@example.test')->sole()->expires_at->toDateString())->toBe('2026-06-22');

    s13bSend($this->owner, $this->company, [['email' => 'bo@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s13bSign('bo@example.test', 'Bo Berg');
    expect(SigningDownloadLink::query()->where('email', 'bo@example.test')->sole()->expires_at->toDateString())->toBe('2026-06-29');
});

it('hands each signer their own stamped version and the copies the final one', function () {
    $envelope = s13bSend($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE'], ['email' => 'bo@example.test', 'step' => 2, 'verification' => 'NONE']]);
    s13bSign('anna@example.test');
    s13bSign('bo@example.test', 'Bo Berg');
    [$anna, $bo] = SigningRequest::query()->where('envelope_id', $envelope->id)->orderBy('step')->get()->all();

    foreach ([[$anna, 'versions/anna.pdf'], [$bo, 'versions/bo.pdf']] as [$signer, $path]) {
        Storage::disk(SigningRequest::DISK)->put($path, '%PDF-1.4 '.$path);
        SigningDocumentVersion::create(['envelope_id' => $envelope->id, 'kind' => SigningDocumentVersion::AFTER_SIGNER, 'signing_request_id' => $signer->id, 'path' => $path, 'sha256' => hash('sha256', $path), 'bytes' => 10]);
    }

    CurrentCompany::run($this->company, function () use ($envelope, $anna, $bo): void {
        expect(CompletionFiles::forSigner($anna->refresh())[0]['path'])->toBe('versions/anna.pdf')
            ->and(CompletionFiles::forSigner($bo->refresh())[0]['path'])->toBe('versions/bo.pdf')
            ->and(CompletionFiles::forEnvelope($envelope->refresh(), SigningDownloadLink::COPY)[0]['path'])->toBe('versions/bo.pdf')
            ->and(CompletionFiles::forEnvelope($envelope, SigningDownloadLink::COMPANY)[0]['path'])->toBe('versions/bo.pdf');
    });
});

it('names a downloaded file with an ASCII fallback and the real name for å, ä and ö', function () {
    s13bSend($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']], [], 'Försäkringsbrev åäö.pdf');
    s13bSign('anna@example.test');

    $disposition = (string) $this->get(route('signing.copy.file', ['token' => s13bToken('anna@example.test'), 'file' => 1]))->assertOk()->headers->get('Content-Disposition');
    expect($disposition)->toContain('attachment')->toContain('Forsakringsbrev aao.pdf')->toContain("filename*=utf-8''".rawurlencode('Försäkringsbrev åäö.pdf'));
});

it('queues the mails that carry a link or a token encrypted', function () {
    foreach ([SignedDocumentMail::class, SigningEnvelopeMail::class, SigningRequestMail::class, SigningReminderMail::class, SigningDeadlineMail::class] as $class) {
        expect(is_subclass_of($class, ShouldBeEncrypted::class))->toBeTrue($class);
    }
});
