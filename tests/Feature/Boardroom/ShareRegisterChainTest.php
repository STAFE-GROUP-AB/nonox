<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Boardroom\Domain\ChainVerifier;
use App\Modules\Boardroom\Domain\EventHash;
use App\Modules\Boardroom\Models\Register;
use App\Modules\Boardroom\Models\ShareEvent;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Boardroom;
use Tests\Support\Bookkeeping;

/*
 * The share register's hash chain and immutability (ADR-070 §2, the pattern
 * of ADR-007): every entry links to the one before, share-register:verify
 * finds a change or a removal, and the database refuses UPDATE, DELETE and
 * TRUNCATE whichever way they are sent.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    Boardroom::enable($this->company, $this->owner);
    $this->a = Boardroom::person($this->owner, $this->company, 'Anna Andersson', '19800101123');
    $this->b = Boardroom::person($this->owner, $this->company, 'Bertil Berg', '19750505123');
    $this->first = Boardroom::formation($this->owner, $this->company, $this->a);
    $this->second = Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-01-15', 'share_from' => 501, 'share_to' => 1000, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->b->id]);
    $this->third = Boardroom::record($this->owner, $this->company, ['kind' => 'CERTIFICATE', 'effective_date' => '2027-01-20', 'share_from' => 501, 'share_to' => 1000, 'certificate_issued' => true]);
});

/** Tampering straight in the database, with the trigger off inside the rolled-back test transaction. */
function tamperShareRegister(string $sql, array $bindings = []): void
{
    DB::statement('ALTER TABLE boardroom_share_events DISABLE TRIGGER USER');
    DB::statement($sql, $bindings);
    DB::statement('ALTER TABLE boardroom_share_events ENABLE TRIGGER USER');
}

it('links every entry to the one before it and keeps the head on the register row', function () {
    expect($this->first->previous_hash)->toBeNull()
        ->and($this->second->previous_hash)->toBe($this->first->hash)
        ->and($this->third->previous_hash)->toBe($this->second->hash)
        ->and(Register::query()->first()?->head_hash)->toBe($this->third->hash)
        ->and(Register::query()->first()?->last_sequence)->toBe(3)
        ->and(EventHash::of(ShareEvent::query()->findOrFail($this->second->id), $this->first->hash))->toBe($this->second->hash);
});

it('keeps the holder\'s details encrypted in the entry, and hashes them in clear text', function () {
    $raw = (string) DB::table('boardroom_share_events')->where('id', $this->first->id)->value('holder_snapshot');

    expect($raw)->not->toContain('Anna')->not->toContain('19800101')
        ->and(EventHash::canonicalJson($this->first))->toContain('Anna Andersson');
});

it('confirms an intact chain', function () {
    expect(app(ChainVerifier::class)->verify($this->company))->toBe(['ok' => true, 'entries' => 3, 'problems' => []]);
});

it('flags an entry changed with raw SQL, even with its own hash recomputed', function () {
    tamperShareRegister('update boardroom_share_events set share_from = 400 where id = ?', [$this->second->id]);

    expect(app(ChainVerifier::class)->verify($this->company)['problems'])->toContain('Händelse 2 har ändrats efter att den skrevs.');

    $forged = ShareEvent::query()->findOrFail($this->second->id);
    tamperShareRegister('update boardroom_share_events set hash = ? where id = ?', [EventHash::of($forged, $this->first->hash), $this->second->id]);

    expect(app(ChainVerifier::class)->verify($this->company)['problems'])->toContain('Kedjan är bruten före händelse 3. En händelse kan ha tagits bort.');
});

it('flags a removed entry, in the middle and at the end', function () {
    tamperShareRegister('delete from boardroom_share_events where id = ?', [$this->third->id]);
    expect(app(ChainVerifier::class)->verify($this->company)['problems'])->toContain('Kedjans sista länk stämmer inte med den sparade. En händelse kan ha tagits bort.');

    tamperShareRegister('delete from boardroom_share_events where id = ?', [$this->second->id]);
    expect(app(ChainVerifier::class)->verify($this->company)['problems'])->toContain('Kedjans sista länk stämmer inte med den sparade. En händelse kan ha tagits bort.');
});

it('refuses changes and deletes through Eloquent, the query builder and raw SQL', function () {
    $event = ShareEvent::query()->findOrFail($this->first->id);

    expect(fn () => $event->update(['note' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('boardroom_share_events')->where('id', $event->id)->update(['note' => 'x'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('boardroom_share_events')->where('id', $event->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::statement('update boardroom_share_events set note = ?', ['x'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::statement('delete from boardroom_share_events')))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::statement('truncate boardroom_share_events cascade')))->toThrow(QueryException::class);

    expect(ShareEvent::query()->count())->toBe(3);
});

it('reports through the command, records the result and runs every night', function () {
    $this->artisan('share-register:verify', ['company' => (string) $this->company->id])
        ->expectsOutputToContain('OK: 3 händelser, inga avvikelser.')
        ->assertSuccessful();

    tamperShareRegister("update boardroom_share_events set note = 'Ändrad' where id = ?", [$this->third->id]);

    $this->artisan('share-register:verify')
        ->expectsOutputToContain('Händelse 3 har ändrats efter att den skrevs.')
        ->assertFailed();

    expect(AuditLog::forCompany($this->company)->where('action', 'share_register.verified')->orderBy('id')->pluck('payload')->map(fn ($p) => $p['ok'])->all())->toBe([true, false]);

    $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command);
    expect($events->contains(fn ($command) => str_contains((string) $command, 'share-register:verify')))->toBeTrue();
});

it('writes ids and kinds to the audit log, never names or personal numbers', function () {
    $payloads = AuditLog::forCompany($this->company)->where('action', 'like', 'share_register.%')->pluck('payload')->toJson();

    expect($payloads)->not->toContain('Anna')->not->toContain('19800101')->toContain('TRANSFER');
});
