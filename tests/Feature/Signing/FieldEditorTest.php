<?php

declare(strict_types=1);

use App\Actions\Signing\SendForSigning;
use App\Domain\Signing\Fields\FieldType;
use App\Domain\Signing\Fields\SigningFields;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\SigningField;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Livewire\SendWizard;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Modules\Signing\Support\FieldLayout;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\PdfEngineFixtures;

/*
 * The field editor of the send wizard (ADR-046 amendment 2026-11-18, gap 2,
 * package S8): the step "Fält i dokumentet", the geometry that keeps every
 * box inside its page and valid for its type, the keyboard and form
 * alternatives to dragging, and the PDF the editor shows. The PDF engine is
 * the fake one (A4 pages of 595.28 × 841.89 points).
 */

function s8Store(User $user, Company $company, string $title = 'Hyresavtal'): SigningDocument
{
    $path = tempnam(sys_get_temp_dir(), 's8');
    file_put_contents($path, PdfEngineFixtures::pdf(2));

    try {
        return app(StoreSigningDocument::class)($user, $company, $path, 'Avtal.pdf', $title);
    } finally {
        @unlink($path);
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    Queue::fake();
    $this->engine = FakePdfEngine::install();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(ModuleManager::class)->enable(SigningModule::KEY, $this->company, $this->owner);
    $this->document = s8Store($this->owner, $this->company);
});

/** The wizard on the field step with one signer. */
function s8Wizard(SigningDocument $document, ?string $email = 'eva@example.test'): Testable
{
    return Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $document->id])
        ->set('signers.0.email', $email)
        ->call('next')
        ->assertSet('step', 3);
}

it('gives every field type a default size that SigningFields accepts at every preset position', function () {
    $page = ['width' => 59528, 'height' => 84189];

    foreach (FieldType::cases() as $type) {
        foreach (FieldLayout::PRESETS as $preset) {
            [$width, $height] = FieldLayout::defaultSize($type);
            [$x, $y] = FieldLayout::preset($preset, $width, $height, $page['width'], $page['height']);
            $box = FieldLayout::clamp($type, ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height], $page['width'], $page['height']);

            $definition = [
                'signer' => 0, 'type' => $type->value, 'page' => 1, ...$box, 'label' => $type->label(), 'required' => true,
                'options' => match ($type) {
                    FieldType::Dropdown => ['Ja', 'Nej'],
                    FieldType::Radio => ['Ja'],
                    default => [],
                },
                'group_key' => $type === FieldType::Radio ? 'val' : null,
            ];
            $signature = ['signer' => 0, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 0, 'y' => 0, 'width' => 20000, 'height' => 4000, 'label' => 'U'];
            $clean = SigningFields::validate($type === FieldType::Signature ? [$definition] : [$definition, $signature], 1);

            SigningFields::checkGeometry($clean, [['width' => 595.28, 'height' => 841.89, 'rotation' => 0]]);
            expect($box['x'])->toBeGreaterThanOrEqual(0)->and($box['y'])->toBeGreaterThanOrEqual(0);
        }
    }
});

it('clamps a box into its page and never below what its type needs', function () {
    $narrow = FieldLayout::clamp(FieldType::Date, ['x' => -10, 'y' => -10, 'width' => 600, 'height' => 100], 59528, 84189);
    $name = FieldLayout::clamp(FieldType::Name, ['x' => 59000, 'y' => 84000, 'width' => 999999, 'height' => 999999], 59528, 84189);

    expect($narrow['x'])->toBe(0)->and($narrow['y'])->toBe(0)
        ->and($narrow['width'])->toBe(FieldLayout::minWidth(FieldType::Date))
        ->and($narrow['height'])->toBe(SigningFields::MIN_SIZE)
        ->and($name['x'] + $name['width'])->toBeLessThanOrEqual(59528)
        ->and($name['y'] + $name['height'])->toBeLessThanOrEqual(84189)
        ->and($name['width'])->toBeLessThanOrEqual(SigningFields::MAX_SIZE);

    // The narrowest date, name and initials boxes are accepted by the validation, one unit narrower is not for a date.
    foreach ([FieldType::Date, FieldType::Name, FieldType::Signature, FieldType::Initials] as $type) {
        $definition = ['signer' => 0, 'type' => $type->value, 'page' => 1, 'x' => 0, 'y' => 0, 'width' => FieldLayout::minWidth($type), 'height' => 2000, 'label' => 'F'];
        expect(fn () => SigningFields::validate([$definition, ['signer' => 0, 'type' => 'SIGNATURE', 'page' => 1, 'x' => 0, 'y' => 0, 'width' => 20000, 'height' => 4000, 'label' => 'U']], 1))->not->toThrow(Exception::class);
    }
});

it('converts between millimetres and PDF units', function () {
    expect(FieldLayout::mmToUnits(25.4))->toBe(7200)
        ->and(FieldLayout::unitsToMm(7200))->toBe(25.4)
        ->and(FieldLayout::unitsToMm(FieldLayout::mmToUnits(100.0)))->toBe(100.0);
});

it('opens the field step after the signers, measures the pages and lets a document go without fields', function () {
    $wizard = s8Wizard($this->document)
        ->assertSee('Fält i dokumentet')
        ->assertSee('Sida 1 av 2')
        ->assertSet('pageSizes.0.width', 59528)
        ->assertSet('pageSizes.0.height', 84189)
        ->call('next')->assertSet('step', 4)
        ->call('next')->call('next')->assertSet('step', 6)
        ->assertSee('Inga fält')
        ->call('send')->assertHasNoErrors();

    expect(SigningField::query()->count())->toBe(0)
        ->and(SigningEnvelope::query()->sole()->title)->toBe('Hyresavtal');
});

it('adds a field at a preset position and sends it with the envelope in the form the validation takes', function () {
    $wizard = s8Wizard($this->document)
        ->set('new.page', 2)
        ->set('new.preset', 'bottom-right')
        ->call('addField')
        ->assertSet('selected', 0)
        ->assertSet('fields.0.type', 'SIGNATURE')
        ->assertSet('fields.0.page', 2)
        ->assertSee('Fältet ”Underskrift” är tillagt.');

    [$width, $height] = FieldLayout::defaultSize(FieldType::Signature);

    $wizard->assertSet('fields.0.x', 59528 - FieldLayout::MARGIN - $width)
        ->assertSet('fields.0.y', FieldLayout::MARGIN)
        ->assertSet('fields.0.height', $height)
        ->set('new.type', 'DATE')->set('new.page', 2)->set('new.preset', 'bottom-left')->call('addField')
        ->call('next')->assertSet('step', 4)->assertHasNoErrors()
        ->call('next')->call('next')->assertSee('2 fält på 1 undertecknare')
        ->call('send')->assertHasNoErrors();

    $signature = SigningField::query()->where('type', 'SIGNATURE')->sole();
    $date = SigningField::query()->where('type', 'DATE')->sole();

    expect($signature->page)->toBe(2)
        ->and($signature->x)->toBe(59528 - FieldLayout::MARGIN - $width)
        ->and($signature->y)->toBe(FieldLayout::MARGIN)
        ->and($signature->page_width)->toBe(59528)
        ->and($signature->page_height)->toBe(84189)
        ->and($signature->required)->toBeTrue()
        ->and($date->x)->toBe(FieldLayout::MARGIN)
        ->and(SigningEnvelope::query()->sole()->title)->toBe('Hyresavtal');
});

it('places a dropped field around the drop point and keeps it inside the page', function () {
    s8Wizard($this->document)
        ->call('addFieldAt', 'TEXT', 1, 30000, 40000)
        ->assertSet('fields.0.x', 30000 - 9000)
        ->assertSet('fields.0.y', 40000 - 1000)
        ->call('addFieldAt', 'TEXT', 1, 0, 0)
        ->assertSet('fields.1.x', 0)
        ->assertSet('fields.1.y', 0)
        ->call('addFieldAt', 'NOT_A_TYPE', 1, 100, 100)
        ->call('addFieldAt', 'TEXT', 9, 100, 100)
        ->assertCount('fields', 2);
});

it('moves and resizes a box with the pointer and clamps whatever the client sends', function () {
    s8Wizard($this->document)
        ->call('addField')
        ->call('moveField', 0, 10000, 20000, 15000, 3000)
        ->assertSet('fields.0.x', 10000)->assertSet('fields.0.y', 20000)->assertSet('fields.0.width', 15000)->assertSet('fields.0.height', 3000)
        ->call('moveField', 0, -500, 99999999, 99999999, 99999999)
        ->assertSet('fields.0.x', 0)
        ->assertSet('fields.0.width', 59528)
        ->assertSet('fields.0.height', 84189)
        ->assertSet('fields.0.y', 0)
        ->call('moveField', 5, 1, 1, 1, 1)
        ->assertCount('fields', 1);
});

it('moves a box with the keys: right and down are positive, resize keeps the upper left corner', function () {
    $wizard = s8Wizard($this->document)->call('addField');
    $before = $wizard->get('fields.0');

    $wizard->call('nudge', 0, 10, 0)
        ->assertSet('fields.0.x', $before['x'] + FieldLayout::mmToUnits(10))
        ->call('nudge', 0, 0, 10)
        ->assertSet('fields.0.y', $before['y'] - FieldLayout::mmToUnits(10));

    $moved = $wizard->get('fields.0');
    $wizard->call('nudge', 0, 5, 5, true);
    $resized = $wizard->get('fields.0');

    expect($resized['width'])->toBe($moved['width'] + FieldLayout::mmToUnits(5))
        ->and($resized['height'])->toBe($moved['height'] + FieldLayout::mmToUnits(5))
        ->and($resized['y'] + $resized['height'])->toBe($moved['y'] + $moved['height'])
        ->and($resized['x'])->toBe($moved['x']);

    // Far outside the page: held inside.
    $wizard->call('nudge', 0, 100, 100)->call('nudge', 0, 100, 100)->call('nudge', 0, 100, 100)->call('nudge', 0, 100, 100)->call('nudge', 0, 100, 100)->call('nudge', 0, 100, 100);
    $far = $wizard->get('fields.0');
    expect($far['x'] + $far['width'])->toBeLessThanOrEqual(59528)->and($far['y'])->toBeGreaterThanOrEqual(0);
});

it('edits the box in millimetres from the upper left corner and says where it ended up', function () {
    $wizard = s8Wizard($this->document)->call('addField')
        ->set('geometry.left', '20')->set('geometry.top', '30,5')->set('geometry.width', '70')->set('geometry.height', '15')
        ->call('applyGeometry')
        ->assertSet('fields.0.x', FieldLayout::mmToUnits(20))
        ->assertSet('fields.0.width', FieldLayout::mmToUnits(70))
        ->assertSet('fields.0.height', FieldLayout::mmToUnits(15))
        ->assertSet('fields.0.y', 84189 - FieldLayout::mmToUnits(30.5) - FieldLayout::mmToUnits(15))
        ->assertSet('geometry.left', '20')
        ->assertSet('geometry.top', '30.5')
        ->assertSee('20 mm från vänster och 30.5 mm från överkanten');

    // A value that is not a number puts the form back to what is stored.
    $wizard->set('geometry.left', 'abc')->call('applyGeometry')->assertSet('geometry.left', '20');
});

it('keeps a field inside its page and its signer when the form changes them', function () {
    s8Wizard($this->document)->call('addFieldAt', 'DATE', 1, 100, 100)
        ->set('fields.0.page', 2)
        ->assertSet('fields.0.page', 2)
        ->set('fields.0.page', 9)
        ->assertSet('fields.0.page', 1)
        ->set('fields.0.signer', 4)
        ->assertSet('fields.0.signer', 0)
        ->set('fields.0.width', 1)
        ->assertSet('fields.0.width', FieldLayout::minWidth(FieldType::Date));
});

it('refuses to go on while a signer has no signature field, and names the signer', function () {
    $wizard = Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $this->document->id])
        ->set('signers.0.email', 'eva@example.test')
        ->call('addSigner')
        ->set('signers.1.email', 'bo@example.test')
        ->call('next')->assertSet('step', 3)
        ->set('new.signer', 0)->call('addField')
        ->call('next')
        ->assertSet('step', 3)
        ->assertSee('Varje undertecknare behöver minst ett underskriftsfält. Undertecknare 2 saknar det.')
        ->set('new.signer', 1)->call('addField')
        ->call('next')->assertSet('step', 4)->assertHasNoErrors();

    expect($wizard->get('fields.1.signer'))->toBe(1);
});

it('shows the validation of a field in the step and keeps the sender there', function () {
    s8Wizard($this->document)->call('addField')
        ->set('fields.0.label', '')
        ->call('next')
        ->assertSet('step', 3)
        ->assertSee('Fält 1');
});

it('drops the fields of a signer who is removed and shifts the later ones', function () {
    $wizard = Livewire::test(SendWizard::class, ['type' => 'DOCUMENT', 'documentId' => $this->document->id])
        ->set('signers.0.email', 'a@example.test')
        ->call('addSigner')->set('signers.1.email', 'b@example.test')
        ->call('addSigner')->set('signers.2.email', 'c@example.test')
        ->call('next')
        ->set('new.signer', 0)->call('addField')
        ->set('new.signer', 1)->call('addField')
        ->set('new.signer', 2)->call('addField');

    $wizard->call('removeSigner', 1)->assertCount('fields', 2)
        ->assertSet('fields.0.signer', 0)
        ->assertSet('fields.1.signer', 1)
        ->assertSet('selected', null);
});

it('removes a field and says so', function () {
    s8Wizard($this->document)->call('addField')->call('addField')
        ->call('removeField', 0)
        ->assertCount('fields', 1)
        ->assertSet('selected', null)
        ->assertSee('är borttaget')
        ->call('removeField', 7)
        ->assertCount('fields', 1);
});

it('turns the form into dropdown options, a radio group and number rules the way SigningFields takes them', function () {
    $wizard = s8Wizard($this->document)
        ->set('new.type', 'DROPDOWN')->call('addField')
        ->set('fields.0.options_text', "Hyra\nKöp\n\nLeasing ")
        ->set('new.type', 'RADIO')->call('addField')
        ->set('new.type', 'RADIO')->call('addField')
        ->set('fields.2.group_key', 'grupp1')->set('fields.2.options_text', 'Nej')
        ->set('new.type', 'NUMBER')->call('addField')
        ->set('fields.3.rules.min', '0,5')->set('fields.3.rules.max', '10')->set('fields.3.rules.decimals', '2')
        ->set('new.type', 'TEXT')->call('addField')
        ->set('fields.4.rules.max_length', '40')
        ->set('new.type', 'SIGNATURE')->call('addField')
        ->call('next')->assertSet('step', 4)->assertHasNoErrors()
        ->call('next')->call('next')->call('send')->assertHasNoErrors();

    $fields = SigningField::query()->orderBy('position')->get();

    expect($fields->firstWhere('type', FieldType::Dropdown)->options)->toBe(['Hyra', 'Köp', 'Leasing'])
        ->and($fields->where('type', FieldType::Radio)->pluck('group_key')->all())->toBe(['grupp1', 'grupp1'])
        ->and($fields->where('type', FieldType::Radio)->map(fn (SigningField $field): array => $field->options)->values()->all())->toBe([['Alternativ 1'], ['Nej']])
        ->and($fields->firstWhere('type', FieldType::Number)->rules)->toEqual(['min' => '0.5', 'max' => '10', 'decimals' => 2])
        ->and($fields->firstWhere('type', FieldType::Text)->rules)->toBe(['max_length' => 40]);
});

it('stops at 200 fields', function () {
    $wizard = s8Wizard($this->document);

    foreach (range(1, SigningFields::MAX_FIELDS) as $unused) {
        $wizard->set('new.type', 'CHECKBOX')->call('addField');
    }

    $wizard->assertCount('fields', SigningFields::MAX_FIELDS)
        ->call('addField')
        ->assertCount('fields', SigningFields::MAX_FIELDS)
        ->assertSee('Högst 200 fält i ett dokument.');
});

it('says why there are no fields when the PDF engine is not installed, and still sends the document', function () {
    $this->engine->installed = false;

    s8Wizard($this->document)
        ->assertSee('kräver PDF-motorn')
        ->assertSet('pageSizes', [])
        ->call('addField')->assertCount('fields', 0)
        ->call('next')->assertSet('step', 4);
});

it('refuses a send that gives a signer no signature field, whatever way the fields arrive', function () {
    $field = fn (int $signer, string $type): array => ['signer' => $signer, 'type' => $type, 'page' => 1, 'x' => 5000, 'y' => 5000 + $signer * 9000, 'width' => 20000, 'height' => 4000, 'label' => 'Fält'];
    $input = fn (array $fields): array => ['signers' => [['email' => 'a@example.test', 'step' => 1], ['email' => 'b@example.test', 'step' => 1]], 'fields' => $fields];

    expect(fn () => SigningFields::validate([$field(0, 'SIGNATURE'), $field(1, 'TEXT')], 2))->toThrow(ValidationException::class, 'Undertecknare 2 saknar det')
        ->and(fn () => SigningFields::validate([$field(0, 'SIGNATURE'), $field(1, 'SIGNATURE')], 2))->not->toThrow(Exception::class)
        ->and(SigningFields::validate([], 2))->toBe([])
        ->and(fn () => app(SendForSigning::class)->envelope($this->owner, $this->company, $this->document, $input([$field(0, 'SIGNATURE'), $field(1, 'DATE')]), minSigners: 1))->toThrow(ValidationException::class, 'Undertecknare 2 saknar det');

    expect(SigningEnvelope::query()->count())->toBe(0)->and(SigningField::query()->count())->toBe(0);

    app(SendForSigning::class)->envelope($this->owner, $this->company, $this->document, $input([$field(0, 'SIGNATURE'), $field(1, 'SIGNATURE')]), minSigners: 1);

    expect(SigningField::query()->count())->toBe(2);
});

it('resizes with the upper left corner fixed, also past the page edge and below the minimum', function () {
    $wizard = s8Wizard($this->document)->call('addFieldAt', 'TEXT', 1, 55000, 3000);
    $before = $wizard->get('fields.0');
    $top = $before['y'] + $before['height'];

    $wizard->call('nudge', 0, 100, 100, true);
    $past = $wizard->get('fields.0');

    expect($past['x'])->toBe($before['x'])
        ->and($past['x'] + $past['width'])->toBe(59528)
        ->and($past['y'])->toBe(0)
        ->and($past['y'] + $past['height'])->toBe($top);

    foreach (range(1, 4) as $unused) {
        $wizard->call('nudge', 0, -100, -100, true);
    }

    $small = $wizard->get('fields.0');

    expect($small['x'])->toBe($before['x'])
        ->and($small['width'])->toBe(FieldLayout::minWidth(FieldType::Text))
        ->and($small['height'])->toBe(SigningFields::MIN_SIZE)
        ->and($small['y'] + $small['height'])->toBe($top);
});

it('survives a client that sends fields with keys missing', function () {
    s8Wizard($this->document)
        ->set('fields', [['type' => 'DATE']])
        ->call('selectField', 0)
        ->call('nudge', 0, 1, 1)
        ->call('nudge', 0, 1, 1, true)
        ->call('removeField', 0)
        ->set('fields', [['type' => 'DATE', 'label' => 'D']])
        ->call('next')
        ->assertSet('step', 3);
});

it('answers 404 and not a server error for a document type or key that is not a string', function () {
    $this->get('/signering/ny/dokument?typ[]=DOCUMENT&dokument[]=x')->assertNotFound();
    $this->get('/signering/ny/dokument?typ=DOCUMENT&dokument[]=x')->assertNotFound();
    $this->get('/signering/ny?typ[]=DOCUMENT&dokument[]=x')->assertOk();
});

it('runs a fixed number of queries when a field is added and when the step is left, whatever the number of fields', function () {
    $measure = function (int $fields): array {
        $wizard = s8Wizard($this->document, 'm'.$fields.'@example.test');

        foreach (range(1, $fields) as $unused) {
            $wizard->call('addField');
        }

        $wizard->call('addField');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $wizard->call('addField');
        $added = count(DB::getQueryLog());
        DB::flushQueryLog();
        $wizard->call('next');
        $next = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$added, $next];
    };

    expect($measure(20))->toBe($measure(1));
});

it('draws the same number of queries for one field as for twenty', function () {
    $count = function (int $fields): int {
        $wizard = s8Wizard($this->document, 'q'.$fields.'@example.test');

        foreach (range(1, $fields) as $unused) {
            $wizard->call('addField');
        }

        $wizard->call('selectField', 0);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $wizard->call('selectField', 0);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $counts = [$count(1), $count(20)];
    expect($counts[1])->toBe($counts[0]);
});

it('serves the chosen document to the editor from NONOX itself, byte for byte and never cached', function () {
    $response = $this->get(route('signing.new.pdf', ['typ' => 'DOCUMENT', 'dokument' => $this->document->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->getContent())->toBe($this->document->signingDocument()['bytes'])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
});

it('gives another company, another uploader and a bad key a 404 for the editor PDF', function () {
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    CurrentCompany::set($otherCompany);
    app(ModuleManager::class)->enable(SigningModule::KEY, $otherCompany, $otherOwner);
    $foreign = s8Store($otherOwner, $otherCompany, 'Främmande');
    CurrentCompany::set($this->company);

    $this->get(route('signing.new.pdf', ['typ' => 'DOCUMENT', 'dokument' => $foreign->id]))->assertNotFound();
    $this->get(route('signing.new.pdf', ['typ' => 'DOCUMENT', 'dokument' => 'inte-ett-id']))->assertNotFound();
    $this->get(route('signing.new.pdf', ['typ' => 'NONSENSE', 'dokument' => $this->document->id]))->assertNotFound();
    $this->get(route('signing.new.pdf'))->assertNotFound();

    // Another member who did not upload it and may not manage signing.
    $member = User::factory()->create();
    $this->company->users()->attach($member, ['role' => 'bookkeeper']);
    $member->forceFill(['current_team_id' => $this->company->id])->save();
    $this->actingAs($member);
    $this->get(route('signing.new.pdf', ['typ' => 'DOCUMENT', 'dokument' => $this->document->id]))->assertNotFound();
});

it('answers 404 for the editor PDF while the module is off', function () {
    app(ModuleManager::class)->disable(SigningModule::KEY, $this->company, $this->owner, confirmed: true);

    $this->get(route('signing.new.pdf', ['typ' => 'DOCUMENT', 'dokument' => $this->document->id]))->assertNotFound();
});

it('lets a wizard page load the editor script and no script from elsewhere', function () {
    $html = $this->get(route('signing.new', ['typ' => 'DOCUMENT', 'dokument' => $this->document->id]))->assertOk()->getContent();

    expect($html)->toContain('signing-editor')->and($html)->not->toContain('cdn.')->and($html)->not->toContain('unpkg');
});
