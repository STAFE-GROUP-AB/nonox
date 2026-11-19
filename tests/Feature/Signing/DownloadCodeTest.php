<?php

declare(strict_types=1);

use App\Actions\Signing\ManageDownloadLinks;
use App\Actions\Signing\PruneSigningLinks;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\CompletionLinks;
use App\Domain\Signing\DownloadCodes;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\SigningPdfs;
use App\Mail\SignedDocumentMail;
use App\Mail\SigningDownloadCodeMail;
use App\Mail\SigningEnvelopeMail;
use App\Mail\SigningRequestMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SigningDownloadCode;
use App\Models\SigningDownloadEvent;
use App\Models\SigningDownloadLink;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\SigningModule;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\PdfEngineFixtures;
use Tests\Support\SigningConsentFixture;

/*
 * The e-mail code before the files of a confidential envelope are downloaded
 * through a completion link (ADR-046 S13 amendment, owner decision
 * 2026-11-19). The helpers of CompletionLinksTest (s13b*) send and sign.
 */

function s13cPng(): string
{
    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

/** @param  list<array<string, mixed>>  $signers */
function s13cSend(User $user, Company $company, array $signers, array $extra = [], string $name = 'Avtal 2026.pdf'): SigningEnvelope
{
    $path = tempnam(sys_get_temp_dir(), 's13c');
    file_put_contents($path, PdfEngineFixtures::pdf(2));

    try {
        $document = app(StoreSigningDocument::class)($user, $company, $path, $name, 'Samarbetsavtal');
    } finally {
        @unlink($path);
    }

    return app(SendForSigning::class)->envelope($user, $company, $document, ['signers' => $signers, ...$extra], minSigners: 1);
}

function s13cSign(string $email, string $name = 'Anna Andersson'): SigningRequest
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

    SigningConsentFixture::give($request);

    return $links->sign($request, ['name' => $name, 'accept' => '1', 'method' => 'DRAWN', 'signature' => s13cPng(), 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)], '203.0.113.7', 'Pest');
}

/** The token mailed to an address in a completion mail. */
function s13cToken(string $email, ?string $kind = null): string
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

function s13cCode(int $nth = 0): string
{
    return Mail::sent(SigningDownloadCodeMail::class)->values()[$nth]->code;
}

function s13cWrong(string $right): string
{
    return $right === '000000' ? '111111' : '000000';
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

    s13cSend($this->owner, $this->company, [['email' => 'eva@example.test', 'step' => 1, 'verification' => 'NONE']], ['confidential' => true]);
    s13cSign('eva@example.test', 'Eva Ek');
    $this->token = s13cToken('eva@example.test');
    $this->link = SigningDownloadLink::query()->where('email', 'eva@example.test')->sole();
    auth()->logout();
});

it('holds the files of a confidential envelope behind a code page', function () {
    $this->get(route('signing.copy', ['token' => $this->token]))
        ->assertOk()
        ->assertSee('Skriv in en kod för att ladda ner filerna')
        ->assertSee('e***@example.test')
        ->assertDontSee('Samarbetsavtal')
        ->assertDontSee('Ladda ner</a>', false)
        ->assertSee('Skicka kod');

    $this->get(route('signing.copy.file', ['token' => $this->token, 'file' => 1]))->assertRedirect(route('signing.copy', ['token' => $this->token]));
    expect(SigningDownloadEvent::query()->count())->toBe(0)
        ->and($this->link->refresh()->downloads)->toBe(0);
});

it('asks for no code when the envelope is not confidential', function () {
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    s13cSend($this->owner, $this->company, [['email' => 'bo@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s13cSign('bo@example.test', 'Bo Berg');
    $open = s13cToken('bo@example.test');
    auth()->logout();

    $this->get(route('signing.copy', ['token' => $open]))->assertOk()->assertDontSee('Skriv in en kod')->assertSee('Ladda ner');
    $this->get(route('signing.copy.file', ['token' => $open, 'file' => 1]))->assertOk();
    $this->post(route('signing.copy.code', ['token' => $open]))->assertRedirect(route('signing.copy', ['token' => $open]));
    Mail::assertNotSent(SigningDownloadCodeMail::class);
});

it('sends one code to the link\'s own address, and reuses it inside the minute', function () {
    $this->post(route('signing.copy.code', ['token' => $this->token]))->assertRedirect()->assertSessionHas('signing-download-code');
    // A double click: the code already sent is reused, no second mail.
    $this->post(route('signing.copy.code', ['token' => $this->token]))->assertRedirect();
    Mail::assertSent(SigningDownloadCodeMail::class, 1);
    Mail::assertSent(SigningDownloadCodeMail::class, fn (SigningDownloadCodeMail $mail): bool => $mail->hasTo('eva@example.test') && preg_match('/^\d{6}$/', $mail->code) === 1);

    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:01:05'));
    $this->post(route('signing.copy.code', ['token' => $this->token]))->assertRedirect();
    Mail::assertSent(SigningDownloadCodeMail::class, 2);

    CurrentCompany::set($this->company);
    expect(SigningDownloadCode::query()->count())->toBe(2);
});

it('stores the code only as a keyed hash and never writes an address or a code to the audit log', function () {
    $this->post(route('signing.copy.code', ['token' => $this->token]));
    $code = s13cCode();

    CurrentCompany::set($this->company);
    $row = SigningDownloadCode::query()->sole();
    expect($row->code_mac)->toBe(DownloadCodes::mac($this->link->id, $code))->not->toContain($code)
        ->and(Schema::getColumnListing('signing_download_codes'))->not->toContain('code')
        ->and($row->sent_at->diffInMinutes($row->expires_at))->toEqual(10);

    $this->post(route('signing.copy.verify', ['token' => $this->token]), ['code' => s13cWrong($code)]);
    $this->post(route('signing.copy.verify', ['token' => $this->token]), ['code' => $code]);

    $entries = AuditLog::query()->where('action', 'like', 'signing.download_code_%')->orderBy('id')->get();
    expect($entries->pluck('action')->all())->toBe(['signing.download_code_sent', 'signing.download_code_failed', 'signing.download_code_verified']);

    foreach ($entries as $entry) {
        $json = json_encode($entry->payload).$entry->ip.$entry->user_agent;
        expect($json)->not->toContain('eva@example.test')->not->toContain($code);
    }

    expect(fn () => DB::transaction(fn () => DB::table('signing_download_codes')->where('id', $row->id)->update(['code_mac' => str_repeat('a', 64)])))->toThrow(QueryException::class, 'cannot be changed')
        ->and(fn () => DB::transaction(fn () => DB::table('signing_download_codes')->where('id', $row->id)->update(['verified_at' => null])))->toThrow(QueryException::class, 'stays used');
});

it('opens the downloads for the browser session for thirty minutes after a right code', function () {
    $this->post(route('signing.copy.code', ['token' => $this->token]));
    $code = s13cCode();

    $this->post(route('signing.copy.verify', ['token' => $this->token]), ['code' => s13cWrong($code)])
        ->assertRedirect()->assertSessionHasErrors('code');
    $this->get(route('signing.copy.file', ['token' => $this->token, 'file' => 1]))->assertRedirect(route('signing.copy', ['token' => $this->token]));

    $this->post(route('signing.copy.verify', ['token' => $this->token]), ['code' => substr($code, 0, 3).' '.substr($code, 3)])->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('signing.copy', ['token' => $this->token]))->assertOk()->assertSee('Ladda ner')->assertDontSee('Skriv in en kod');
    $this->get(route('signing.copy.file', ['token' => $this->token, 'file' => 1]))->assertOk();
    CurrentCompany::set($this->company);
    expect(SigningDownloadEvent::query()->count())->toBe(1);

    // A used code cannot be typed again, and the unlock ends after thirty minutes.
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:31:00'));
    $this->get(route('signing.copy.file', ['token' => $this->token, 'file' => 1]))->assertRedirect(route('signing.copy', ['token' => $this->token]));
    $this->get(route('signing.copy', ['token' => $this->token]))->assertSee('Skriv in en kod för att ladda ner filerna');
});

it('does not let one browser\'s code open the files for another', function () {
    $this->post(route('signing.copy.code', ['token' => $this->token]));
    $this->post(route('signing.copy.verify', ['token' => $this->token]), ['code' => s13cCode()]);
    $this->get(route('signing.copy.file', ['token' => $this->token, 'file' => 1]))->assertOk();

    $this->flushSession();
    $this->get(route('signing.copy.file', ['token' => $this->token, 'file' => 1]))->assertRedirect();
});

it('refuses an expired code and a code that is not six digits, and says so', function () {
    $this->post(route('signing.copy.code', ['token' => $this->token]));
    $code = s13cCode();

    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:11:00'));
    $this->post(route('signing.copy.verify', ['token' => $this->token]), ['code' => $code])->assertSessionHasErrors(['code' => 'Koden gäller inte längre. Be om en ny kod.']);

    $this->post(route('signing.copy.verify', ['token' => $this->token]), ['code' => 'abc'])->assertSessionHasErrors(['code' => 'Skriv koden med sex siffror.']);
});

it('locks the code step after ten wrong tries over all of the link\'s codes', function () {
    $second = false;

    foreach ([0, 1] as $round) {
        $this->post(route('signing.copy.code', ['token' => $this->token]));
        $code = s13cCode($round);

        foreach (range(1, 5) as $try) {
            $this->post(route('signing.copy.verify', ['token' => $this->token]), ['code' => s13cWrong($code)]);
        }

        $this->travelTo(CarbonImmutable::now()->addSeconds(61));
        $second = true;
    }

    // Even the right code of the second round now gets nowhere, and no new code is sent.
    $this->post(route('signing.copy.verify', ['token' => $this->token]), ['code' => s13cCode(1)])->assertSessionHasErrors(['code' => 'För många felaktiga försök. Be avsändaren skicka en ny länk.']);
    $this->post(route('signing.copy.code', ['token' => $this->token]))->assertSessionHasErrors('code');
    Mail::assertSent(SigningDownloadCodeMail::class, 2);
    $this->get(route('signing.copy', ['token' => $this->token]))->assertSee('För många felaktiga försök');
    expect($second)->toBeTrue();
});

it('sends at most three codes an hour', function () {
    foreach ([0, 1, 2, 3] as $round) {
        $this->post(route('signing.copy.code', ['token' => $this->token]));
        $this->travelTo(CarbonImmutable::now()->addSeconds(61));
    }

    Mail::assertSent(SigningDownloadCodeMail::class, 3);
});

it('writes the code mail in the language of the link, in the company\'s name', function () {
    CurrentCompany::set($this->company);
    $envelope = SigningEnvelope::query()->sole();
    $token = CompletionLinks::issue($envelope, SigningDownloadLink::COPY, 'ben@example.test', 'en');
    $this->post(route('signing.copy.code', ['token' => $token]));

    Mail::assertSent(SigningDownloadCodeMail::class, function (SigningDownloadCodeMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'lang="en"')
            && str_contains($html, 'Your code')
            && str_contains($html, $mail->code)
            && str_contains($html, e($this->company->legal_name ?: $this->company->name))
            && str_contains($mail->envelope()->subject, 'Your code to download');
    });
});

it('prunes the codes of a link that no longer works', function () {
    $this->post(route('signing.copy.code', ['token' => $this->token]));
    CurrentCompany::set($this->company);
    expect(SigningDownloadCode::query()->count())->toBe(1);

    app(PruneSigningLinks::class)->run(CarbonImmutable::now());
    expect(SigningDownloadCode::query()->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-06-23 10:00:00'));
    app(PruneSigningLinks::class)->run(CarbonImmutable::now());
    expect(SigningDownloadCode::query()->count())->toBe(0)
        ->and(SigningDownloadLink::query()->where('id', $this->link->id)->exists())->toBeTrue();
});

it('keeps another company\'s codes out of sight', function () {
    $this->post(route('signing.copy.code', ['token' => $this->token]));
    [, $other] = Bookkeeping::company();

    expect(CompanyScope::bypass(fn (): int => SigningDownloadCode::query()->count()))->toBe(1)
        ->and(CurrentCompany::run($other, fn (): int => SigningDownloadCode::query()->count()))->toBe(0);
});

function s13cUnlock(object $test, string $token): void
{
    $test->post(route('signing.copy.code', ['token' => $token]));
    $test->post(route('signing.copy.verify', ['token' => $token]), ['code' => Mail::sent(SigningDownloadCodeMail::class)->last()->code]);
}

it('does not let an unlock for one link open another link in the same session', function () {
    CurrentCompany::set($this->company);
    $other = CompletionLinks::issue(SigningEnvelope::query()->sole(), SigningDownloadLink::COPY, 'cy@example.test', 'sv');

    s13cUnlock($this, $this->token);
    $this->get(route('signing.copy.file', ['token' => $this->token, 'file' => 1]))->assertOk();
    $this->get(route('signing.copy.file', ['token' => $other, 'file' => 1]))->assertRedirect(route('signing.copy', ['token' => $other]));
});

it('answers 410 for an unlocked session once the link is closed, and for the old link after a resend', function () {
    CurrentCompany::set($this->company);
    s13cUnlock($this, $this->token);
    $this->get(route('signing.copy.file', ['token' => $this->token, 'file' => 1]))->assertOk();

    CompletionLinks::close(SigningEnvelope::query()->sole(), $this->link);
    $this->get(route('signing.copy.file', ['token' => $this->token, 'file' => 1]))->assertStatus(410);

    $this->actingAs($this->owner);
    app(ManageDownloadLinks::class)->resend($this->owner, $this->company, SigningEnvelope::query()->sole(), SigningDownloadLink::query()->whereKey($this->link->id)->firstOrFail());
    auth()->logout();
    $this->get(route('signing.copy.file', ['token' => $this->token, 'file' => 1]))->assertStatus(410);
    $new = collect([SignedDocumentMail::class, SigningEnvelopeMail::class])->flatMap(fn (string $class) => Mail::queued($class))->filter(fn ($mail): bool => $mail->hasTo('eva@example.test') && $mail->downloadToken !== null)->last()->downloadToken;
    expect($new)->not->toBe($this->token);
    $this->get(route('signing.copy.file', ['token' => $new, 'file' => 1]))->assertRedirect(route('signing.copy', ['token' => $new]));
});

it('redirects a HEAD request for a file while locked, with no download record', function () {
    $this->call('HEAD', route('signing.copy.file', ['token' => $this->token, 'file' => 1]))->assertRedirect(route('signing.copy', ['token' => $this->token]));
    CurrentCompany::set($this->company);
    expect(SigningDownloadEvent::query()->count())->toBe(0);
});

it('removes the code and writes only a not-sent entry when the mail cannot be sent', function () {
    $pending = Mockery::mock();
    $pending->shouldReceive('send')->andThrow(new RuntimeException('smtp down'));
    Mail::swap(Mockery::mock(Mail::getFacadeRoot())->shouldReceive('to')->andReturn($pending)->getMock());

    $this->post(route('signing.copy.code', ['token' => $this->token]))->assertRedirect()->assertSessionHasErrors('code');

    CurrentCompany::set($this->company);
    expect(SigningDownloadCode::query()->count())->toBe(0);
    $entries = AuditLog::query()->where('action', 'like', 'signing.download_code_%')->get();
    expect($entries->pluck('action')->all())->toBe(['signing.download_code_not_sent'])
        ->and(json_encode($entries->first()->payload))->not->toContain('eva@example.test')
        ->and($entries->first()->payload)->toEqualCanonicalizing(['download_link_id' => $this->link->id, 'envelope_id' => $this->link->envelope_id]);
});
