<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Manufacturing\Actions\Workshop\PruneWorkshopTime;
use App\Modules\Manufacturing\Actions\Workshop\RecordTerminalTime;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopDevices;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopPeople;
use App\Modules\Manufacturing\Livewire\Workshop\TerminalAdminPage;
use App\Modules\Manufacturing\ManufacturingSettings;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\Manufacturing\Models\WorkshopTimeEntry;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Bookkeeping;
use Tests\Support\Manufacturing;
use Tests\Support\Workshop;

/*
 * Setting up the workshop terminal (ADR-071 §7): switching it on, pairing
 * tablets, the people with their code and consented photo, who may do it,
 * and the retention of the person on a time entry.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Manufacturing::company();
    $this->actingAs($this->owner);
    $this->fx = Manufacturing::fixtures($this->company, $this->owner);
    $this->fx['articles']['table']->forceFill(['is_stock_item' => true])->save();
});

it('is off until it is switched on, and then pairs tablets and adds people', function () {
    expect(ManufacturingSettings::terminalEnabled($this->company))->toBeFalse();
    expect(fn () => app(WorkshopDevices::class)->register($this->owner, $this->company, 'Snickeriet'))->toThrow(HttpException::class);

    Livewire::test(TerminalAdminPage::class)
        ->assertSee('Det här är produktionstid')
        ->assertSee('Avstängd')
        ->call('enable', true)
        ->assertSee('Påslagen')
        ->set('deviceName', 'Snickeriet')->call('registerDevice')
        ->assertSee('Koppla Snickeriet')->assertSee('/verkstad/terminal?kod=')
        ->set('personName', 'Anna Lind')->set('personCode', '2580')->call('addPerson')
        ->assertSee('Anna Lind')->assertSee('Kod vald');

    expect(ManufacturingSettings::terminalEnabled($this->company))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'like', 'manufacturing.terminal_%')->pluck('action')->all())->toContain('manufacturing.terminal_device_registered', 'manufacturing.terminal_person_created');
});

it('refuses weak codes, empty names and a person without consent for a photo', function () {
    Workshop::enable($this->company, $this->owner);

    Livewire::test(TerminalAdminPage::class)
        ->set('personName', 'Bo Berg')->set('personCode', '1234')->call('addPerson')->assertHasErrors('code')
        ->set('personName', '  ')->set('personCode', '2580')->call('addPerson')->assertHasErrors('name');

    $person = Workshop::person($this->owner, $this->company);
    $photo = UploadedFile::fake()->image('anna.jpg', 600, 400);

    expect(fn () => app(WorkshopPeople::class)->setPhoto($this->owner, $this->company, $person, $photo, false))->toThrow(ValidationException::class, 'samtyckt');
});

it('stores a consented photo as a small square on the private disk and removes it again', function () {
    Storage::fake('local');
    Workshop::enable($this->company, $this->owner);
    $person = Workshop::person($this->owner, $this->company);
    $people = app(WorkshopPeople::class);

    $people->setPhoto($this->owner, $this->company, $person, UploadedFile::fake()->image('anna.jpg', 600, 400), true);
    $person->refresh();

    expect($person->photo_path)->toStartWith("manufacturing/workshop-photos/{$this->company->id}/")->and($person->photo_consent_at)->not->toBeNull();
    Storage::disk('local')->assertExists($person->photo_path);
    [$width, $height] = getimagesizefromstring((string) Storage::disk('local')->get($person->photo_path));
    expect([$width, $height])->toBe([256, 256])->and(WorkshopPeople::photoDataUrl($person))->toStartWith('data:image/jpeg;base64,');

    $path = $person->photo_path;
    $people->removePhoto($this->owner, $this->company, $person);
    Storage::disk('local')->assertMissing($path);
    expect($person->refresh()->photo_path)->toBeNull()->and($person->photo_consent_at)->toBeNull();

    $people->setPhoto($this->owner, $this->company, $person, UploadedFile::fake()->image('anna.jpg'), true);
    $people->archive($this->owner, $this->company, $person, true);
    expect($person->refresh()->photo_path)->toBeNull();
    expect(fn () => $people->setPhoto($this->owner, $this->company, $person, UploadedFile::fake()->create('x.exe', 10), true))->toThrow(ValidationException::class);
});

it('deletes a person who never reported and archives one who did', function () {
    Workshop::enable($this->company, $this->owner);
    $people = app(WorkshopPeople::class);
    $idle = Workshop::person($this->owner, $this->company, 'Ingen Tid');
    $busy = Workshop::person($this->owner, $this->company, 'Har Tid');
    [$device] = Workshop::device($this->owner, $this->company);
    $order = Workshop::order($this);
    app(RecordTerminalTime::class)($device, $busy, $order->operations()->first()->id, 1, 0);

    expect($people->delete($this->owner, $this->company, $idle))->toBeTrue()
        ->and(WorkshopPerson::query()->find($idle->id))->toBeNull()
        ->and($people->delete($this->owner, $this->company, $busy))->toBeFalse()
        ->and($busy->refresh()->archived_at)->not->toBeNull()
        ->and(WorkshopTimeEntry::query()->count())->toBe(1);
});

it('lets a new code lift a lock-out', function () {
    Workshop::enable($this->company, $this->owner);
    $person = Workshop::person($this->owner, $this->company);
    $person->forceFill(['failed_attempts' => 3, 'locked_until' => CarbonImmutable::now()->addMinutes(4)])->save();

    app(WorkshopPeople::class)->setCode($this->owner, $this->company, $person, '7391');

    expect($person->refresh()->isLocked())->toBeFalse()->and($person->failed_attempts)->toBe(0)->and($person->codeMatches('7391'))->toBeTrue()->and($person->codeMatches('2580'))->toBeFalse();
});

it('gives the terminal to owner and admin only, and the analysis also to the accountant', function (string $role, bool $terminal, bool $analyse) {
    $user = Bookkeeping::member($this->company, $role);
    $this->company->unsetRelation('users');
    CurrentCompany::set($this->company);
    $gate = Gate::forUser($user);

    expect($gate->allows('terminal', [WorkshopDevice::class, $this->company]))->toBe($terminal, "{$role} terminal")
        ->and($gate->allows('analyse', [WorkshopDevice::class, $this->company]))->toBe($analyse, "{$role} analyse");

    Workshop::enable($this->company, $this->owner);
    $this->actingAs($user)->get(route('manufacturing.terminal.admin'))->assertStatus($terminal ? 200 : 403);

    if (! $terminal) {
        expect(fn () => app(WorkshopDevices::class)->register($user, $this->company, 'X'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(WorkshopPeople::class)->create($user, $this->company, ['name' => 'X']))->toThrow(AuthorizationException::class);
    }
})->with([
    'admin' => ['admin', true, true],
    'accountant' => ['accountant', false, true],
    'bookkeeper' => ['bookkeeper', false, false],
    'approver' => ['approver', false, false],
    'auditor' => ['auditor', false, false],
]);

it('answers 404 on the admin page and the analysis while the module is off', function () {
    app(ModuleManager::class)->disable('manufacturing', $this->company, $this->owner, confirmed: true);

    $this->get(route('manufacturing.terminal.admin'))->assertNotFound();
    $this->get(route('manufacturing.analysis'))->assertNotFound();
});

it('cuts time entries loose from their person after 24 months, and nothing else', function () {
    Workshop::enable($this->company, $this->owner);
    $person = Workshop::person($this->owner, $this->company);
    [$device] = Workshop::device($this->owner, $this->company);
    $operation = Workshop::order($this)->operations()->first();
    $record = app(RecordTerminalTime::class);

    $old = $record($device, $person, $operation->id, 1, 0);
    $this->travelTo(CarbonImmutable::parse('2028-11-09 10:00:00'));
    $recent = $record($device, $person, $operation->id, 0, 30);

    $this->travelTo(CarbonImmutable::parse('2028-11-11 10:00:00'));
    $result = app(PruneWorkshopTime::class)();

    expect($result)->toBe(['entries' => 1, 'people' => 0])
        ->and($old->refresh()->person_id)->toBeNull()->and($old->minutes)->toBe(60)->and($old->production_order_id)->toBe($operation->production_order_id)
        ->and($recent->refresh()->person_id)->toBe($person->id);

    // Run again: nothing more to do. And privacy:prune runs it.
    expect(app(PruneWorkshopTime::class)())->toBe(['entries' => 0, 'people' => 0]);
    $this->artisan('privacy:prune')->assertSuccessful()->expectsOutputToContain('workshop_time_entries');
});

it('removes an archived person with no entries left after retention', function () {
    Workshop::enable($this->company, $this->owner);
    $person = Workshop::person($this->owner, $this->company);
    app(WorkshopPeople::class)->archive($this->owner, $this->company, $person, true);

    $this->travelTo(CarbonImmutable::parse('2028-11-11 10:00:00'));

    expect(app(PruneWorkshopTime::class)())->toBe(['entries' => 0, 'people' => 1])
        ->and(WorkshopPerson::query()->count())->toBe(0);
});

it('exports a register extract for a person on the terminal, without the code', function () {
    Workshop::enable($this->company, $this->owner);
    $person = Workshop::person($this->owner, $this->company, 'Anna Lind', '2580');
    [$device] = Workshop::device($this->owner, $this->company, 'Snickeriet');
    $order = Workshop::order($this);
    $record = app(RecordTerminalTime::class);
    $entry = $record($device, $person, $order->operations()->first()->id, 1, 15);
    $record->correct($device, $person, $entry->id);
    $other = Workshop::person($this->owner, $this->company, 'Bo Berg', '1397');
    $record($device, $other, $order->operations()->first()->id, 0, 30);

    $response = $this->get(route('privacy.export.workshopPerson', $person))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="registerutdrag-verkstad-'.$person->id.'.json"');
    $json = $response->json();

    expect($json['person'])->toBe(['namn' => 'Anna Lind', 'arkiverad' => null, 'har_kod' => true])
        ->and($json['foto']['sparat'])->toBeFalse()
        ->and(collect($json['rapporterad_tid'])->pluck('minuter')->all())->toBe([75, -75])
        ->and($json['rapporterad_tid'][0])->toMatchArray(['order' => $order->reference(), 'arbetssteg' => 'Kapning', 'surfplatta' => 'Snickeriet', 'dag' => '2026-11-10'])
        ->and($json['rapporterad_tid'][1]['rattar_rapport'])->toBe($entry->id);

    $raw = (string) $response->getContent();
    expect($raw)->not->toContain('code_verifier')->not->toContain('code_salt')->not->toContain('Bo Berg')
        ->and(AuditLog::query()->where('action', 'privacy.exported')->where('payload->subject', 'workshop_person')->exists())->toBeTrue();
});

it('refuses the register extract to those who may not set the terminal up, and for another company\'s person', function () {
    Workshop::enable($this->company, $this->owner);
    $person = Workshop::person($this->owner, $this->company);

    $this->actingAs(Bookkeeping::member($this->company, 'accountant'))->get(route('privacy.export.workshopPerson', $person))->assertForbidden();

    ['owner' => $otherOwner, 'company' => $otherCompany] = Manufacturing::company();
    Workshop::enable($otherCompany, $otherOwner);
    $theirs = Workshop::person($otherOwner, $otherCompany, 'Annan Person');
    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);

    $this->get(route('privacy.export.workshopPerson', $theirs))->assertNotFound();
});
