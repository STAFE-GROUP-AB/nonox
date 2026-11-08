<?php

declare(strict_types=1);

use App\Actions\Accounting\DeleteDraftVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Accounting\ImmutableVoucher;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.3: a posted voucher and its rows cannot be altered via the
 * policy, Eloquent, the query builder or raw SQL. (No API exists yet.)
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->voucher = Bookkeeping::post($this->owner, $this->company);
    $this->row = $this->voucher->rows()->first();
});

/**
 * Runs a statement in a savepoint and returns whether the database refused it.
 */
function refusedByDatabase(callable $statement): bool
{
    try {
        DB::transaction($statement);
    } catch (QueryException $exception) {
        return str_contains($exception->getMessage(), 'posted voucher')
            || str_contains($exception->getMessage(), 'cannot be truncated')
            // With the database roles (ADR-079 §6) the grant refuses TRUNCATE before the trigger runs.
            || str_contains($exception->getMessage(), 'permission denied for table');
    }

    return false;
}

it('refuses changes through the policy', function () {
    expect($this->owner->can('update', $this->voucher))->toBeFalse()
        ->and($this->owner->can('delete', $this->voucher))->toBeFalse()
        ->and($this->owner->can('post', $this->voucher))->toBeFalse()
        ->and(fn () => app(SaveDraftVoucher::class)($this->owner, $this->company, ['voucher_date' => '2026-03-01', 'description' => 'X', 'rows' => [['account' => 1930, 'debit_ore' => 1]]], $this->voucher))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteDraftVoucher::class)($this->owner, $this->voucher))->toThrow(AuthorizationException::class);
});

it('refuses changes through Eloquent', function () {
    expect(fn () => $this->voucher->update(['description' => 'Ändrad']))->toThrow(ImmutableVoucher::class)
        ->and(fn () => $this->voucher->delete())->toThrow(ImmutableVoucher::class)
        ->and(fn () => $this->row->update(['debit_ore' => 1]))->toThrow(ImmutableVoucher::class)
        ->and(fn () => $this->row->delete())->toThrow(ImmutableVoucher::class)
        ->and(fn () => VoucherRow::create(['voucher_id' => $this->voucher->id, 'account_id' => $this->row->account_id, 'debit_ore' => 1]))
        ->toThrow(ImmutableVoucher::class);
});

it('refuses changes through the query builder', function () {
    expect(refusedByDatabase(fn () => Voucher::query()->whereKey($this->voucher->id)->update(['description' => 'Ändrad'])))->toBeTrue()
        ->and(refusedByDatabase(fn () => Voucher::query()->whereKey($this->voucher->id)->delete()))->toBeTrue()
        ->and(refusedByDatabase(fn () => VoucherRow::query()->whereKey($this->row->id)->update(['debit_ore' => 1])))->toBeTrue()
        ->and(refusedByDatabase(fn () => VoucherRow::query()->whereKey($this->row->id)->delete()))->toBeTrue();
});

it('refuses changes through raw SQL', function () {
    $id = $this->voucher->id;

    expect(refusedByDatabase(fn () => DB::update('update vouchers set description = ? where id = ?', ['Ändrad', $id])))->toBeTrue()
        ->and(refusedByDatabase(fn () => DB::update('update vouchers set status = ? where id = ?', ['DRAFT', $id])))->toBeTrue()
        ->and(refusedByDatabase(fn () => DB::delete('delete from vouchers where id = ?', [$id])))->toBeTrue()
        ->and(refusedByDatabase(fn () => DB::update('update voucher_rows set debit_ore = 1 where voucher_id = ?', [$id])))->toBeTrue()
        ->and(refusedByDatabase(fn () => DB::delete('delete from voucher_rows where voucher_id = ?', [$id])))->toBeTrue()
        ->and(refusedByDatabase(fn () => DB::insert(
            'insert into voucher_rows (voucher_id, company_id, account_id, debit_ore, credit_ore) values (?, ?, ?, 1, 0)',
            [$id, $this->company->id, $this->row->account_id],
        )))->toBeTrue()
        ->and(refusedByDatabase(fn () => DB::statement('truncate voucher_rows cascade')))->toBeTrue()
        ->and(refusedByDatabase(fn () => DB::statement('truncate vouchers cascade')))->toBeTrue();

    expect($this->voucher->fresh()->description)->toBe('Försäljning')
        ->and($this->voucher->rows()->count())->toBe(2);
});

it('refuses to mark an unbalanced voucher as posted, even with raw SQL', function () {
    $draft = app(SaveDraftVoucher::class)($this->owner, $this->company, [
        'voucher_date' => '2026-03-01', 'description' => 'Obalans', 'rows' => [
            ['account' => 1930, 'debit_ore' => 100], ['account' => 3001, 'credit_ore' => 99],
        ],
    ]);

    $refused = false;

    try {
        DB::transaction(fn () => DB::update(
            "update vouchers set status = 'POSTED', number = 99, posted_at = now(), hash = repeat('0', 64) where id = ?",
            [$draft->id],
        ));
    } catch (QueryException $exception) {
        $refused = str_contains($exception->getMessage(), 'does not balance');
    }

    expect($refused)->toBeTrue();
});

it('allows linking the correcting voucher exactly once', function () {
    $correction = Bookkeeping::post($this->owner, $this->company);

    $this->voucher->forceFill(['corrected_by_voucher_id' => $correction->id])->save();

    expect($this->voucher->fresh()->corrected_by_voucher_id)->toBe($correction->id)
        ->and(refusedByDatabase(fn () => DB::update('update vouchers set corrected_by_voucher_id = null where id = ?', [$this->voucher->id])))->toBeTrue()
        ->and(fn () => $this->voucher->fresh()->forceFill(['corrected_by_voucher_id' => $this->voucher->id])->save())->toThrow(ImmutableVoucher::class);
});

it('still lets drafts be edited and deleted', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company);

    app(SaveDraftVoucher::class)($this->owner, $this->company, [
        'voucher_date' => '2026-04-01', 'description' => 'Ändrat utkast', 'rows' => [
            ['account' => 1930, 'debit_ore' => 5], ['account' => 3001, 'credit_ore' => 5],
        ],
    ], $draft);

    expect($draft->fresh()->description)->toBe('Ändrat utkast')
        ->and((int) $draft->rows()->sum('debit_ore'))->toBe(5);

    app(DeleteDraftVoucher::class)($this->owner, $draft);

    expect(Voucher::query()->find($draft->id))->toBeNull();
});
