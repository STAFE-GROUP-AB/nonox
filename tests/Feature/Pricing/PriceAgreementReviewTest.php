<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Pricing\ActivatePriceAgreement;
use App\Actions\Pricing\DeletePriceAgreement;
use App\Actions\Pricing\NewPriceAgreementVersion;
use App\Actions\Pricing\ReopenPriceAgreement;
use App\Actions\Pricing\SavePriceAgreement;
use App\Actions\Pricing\SavePriceAgreementItem;
use App\Actions\Pricing\SendPriceAgreementForSigning;
use App\Actions\Privacy\EraseRegisterEntry;
use App\Actions\Signing\SignInSession;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Domain\Pricing\PriceContext;
use App\Domain\Pricing\PriceRequest;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Models\Customer;
use App\Models\PriceAgreement;
use App\Models\SigningRequest;
use App\Support\Privacy\DataSubjectExport;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\SigningConsentFixture;

/*
 * ADR-082 P5, review fixes: a signature is checked like a manual activation
 * (end date, a version's start, overlap, also with agreements out for
 * signing); a version never rewrites days that have passed; a draft once
 * sent is kept; the manual-activation reason is in the customer's register
 * extract and replaced on erasure.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->article = app(SaveArticle::class)($this->owner, $this->company, ['number' => 'A-1', 'name' => 'Skruv', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]);
    $this->customer = Crm::customer($this->company, $this->owner, 'Byggbolaget AB', ['email' => 'inkop@example.com', 'org_number' => '556036-0793']);
});

function rvAgreement(array $extra = [], string $price = '90'): PriceAgreement
{
    $test = test();
    $agreement = app(SavePriceAgreement::class)($test->owner, $test->company, ['customer_id' => $test->customer->id, 'title' => 'R', 'currency' => 'SEK', 'valid_from' => '2026-06-01', ...$extra]);
    app(SavePriceAgreementItem::class)($test->owner, $test->company, $agreement, ['target' => 'article', 'article_id' => $test->article->id, 'mode' => 'price', 'price' => $price]);

    return $agreement->fresh();
}

function rvPrice(string $date): ?int
{
    $test = test();

    return app(PriceResolver::class)->resolveMany(new PriceContext((int) $test->company->id, $date, 'SEK', $test->customer->id), [new PriceRequest('r', $test->article->id)])['r']->unitPriceMinor;
}

function rvSign(array $sent): TestResponse
{
    $token = basename((string) parse_url($sent['url'], PHP_URL_PATH));
    SigningConsentFixture::give($sent['request']);
    $response = test()->post("/signera/{$token}", ['name' => 'Eva E', 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => 'Eva E', 'document_sha256' => $sent['request']->document_sha256, ...SigningPdfs::acceptanceFields($sent['request'])]);
    CurrentCompany::set(test()->company);

    return $response;
}

it('refuses to activate by hand an agreement overlapping one that is out for signing', function () {
    $a = rvAgreement([], '90');
    app(SendPriceAgreementForSigning::class)($this->owner, $this->company, $a, ['email' => 'inkop@example.com']);
    $b = rvAgreement([], '80');

    expect(fn () => app(ActivatePriceAgreement::class)($this->owner, $this->company, $b, 'Undertecknat på papper.'))->toThrow(ValidationException::class, 'Två avtal för samma kund kan inte gälla samma dag')
        ->and($b->fresh()->status)->toBe(PriceAgreementStatus::Draft);
});

it('refuses to send a second agreement for the same days while the first is out for signing', function () {
    $a = rvAgreement([], '90');
    $b = rvAgreement([], '80');
    app(SendPriceAgreementForSigning::class)($this->owner, $this->company, $a, ['email' => 'inkop@example.com']);

    expect(fn () => app(SendPriceAgreementForSigning::class)($this->owner, $this->company, $b, ['email' => 'inkop@example.com']))->toThrow(ValidationException::class, 'Två avtal för samma kund kan inte gälla samma dag')
        ->and($b->fresh()->status)->toBe(PriceAgreementStatus::Draft);
});

it('refuses a signature when another agreement for the same days became active meanwhile, and rolls the signature back', function () {
    $a = rvAgreement([], '90');
    $sent = app(SendPriceAgreementForSigning::class)($this->owner, $this->company, $a, ['email' => 'inkop@example.com']);
    // Made active outside the checked paths (for example an import): the signature must still not make two agreements active.
    $b = rvAgreement(['valid_from' => '2026-07-01'], '80');
    DB::table('price_agreements')->where('id', $b->id)->update(['status' => 'ACTIVE']);

    rvSign($sent)->assertRedirect()->assertSessionHasErrors(['document' => 'Prisavtalet kan inte längre signeras, eftersom ett annat avtal för samma period har börjat gälla. Kontakta avsändaren.']);

    expect($a->fresh()->status)->toBe(PriceAgreementStatus::AwaitingSignature)
        ->and($sent['request']->refresh()->status)->toBe(SigningStatus::Pending)
        ->and(rvPrice('2026-07-15'))->toBe(8000);
});

it('ends a signing link no later than the agreement and refuses a signature after its last day', function () {
    $a = rvAgreement(['valid_to' => '2026-06-30']);
    $sent = app(SendPriceAgreementForSigning::class)($this->owner, $this->company, $a, ['email' => 'inkop@example.com']);

    expect($sent['request']->expires_at->setTimezone('Europe/Stockholm')->toDateString())->toBe('2026-06-30');

    $locked = $a->fresh();
    $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00:00'));

    expect(fn () => DB::transaction(fn () => $locked->markSigned(CarbonImmutable::now(), str_repeat('a', 64))))->toThrow(ValidationException::class, 'sista giltighetsdag har passerat')
        ->and($a->fresh()->status)->toBe(PriceAgreementStatus::AwaitingSignature);
});

it('never lets a version rewrite days that have passed, on activation or on signature', function () {
    $old = rvAgreement(['valid_from' => '2026-01-01'], '90');
    app(ActivatePriceAgreement::class)($this->owner, $this->company, $old, 'Undertecknat på papper.');
    $new = app(NewPriceAgreementVersion::class)($this->owner, $this->company, $old->fresh());
    app(SavePriceAgreement::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'title' => 'R', 'currency' => 'SEK', 'valid_from' => '2026-02-01'], $new);
    app(SavePriceAgreementItem::class)($this->owner, $this->company, $new->fresh(), ['target' => 'article', 'article_id' => $this->article->id, 'mode' => 'price', 'price' => '70'], $new->items()->firstOrFail());

    expect(fn () => app(ActivatePriceAgreement::class)($this->owner, $this->company, $new->fresh(), 'Ny nivå på papper.'))->toThrow(ValidationException::class, 'bakåt i tiden')
        ->and(fn () => app(SendPriceAgreementForSigning::class)($this->owner, $this->company, $new->fresh(), ['email' => 'inkop@example.com']))->toThrow(ValidationException::class, 'bakåt i tiden')
        ->and(rvPrice('2026-03-01'))->toBe(9000)
        ->and($old->fresh()->valid_to)->toBeNull();

    // Sent with a start tomorrow, signed the day after: refused, the old agreement keeps every passed day.
    app(SavePriceAgreement::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'title' => 'R', 'currency' => 'SEK', 'valid_from' => '2026-06-16'], $new->fresh());
    $sent = app(SendPriceAgreementForSigning::class)($this->owner, $this->company, $new->fresh(), ['email' => 'inkop@example.com']);
    $this->travelTo(CarbonImmutable::parse('2026-06-17 09:00:00'));

    rvSign($sent)->assertSessionHasErrors('document');
    expect($new->fresh()->status)->toBe(PriceAgreementStatus::AwaitingSignature)
        ->and($old->fresh()->status)->toBe(PriceAgreementStatus::Active)
        ->and(rvPrice('2026-06-16'))->toBe(9000);
});

it('keeps a draft that was once sent for signing, with its signing history', function () {
    $a = rvAgreement();
    app(SendPriceAgreementForSigning::class)($this->owner, $this->company, $a, ['email' => 'inkop@example.com']);
    app(ReopenPriceAgreement::class)($this->owner, $this->company, $a);

    expect(fn () => app(DeletePriceAgreement::class)($this->owner, $this->company, $a->fresh()))->toThrow(ValidationException::class, 'har skickats för signering')
        ->and(PriceAgreement::query()->whereKey($a->id)->exists())->toBeTrue();
});

it('lets the company itself be removed with an active agreement (the delete guard only stops a direct delete)', function () {
    $a = rvAgreement();
    app(ActivatePriceAgreement::class)($this->owner, $this->company, $a, 'Undertecknat på papper.');

    $trigger = DB::selectOne("SELECT pg_get_functiondef('price_agreements_protect_delete'::regproc) AS def")->def;

    expect($trigger)->toContain('pg_trigger_depth() > 1 AND NOT EXISTS (SELECT 1 FROM teams WHERE id = OLD.company_id)');
});

it('puts the manual-activation reason in the customer\'s register extract and replaces it when the customer is erased', function () {
    $a = rvAgreement();
    app(ActivatePriceAgreement::class)($this->owner, $this->company, $a, 'Eva Exempelsson skrev under på papper.');

    $extract = DataSubjectExport::customer(Customer::query()->findOrFail($this->customer->id));
    expect($extract['prisavtal'][0])->toMatchArray(['nummer' => 'PA-1', 'status' => 'ACTIVE', 'aktiverat_genom' => 'MANUAL', 'skal_vid_aktivering' => 'Eva Exempelsson skrev under på papper.']);

    expect(app(EraseRegisterEntry::class)->customer($this->owner, $this->company, Customer::query()->findOrFail($this->customer->id)))->toBe('anonymised');

    $a->refresh();
    expect($a->activation_reason)->toBe(PriceAgreement::ERASED_REASON)
        ->and($a->status)->toBe(PriceAgreementStatus::Active)
        ->and(Customer::query()->findOrFail($this->customer->id)->email)->toBeNull();
});

it('writes no file when a signature at the link is refused by the agreement\'s own check', function () {
    $a = rvAgreement([], '90');
    $sent = app(SendPriceAgreementForSigning::class)($this->owner, $this->company, $a, ['email' => 'inkop@example.com']);
    $b = rvAgreement(['valid_from' => '2026-07-01'], '80');
    DB::table('price_agreements')->where('id', $b->id)->update(['status' => 'ACTIVE']);
    $before = Storage::disk('local')->allFiles();

    rvSign($sent)->assertSessionHasErrors('document');

    expect(Storage::disk('local')->allFiles())->toBe($before)
        ->and(collect(Storage::disk('local')->allFiles())->filter(fn (string $path): bool => str_contains($path, 'signeringsbevis'))->all())->toBe([])
        ->and($sent['request']->refresh()->signed_document_path)->toBeNull();
});

it('writes no file when a signature in a session is refused by the agreement\'s own check', function () {
    $a = rvAgreement([], '90');
    app(SendPriceAgreementForSigning::class)($this->owner, $this->company, $a, ['email' => 'inkop@example.com']);
    $b = rvAgreement(['valid_from' => '2026-07-01'], '80');
    DB::table('price_agreements')->where('id', $b->id)->update(['status' => 'ACTIVE']);
    $before = Storage::disk('local')->allFiles();
    $requests = SigningRequest::query()->count();
    $locked = $a->fresh();

    expect(fn () => app(SignInSession::class)->accept($locked, ['email' => 'inkop@example.com', 'name' => 'Eva E', 'contact_id' => null, 'ip' => '203.0.113.7', 'user_agent' => 'Pest'], 'Jag godkänner.', $locked->signingFingerprint()))
        ->toThrow(ValidationException::class, 'ett annat avtal för samma period');

    expect(Storage::disk('local')->allFiles())->toBe($before)
        ->and(SigningRequest::query()->count())->toBe($requests)
        ->and($a->fresh()->status)->toBe(PriceAgreementStatus::AwaitingSignature);
});

it('deletes a never-sent draft with an erased customer, and anonymises one whose agreement was sent', function () {
    rvAgreement(['title' => 'Avtal med Eva Exempelsson']);

    expect(app(EraseRegisterEntry::class)->customer($this->owner, $this->company, Customer::query()->findOrFail($this->customer->id)))->toBe('deleted')
        ->and(PriceAgreement::query()->where('customer_id', $this->customer->id)->exists())->toBeFalse();

    $other = Crm::customer($this->company, $this->owner, 'Annan AB', ['email' => 'annan@example.com']);
    $sentDraft = app(SavePriceAgreement::class)($this->owner, $this->company, ['customer_id' => $other->id, 'title' => 'Avtal med Eva Exempelsson', 'currency' => 'SEK', 'valid_from' => '2026-06-01']);
    app(SavePriceAgreementItem::class)($this->owner, $this->company, $sentDraft, ['target' => 'article', 'article_id' => $this->article->id, 'mode' => 'price', 'price' => '90']);
    app(SendPriceAgreementForSigning::class)($this->owner, $this->company, $sentDraft, ['email' => 'annan@example.com']);
    app(ReopenPriceAgreement::class)($this->owner, $this->company, $sentDraft);

    expect(app(EraseRegisterEntry::class)->customer($this->owner, $this->company, Customer::query()->findOrFail($other->id)))->toBe('anonymised')
        ->and($sentDraft->fresh()->title)->toBe(PriceAgreement::ERASED_TITLE);
});
