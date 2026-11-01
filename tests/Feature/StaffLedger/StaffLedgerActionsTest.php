<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Payroll\Actions\SaveEmployee;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\StaffLedger\Actions\AppendEntry;
use App\Modules\StaffLedger\Actions\CheckInFromPhone;
use App\Modules\StaffLedger\Actions\CorrectEntry;
use App\Modules\StaffLedger\Actions\PersonCredentials;
use App\Modules\StaffLedger\Actions\RecordManualEntry;
use App\Modules\StaffLedger\Actions\SaveLocation;
use App\Modules\StaffLedger\Actions\SavePerson;
use App\Modules\StaffLedger\Actions\TimeProposals;
use App\Modules\StaffLedger\Domain\CheckInMethod;
use App\Modules\StaffLedger\Domain\CorrectionKind;
use App\Modules\StaffLedger\Domain\Direction;
use App\Modules\StaffLedger\Domain\EntryType;
use App\Modules\StaffLedger\Domain\LedgerQuery;
use App\Modules\StaffLedger\Domain\Pin;
use App\Modules\StaffLedger\Livewire\LedgerPage;
use App\Modules\StaffLedger\Livewire\LocationsPage;
use App\Modules\StaffLedger\Livewire\MyCheckIn;
use App\Modules\StaffLedger\Livewire\PeoplePage;
use App\Modules\StaffLedger\Mail\ForgottenCheckOutMail;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\Models\TimeProposal;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;
use Tests\Support\StaffLedger;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    StaffLedger::enable($this->company, $this->owner);
    $this->location = StaffLedger::location($this->owner, $this->company);
    $this->person = StaffLedger::person($this->owner, $this->company);
});

function checkInAt(object $test, string $at, EntryType $type = EntryType::CheckIn, ?Person $person = null): Entry
{
    return app(AppendEntry::class)($test->location, $person ?? $test->person, ['type' => $type, 'method' => CheckInMethod::KioskPin, 'occurred_at' => CarbonImmutable::parse($at)]);
}

it('validates locations and requires coordinates for mobile check-in', function () {
    $save = app(SaveLocation::class);

    expect(fn () => StaffLedger::location($this->owner, $this->company, ['postal_code' => '12']))->toThrow(ValidationException::class)
        ->and(fn () => StaffLedger::location($this->owner, $this->company, ['business_type' => 'BYGG']))->toThrow(ValidationException::class)
        ->and(fn () => StaffLedger::location($this->owner, $this->company, ['mobile_enabled' => true, 'latitude' => '', 'longitude' => '']))->toThrow(ValidationException::class, 'Mobil incheckning kräver lokalens koordinater.')
        ->and(fn () => StaffLedger::location($this->owner, $this->company, ['kiosk_enabled' => false, 'mobile_enabled' => false]))->toThrow(ValidationException::class);

    $updated = $save($this->owner, $this->company, [...$this->location->only(['name', 'address_line1', 'postal_code', 'city', 'radius_meters']), 'business_type' => 'LAUNDRY', 'latitude' => '59,3293', 'longitude' => '18,0686', 'mobile_enabled' => true, 'kiosk_enabled' => true], $this->location);

    expect($updated->mobile_enabled)->toBeTrue()
        ->and($updated->fresh()->latitude)->toBe('59.329300')
        ->and(AuditLog::forCompany($this->company)->where('action', 'staff_ledger.location_updated')->sole()->payload['after'])->toMatchArray(['business_type' => 'LAUNDRY', 'mobile_enabled' => true]);
});

it('accepts a location without a ledger obligation, used for time reporting only', function () {
    $location = StaffLedger::location($this->owner, $this->company, ['business_type' => 'OTHER']);

    expect($location->business_type->label())->toBe('Övrig verksamhet (bara tidsredovisning)');
});

it('validates people: Luhn, duplicates and hired staff', function () {
    expect(fn () => StaffLedger::person($this->owner, $this->company, '19900101123'))->toThrow(ValidationException::class, 'Det finns redan en person med det numret i personalliggaren.')
        ->and(fn () => app(SavePerson::class)($this->owner, $this->company, ['first_name' => 'A', 'last_name' => 'B', 'personal_number' => '199001011230', 'language' => 'sv']))->toThrow(ValidationException::class)
        ->and(fn () => StaffLedger::person($this->owner, $this->company, '19850505123', null, ['hired' => true]))->toThrow(ValidationException::class)
        ->and(fn () => StaffLedger::person($this->owner, $this->company, '19850505123', null, ['language' => 'xx']))->toThrow(ValidationException::class);

    // A coordination number has 60 added to the day.
    $coordination = StaffLedger::person($this->owner, $this->company, '19850565123', null);
    $hired = StaffLedger::person($this->owner, $this->company, '19800101234', null, ['hired' => true, 'employer_name' => 'Bemanning Test AB', 'employer_org_number' => '5560160680']);

    expect($coordination->personal_number)->toBe(StaffLedger::personalNumber('19850565123'))
        ->and($hired->employer_org_number)->toBe('556016-0680')
        ->and(json_encode(AuditLog::forCompany($this->company)->where('action', 'staff_ledger.person_created')->pluck('payload')))->not->toContain('19800101');
});

it('stores a PIN only as a PBKDF2 verifier and refuses easy ones', function () {
    $credentials = app(PersonCredentials::class);

    expect(fn () => $credentials->setPin($this->owner, $this->company, $this->person, '1234'))->toThrow(ValidationException::class)
        ->and(fn () => $credentials->setPin($this->owner, $this->company, $this->person, '7777'))->toThrow(ValidationException::class)
        ->and(fn () => $credentials->setPin($this->owner, $this->company, $this->person, '12a4'))->toThrow(ValidationException::class);

    $credentials->setPin($this->owner, $this->company, $this->person, '4826');
    $person = $this->person->fresh();

    expect($person->pin_verifier)->toBe(Pin::verifier('4826', (string) $person->pin_salt))
        ->and(Pin::matches('4826', $person->pin_salt, $person->pin_verifier))->toBeTrue()
        ->and(Pin::matches('4827', $person->pin_salt, $person->pin_verifier))->toBeFalse()
        ->and(json_encode($person->getAttributes()))->not->toContain('4826');
});

it('keeps a photo only with consent and removes it again', function () {
    Storage::fake('local');
    $credentials = app(PersonCredentials::class);
    $file = UploadedFile::fake()->image('anna.jpg', 600, 400);

    expect(fn () => $credentials->setPhoto($this->owner, $this->company, $this->person, $file, false))->toThrow(ValidationException::class);

    $credentials->setPhoto($this->owner, $this->company, $this->person, $file, true);
    $person = $this->person->fresh();

    expect($person->photo_consent_at)->not->toBeNull()
        ->and(getimagesizefromstring((string) Storage::disk('local')->get($person->photo_path))[0])->toBe(PersonCredentials::PHOTO_SIZE)
        ->and(PersonCredentials::photoDataUrl($person))->toStartWith('data:image/jpeg;base64,');

    $credentials->removePhoto($this->owner, $this->company, $person);
    Storage::disk('local')->assertMissing($person->photo_path);
    expect($person->fresh()->photo_path)->toBeNull();
});

it('registers manual entries only with a reason and never in the future', function () {
    $record = app(RecordManualEntry::class);
    $now = CarbonImmutable::now();

    expect(fn () => $record($this->owner, $this->company, $this->person, $this->location, Direction::Out, $now->subHour(), ''))->toThrow(ValidationException::class)
        ->and(fn () => $record($this->owner, $this->company, $this->person, $this->location, Direction::Out, $now->addDay(), 'Glömde'))->toThrow(ValidationException::class);

    $entry = $record($this->owner, $this->company, $this->person, $this->location, Direction::Out, $now->subHour(), 'Glömde checka ut');

    expect($entry->type)->toBe(EntryType::Manual)
        ->and($entry->effectiveDirection())->toBe(Direction::Out)
        ->and($entry->user_id)->toBe($this->owner->id)
        ->and($entry->reason)->toBe('Glömde checka ut');

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    CurrentCompany::set($this->company);
    expect(fn () => $record($bookkeeper, $this->company, $this->person, $this->location, Direction::Out, $now, 'x y z'))->toThrow(AuthorizationException::class);
});

it('corrects with a new entry and never changes the original', function () {
    $in = checkInAt($this, '2026-10-30 08:00');
    $double = checkInAt($this, '2026-10-30 08:00:20', EntryType::CheckOut);
    $out = checkInAt($this, '2026-10-30 16:00', EntryType::CheckOut);
    $correct = app(CorrectEntry::class);

    $void = $correct($this->owner, $this->company, $double, CorrectionKind::Void, null, 'Dubbeltryck');
    $moved = $correct($this->owner, $this->company, $out, CorrectionKind::Time, CarbonImmutable::parse('2026-10-30 17:15'), 'Stängde');

    expect(fn () => $correct($this->owner, $this->company, $double, CorrectionKind::Void, null, 'Igen'))->toThrow(ValidationException::class)
        ->and(fn () => $correct($this->owner, $this->company, $void, CorrectionKind::Void, null, 'Rätta rättelse'))->toThrow(ValidationException::class)
        ->and(fn () => $correct($this->owner, $this->company, $in, CorrectionKind::Time, null, 'Utan tid'))->toThrow(ValidationException::class);

    $shifts = LedgerQuery::shifts($this->location->id, CarbonImmutable::parse('2026-10-30'), CarbonImmutable::parse('2026-10-30 23:59'));

    expect($shifts)->toHaveCount(1)
        ->and($shifts[0]->start?->format('H:i'))->toBe('08:00')
        ->and($shifts[0]->end?->format('H:i'))->toBe('17:15')
        ->and($shifts[0]->corrected())->toBeFalse()
        ->and($out->fresh()->occurred_at->format('H:i'))->toBe('16:00')
        ->and($moved->corrects_entry_id)->toBe($out->id)
        ->and($moved->person_name)->toBe($out->person_name);
});

it('checks in on the phone only inside the geofence and where it is turned on', function () {
    $staff = Bookkeeping::member($this->company, 'staff_ledger_staff');
    CurrentCompany::set($this->company);
    app(SavePerson::class)($this->owner, $this->company, [...$this->person->only(['first_name', 'last_name', 'language']), 'user_id' => $staff->id], $this->person);
    $person = $this->person->fresh();
    $checkIn = app(CheckInFromPhone::class);

    expect(fn () => $checkIn($staff, $person, $this->location, 59.3293, 18.0686, 10))->toThrow(ValidationException::class, 'Mobil incheckning är inte påslagen för lokalen.');

    $this->location->forceFill(['mobile_enabled' => true])->save();

    expect(fn () => $checkIn($staff, $person, $this->location, 59.3393, 18.0686, 10))->toThrow(ValidationException::class, 'Du är 1112 meter från lokalen. Checka in när du är på plats.')
        ->and(fn () => $checkIn($staff, $person, $this->location, 59.3293, 18.0686, 500))->toThrow(ValidationException::class)
        ->and(fn () => $checkIn($this->owner, $person, $this->location, 59.3293, 18.0686, 10))->toThrow(AuthorizationException::class);

    $in = $checkIn($staff, $person, $this->location, 59.3297, 18.0686, 20);
    $out = $checkIn($staff, $person, $this->location, 59.3293, 18.0686, 20);

    expect($in->type)->toBe(EntryType::CheckIn)
        ->and($in->method)->toBe(CheckInMethod::Mobile)
        ->and($in->geo_distance_meters)->toBe(44)
        ->and($out->type)->toBe(EntryType::CheckOut);

    $this->actingAs($staff);
    Livewire::test(MyCheckIn::class)
        ->assertSee('Checka in här')
        ->call('checkIn', $this->location->id, 59.3293, 18.0686, 15.0)
        ->assertDispatched('toast');
});

it('reminds about forgotten check-outs once and never checks anyone out', function () {
    Mail::fake();
    $this->person->forceFill(['email' => 'anna@example.test'])->save();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 22:00'));
    checkInAt($this, '2026-11-02 08:00');

    $this->artisan('personalliggare:remind')->assertSuccessful();
    $this->artisan('personalliggare:remind')->assertSuccessful();

    Mail::assertSent(ForgottenCheckOutMail::class, fn (ForgottenCheckOutMail $mail) => $mail->hasTo('anna@example.test') && $mail->toPerson);
    Mail::assertSent(ForgottenCheckOutMail::class, fn (ForgottenCheckOutMail $mail) => $mail->hasTo($this->owner->email) && ! $mail->toPerson);
    Mail::assertSentCount(2);

    expect(Entry::query()->count())->toBe(1)
        ->and(LedgerQuery::present())->toHaveCount(1);
});

it('proposes time reports from closed shifts and writes them only when approved', function () {
    Payroll::setUp($this->company, $this->owner);
    $employee = app(SaveEmployee::class)($this->owner, $this->company, [
        'first_name' => 'Testa', 'last_name' => 'Person123', 'personal_number' => StaffLedger::personalNumber('19900101123'),
        'tax_residence' => 'SE', 'tax_table' => 33, 'tax_column' => 1, 'start_date' => '2026-01-01',
        'employment_type' => 'TIMANSTALLD', 'salary_form' => 'HOURLY', 'salary' => '180',
    ]);
    app(SavePerson::class)($this->owner, $this->company, [...$this->person->only(['first_name', 'last_name', 'language']), 'employee_id' => $employee->id], $this->person);

    checkInAt($this, '2026-10-05 08:00');
    checkInAt($this, '2026-10-05 16:30', EntryType::CheckOut);
    checkInAt($this, '2026-10-06 09:00');

    $proposals = app(TimeProposals::class)->propose($this->owner, $this->company, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

    expect($proposals)->toHaveCount(1)
        ->and($proposals[0]->days)->toBe(['2026-10-05' => 8.5])
        ->and(TimeReport::query()->count())->toBe(0);

    expect(app(TimeProposals::class)->approve($this->owner, $this->company, $proposals[0]))->toBe(1)
        ->and(TimeReport::query()->sole()->only(['employee_id', 'status']))->toBe(['employee_id' => $employee->id, 'status' => 'APPROVED'])
        ->and($proposals[0]->fresh()->status)->toBe(TimeProposal::APPROVED)
        ->and(fn () => app(TimeProposals::class)->reject($this->owner, $this->company, $proposals[0]->fresh()))->toThrow(ValidationException::class);
});

it('shows toasts on the manager pages', function () {
    $this->actingAs($this->owner);

    Livewire::test(LocationsPage::class)
        ->call('create')
        ->set('form.name', 'Salongen')
        ->set('form.address_line1', 'Testvägen 2')
        ->set('form.postal_code', '22233')
        ->set('form.city', 'Teststad')
        ->set('form.business_type', 'BEAUTY_CARE')
        ->call('save')
        ->assertDispatched('toast', message: 'Lokalen är tillagd.')
        ->call('addDevice', $this->location->id)
        ->set('deviceName', 'Kassan')
        ->call('registerDevice')
        ->assertDispatched('toast')
        ->assertSee('Kopplingskod');

    Livewire::test(PeoplePage::class)
        ->call('create')
        ->set('form.first_name', 'Bo')
        ->set('form.last_name', 'Test')
        ->set('form.personal_number', StaffLedger::personalNumber('19700707123'))
        ->call('save')
        ->assertDispatched('toast', message: 'Bo Test är tillagd. Välj en kod för kiosken.')
        ->set('pin', '4826')
        ->call('savePin')
        ->assertDispatched('toast', message: 'Koden för Bo Test är sparad. Kiosken får den vid nästa uppdatering.')
        ->assertSee('197007');

    $entry = checkInAt($this, CarbonImmutable::now()->subHours(3)->toDateTimeString());

    Livewire::test(LedgerPage::class)
        ->assertSee('Testa Person123')
        ->call('openCorrection', $entry->id)
        ->set('correctionForm.kind', 'VOID')
        ->set('correctionForm.reason', 'Fel person')
        ->call('saveCorrection')
        ->assertDispatched('toast', message: 'Rättelsen är sparad. Den ursprungliga posten finns kvar och visas bredvid.')
        ->call('openManual', $this->person->id)
        ->set('manualForm.reason', 'Glömde')
        ->call('saveManual')
        ->assertDispatched('toast', message: 'Registreringen är sparad i liggaren med din orsak.');
});

it('masks personal numbers without the sensitive permission', function () {
    $accountant = Bookkeeping::member($this->company, 'accountant');
    CurrentCompany::forget();

    $this->actingAs($accountant)->get(route('staff-ledger.people'))->assertOk()
        ->assertSee('19900101-XXXX')
        ->assertDontSee(StaffLedger::personalNumber('19900101123'));

    $this->actingAs($this->owner)->get(route('staff-ledger.people'))->assertOk()->assertSee(substr(StaffLedger::personalNumber('19900101123'), 0, 8).'-'.substr(StaffLedger::personalNumber('19900101123'), 8));
});
