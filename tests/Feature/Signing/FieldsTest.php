<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\Chain\ChainVerifier;
use App\Domain\Signing\Fields\FieldStamper;
use App\Domain\Signing\Fields\PageImages;
use App\Domain\Signing\Fields\SignerFields;
use App\Domain\Signing\Fields\TextFit;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\Pdf\PdfEngineException;
use App\Domain\Signing\Pdf\PdfRefusal;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\SigningUnavailable;
use App\Jobs\RenderSigningPages;
use App\Mail\SignedDocumentMail;
use App\Mail\SigningEnvelopeMail;
use App\Mail\SigningRequestMail;
use App\Models\SalesDocument;
use App\Models\SigningDocumentVersion;
use App\Models\SigningEnvelope;
use App\Models\SigningEvent;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Models\SigningRequest;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * Fields in the PDF per signer (ADR-046 amendment 2026-11-18, gap 2,
 * package S6): fields are placed when an envelope is sent and frozen after;
 * the signer page shows the pages as images with the signer's fields;
 * every value is checked on the server per type; initials; a stamped
 * revision per signer, so earlier signers' values are visible and locked;
 * values in the hash chain; and one signer can never write another's
 * fields. The PDF engine is the test suite's fake.
 */

function s6Png(int $width = 300, int $height = 100): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imageline($image, 5, (int) ($height / 2), $width - 5, (int) ($height / 2) + 5, imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

/** A field definition in 1/100 PDF points, on an A4 page (59 528 × 84 189). */
function s6Field(int $signer, string $type, string $label, array $extra = []): array
{
    static $offset = 0;
    $offset = ($offset + 2500) % 70000;

    return ['signer' => $signer, 'type' => $type, 'page' => 1, 'x' => 5000, 'y' => 5000 + $offset, 'width' => 20000, 'height' => 2000, 'label' => $label, ...$extra];
}

/** @return array<string, mixed> */
function s6SignInput(SigningRequest $request, array $extra = [], string $name = 'Anna Andersson'): array
{
    $version = SigningDocumentVersion::query()->where('envelope_id', $request->envelope_id)->whereIn('kind', ['ORIGINAL', 'AFTER_SIGNER'])->orderByDesc('id')->first();

    return ['name' => $name, 'accept' => '1', 'method' => 'DRAWN', 'signature' => s6Png(), 'document_sha256' => $request->document_sha256, 'revision_sha256' => $version?->sha256, ...SigningPdfs::acceptanceFields($request), ...$extra];
}

function s6LinkFor(string $email): ?string
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

/** @return array{request: SigningRequest, token: string} */
function s6SendOne(object $test, array $fields): array
{
    $sent = app(SendForSigning::class)($test->owner, $test->company, $test->quote, ['email' => 'anna@kontorshuset.test', 'fields' => $fields]);

    return ['request' => $sent['request'], 'token' => basename((string) parse_url($sent['url'], PHP_URL_PATH))];
}

function s6SendTwo(object $test, array $fields, int $secondStep = 2): SigningEnvelope
{
    return app(SendForSigning::class)->envelope($test->owner, $test->company, $test->quote, [
        'signers' => [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => $secondStep]],
        'fields' => $fields,
    ]);
}

function s6Sign(string $token, array $extra = [], string $name = 'Anna Andersson'): SigningRequest
{
    $links = app(SignAtLink::class);
    $request = $links->find($token);
    expect($request)->toBeInstanceOf(SigningRequest::class);

    return $links->sign($request, s6SignInput($request, $extra, $name), '203.0.113.7', 'Pest');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    $this->engine = FakePdfEngine::install();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB', ['email' => 'inkop@kontorshuset.test']);
    $this->quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-06-15',
        'until_date' => '2026-07-15',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]],
    ]);
});

it('places fields when an envelope is sent, from the page sizes the engine reads, and freezes them', function () {
    ['request' => $request] = s6SendOne($this, [s6Field(0, 'TEXT', 'Er referens', ['required' => true]), s6Field(0, 'SIGNATURE', 'Underskrift')]);

    $fields = SigningField::query()->where('envelope_id', $request->envelope_id)->orderBy('position')->get();

    expect($fields)->toHaveCount(2)
        ->and($fields[0]->signing_request_id)->toBe($request->id)
        ->and($fields[0]->page_width)->toBe(59528)
        ->and($fields[0]->page_height)->toBe(84189)
        ->and($this->engine->operations())->toContain('inspect');

    // Frozen: the envelope is out and its chain has begun.
    expect(fn () => DB::transaction(fn () => DB::table('signing_fields')->where('id', $fields[0]->id)->update(['label' => 'Ändrad'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_fields')->where('id', $fields[0]->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => SigningField::create([...$fields[0]->only(['envelope_id', 'signing_request_id', 'type', 'page', 'x', 'y', 'width', 'height', 'page_width', 'page_height', 'label']), 'position' => 3])))->toThrow(QueryException::class);
});

it('refuses fields outside the page, on a page that does not exist, and without the PDF engine', function () {
    expect(fn () => s6SendOne($this, [[...s6Field(0, 'TEXT', 'För bred'), 'x' => 50000, 'width' => 20000]]))->toThrow(ValidationException::class)
        ->and(fn () => s6SendOne($this, [[...s6Field(0, 'TEXT', 'Sida 9'), 'page' => 9]]))->toThrow(ValidationException::class)
        ->and(fn () => s6SendOne($this, [s6Field(0, 'DROPDOWN', 'Utan val')]))->toThrow(ValidationException::class)
        ->and(fn () => s6SendOne($this, [s6Field(1, 'TEXT', 'Fel undertecknare')]))->toThrow(ValidationException::class);

    $this->engine->installed = false;

    try {
        s6SendOne($this, [s6Field(0, 'TEXT', 'Er referens')]);
        $this->fail('Fields without the engine must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['fields'][0])->toContain('PDF-motorn');
    }

    expect(SigningField::query()->count())->toBe(0)->and(SigningRequest::query()->count())->toBe(0);
});

it('shows the pages as images with the signer\'s fields, behind the token, without widening the CSP', function () {
    ['token' => $token, 'request' => $request] = s6SendOne($this, [s6Field(0, 'TEXT', 'Er referens', ['required' => true]), s6Field(0, 'DATE', 'Datum')]);

    $page = $this->get("/signera/{$token}")->assertOk()
        ->assertSee(route('signing.page', ['token' => $token, 'page' => 1]), false)
        ->assertSee('id="falt-', false)
        ->assertSee('form="signera-formular"', false)
        ->assertSee('Nästa obligatoriska fält')
        ->assertSee('fylls i med dagens datum när du signerar.')
        ->assertSee('name="revision_sha256"', false)
        ->assertDontSee('<iframe', false);

    expect($page->headers->get('Content-Security-Policy'))->toContain("script-src 'self'")->not->toContain('unsafe-eval')->not->toContain('worker-src');

    $image = $this->get(route('signing.page', ['token' => $token, 'page' => 1]))->assertOk();
    expect($image->headers->get('Content-Type'))->toBe('image/png')
        ->and($image->headers->get('Cache-Control'))->toContain('no-store')
        ->and($image->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(str_starts_with((string) $image->getContent(), "\x89PNG"))->toBeTrue();

    // Rendered once per version, then served from the disk.
    $this->get(route('signing.page', ['token' => $token, 'page' => 1]))->assertOk();
    expect(array_count_values($this->engine->operations())['render_pages'])->toBe(1);

    $this->get(route('signing.page', ['token' => $token, 'page' => 99]))->assertNotFound();
    $this->get(route('signing.page', ['token' => str_repeat('A', 43), 'page' => 1]))->assertNotFound();
    expect(Storage::disk('local')->exists(PageImages::directory($this->company->id, $request->document_sha256).'/manifest.json'))->toBeTrue();
});

it('keeps the page as it was for an envelope without fields, and serves no page images for it', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test']);
    $token = basename((string) parse_url($sent['url'], PHP_URL_PATH));

    $this->get("/signera/{$token}")->assertOk()->assertSee('<iframe', false)->assertDontSee('revision_sha256');
    $this->get(route('signing.page', ['token' => $token, 'page' => 1]))->assertNotFound();
    expect($this->engine->operations())->not->toContain('render_pages');
});

it('asks for the check before showing page images of a checked signer', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test', 'verification' => 'EMAIL_CODE', 'fields' => [s6Field(0, 'TEXT', 'Er referens')]]);
    $token = basename((string) parse_url($sent['url'], PHP_URL_PATH));

    $this->get(route('signing.page', ['token' => $token, 'page' => 1]))->assertNotFound();
});

it('writes the values, stamps a new revision and chains the value hashes, never the values', function () {
    ['token' => $token, 'request' => $request] = s6SendOne($this, [
        s6Field(0, 'TEXT', 'Er referens', ['required' => true, 'rules' => ['max_length' => 40]]),
        s6Field(0, 'NUMBER', 'Antal licenser', ['rules' => ['min' => '1', 'max' => '500', 'decimals' => 1]]),
        s6Field(0, 'CHECKBOX', 'Jag har läst villkoren', ['required' => true]),
        s6Field(0, 'DROPDOWN', 'Betalning', ['options' => ['Faktura 30 dagar', 'Förskott']]),
        s6Field(0, 'RADIO', 'Ja', ['group_key' => 'option', 'options' => ['Ja'], 'required' => true]),
        s6Field(0, 'RADIO', 'Nej', ['group_key' => 'option', 'options' => ['Nej']]),
        s6Field(0, 'DATE', 'Datum'),
        s6Field(0, 'NAME', 'Namnförtydligande'),
        s6Field(0, 'SIGNATURE', 'Underskrift'),
    ]);
    $fields = SigningField::query()->where('envelope_id', $request->envelope_id)->orderBy('position')->get();
    $original = $request->document_sha256;

    $signed = s6Sign($token, [
        'fields' => [$fields[0]->id => ' PO-4471 ', $fields[1]->id => '12,5', $fields[2]->id => '1', $fields[3]->id => 'Förskott'],
        'radio' => ['option' => (string) $fields[5]->id],
    ]);

    expect($signed->status)->toBe(SigningStatus::Signed);

    $values = SigningFieldValue::query()->orderBy('field_id')->get()->keyBy('field_id');
    expect($values)->toHaveCount(8)
        ->and($values[$fields[0]->id]->value)->toBe('PO-4471')
        ->and($values[$fields[1]->id]->value)->toBe('12.5')
        ->and($values[$fields[2]->id]->value)->toBe('1')
        ->and($values[$fields[3]->id]->value)->toBe('Förskott')
        ->and($values->has($fields[4]->id))->toBeFalse()
        ->and($values[$fields[5]->id]->value)->toBe('Nej')
        ->and($values[$fields[6]->id]->value)->toBe('2026-06-15')
        ->and($values[$fields[7]->id]->value)->toBe('Anna Andersson')
        ->and($values[$fields[8]->id]->image)->not->toBeNull()
        ->and($values[$fields[8]->id]->value)->toBeNull();

    // The image is encrypted at rest.
    expect(DB::table('signing_field_values')->where('field_id', $fields[8]->id)->value('image'))->not->toStartWith('iVBOR');

    $version = SigningDocumentVersion::query()->where('kind', SigningDocumentVersion::AFTER_SIGNER)->sole();
    $bytes = (string) Storage::disk('local')->get($version->path);
    expect($version->signing_request_id)->toBe($request->id)
        ->and(hash('sha256', $bytes))->toBe($version->sha256)
        ->and(str_starts_with($bytes, (string) Storage::disk('local')->get($request->document_path)))->toBeTrue()
        ->and($request->refresh()->document_sha256)->toBe($original);

    $filled = SigningEvent::query()->where('event', SigningEventType::FieldsFilled->value)->sole();
    expect($filled->payload['version_sha256'])->toBe($version->sha256)
        ->and($filled->payload['shown_sha256'])->toBe($original)
        ->and($filled->payload['values'])->toHaveCount(8)
        ->and(json_encode($filled->payload))->not->toContain('PO-4471')->not->toContain('Förskott')
        ->and($filled->payload['values'][0]['hash'])->toBe(FieldStamper::hashOf($values[$filled->payload['values'][0]['field_id']]));

    // FIELDS_FILLED comes before SIGNED in the chain.
    $order = SigningEvent::query()->where('envelope_id', $request->envelope_id)->orderBy('sequence')->pluck('event')->map->value->all();
    expect(array_search('FIELDS_FILLED', $order, true))->toBeLessThan(array_search('SIGNED', $order, true));

    $result = app(ChainVerifier::class)->verify($this->company);
    expect($result['ok'])->toBeTrue();

    // A value changed behind the app's back is reported by signing:verify.
    DB::statement('ALTER TABLE signing_field_values DISABLE TRIGGER signing_field_values_protect');
    DB::table('signing_field_values')->where('field_id', $fields[0]->id)->update(['value' => 'PO-9999']);
    DB::statement('ALTER TABLE signing_field_values ENABLE TRIGGER signing_field_values_protect');

    $broken = app(ChainVerifier::class)->verify($this->company);
    expect($broken['ok'])->toBeFalse()
        ->and(collect($broken['problems'])->pluck('code')->all())->toContain('FIELDS');
});

it('refuses values that break their field\'s rules, on the server', function (array $definition, mixed $value, string $message) {
    ['token' => $token, 'request' => $request] = s6SendOne($this, [$definition]);
    $field = SigningField::query()->where('envelope_id', $request->envelope_id)->sole();

    try {
        s6Sign($token, ['fields' => [$field->id => $value]]);
        $this->fail('The value must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors()["fields.{$field->id}"][0])->toContain($message);
    }

    expect($request->refresh()->status)->toBe(SigningStatus::Pending)
        ->and(SigningFieldValue::query()->count())->toBe(0)
        ->and(SigningDocumentVersion::query()->where('kind', 'AFTER_SIGNER')->count())->toBe(0);
})->with([
    'required text' => [s6Field(0, 'TEXT', 'Er referens', ['required' => true]), '', 'Fyll i'],
    'too long text' => [s6Field(0, 'TEXT', 'Kort', ['rules' => ['max_length' => 5]]), 'sex tecken', 'högst 5 tecken'],
    'control character' => [s6Field(0, 'TEXT', 'Rad'), "två\nrader", 'tecken som inte går'],
    'not a number' => [s6Field(0, 'NUMBER', 'Antal'), '12a', 'ska vara ett tal'],
    'too many decimals' => [s6Field(0, 'NUMBER', 'Antal', ['rules' => ['decimals' => 1]]), '1,25', 'högst 1 decimaler'],
    'decimals in a whole number' => [s6Field(0, 'NUMBER', 'Antal'), '2,5', 'heltal'],
    'below the minimum' => [s6Field(0, 'NUMBER', 'Antal', ['rules' => ['min' => '10', 'max' => '20']]), '9', 'mellan 10 och 20'],
    'required checkbox' => [s6Field(0, 'CHECKBOX', 'Villkoren', ['required' => true]), null, 'Kryssa i'],
    'odd checkbox value' => [s6Field(0, 'CHECKBOX', 'Villkoren'), 'ja', 'kryssas i eller lämnas tom'],
    'option not offered' => [s6Field(0, 'DROPDOWN', 'Betalning', ['options' => ['Faktura']]), 'Kontant', 'Välj ett av alternativen'],
]);

it('refuses a radio choice that is not a button of the group, and requires a required group', function () {
    ['token' => $token, 'request' => $request] = s6SendOne($this, [
        s6Field(0, 'RADIO', 'Ja', ['group_key' => 'val', 'options' => ['Ja'], 'required' => true]),
        s6Field(0, 'RADIO', 'Nej', ['group_key' => 'val', 'options' => ['Nej']]),
    ]);
    $first = SigningField::query()->where('envelope_id', $request->envelope_id)->orderBy('position')->first();

    expect(fn () => s6Sign($token, ['radio' => ['val' => '999999']]))->toThrow(ValidationException::class)
        ->and(fn () => s6Sign($token))->toThrow(ValidationException::class);

    try {
        s6Sign($token);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey("fields.{$first->id}");
    }
});

it('takes drawn or typed initials, sized for a small box, and requires them when a field does', function () {
    ['token' => $token, 'request' => $request] = s6SendOne($this, [
        s6Field(0, 'INITIALS', 'Initialer sida 1', ['required' => true]),
        [...s6Field(0, 'INITIALS', 'Initialer sida 1 igen'), 'width' => 3000],
    ]);

    try {
        s6Sign($token);
        $this->fail('Required initials must be given.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('initials');
    }

    expect(fn () => s6Sign($token, ['initials_method' => 'TYPED', 'typed_initials' => 'ABCDEFGHIJK']))->toThrow(ValidationException::class)
        ->and(fn () => s6Sign($token, ['initials' => 'data:image/png;base64,bm90IGEgcG5n']))->toThrow(ValidationException::class);

    s6Sign($token, ['initials' => s6Png(120, 60)]);

    $values = SigningFieldValue::query()->get();
    expect($values)->toHaveCount(2)
        ->and($values->every(fn (SigningFieldValue $value): bool => $value->image !== null && $value->value === null))->toBeTrue()
        ->and($request->refresh()->status)->toBe(SigningStatus::Signed);
});

it('stamps typed initials as text', function () {
    ['token' => $token] = s6SendOne($this, [s6Field(0, 'INITIALS', 'Initialer', ['required' => true])]);

    s6Sign($token, ['initials_method' => 'TYPED', 'typed_initials' => 'A.A.']);

    expect(SigningFieldValue::query()->sole()->value)->toBe('A.A.');
});

it('never lets one signer write another signer\'s fields', function () {
    Mail::fake();
    $envelope = s6SendTwo($this, [
        s6Field(0, 'TEXT', 'Annas referens'),
        s6Field(1, 'TEXT', 'Bos referens', ['required' => true]),
    ], secondStep: 1);
    [$anna, $bo] = SigningRequest::query()->where('envelope_id', $envelope->id)->orderBy('id')->get()->all();
    [$annasField, $bosField] = SigningField::query()->where('envelope_id', $envelope->id)->orderBy('position')->get()->all();

    s6Sign((string) s6LinkFor('anna@kontorshuset.test'), ['fields' => [$annasField->id => 'A-1', $bosField->id => 'Skrivet av Anna', 'falskt' => 'x']]);

    expect(SigningFieldValue::query()->where('field_id', $bosField->id)->exists())->toBeFalse()
        ->and(SigningFieldValue::query()->where('field_id', $annasField->id)->value('value'))->toBe('A-1');

    $rejected = SigningEvent::query()->where('event', SigningEventType::FieldRejected->value)->sole();
    expect($rejected->signing_request_id)->toBe($anna->id)
        ->and($rejected->payload['field_ids'])->toBe([$bosField->id])
        ->and($rejected->payload['other'])->toBe(1)
        ->and(json_encode($rejected->payload))->not->toContain('Skrivet av Anna');

    // The database refuses it too: a value belongs to its field's own signer, and a signed signer writes nothing more.
    expect(fn () => DB::transaction(fn () => SigningFieldValue::create(['field_id' => $bosField->id, 'signing_request_id' => $anna->id, 'value' => 'x', 'filled_at' => now()])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => SigningFieldValue::create(['field_id' => $annasField->id, 'signing_request_id' => $anna->id, 'value' => 'y', 'filled_at' => now()])))->toThrow(QueryException::class);

    // Bo's own required field is still his to fill in.
    s6Sign((string) s6LinkFor('bo@kontorshuset.test'), ['fields' => [$bosField->id => 'B-2']], 'Bo Berg');
    expect(SigningFieldValue::query()->where('field_id', $bosField->id)->value('value'))->toBe('B-2')
        ->and($bo->refresh()->status)->toBe(SigningStatus::Signed)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_field_values')->where('field_id', $bosField->id)->update(['value' => 'C'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_field_values')->where('field_id', $bosField->id)->delete()))->toThrow(QueryException::class);
});

it('shows a later signer the revision with the earlier signer\'s values, visible and locked', function () {
    $envelope = s6SendTwo($this, [
        s6Field(0, 'TEXT', 'Annas referens'),
        s6Field(1, 'TEXT', 'Bos referens'),
        s6Field(1, 'CHECKBOX', 'Bo godkänner'),
    ]);
    [$annasField, $bosField] = SigningField::query()->where('envelope_id', $envelope->id)->orderBy('position')->get()->all();

    $annaToken = (string) s6LinkFor('anna@kontorshuset.test');
    $this->get("/signera/{$annaToken}")->assertOk()->assertSee('Undertecknare 2');
    s6Sign($annaToken, ['fields' => [$annasField->id => 'A-1']]);

    $afterAnna = SigningDocumentVersion::query()->where('kind', 'AFTER_SIGNER')->sole();
    $boToken = (string) s6LinkFor('bo@kontorshuset.test');

    $this->get("/signera/{$boToken}")->assertOk()
        ->assertSee('ifyllt av Undertecknare 1: A-1')
        ->assertSee('value="'.$afterAnna->sha256.'"', false)
        ->assertSee($afterAnna->sha256)
        ->assertDontSee('name="fields['.$annasField->id.']"', false);

    // The PDF Bo opens is the revision with Anna's values.
    $pdf = $this->get(route('signing.document', ['token' => $boToken]))->assertOk();
    expect(hash('sha256', (string) $pdf->getContent()))->toBe($afterAnna->sha256);

    s6Sign($boToken, ['fields' => [$bosField->id => 'B-2']], 'Bo Berg');

    $versions = SigningDocumentVersion::query()->where('kind', 'AFTER_SIGNER')->orderBy('id')->get();
    expect($versions)->toHaveCount(2)
        ->and(str_starts_with((string) Storage::disk('local')->get($versions[1]->path), (string) Storage::disk('local')->get($versions[0]->path)))->toBeTrue()
        ->and($envelope->refresh()->status->value)->toBe('COMPLETED')
        ->and(app(ChainVerifier::class)->verify($this->company)['problems'])->toBe([]);
});

it('asks a parallel signer to reload when another signed in between', function () {
    $envelope = s6SendTwo($this, [s6Field(0, 'TEXT', 'Annas referens'), s6Field(1, 'TEXT', 'Bos referens')], secondStep: 1);
    $links = app(SignAtLink::class);
    $bo = $links->find((string) s6LinkFor('bo@kontorshuset.test'));
    $stale = s6SignInput($bo, [], 'Bo Berg');

    s6Sign((string) s6LinkFor('anna@kontorshuset.test'), ['fields' => [SigningField::query()->orderBy('position')->value('id') => 'A-1']]);

    try {
        $links->sign($bo, $stale, '203.0.113.8', 'Pest');
        $this->fail('A stale revision must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('revision_sha256');
    }

    expect($bo->refresh()->status)->toBe(SigningStatus::Pending)
        ->and(SigningFieldValue::query()->where('signing_request_id', $bo->id)->count())->toBe(0);

    $links->sign($bo, s6SignInput($bo, [], 'Bo Berg'), '203.0.113.8', 'Pest');
    expect($envelope->refresh()->status->value)->toBe('COMPLETED');
});

it('saves nothing and leaves no file when the engine cannot stamp', function () {
    ['token' => $token, 'request' => $request] = s6SendOne($this, [s6Field(0, 'TEXT', 'Er referens')]);
    $files = Storage::disk('local')->allFiles();
    $this->engine->failNext = PdfEngineException::refused(PdfRefusal::Unreadable);

    try {
        s6Sign($token, ['fields' => [SigningField::query()->value('id') => 'X-1']]);
        $this->fail('A failed stamp must not sign.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('fields');
    }

    expect($request->refresh()->status)->toBe(SigningStatus::Pending)
        ->and(SigningFieldValue::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toEqualCanonicalizing($files);
});

it('posts the fields through the public page and shows each refused value next to its field', function () {
    ['token' => $token, 'request' => $request] = s6SendOne($this, [s6Field(0, 'TEXT', 'Er referens', ['required' => true])]);
    $field = SigningField::query()->sole();

    $this->post("/signera/{$token}", s6SignInput($request, ['fields' => [$field->id => '']]))->assertRedirect("/signera/{$token}");
    $this->get("/signera/{$token}")->assertSee('Fyll i ”Er referens”.')->assertSee('id="fel-'.$field->id.'"', false);

    $this->post("/signera/{$token}", s6SignInput($request, ['fields' => [$field->id => 'PO-1']]))->assertOk();
    expect(SigningFieldValue::query()->sole()->value)->toBe('PO-1');
});

it('runs a fixed number of queries on the signer page, whatever the number of signers and fields', function () {
    $count = function (int $signers, int $fieldsEach): int {
        $this->quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
            'customer_id' => $this->customer->id,
            'document_date' => '2026-06-15',
            'until_date' => '2026-07-15',
            'rows' => [['description' => 'Konsulttimme', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500]],
        ]);
        Mail::fake();
        $definitions = [];

        foreach (range(0, $signers - 1) as $signer) {
            foreach (range(1, $fieldsEach) as $index) {
                $definitions[] = s6Field($signer, $index % 2 === 0 ? 'TEXT' : 'CHECKBOX', "Fält {$signer}-{$index}");
            }
        }

        $envelope = app(SendForSigning::class)->envelope($this->owner, $this->company, $this->quote, [
            'signers' => array_map(fn (int $index): array => ['email' => "signer{$index}@kontorshuset.test", 'step' => 1], range(1, $signers)),
            'fields' => $definitions,
        ]);
        $token = (string) s6LinkFor('signer1@kontorshuset.test');
        expect(SigningRequest::query()->where('envelope_id', $envelope->id)->count())->toBe($signers);
        $this->get("/signera/{$token}")->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get("/signera/{$token}")->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(10, 12))->toBe($count(2, 1));
});

it('keeps stamped text inside its box: shrunk to fit, clipped by the engine, and refused when too wide', function () {
    ['token' => $token, 'request' => $request] = s6SendOne($this, [
        [...s6Field(0, 'TEXT', 'Referens'), 'width' => 4000, 'rules' => ['max_length' => 500]],
        [...s6Field(0, 'TEXT', 'Ort'), 'width' => 6000],
        s6Field(0, 'CHECKBOX', 'Villkor'),
    ]);
    [$narrow, $town, $box] = SigningField::query()->where('envelope_id', $request->envelope_id)->orderBy('position')->get()->all();

    // Placing: the longest text is what fits in the box at 6 points.
    expect($narrow->rule('max_length'))->toBe(TextFit::maxLength(40))->toBeLessThan(20);

    try {
        s6Sign($token, ['fields' => [$town->id => 'WWWWWWWWWWWWW']]);
        $this->fail('Text wider than its box must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors()["fields.{$town->id}"][0])->toBe('Texten får inte plats i fältet');
    }

    s6Sign($token, ['fields' => [$narrow->id => 'PO-12', $town->id => 'Västerhaninge', $box->id => '1']]);

    $stamped = collect($this->engine->stamped)->keyBy('text');
    $long = $stamped['Västerhaninge'];
    expect($long['box'])->toBe(['width' => 58.0, 'height' => 20.0])
        ->and($long['x'])->toBe(52.0)
        ->and($long['font_size'])->toBeLessThan(11)->toBeGreaterThanOrEqual(TextFit::MIN_SIZE)
        ->and(TextFit::width('Västerhaninge', $long['font_size']))->toBeLessThanOrEqual(TextFit::room(60))
        ->and($stamped['PO-12']['box']['height'])->toBe(20.0)
        ->and($stamped['X']['box']['width'])->toBeLessThanOrEqual(200.0);
});

it('refuses a dropdown option that does not fit its box when the field is placed', function () {
    expect(fn () => s6SendOne($this, [[...s6Field(0, 'DROPDOWN', 'Betalning', ['options' => ['Faktura med trettio dagars betalningstid']]), 'width' => 3000]]))
        ->toThrow(ValidationException::class);
});

it('refuses emoji and bidirectional overrides, and keeps a zero-width joiner the scripts need', function () {
    ['token' => $token, 'request' => $request] = s6SendOne($this, [[...s6Field(0, 'TEXT', 'Hälsning'), 'width' => 30000]]);
    $field = SigningField::query()->where('envelope_id', $request->envelope_id)->sole();

    expect(fn () => s6Sign($token, ['fields' => [$field->id => "abc\u{202E}def"]]))->toThrow(ValidationException::class)
        ->and(fn () => s6Sign($token, ['fields' => [$field->id => "abc\u{2066}def"]]))->toThrow(ValidationException::class);

    try {
        s6Sign($token, ['fields' => [$field->id => "Tack \u{1F469}\u{200D}\u{1F4BB} \u{2764}\u{FE0F}"]]);
        $this->fail('Emoji must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors()["fields.{$field->id}"][0])->toBe('Emoji och liknande symboler kan inte skrivas i dokumentet.');
    }

    s6Sign($token, ['fields' => [$field->id => "क्\u{200D}ष"]]);
    expect(SigningFieldValue::query()->sole()->value)->toBe("क्\u{200D}ष");
});

it('requires boxes wide enough for what NONOX fills in, and then never refuses a date, a name or a typed signature', function () {
    expect(fn () => s6SendOne($this, [[...s6Field(0, 'DATE', 'Datum'), 'width' => 3000]]))->toThrow(ValidationException::class)
        ->and(fn () => s6SendOne($this, [[...s6Field(0, 'NAME', 'Namn'), 'width' => 6000]]))->toThrow(ValidationException::class)
        ->and(fn () => s6SendOne($this, [[...s6Field(0, 'SIGNATURE', 'Underskrift'), 'width' => 6000]]))->toThrow(ValidationException::class)
        ->and(fn () => s6SendOne($this, [[...s6Field(0, 'TEXT', 'Jättefält'), 'width' => 300000]]))->toThrow(ValidationException::class);

    ['token' => $token] = s6SendOne($this, [
        [...s6Field(0, 'DATE', 'Datum'), 'width' => 6000],
        [...s6Field(0, 'NAME', 'Namnförtydligande'), 'width' => 12000],
        [...s6Field(0, 'SIGNATURE', 'Underskrift'), 'width' => 12000],
    ]);
    $long = 'Anna-Karin Margareta Wilhelmina von Knorring-Andersson Lindqvist';

    s6Sign($token, ['method' => 'TYPED', 'signature' => null, 'typed_signature' => $long], $long);

    expect(SigningFieldValue::query()->pluck('value')->all())->toContain('2026-06-15', $long)
        ->and(collect($this->engine->stamped)->firstWhere('text', $long)['font_size'])->toBe(TextFit::MIN_SIZE);
});

it('takes typed initials when nothing was drawn, as on a page without JavaScript', function () {
    ['token' => $token] = s6SendOne($this, [s6Field(0, 'INITIALS', 'Initialer', ['required' => true])]);

    s6Sign($token, ['initials_method' => 'DRAWN', 'initials' => '', 'typed_initials' => 'AA']);

    expect(SigningFieldValue::query()->sole()->value)->toBe('AA');
});

it('checks the fields against the pages before taking any mail quota', function () {
    expect(fn () => s6SendOne($this, [[...s6Field(0, 'TEXT', 'Sida 9'), 'page' => 9]]))->toThrow(ValidationException::class)
        ->and(RateLimiter::attempts("mail-quota:documents:{$this->company->id}"))->toBe(0);
});

it('mails a signer who stamped nothing the version they signed, not a later one', function () {
    $envelope = s6SendTwo($this, [s6Field(0, 'TEXT', 'Annas referens'), s6Field(1, 'TEXT', 'Bos referens')]);
    [, $bosField] = SigningField::query()->where('envelope_id', $envelope->id)->orderBy('position')->get()->all();
    $original = SigningDocumentVersion::query()->where('envelope_id', $envelope->id)->where('kind', 'ORIGINAL')->sole();

    $anna = s6Sign((string) s6LinkFor('anna@kontorshuset.test'));
    s6Sign((string) s6LinkFor('bo@kontorshuset.test'), ['fields' => [$bosField->id => 'B-2']], 'Bo Berg');

    expect(SignerFields::shownVersion($anna->refresh())?->sha256)->toBe($original->sha256)
        ->and(SigningDocumentVersion::query()->where('kind', 'AFTER_SIGNER')->count())->toBe(1);
});

it('puts the render job back while another worker holds the lock', function () {
    Queue::fake();
    ['request' => $request] = s6SendOne($this, [s6Field(0, 'TEXT', 'Er referens')]);
    $version = SigningDocumentVersion::query()->where('envelope_id', $request->envelope_id)->sole();
    $lock = Cache::lock("signing-pages:{$this->company->id}:{$version->sha256}", 60);
    $lock->get();

    $job = (new RenderSigningPages($this->company->id, $version->id))->withFakeQueueInteractions();
    $job->handle(app(PageImages::class));

    $job->assertReleased(30);
    expect($job->timeout)->toBe(300)->and(PageImages::LOCK_SECONDS)->toBeGreaterThan($job->timeout);
    $lock->release();
});

it('sends every party the finished document with every signer\'s values, and serves it from the file link', function () {
    $envelope = s6SendTwo($this, [s6Field(0, 'TEXT', 'Annas referens'), s6Field(1, 'TEXT', 'Bos referens')]);
    [$annasField, $bosField] = SigningField::query()->where('envelope_id', $envelope->id)->orderBy('position')->get()->all();

    s6Sign((string) s6LinkFor('anna@kontorshuset.test'), ['fields' => [$annasField->id => 'A-1']]);
    s6Sign((string) s6LinkFor('bo@kontorshuset.test'), ['fields' => [$bosField->id => 'B-2']], 'Bo Berg');

    $last = SigningDocumentVersion::query()->where('envelope_id', $envelope->id)->where('kind', 'AFTER_SIGNER')->orderByDesc('id')->first();
    $digest = fn (Attachment $attachment): string => hash('sha256', (string) $attachment->attachWith(fn () => null, fn (Closure $data) => $data()));
    $documents = [];

    Mail::assertQueued(SigningEnvelopeMail::class, function (SigningEnvelopeMail $mail) use (&$documents, $digest): bool {
        if (in_array($mail->kind, [SigningEnvelopeMail::COPY, SigningEnvelopeMail::COMPANY], true)) {
            $documents[] = $digest($mail->attachments()[0]);
        }

        return true;
    });

    Mail::assertQueued(SignedDocumentMail::class, function (SignedDocumentMail $mail) use (&$documents, $digest): bool {
        if ($mail->hasTo('bo@kontorshuset.test')) {
            $documents[] = $digest($mail->attachments()[0]);
        }

        return true;
    });

    expect($documents)->not->toBeEmpty()
        ->and(array_values(array_unique($documents)))->toBe([$last->sha256]);

    $bo = SigningRequest::query()->where('envelope_id', $envelope->id)->where('email', 'bo@kontorshuset.test')->sole();
    $file = $this->get(route('signing.file', ['signing' => $bo->id, 'file' => 'dokument']))->assertOk();
    expect(hash('sha256', (string) $file->getContent()))->toBe($last->sha256);

    // Two signers, two signing certificates of their own, and the chain verifies.
    expect(SigningRequest::query()->where('envelope_id', $envelope->id)->pluck('signed_document_path')->unique())->toHaveCount(2)
        ->and(app(ChainVerifier::class)->verify($this->company)['problems'])->toBe([]);
});

it('renders the page images in the background, and the page never starts the engine', function () {
    Queue::fake();
    ['token' => $token, 'request' => $request] = s6SendOne($this, [s6Field(0, 'TEXT', 'Er referens')]);

    Queue::assertPushed(RenderSigningPages::class, fn (RenderSigningPages $job): bool => $job->companyId === $this->company->id);

    $this->get("/signera/{$token}")->assertOk()->assertSee('Sidorna kan inte visas som bilder just nu');
    $this->get(route('signing.page', ['token' => $token, 'page' => 1]))->assertNotFound();
    expect($this->engine->operations())->not->toContain('render_pages');

    // The job renders once; a second run does nothing.
    $version = SigningDocumentVersion::query()->where('envelope_id', $request->envelope_id)->sole();
    (new RenderSigningPages($this->company->id, $version->id))->handle(app(PageImages::class));
    (new RenderSigningPages($this->company->id, $version->id))->handle(app(PageImages::class));
    expect(array_count_values($this->engine->operations())['render_pages'])->toBe(1);

    $this->get("/signera/{$token}")->assertOk()->assertDontSee('Sidorna kan inte visas som bilder just nu');
    $this->get(route('signing.page', ['token' => $token, 'page' => 1]))->assertOk();
});

it('leaves a version alone while another worker holds its render lock', function () {
    Queue::fake();
    ['request' => $request] = s6SendOne($this, [s6Field(0, 'TEXT', 'Er referens')]);
    $version = SigningDocumentVersion::query()->where('envelope_id', $request->envelope_id)->sole();
    $lock = Cache::lock("signing-pages:{$this->company->id}:{$version->sha256}", 60);
    $lock->get();

    expect(app(PageImages::class)->render($version))->toBeNull()
        ->and($this->engine->operations())->not->toContain('render_pages');

    $lock->release();
});

it('refuses to stamp onto a shown version whose file changed', function () {
    ['token' => $token, 'request' => $request] = s6SendOne($this, [s6Field(0, 'TEXT', 'Er referens')]);
    $field = SigningField::query()->sole();
    $version = SigningDocumentVersion::query()->where('envelope_id', $request->envelope_id)->sole();
    $links = app(SignAtLink::class);

    // The signer row's own hash check passes (its file is untouched); only the version's file is another one.
    $copy = 'crm-signing/'.$this->company->id.'/ersatt.pdf';
    Storage::disk('local')->put($copy, Storage::disk('local')->get($version->path)."\n% andrad\n");
    DB::statement('ALTER TABLE signing_document_versions DISABLE TRIGGER signing_document_versions_append_only');
    DB::table('signing_document_versions')->where('id', $version->id)->update(['path' => $copy]);
    DB::statement('ALTER TABLE signing_document_versions ENABLE TRIGGER signing_document_versions_append_only');

    expect(fn () => $links->sign($links->find($token), s6SignInput($request, ['fields' => [$field->id => 'X-1']]), null, null))
        ->toThrow(SigningUnavailable::class);
    expect(SigningFieldValue::query()->count())->toBe(0);
});

it('uses no mail quota and stores nothing when a send with fields is refused', function () {
    $this->engine->installed = false;

    expect(fn () => s6SendOne($this, [s6Field(0, 'TEXT', 'Er referens')]))->toThrow(ValidationException::class)
        ->and(RateLimiter::attempts("mail-quota:documents:{$this->company->id}"))->toBe(0)
        ->and(SigningEnvelope::query()->count())->toBe(0);
});
