<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\Chain\EventHash;
use App\Domain\Signing\Pdf\PdfScripts;
use App\Domain\Signing\SigningHost;
use App\Domain\Signing\SigningPdfs;
use App\Mail\SigningRequestMail;
use App\Models\SalesDocument;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * Branding and scripts of the signing certificate (ADR-046 amendment
 * 2026-11-18, package S20): the brand is presentation only, the certificate
 * is bilingual, the instance's signing host, and Greek, Cyrillic, Chinese,
 * Japanese and Korean names in the certificate. The CJK golden test needs
 * the font from scripts/fetch-signing-cjk-font.sh (CI fetches it) and skips,
 * with a message, where it has not been fetched.
 */

function s20Signed(object $test, string $name): SigningRequest
{
    $sent = app(SendForSigning::class)($test->owner, $test->company, $test->quote, ['email' => 'signer@kontorshuset.test']);
    $links = app(SignAtLink::class);
    $request = $links->find(basename((string) parse_url($sent['url'], PHP_URL_PATH)));
    expect($request)->toBeInstanceOf(SigningRequest::class);

    return $links->sign($request, ['name' => $name, 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => $name, 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)], '203.0.113.7', 'Pest');
}

function s20Text(string $pdf): string
{
    return (new Parser)->parseContent($pdf)->getText();
}

function s20Squash(string $text): string
{
    return (string) preg_replace('/\s+/u', ' ', $text);
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
    $this->quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-06-15',
        'until_date' => '2026-07-15',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]],
    ]);
});

it('renders the certificate in Swedish and English, one document for every signer', function () {
    $text = s20Squash(s20Text(SigningPdfs::certificate(s20Signed($this, 'Anna Andersson'))));

    expect($text)->toContain('Signeringsbevis / Signing certificate')
        ->and($text)->toContain('Dokumentet som signerades / ')
        ->and($text)->toContain('Namn / Name')
        ->and($text)->toContain('Kontrollsumma (SHA-256)')
        ->and($text)->toContain('Anna Andersson')
        ->and($text)->toContain('A simple electronic signature')
        ->and($text)->toContain('En enkel elektronisk underskrift');
});

it('keeps Greek and Cyrillic names readable in the certificate (golden)', function () {
    $request = s20Signed($this, 'Ελένη Παπαδοπούλου Борис Иванов');
    $text = s20Squash(s20Text(SigningPdfs::certificate($request)));

    expect($text)->toContain('Ελένη Παπαδοπούλου Борис Иванов')
        ->and($text)->not->toContain(PdfScripts::PLACEHOLDER)
        ->and($text)->not->toContain('?');
});

it('draws Chinese, Japanese and Korean names with Noto Sans CJK when the font is there (golden)', function () {
    if (PdfScripts::cjkFont() === null) {
        $this->markTestSkipped('The CJK font is not fetched here (scripts/fetch-signing-cjk-font.sh).');
    }

    $request = s20Signed($this, 'Ελένη 王小明 山田太郎 김민수 Борис');
    $pdf = SigningPdfs::certificate($request);
    $text = s20Squash(s20Text($pdf));

    // The Greek and Cyrillic stay in DejaVu Sans, the CJK runs get Noto Sans CJK.
    expect($text)->toContain('Ελένη 王小明 山田太郎 김민수 Борис')
        ->and($text)->not->toContain(PdfScripts::PLACEHOLDER)
        ->and($pdf)->toContain('NotoSansCJK')
        ->and($pdf)->toContain('DejaVuSans');
});

it('degrades with a clear notice instead of broken glyphs when the CJK font is missing', function () {
    config(['nonox.signing.pdf_engine.fonts_dir' => sys_get_temp_dir().'/nonox-no-fonts-'.bin2hex(random_bytes(4))]);

    $request = s20Signed($this, '王小明 Anna');
    $pdf = SigningPdfs::certificate($request);
    $text = s20Squash(s20Text($pdf));

    expect(PdfScripts::cjkFont())->toBeNull()
        ->and($text)->toContain('[…] Anna')
        ->and($text)->not->toContain('王')
        ->and($text)->toContain('Namnet eller texten innehåller kinesiska')
        ->and($text)->toContain('The name or text contains Chinese, Japanese or Korean characters')
        ->and($pdf)->not->toContain('NotoSansCJK');

    // The stored name is untouched: only the picture of it degrades.
    expect($request->fresh()->signer_name)->toBe('王小明 Anna');
});

it('splits only the text of the HTML into script runs, never tags, attributes, style or title', function () {
    $html = '<html><head><title>王</title><style>.a { content: "王"; }</style></head><body><img alt="王" src="x"><p>Hej 王小明, hej</p></body></html>';

    $with = PdfScripts::prepare($html, true);
    $without = PdfScripts::prepare($html, false);
    $latin = PdfScripts::prepare('<p>Ελένη Борис Anna</p>', true);

    expect($with['html'])->toContain('<img alt="王" src="x">')
        ->and($with['html'])->toContain('<title>王</title>')
        ->and($with['html'])->toContain('Hej <span class="cjk">王小明</span>, hej')
        ->and($with['cjk'])->toBeTrue()
        ->and($without['html'])->toContain('Hej […], hej')
        ->and($without['html'])->toContain('<img alt="王" src="x">')
        ->and($without['degraded'])->toBeTrue()
        ->and($latin)->toBe(['html' => '<p>Ελένη Борис Anna</p>', 'cjk' => false, 'degraded' => false]);
});

it('recognises Han, kana, Hangul and CJK punctuation, and not Greek, Cyrillic or Latin', function () {
    expect(PdfScripts::hasCjk('王'))->toBeTrue()
        ->and(PdfScripts::hasCjk('ひらがな'))->toBeTrue()
        ->and(PdfScripts::hasCjk('カタカナ'))->toBeTrue()
        ->and(PdfScripts::hasCjk('한국어'))->toBeTrue()
        ->and(PdfScripts::hasCjk('、'))->toBeTrue()
        ->and(PdfScripts::hasCjk('Ελληνικά Русский Åäö'))->toBeFalse();
});

it('leaves the evidence digest and the certificate text unchanged by a brand', function () {
    $request = s20Signed($this, 'Anna Andersson');
    $envelope = SigningEnvelope::query()->sole();
    $plainDigest = EventHash::evidenceOf($request->fresh());
    $plainText = s20Squash(s20Text(SigningPdfs::certificate($request->fresh())));
    $plainChain = $envelope->fresh()->chain_head;
    $plainDocumentSha = $request->fresh()->document_sha256;
    $plainPdfSha = hash('sha256', (string) Storage::disk('local')->get($request->fresh()->document_path));

    $this->company->forceFill(['brand_color' => '#C2410C', 'brand_accent_color' => '#1D4ED8'])->save();
    $image = imagecreatetruecolor(120, 40);
    imagefilledrectangle($image, 0, 0, 119, 39, (int) imagecolorallocate($image, 194, 65, 12));
    ob_start();
    imagepng($image);
    Storage::disk('local')->put('logos/s20.png', (string) ob_get_clean());
    $this->company->forceFill(['logo_path' => 'logos/s20.png'])->save();

    $brandedPdf = SigningPdfs::certificate($request->fresh());

    expect(EventHash::evidenceOf($request->fresh()))->toBe($plainDigest)
        ->and(s20Squash(s20Text($brandedPdf)))->toBe($plainText)
        ->and($envelope->fresh()->chain_head)->toBe($plainChain)
        ->and($request->fresh()->document_sha256)->toBe($plainDocumentSha)
        ->and(hash('sha256', (string) Storage::disk('local')->get($request->fresh()->document_path)))->toBe($plainPdfSha)
        ->and($plainPdfSha)->toBe($plainDocumentSha)
        ->and($brandedPdf)->toContain('%PDF');
});

it('puts the company brand on the page and in the mails', function () {
    $this->company->forceFill(['brand_color' => '#C2410C'])->save();
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'signer@kontorshuset.test']);
    $url = $sent['url'];

    $this->get($url)->assertOk()->assertSee('background: #C2410C', false);

    Mail::assertQueued(SigningRequestMail::class, function (SigningRequestMail $mail): bool {
        expect($mail->render())->toContain('border-top: 6px solid #C2410C');

        return true;
    });
});

it('uses the instance signing host for links, and a request to it reaches the signing pages only', function () {
    config(['app.url' => 'https://nonox.test', 'nonox.signing.host' => 'Sign.Example.Org']);

    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'signer@kontorshuset.test']);
    $token = basename((string) parse_url($sent['url'], PHP_URL_PATH));

    expect($sent['url'])->toBe("https://sign.example.org/signera/{$token}")
        ->and(SigningHost::allows('signera/abc'))->toBeTrue()
        ->and(SigningHost::allows('kunder'))->toBeFalse();

    // On the signing host the page is served and its form posts back to the same host.
    $this->get("https://sign.example.org/signera/{$token}")->assertOk()->assertSee("https://sign.example.org/signera/{$token}", false);
    // Anything else on that host is not found, signed in or not.
    $this->get('https://sign.example.org/logga-in')->assertNotFound();
    $this->get('https://sign.example.org/kunder')->assertNotFound();
    // The application's own address still serves the signing page (old links keep working).
    $this->get("https://nonox.test/signera/{$token}")->assertOk();
});

it('ignores a signing host that is not a plain host name', function () {
    foreach (['', 'https://sign.example.org', 'sign.example.org/path', 'exa mple.org', 'localhost', 'a@b.org', 'sign.example.org:8080'] as $value) {
        config(['nonox.signing.host' => $value]);
        expect(SigningHost::host())->toBeNull();
    }

    config(['nonox.signing.host' => 'sign.example.org', 'app.url' => 'http://localhost']);
    expect(SigningHost::root())->toBe('http://sign.example.org');
});
