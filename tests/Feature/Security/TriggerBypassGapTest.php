<?php

declare(strict_types=1);

use App\Domain\Accounting\LedgerHash;
use App\Domain\Accounting\LedgerVerifier;
use App\Models\FiscalYear;
use App\Models\Voucher;
use App\Support\Database\DatabaseRoles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;

/*
 * ADR-079 build step 1: proves the threat model's claim 4 against our own
 * schema. Code that runs with NONOX's database role (today the owner of the
 * tables, often a superuser) can switch the immutability triggers off and
 * rewrite a posted voucher, and can then recompute the unkeyed hash chain so
 * that ledger:verify reports a clean ledger.
 *
 * These tests DOCUMENT A KNOWN GAP (group `known-gap`) of the single-user mode:
 * one database user for the app and the migrations, which is still the default
 * (development, CI, self-hosted installs that have not switched). They assert
 * that the attack succeeds for such a user. Build step 2 (database roles,
 * ADR-079 §6) is the fix: DatabaseRolesTest runs every attack below as the
 * runtime role `nonox_app` and asserts that each one fails. When the suite
 * itself logs in as a role that cannot alter the ledger, these are skipped.
 *
 * Every statement runs inside the test transaction (DatabaseTransactions):
 * PostgreSQL DDL is transactional, so ALTER TABLE, DROP TRIGGER and CREATE OR
 * REPLACE FUNCTION are all rolled back with the test. LedgerTriggersIntactTest
 * checks that the triggers and functions are intact afterwards.
 */

pest()->group('known-gap');

beforeEach(function () {
    if (! DatabaseRoles::canAlterLedger(DB::connection())) {
        $this->markTestSkipped('The database roles are in use: this role cannot alter the ledger (DatabaseRolesTest).');
    }

    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);

    $this->first = Bookkeeping::post($this->owner, $this->company, 10000);
    $this->voucher = Bookkeeping::post($this->owner, $this->company, 25000);
    $this->last = Bookkeeping::post($this->owner, $this->company, 40000, series: 'B');
});

/** Doubles both rows of the voucher, so it still balances, straight in the database. */
function rewriteAmounts(Voucher $voucher): void
{
    DB::update('update voucher_rows set debit_ore = debit_ore * 2, credit_ore = credit_ore * 2 where voucher_id = ?', [$voucher->id]);
    DB::update('update vouchers set description = ? where id = ?', ['Skrivs om i efterhand', $voucher->id]);
}

function storedTotal(Voucher $voucher): int
{
    return (int) DB::scalar('select sum(debit_ore) from voucher_rows where voucher_id = ?', [$voucher->id]);
}

it('runs as a role that owns the ledger tables', function () {
    $owner = DB::scalar("select tableowner from pg_tables where schemaname = current_schema() and tablename = 'vouchers'");
    $superuser = (bool) DB::scalar('select rolsuper from pg_roles where rolname = current_user');

    // The precondition of the gap: the runtime role owns the tables (or is a superuser).
    expect($owner === DB::scalar('select current_user') || $superuser)->toBeTrue();
});

it('lets the runtime role disable the triggers and change a posted voucher', function () {
    DB::statement('ALTER TABLE vouchers DISABLE TRIGGER vouchers_protect_posted');
    DB::statement('ALTER TABLE voucher_rows DISABLE TRIGGER voucher_rows_protect_posted');

    rewriteAmounts($this->voucher);

    expect(storedTotal($this->voucher))->toBe(50000)
        ->and(DB::scalar('select description from vouchers where id = ?', [$this->voucher->id]))->toBe('Skrivs om i efterhand');
});

it('lets the runtime role drop the triggers and change a posted voucher', function () {
    DB::statement('DROP TRIGGER vouchers_protect_posted ON vouchers');
    DB::statement('DROP TRIGGER voucher_rows_protect_posted ON voucher_rows');

    rewriteAmounts($this->voucher);
    DB::delete('delete from voucher_rows where voucher_id = ?', [$this->last->id]);
    DB::delete('delete from vouchers where id = ?', [$this->last->id]);

    expect(storedTotal($this->voucher))->toBe(50000)
        ->and(DB::scalar('select count(*) from vouchers where id = ?', [$this->last->id]))->toBe(0);
});

it('lets the runtime role replace the protect functions with empty ones and change a posted voucher', function () {
    foreach (['vouchers_protect_posted', 'voucher_rows_protect_posted'] as $function) {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger
            LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            \$\$;
            SQL);
    }

    rewriteAmounts($this->voucher);

    expect(storedTotal($this->voucher))->toBe(50000)
        // The triggers are still there and enabled; only their bodies are gone.
        ->and(DB::scalar("select tgenabled from pg_trigger where tgname = 'vouchers_protect_posted'"))->toBe('O');
});

it('lets the runtime role recompute the hash chain so that ledger:verify passes', function () {
    DB::statement('ALTER TABLE vouchers DISABLE TRIGGER vouchers_protect_posted');
    DB::statement('ALTER TABLE voucher_rows DISABLE TRIGGER voucher_rows_protect_posted');

    rewriteAmounts($this->voucher);

    expect(app(LedgerVerifier::class)->verify($this->company)['ok'])->toBeFalse();

    // The chain is unkeyed: walk it from the start and write every hash again.
    $fiscalYear = FiscalYear::query()->sole();
    $vouchers = Voucher::query()->where('fiscal_year_id', $fiscalYear->id)->where('status', 'POSTED')
        ->with(['rows.account', 'series'])->get()->keyBy(fn (Voucher $voucher): string => $voucher->previous_hash ?? 'start');
    $oldPrevious = 'start';
    $newPrevious = null;

    while (($voucher = $vouchers->get($oldPrevious)) instanceof Voucher) {
        $oldPrevious = (string) $voucher->hash;
        $hash = LedgerHash::of($voucher, $newPrevious);
        DB::update('update vouchers set previous_hash = ?, hash = ? where id = ?', [$newPrevious, $hash, $voucher->id]);
        $newPrevious = $hash;
    }

    DB::update('update fiscal_years set ledger_head_hash = ? where id = ?', [$newPrevious, $fiscalYear->id]);

    expect(storedTotal($this->voucher))->toBe(50000)
        ->and(app(LedgerVerifier::class)->verify($this->company))->toBe(['ok' => true, 'vouchers' => 3, 'problems' => []]);

    $this->artisan('ledger:verify', ['company' => (string) $this->company->id])
        ->expectsOutputToContain('OK: 3 bokförda verifikationer, inga avvikelser.')
        ->assertSuccessful();
});

it('lets a superuser skip the triggers with session_replication_role', function () {
    // Not run: SET session_replication_role needs a superuser (or GRANT SET ON
    // PARAMETER), and step 1 only exercises what the owner role can do. It is
    // a PostgreSQL documentation fact (runtime-config-client), not proven here.
})->skip('Needs a superuser; ADR-079 step 1 does not run superuser-only scenarios.');
