<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Modules\StaffLedger\Actions\AppendEntry;
use App\Modules\StaffLedger\Actions\CorrectEntry;
use App\Modules\StaffLedger\Actions\PruneStaffLedger;
use App\Modules\StaffLedger\Actions\RecordManualEntry;
use App\Modules\StaffLedger\Domain\ChainVerifier;
use App\Modules\StaffLedger\Domain\CheckInMethod;
use App\Modules\StaffLedger\Domain\CorrectionKind;
use App\Modules\StaffLedger\Domain\Direction;
use App\Modules\StaffLedger\Domain\EntryHash;
use App\Modules\StaffLedger\Domain\EntryType;
use App\Modules\StaffLedger\Domain\Retention;
use App\Modules\StaffLedger\Models\Entry;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;
use Tests\Support\StaffLedger;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    StaffLedger::enable($this->company, $this->owner);
    $this->location = StaffLedger::location($this->owner, $this->company);
    $this->person = StaffLedger::person($this->owner, $this->company);

    $append = fn (EntryType $type, string $at) => app(AppendEntry::class)($this->location, $this->person, [
        'type' => $type, 'method' => CheckInMethod::KioskPin, 'occurred_at' => CarbonImmutable::parse($at),
    ]);

    $this->in = $append(EntryType::CheckIn, '2026-10-30 08:00:00');
    $this->out = $append(EntryType::CheckOut, '2026-10-30 16:30:00');
    $this->correction = app(CorrectEntry::class)($this->owner, $this->company, $this->out, CorrectionKind::Time, CarbonImmutable::parse('2026-10-30 17:00:00'), 'Stängde kassan');
});

/**
 * Tampering straight in the database. The append-only trigger is switched
 * off inside the test transaction, which rolls everything back afterwards
 * (the same pattern as the voucher chain tests).
 */
function tamperStaffLedger(string $sql, array $bindings = []): void
{
    DB::statement('ALTER TABLE staff_ledger_entries DISABLE TRIGGER USER');
    DB::statement($sql, $bindings);
    DB::statement('ALTER TABLE staff_ledger_entries ENABLE TRIGGER USER');
}

it('links every entry to the one before it and keeps the head on the location', function () {
    expect($this->in->previous_hash)->toBeNull()
        ->and($this->out->previous_hash)->toBe($this->in->hash)
        ->and($this->correction->previous_hash)->toBe($this->out->hash)
        ->and($this->location->fresh()->ledger_head_hash)->toBe($this->correction->hash)
        ->and(EntryHash::of(Entry::query()->findOrFail($this->out->id), $this->in->hash))->toBe($this->out->hash);
});

it('confirms an intact chain', function () {
    expect(app(ChainVerifier::class)->verify($this->company))->toBe(['ok' => true, 'entries' => 3, 'problems' => []]);
});

it('flags an entry changed in the database with raw SQL', function () {
    tamperStaffLedger("update staff_ledger_entries set occurred_at = '2026-10-30 15:00:00' where id = ?", [$this->out->id]);

    $result = app(ChainVerifier::class)->verify($this->company);

    expect($result['ok'])->toBeFalse()
        ->and($result['problems'])->toContain("Lokal Restaurang Testgatan: post {$this->out->id} har ändrats efter att den skrevs.");
});

it('flags a changed name, even when the hash is recomputed without the chain', function () {
    tamperStaffLedger("update staff_ledger_entries set person_name = 'Någon annan' where id = ?", [$this->in->id]);

    expect(app(ChainVerifier::class)->verify($this->company)['problems'])->toContain("Lokal Restaurang Testgatan: post {$this->in->id} har ändrats efter att den skrevs.");

    // Even a forger who recomputes this row's hash breaks the link to the next one.
    $forged = Entry::query()->findOrFail($this->in->id);
    tamperStaffLedger('update staff_ledger_entries set hash = ? where id = ?', [EntryHash::of($forged, null), $this->in->id]);

    expect(app(ChainVerifier::class)->verify($this->company)['problems'])->toContain("Lokal Restaurang Testgatan: kedjan är bruten före post {$this->out->id}. En post kan ha tagits bort.");
});

it('flags a removed entry, in the middle and at the end', function () {
    tamperStaffLedger('delete from staff_ledger_entries where id = ?', [$this->out->id]);
    expect(app(ChainVerifier::class)->verify($this->company)['problems'])->toContain("Lokal Restaurang Testgatan: kedjan är bruten före post {$this->correction->id}. En post kan ha tagits bort.");

    tamperStaffLedger('delete from staff_ledger_entries where id = ?', [$this->correction->id]);
    expect(app(ChainVerifier::class)->verify($this->company)['problems'])->toContain('Lokal Restaurang Testgatan: kedjans sista länk stämmer inte med den sparade. En post kan ha tagits bort.');
});

it('refuses changes and deletes through Eloquent, the query builder and raw SQL', function () {
    $entry = Entry::query()->findOrFail($this->in->id);

    expect(fn () => $entry->update(['reason' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('staff_ledger_entries')->where('id', $entry->id)->update(['reason' => 'x'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('staff_ledger_entries')->where('id', $entry->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::statement('delete from staff_ledger_entries')))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::statement('truncate staff_ledger_entries cascade')))->toThrow(QueryException::class);

    expect(Entry::query()->count())->toBe(3);
});

it('reports through the command, records the result and runs every night', function () {
    $this->artisan('personalliggare:verify', ['company' => (string) $this->company->id])
        ->expectsOutputToContain('OK: 3 poster, inga avvikelser.')
        ->assertSuccessful();

    tamperStaffLedger("update staff_ledger_entries set reason = 'Ändrad' where id = ?", [$this->correction->id]);

    $this->artisan('personalliggare:verify')
        ->expectsOutputToContain("post {$this->correction->id} har ändrats efter att den skrevs")
        ->assertFailed();

    expect(AuditLog::forCompany($this->company)->where('action', 'staff_ledger.verified')->orderBy('id')->pluck('payload')->map(fn ($p) => $p['ok'])->all())->toBe([true, false]);

    $events = collect(app(Schedule::class)->events());
    expect($events->contains(fn ($event) => str_contains($event->command, 'personalliggare:verify') && $event->expression === '30 3 * * *'))->toBeTrue()
        ->and($events->contains(fn ($event) => str_contains($event->command, 'personalliggare:remind')))->toBeTrue();
});

it('prunes from the old end after the retention period and still verifies', function () {
    // The trigger checks retention by the database's own clock, so the pruned entries are from years back.
    FiscalYear::query()->create(['company_id' => $this->company->id, 'start_date' => '2019-01-01', 'end_date' => '2019-12-31']);
    $old = StaffLedger::location($this->owner, $this->company, ['name' => 'Gamla lokalen']);
    $at = fn (string $time) => CarbonImmutable::parse($time);

    $this->travelTo($at('2019-10-30 08:00'));
    app(AppendEntry::class)($old, $this->person, ['type' => EntryType::CheckIn, 'method' => CheckInMethod::KioskPin, 'occurred_at' => $at('2019-10-30 08:00')]);
    $this->travelTo($at('2019-10-30 16:30'));
    $out = app(AppendEntry::class)($old, $this->person, ['type' => EntryType::CheckOut, 'method' => CheckInMethod::KioskPin, 'occurred_at' => $at('2019-10-30 16:30')]);
    $this->travelBack();
    $later = app(RecordManualEntry::class)($this->owner, $this->company, $this->person, $old, Direction::In, CarbonImmutable::now()->subMinute(), 'Glömde checka in');

    $result = app(PruneStaffLedger::class)();

    expect($result['entries'])->toBe(2)
        ->and(Entry::query()->where('location_id', $old->id)->pluck('id')->all())->toBe([$later->id])
        ->and($old->fresh()->chain_start_hash)->toBe($out->hash)
        ->and(Entry::query()->where('location_id', $this->location->id)->count())->toBe(3)
        ->and(app(ChainVerifier::class)->verify($this->company))->toBe(['ok' => true, 'entries' => 4, 'problems' => []]);

    // Moving the anchor without a recorded prune is caught.
    $old->fresh()->forceFill(['chain_start_hash' => $later->hash])->save();
    expect(app(ChainVerifier::class)->verify($this->company)['problems'])->toContain('Lokal Gamla lokalen: kedjans början stämmer inte med den senaste gallringen.');
});

it('keeps entries inside the retention period', function () {
    // The entries are from the 2026 fiscal year (calendar year): kept through 2028-12-31.
    expect(Retention::earliestKept(CarbonImmutable::parse('2028-12-31'))->toDateString())->toBe('2026-01-01')
        ->and(app(PruneStaffLedger::class)(CarbonImmutable::parse('2028-12-31'))['entries'])->toBe(0)
        ->and(Entry::query()->count())->toBe(3);
});
