<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\StaffLedger\Actions\AppendEntry;
use App\Modules\StaffLedger\Actions\CorrectEntry;
use App\Modules\StaffLedger\Actions\SavePerson;
use App\Modules\StaffLedger\Domain\ChainVerifier;
use App\Modules\StaffLedger\Domain\CheckInMethod;
use App\Modules\StaffLedger\Domain\CorrectionKind;
use App\Modules\StaffLedger\Domain\EntryHash;
use App\Modules\StaffLedger\Domain\EntryType;
use App\Modules\StaffLedger\Livewire\ControlVisitPage;
use App\Modules\StaffLedger\Livewire\PeoplePage;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\Support\Export\LedgerXmlFormat;
use App\Modules\StaffLedger\Support\KioskRoster;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\StaffLedger;

/*
 * A person without a Swedish personal or coordination number is registered
 * with a foreign identity number and the issuing country (9 kap. 5 §
 * skatteförfarandeförordningen, "motsvarande utländska nummer"). Synthetic
 * numbers only.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    StaffLedger::enable($this->company, $this->owner);
    $this->location = StaffLedger::location($this->owner, $this->company);

    $this->save = fn (array $input, ?Person $person = null) => app(SavePerson::class)($this->owner, $this->company, [
        'first_name' => 'Ola',
        'last_name' => 'Nordmann',
        'identity_type' => SavePerson::FOREIGN,
        'personal_number' => '  0101 9012 345  ',
        'identity_country' => 'no',
        'language' => 'en',
        ...$input,
    ], $person);
});

it('registers a foreign identity number with its country, trimmed, encrypted and never Luhn-checked', function () {
    $person = ($this->save)([]);

    expect($person->personal_number)->toBe('0101 9012 345')
        ->and($person->identity_country)->toBe('NO')
        ->and($person->formattedPersonalNumber())->toBe('0101 9012 345 (NO)')
        ->and($person->maskedPersonalNumber())->toBe('Utländskt nummer (NO), dolt')
        ->and($person->maskedPersonalNumber())->not->toContain('9012');

    $raw = (string) DB::table('staff_ledger_people')->where('id', $person->id)->value('personal_number');
    expect($raw)->not->toContain('9012')
        ->and(decrypt($raw, false))->toBe('0101 9012 345');
});

it('requires a country other than Sweden, and at most thirty characters', function (array $input, string $field) {
    expect(fn () => ($this->save)($input))->toThrow(function (ValidationException $exception) use ($field) {
        expect($exception->errors())->toHaveKey($field);
    });
})->with([
    'no country' => [['identity_country' => ''], 'identity_country'],
    'Sweden' => [['identity_country' => 'SE'], 'identity_country'],
    'not a country' => [['identity_country' => 'QQ'], 'identity_country'],
    'empty number' => [['personal_number' => '   '], 'personal_number'],
    'only punctuation' => [['personal_number' => '--/'], 'personal_number'],
    'too long' => [['personal_number' => str_repeat('A', 31)], 'personal_number'],
]);

it('still Luhn-checks a Swedish number', function () {
    expect(fn () => ($this->save)(['identity_type' => SavePerson::SWEDISH, 'personal_number' => '199001011230', 'identity_country' => '']))
        ->toThrow(ValidationException::class, 'Skriv person- eller samordningsnumret med tolv siffror');

    $swede = ($this->save)(['identity_type' => SavePerson::SWEDISH, 'personal_number' => StaffLedger::personalNumber(), 'identity_country' => 'NO']);
    expect($swede->identity_country)->toBeNull()->and($swede->personal_number)->toBe(StaffLedger::personalNumber());
});

it('finds the same foreign number again regardless of spaces and case, but not from another country', function () {
    ($this->save)(['personal_number' => 'ab 12-345']);

    expect(fn () => ($this->save)(['first_name' => 'Kari', 'personal_number' => 'AB12345']))
        ->toThrow(ValidationException::class, 'Det finns redan en person med det numret i personalliggaren.');

    expect(($this->save)(['first_name' => 'Kari', 'personal_number' => 'AB12345', 'identity_country' => 'DK'])->identity_country)->toBe('DK');
});

it('keeps the stored number on edit, but asks for it again when the kind of number changes', function () {
    $person = ($this->save)([]);

    $kept = ($this->save)(['personal_number' => '', 'last_name' => 'Nordmann-Hansen'], $person);
    expect($kept->personal_number)->toBe('0101 9012 345');

    expect(fn () => ($this->save)(['personal_number' => '', 'identity_country' => 'DK'], $person))
        ->toThrow(ValidationException::class, 'Skriv numret igen när du byter till ett annat slags nummer eller land.');

    $swedish = ($this->save)(['identity_type' => SavePerson::SWEDISH, 'personal_number' => StaffLedger::personalNumber('19850303123')], $person);
    expect($swedish->identity_country)->toBeNull()
        ->and(AuditLog::query()->where('subject_id', (string) $person->id)->latest('id')->first()?->payload['after']['personal_number'] ?? null)->toBe('[ändrat]');
});

it('accepts a foreign employer with its country and still checks a Swedish organisation number', function () {
    $hired = ($this->save)(['hired' => true, 'employer_name' => 'Bemanning AS', 'employer_foreign' => true, 'employer_org_number' => ' 999 888 777 ', 'employer_country' => 'NO']);

    expect($hired->employer_org_number)->toBe('999 888 777')
        ->and($hired->employer_country)->toBe('NO')
        ->and($hired->isHired())->toBeTrue()
        ->and($hired->employerLabel())->toBe('Bemanning AS (999 888 777, NO)');

    expect(fn () => ($this->save)(['first_name' => 'Kari', 'personal_number' => 'X1', 'hired' => true, 'employer_name' => 'Bemanning AS', 'employer_foreign' => true, 'employer_org_number' => '999', 'employer_country' => '']))
        ->toThrow(ValidationException::class, 'Välj landet där arbetsgivaren är registrerad.');

    expect(fn () => ($this->save)(['first_name' => 'Kari', 'personal_number' => 'X2', 'hired' => true, 'employer_name' => 'Bemanning AB', 'employer_org_number' => '5560160681']))
        ->toThrow(ValidationException::class, 'Kontrollera arbetsgivarens organisationsnummer.');

    $swedishEmployer = ($this->save)(['first_name' => 'Kari', 'personal_number' => 'X3', 'hired' => true, 'employer_name' => 'Bemanning AB', 'employer_org_number' => '5560160680', 'employer_country' => 'NO']);
    expect($swedishEmployer->employer_country)->toBeNull()->and($swedishEmployer->employer_org_number)->toBe('556016-0680');
});

it('copies the number and country into the entry, hashes them and keeps older hashes unchanged', function () {
    $person = ($this->save)(['hired' => true, 'employer_name' => 'Bemanning AS', 'employer_foreign' => true, 'employer_org_number' => '999888777', 'employer_country' => 'NO']);
    $in = app(AppendEntry::class)($this->location, $person, ['type' => EntryType::CheckIn, 'method' => CheckInMethod::KioskPin, 'occurred_at' => CarbonImmutable::now()->subHour()]);
    $correction = app(CorrectEntry::class)($this->owner, $this->company, $in, CorrectionKind::Void, null, 'Dubbeltryck');

    expect($in->identity_country)->toBe('NO')
        ->and($in->employer_country)->toBe('NO')
        ->and($correction->identity_country)->toBe('NO')
        ->and(EntryHash::canonicalJson($in))->toContain('"identity_country":"NO"')
        ->and(app(ChainVerifier::class)->verify($this->company)['ok'])->toBeTrue();

    // A Swedish entry's payload has no country keys, so hashes written before foreign numbers still verify.
    $swede = StaffLedger::person($this->owner, $this->company, '19900101123', null);
    $entry = app(AppendEntry::class)($this->location, $swede, ['type' => EntryType::CheckIn, 'method' => CheckInMethod::KioskPin, 'occurred_at' => CarbonImmutable::now()]);
    expect(EntryHash::canonicalJson($entry))->not->toContain('country');

    // Changing the country afterwards is caught by the chain.
    DB::statement('ALTER TABLE staff_ledger_entries DISABLE TRIGGER USER');
    DB::statement("update staff_ledger_entries set identity_country = 'DK' where id = ?", [$in->id]);
    DB::statement('ALTER TABLE staff_ledger_entries ENABLE TRIGGER USER');
    expect(app(ChainVerifier::class)->verify($this->company)['problems'])->toContain("Lokal Restaurang Testgatan: post {$in->id} har ändrats efter att den skrevs.");
});

it('shows the foreign number and country in the control visit, the exports and on the kiosk', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00'));
    $person = ($this->save)(['hired' => true, 'employer_name' => 'Bemanning AS', 'employer_foreign' => true, 'employer_org_number' => '999888777', 'employer_country' => 'NO']);
    app(AppendEntry::class)($this->location, $person, ['type' => EntryType::CheckIn, 'method' => CheckInMethod::KioskPin, 'occurred_at' => CarbonImmutable::parse('2026-11-02 08:00')]);
    [$device] = StaffLedger::kiosk($this->owner, $this->company, $this->location);
    $sensitive = KioskRoster::sensitive($device);

    expect($sensitive['people'][0]['personal_number'])->toBe('0101 9012 345 (NO)')
        ->and($sensitive['people'][0]['employer_org_number'])->toBe('999888777, NO')
        ->and($sensitive['shifts'][0]['personal_number'])->toBe('0101 9012 345 (NO)');

    CurrentCompany::forget();
    $this->actingAs($this->owner);

    $this->get(route('staff-ledger.control-visit'))->assertOk()
        ->assertSee('0101 9012 345 (NO)')
        ->assertSee('Bemanning AS (999888777, NO)');

    Livewire::test(ControlVisitPage::class)->set('search', '9012 345')->assertSee('Ola Nordmann');

    $csv = (string) $this->get(route('staff-ledger.control-visit.export', ['format' => 'csv', 'fran' => '2026-11-02', 'till' => '2026-11-02']))->assertOk()->getContent();
    expect($csv)->toContain('Land som utfärdat ID-numret')
        ->and($csv)->toContain('"Ola Nordmann";"0101 9012 345";NO;"Bemanning AS";999888777;NO;');

    $xml = (string) $this->get(route('staff-ledger.control-visit.export', ['format' => 'xml', 'fran' => '2026-11-02', 'till' => '2026-11-02']))->assertOk()->getContent();
    $document = new DOMDocument;
    $document->loadXML($xml);
    libxml_use_internal_errors(true);
    $valid = $document->schemaValidate(app(LedgerXmlFormat::class)->schemaPath());
    $errors = array_map(fn ($error) => trim($error->message), libxml_get_errors());
    libxml_clear_errors();

    expect($errors)->toBe([])
        ->and($valid)->toBeTrue()
        ->and($document->getElementsByTagName('Identitet')->length)->toBe(0)
        ->and($document->getElementsByTagName('UtlandskIdentitet')->item(0)?->textContent)->toContain('0101 9012 345')
        ->and($document->getElementsByTagName('UtlandsktNummer')->item(0)?->textContent)->toContain('999888777')
        ->and($document->getElementsByTagName('Land')->item(0)?->textContent)->toBe('NO');
});

it('includes the countries in the register extract', function () {
    $person = ($this->save)([]);
    app(AppendEntry::class)($this->location, $person, ['type' => EntryType::CheckIn, 'method' => CheckInMethod::KioskPin, 'occurred_at' => CarbonImmutable::now()]);
    $this->actingAs($this->owner);

    $json = $this->get(route('privacy.export.staffLedgerPerson', $person))->assertOk()->json();

    expect($json['person']['personnummer'])->toBe('0101 9012 345')
        ->and($json['person']['identitetsnummer_utfardat_i'])->toBe('NO')
        ->and($json['registreringar'][0]['identitetsnummer_utfardat_i'])->toBe('NO');
});

it('adds a foreign person from the people page with a toast, and masks the number in the list', function () {
    CurrentCompany::forget();
    $this->actingAs($this->owner);

    Livewire::test(PeoplePage::class)
        ->call('create')
        ->set('form.first_name', 'Ola')
        ->set('form.last_name', 'Nordmann')
        ->set('form.identity_type', SavePerson::FOREIGN)
        ->assertSee('Land som utfärdat numret')
        ->set('form.personal_number', 'P-778899')
        ->set('form.identity_country', 'PL')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Ola Nordmann är tillagd. Välj en kod för kiosken.')
        ->assertSee('Utländskt nummer (PL), dolt')
        ->assertDontSee('P-778899')
        ->call('edit', Person::query()->where('first_name', 'Ola')->sole()->id)
        ->assertSet('form.identity_type', SavePerson::FOREIGN)
        ->assertSet('form.identity_country', 'PL')
        ->assertSet('form.personal_number', '');
});

it('is refused to roles that may not manage the staff', function () {
    $this->owner = Bookkeeping::member($this->company, 'auditor');

    expect(fn () => ($this->save)([]))->toThrow(AuthorizationException::class);
});

it('keeps a foreign person inside its company', function () {
    $person = ($this->save)([]);
    [$otherOwner, $other] = Bookkeeping::company();

    CurrentCompany::run($other, function () use ($otherOwner, $other, $person) {
        StaffLedger::enable($other, $otherOwner);

        expect(Person::query()->find($person->id))->toBeNull();

        // The same foreign number may be registered in another company.
        $twin = app(SavePerson::class)($otherOwner, $other, ['first_name' => 'Ola', 'last_name' => 'Nordmann', 'identity_type' => SavePerson::FOREIGN, 'personal_number' => '0101 9012 345', 'identity_country' => 'NO', 'language' => 'sv']);
        expect($twin->company_id)->toBe($other->id);
    });

    CurrentCompany::set($this->company);
    expect(CompanyScope::bypass(fn () => Person::query()->where('identity_country', 'NO')->count()))->toBe(2)
        ->and(Person::query()->pluck('id')->all())->toBe([$person->id]);
});
