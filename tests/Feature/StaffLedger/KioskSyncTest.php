<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Actions\KioskDevices;
use App\Modules\StaffLedger\Actions\PersonCredentials;
use App\Modules\StaffLedger\Actions\RecordKioskEvents;
use App\Modules\StaffLedger\Domain\EntryFlag;
use App\Modules\StaffLedger\Domain\EntryType;
use App\Modules\StaffLedger\Domain\LedgerQuery;
use App\Modules\StaffLedger\Domain\Pin;
use App\Modules\StaffLedger\Domain\QrToken;
use App\Modules\StaffLedger\Livewire\LocationsPage;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\StaffLedger;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    StaffLedger::enable($this->company, $this->owner);
    $this->location = StaffLedger::location($this->owner, $this->company);
    $this->anna = StaffLedger::person($this->owner, $this->company, '19900101123');
    $this->bo = StaffLedger::person($this->owner, $this->company, '19851212456', '1397', ['is_manager' => true]);
    [$this->device, $this->token] = StaffLedger::kiosk($this->owner, $this->company, $this->location);
    $this->lastHash = null;

    // Kiosk requests have no session: forget the test's company, the token decides.
    CurrentCompany::forget();
});

/**
 * An event as kiosk-core.js makes it: numbered, with the device's time and
 * linked into the device's own hash chain.
 */
function kioskEvent(object $test, int $personId, string $direction, int $sequence, string $occurredAt, string $method = 'KIOSK_PIN'): array
{
    $event = [
        'uuid' => (string) Str::uuid(),
        'person_id' => $personId,
        'location_id' => $test->location->id,
        'direction' => $direction,
        'method' => $method,
        'occurred_at' => $occurredAt,
        'sequence' => $sequence,
        'previous_client_hash' => $test->lastHash,
    ];
    $event['client_hash'] = hash('sha256', ($test->lastHash ?? '').RecordKioskEvents::clientCanonical($event, $test->location->id, $personId, $event['uuid'], $sequence));
    $test->lastHash = $event['client_hash'];

    return $event;
}

function sendEvents(object $test, array $events, ?string $sentAt = null): TestResponse
{
    return $test->withToken($test->token)->postJson(route('staff-ledger.kiosk.events'), [
        'sent_at' => $sentAt ?? CarbonImmutable::now()->toIso8601String(),
        'events' => $events,
    ]);
}

function ledgerEntries(object $test): Collection
{
    return CurrentCompany::run($test->company, fn () => Entry::query()->orderBy('id')->get());
}

it('computes the same device hash as the kiosk (tests/js/staff-ledger-kiosk.test.mjs)', function () {
    $fixed = ['direction' => 'IN', 'method' => 'KIOSK_PIN', 'occurred_at' => '2026-11-02T08:02:05+01:00'];

    expect(hash('sha256', RecordKioskEvents::clientCanonical($fixed, 3, 7, '6f1c2a3e-0b9d-4c55-9a77-2f0e4d1b8c01', 1)))
        ->toBe('acecf47a593bf3e1daf52e54709e304a96bd30eabac9e2d93b8669403d534762')
        ->and(Pin::verifier('2580', '00112233445566778899aabbccddeeff'))->toBe('d167fda4230a3fd774345ac86bcc02b9eb9e97c18db13fdd31c8d6084e0eb475');
});

it('pairs a kiosk once with a code that expires', function () {
    $devices = app(KioskDevices::class);
    [$device, $code] = CurrentCompany::run($this->company, fn () => $devices->register($this->owner, $this->company, $this->location, 'Kassan'));

    $this->postJson(route('staff-ledger.kiosk.pair'), ['code' => 'FEL-KOD1'])->assertStatus(422);
    $token = $this->postJson(route('staff-ledger.kiosk.pair'), ['code' => strtolower($code)])->assertOk()->json('token');
    $this->postJson(route('staff-ledger.kiosk.pair'), ['code' => $code])->assertStatus(422);

    expect($token)->toHaveLength(64)
        ->and(CurrentCompany::run($this->company, fn () => $device->fresh()->token_hash))->toBe(hash('sha256', $token));

    [, $late] = CurrentCompany::run($this->company, fn () => $devices->register($this->owner, $this->company, $this->location, 'Lagret'));
    $this->travel(KioskDevices::PAIRING_MINUTES + 1)->minutes();
    $this->postJson(route('staff-ledger.kiosk.pair'), ['code' => $late])->assertStatus(422);
});

it('connects a paired kiosk again as a new device and blocks the old token', function () {
    $devices = app(KioskDevices::class);
    [$device, $code] = CurrentCompany::run($this->company, fn () => $devices->register($this->owner, $this->company, $this->location, 'Entrén'));
    $old = $this->postJson(route('staff-ledger.kiosk.pair'), ['code' => $code])->json('token');

    [$new, $again] = CurrentCompany::run($this->company, fn () => $devices->replace($this->owner, $this->company, $device->fresh()));
    $token = $this->postJson(route('staff-ledger.kiosk.pair'), ['code' => $again])->assertOk()->json('token');

    expect($new->id)->not->toBe($device->id)
        ->and($new->name)->toBe('Entrén')
        ->and(CurrentCompany::run($this->company, fn () => $device->fresh()->revoked_at))->not->toBeNull();
    $this->withToken($old)->getJson(route('staff-ledger.kiosk.roster'))->assertStatus(401);
    $this->withToken($token)->getJson(route('staff-ledger.kiosk.roster'))->assertOk();
});

it('gives the kiosk its roster without PINs, and refuses unknown or revoked devices', function () {
    $roster = $this->withToken($this->token)->getJson(route('staff-ledger.kiosk.roster'))->assertOk()->json();

    expect($roster['location']['name'])->toBe('Restaurang Testgatan')
        ->and(collect($roster['people'])->pluck('name')->all())->toContain('Testa Person123', 'Testa Person456')
        ->and($roster['people'][0])->toHaveKeys(['pin_salt', 'pin_verifier', 'qr_hash'])->not->toHaveKey('pin')
        ->and(json_encode($roster))->not->toContain('2580')
        ->and($roster)->not->toHaveKey('sensitive')
        ->and(json_encode($roster))->not->toContain(substr(StaffLedger::personalNumber('19900101123'), 8))
        ->and($roster['sealed']['keys'])->toHaveCount(1)
        ->and($roster['sealed']['keys'][0]['person_id'])->toBe($this->bo->id)
        ->and($roster['iterations'])->toBe(Pin::ITERATIONS);

    $this->withToken('fel')->getJson(route('staff-ledger.kiosk.roster'))->assertUnauthorized();

    CurrentCompany::run($this->company, fn () => app(KioskDevices::class)->revoke($this->owner, $this->company, $this->device));
    $this->withToken($this->token)->getJson(route('staff-ledger.kiosk.roster'))->assertUnauthorized();
});

it('records check-ins with the device time and the received time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:02:30'));

    sendEvents($this, [kioskEvent($this, $this->anna->id, 'IN', 1, '2026-11-02T08:02:05+01:00')])
        ->assertOk()
        ->assertJson(['accepted' => [1], 'duplicates' => [], 'rejected' => [], 'last_sequence' => 1]);

    $entry = ledgerEntries($this)->sole();

    expect($entry->type)->toBe(EntryType::CheckIn)
        ->and($entry->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-11-02 08:02:05')
        ->and($entry->received_at->format('Y-m-d H:i:s'))->toBe('2026-11-02 08:02:30')
        ->and($entry->personal_number)->toBe(StaffLedger::personalNumber('19900101123'))
        ->and($entry->device_sequence)->toBe(1)
        ->and($entry->flags)->toBe([]);
});

it('accepts late offline events in order and resends idempotently', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00'));

    // Three days offline: the queue is sent out of order and then sent again.
    $events = [
        kioskEvent($this, $this->anna->id, 'IN', 1, '2026-11-02T08:00:00+01:00'),
        kioskEvent($this, $this->bo->id, 'IN', 2, '2026-11-02T09:00:00+01:00'),
        kioskEvent($this, $this->anna->id, 'OUT', 3, '2026-11-02T16:00:00+01:00'),
    ];

    sendEvents($this, array_reverse($events))->assertOk()->assertJson(['accepted' => [1, 2, 3]]);
    sendEvents($this, $events)->assertOk()->assertJson(['accepted' => [], 'duplicates' => [1, 2, 3]]);

    $entries = ledgerEntries($this);

    expect($entries)->toHaveCount(3)
        ->and($entries->pluck('device_sequence')->all())->toBe([1, 2, 3])
        ->and($entries->every(fn (Entry $entry) => in_array(EntryFlag::Late->value, $entry->flags, true)))->toBeTrue()
        ->and($entries->every(fn (Entry $entry) => ! in_array(EntryFlag::ClientChainBreak->value, $entry->flags, true)))->toBeTrue();

    $shifts = CurrentCompany::run($this->company, fn () => LedgerQuery::shifts($this->location->id, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-02 23:59')));
    expect(collect($shifts)->map(fn ($shift) => [$shift->personName, $shift->start?->format('H:i'), $shift->end?->format('H:i')])->all())
        ->toBe([['Testa Person123', '08:00', '16:00'], ['Testa Person456', '09:00', null]]);
});

it('flags clock skew, a skipped sequence number and a broken device chain, but keeps the events', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00'));

    // The kiosk's clock is 20 minutes behind.
    sendEvents($this, [kioskEvent($this, $this->anna->id, 'IN', 1, '2026-11-02T11:40:00+01:00')], '2026-11-02T11:40:01+01:00')->assertJson(['accepted' => [1]]);

    $gap = kioskEvent($this, $this->anna->id, 'OUT', 3, '2026-11-02T12:00:00+01:00');
    sendEvents($this, [$gap])->assertJson(['accepted' => [3]]);

    $broken = kioskEvent($this, $this->bo->id, 'IN', 4, '2026-11-02T12:00:00+01:00');
    $broken['client_hash'] = str_repeat('0', 64);
    sendEvents($this, [$broken])->assertJson(['accepted' => [4]]);

    // The missing number arrives later and fills the gap without moving the device back.
    $this->lastHash = null;
    sendEvents($this, [kioskEvent($this, $this->bo->id, 'OUT', 2, '2026-11-02T11:50:00+01:00')])->assertJson(['accepted' => [2], 'last_sequence' => 4]);

    $flags = ledgerEntries($this)->mapWithKeys(fn (Entry $entry) => [$entry->device_sequence => $entry->flags])->all();

    expect($flags[1])->toContain(EntryFlag::ClockSkew->value)
        ->and($flags[3])->toContain(EntryFlag::SequenceGap->value)
        ->and($flags[4])->toContain(EntryFlag::ClientChainBreak->value)
        ->and(ledgerEntries($this))->toHaveCount(4)
        ->and(ledgerEntries($this)->firstWhere('device_sequence', 1)->clock_skew_seconds)->toBe(1199);
});

it('rejects only what it cannot store', function () {
    $unknown = kioskEvent($this, 999_999, 'IN', 1, CarbonImmutable::now()->toIso8601String());
    $malformed = ['sequence' => 2, 'uuid' => 'x'];

    sendEvents($this, [$unknown, $malformed])->assertOk()->assertJson([
        'accepted' => [],
        'rejected' => [['sequence' => 1, 'reason' => 'unknown_person'], ['sequence' => 2, 'reason' => 'invalid']],
    ]);

    // A person of another company is unknown here.
    [$otherOwner, $other] = Bookkeeping::company();
    CurrentCompany::run($other, fn () => StaffLedger::enable($other, $otherOwner));
    $stranger = CurrentCompany::run($other, fn () => StaffLedger::person($otherOwner, $other, '19700101123'));
    CurrentCompany::forget();

    sendEvents($this, [kioskEvent($this, $stranger->id, 'IN', 3, CarbonImmutable::now()->toIso8601String())])->assertJson(['rejected' => [['sequence' => 3, 'reason' => 'unknown_person']]]);
    expect(ledgerEntries($this))->toBeEmpty();
});

it('answers 404 while the module is off', function () {
    CurrentCompany::run($this->company, fn () => app(ModuleManager::class)->disable(StaffLedgerModule::KEY, $this->company, $this->owner, confirmed: true));

    $this->withToken($this->token)->getJson(route('staff-ledger.kiosk.roster'))->assertNotFound();
    sendEvents($this, [kioskEvent($this, $this->anna->id, 'IN', 1, CarbonImmutable::now()->toIso8601String())])->assertNotFound();
});

it('answers a check-in well within the three-second budget', function () {
    // Warm up (route, config and the first query), then time a single live check-in.
    $this->withToken($this->token)->getJson(route('staff-ledger.kiosk.roster'))->assertOk();

    $started = hrtime(true);
    sendEvents($this, [kioskEvent($this, $this->anna->id, 'IN', 1, CarbonImmutable::now()->toIso8601String())])->assertJson(['accepted' => [1]]);
    $milliseconds = (hrtime(true) - $started) / 1_000_000;

    // The kiosk shows "Incheckad" before it sends anything; this is the server's share, kept far below the budget.
    expect($milliseconds)->toBeLessThan(500);
});

it('opens the control visit at the kiosk with a manager PIN and locks out after five wrong ones', function () {
    $this->withToken($this->token)->postJson(route('staff-ledger.kiosk.control-visit'), ['pin' => '2580'])->assertOk()->assertJson(['ok' => false]);

    $answer = $this->withToken($this->token)->postJson(route('staff-ledger.kiosk.control-visit'), ['pin' => '1397'])->assertOk()->json();
    expect($answer['ok'])->toBeTrue()->and($answer['sensitive']['people'][0]['personal_number'])->toMatch('/^\d{8}-\d{4}$/');

    expect(CurrentCompany::run($this->company, fn () => AuditLog::forCompany($this->company)->where('action', 'staff_ledger.control_visit_opened')->latest('id')->first()->payload))
        ->toMatchArray(['where' => 'kiosk', 'manager_person_id' => $this->bo->id]);

    foreach (range(1, 5) as $attempt) {
        $this->withToken($this->token)->postJson(route('staff-ledger.kiosk.control-visit'), ['pin' => '0000']);
    }

    $this->withToken($this->token)->postJson(route('staff-ledger.kiosk.control-visit'), ['pin' => '1397'])->assertStatus(429)->assertJson(['locked' => true]);
});

it('records a control visit opened offline when the kiosk syncs', function () {
    $this->withToken($this->token)->postJson(route('staff-ledger.kiosk.events'), [
        'sent_at' => CarbonImmutable::now()->toIso8601String(),
        'events' => [],
        'logs' => [['type' => 'control_visit_opened', 'person_id' => $this->bo->id, 'at' => '2026-11-02T10:00:00+01:00']],
    ])->assertOk();

    expect(CurrentCompany::run($this->company, fn () => AuditLog::forCompany($this->company)->where('action', 'staff_ledger.control_visit_opened')->sole()->payload))
        ->toMatchArray(['where' => 'kiosk_offline', 'manager_person_id' => $this->bo->id]);
});

it('recognises a personal QR code only for its own company, and a new code replaces the old', function () {
    $token = CurrentCompany::run($this->company, fn () => QrToken::for($this->anna->fresh()));

    expect(QrToken::personId($token, $this->company->id))->toBe($this->anna->id)
        ->and(QrToken::personId($token, $this->company->id + 1))->toBeNull()
        ->and(QrToken::personId(str_replace('.', '.x', $token), $this->company->id))->toBeNull();

    $roster = $this->withToken($this->token)->getJson(route('staff-ledger.kiosk.roster'))->json();
    expect(collect($roster['people'])->firstWhere('id', $this->anna->id)['qr_hash'])->toBe(hash('sha256', $token));

    CurrentCompany::run($this->company, fn () => app(PersonCredentials::class)->renewQr($this->owner, $this->company, $this->anna->fresh()));
    $roster = $this->withToken($this->token)->getJson(route('staff-ledger.kiosk.roster'))->json();
    expect(collect($roster['people'])->firstWhere('id', $this->anna->id)['qr_hash'])->not->toBe(hash('sha256', $token));
});

it('serves an installable, cacheable kiosk page with the camera allowed only there', function () {
    $this->get(route('staff-ledger.kiosk'))->assertOk()
        ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()')
        ->assertSee('kiosk-config', false)
        ->assertSee('Tryck på ditt namn')
        ->assertSee('اضغط على اسمك');

    $this->get(route('staff-ledger.kiosk.manifest'))->assertOk()->assertJson(['display' => 'fullscreen', 'scope' => '/personalliggare/kiosk/']);
    $this->get(route('staff-ledger.kiosk.worker'))->assertOk()
        ->assertHeader('Service-Worker-Allowed', '/personalliggare/kiosk/')
        ->assertSee('const PRECACHE', false)
        ->assertSee('/personalliggare/kiosk/start', false);

    $this->actingAs($this->owner)->get(route('staff-ledger.overview'))->assertOk()->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
});

it('removes kiosks: one without entries is deleted, one with entries is blocked and hidden', function () {
    $devices = app(KioskDevices::class);
    [$unused] = CurrentCompany::run($this->company, fn () => $devices->register($this->owner, $this->company, $this->location, 'Lagret'));
    sendEvents($this, [kioskEvent($this, $this->anna->id, 'IN', 1, CarbonImmutable::now()->subMinute()->toIso8601String())])->assertOk();

    CurrentCompany::run($this->company, function () use ($devices, $unused): void {
        $devices->remove($this->owner, $this->company, $unused);
        $devices->remove($this->owner, $this->company, $this->device->fresh());
    });

    $kept = CurrentCompany::run($this->company, fn () => $this->device->fresh());
    expect(CurrentCompany::run($this->company, fn () => $unused->fresh()))->toBeNull()
        ->and($kept->removed_at)->not->toBeNull()
        ->and($kept->revoked_at)->not->toBeNull()
        ->and(CurrentCompany::run($this->company, fn () => Entry::query()->where('device_id', $kept->id)->count()))->toBe(1)
        ->and(AuditLog::query()->where('action', 'staff_ledger.device_removed')->count())->toBe(2);
    $this->withToken($this->token)->getJson(route('staff-ledger.kiosk.roster'))->assertUnauthorized();

    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);
    Livewire::test(LocationsPage::class)->assertDontSee('Lagret')->assertDontSee($kept->name);

    $accountant = Bookkeeping::member($this->company, 'accountant');
    CurrentCompany::forget();
    expect(fn () => CurrentCompany::run($this->company, fn () => $devices->remove($accountant, $this->company, $kept)))->toThrow(AuthorizationException::class);
});

it('seals the offline control visit data so only a manager PIN opens it', function () {
    $sealed = $this->withToken($this->token)->getJson(route('staff-ledger.kiosk.roster'))->json('sealed');
    $entry = $sealed['keys'][0];
    $open = function (string $cipher, string $iv, string $key): string|false {
        $raw = (string) base64_decode($cipher);

        return openssl_decrypt(substr($raw, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, (string) base64_decode($iv), substr($raw, -16));
    };

    $dataKey = $open($entry['key'], $entry['iv'], (string) hex2bin(Pin::controlKey('1397', $entry['salt'])));
    $data = json_decode((string) $open($sealed['data'], $sealed['iv'], (string) $dataKey), true);

    expect($data['people'])->toHaveCount(2)
        ->and(collect($data['people'])->pluck('personal_number')->all())->toContain(substr(StaffLedger::personalNumber('19851212456'), 0, 8).'-'.substr(StaffLedger::personalNumber('19851212456'), 8))
        ->and($open($entry['key'], $entry['iv'], (string) hex2bin(Pin::controlKey('2580', $entry['salt']))))->toBeFalse();
});
