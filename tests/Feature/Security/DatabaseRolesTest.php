<?php

declare(strict_types=1);

use App\Domain\Accounting\LedgerHash;
use App\Domain\Accounting\LedgerVerifier;
use App\Models\FiscalYear;
use App\Models\Voucher;
use App\Support\Database\DatabaseRoles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;
use Tests\Support\DatabaseRolesHarness;

/*
 * ADR-079 build step 2: TriggerBypassGapTest (group `known-gap`) proves that a
 * role owning the tables can switch the immutability triggers off. Here the same
 * attacks run as the runtime role nonox_app, after database/roles/roles.sql,
 * transfer-ownership.sql and the grants, and every one of them fails.
 *
 * The roles are created inside the test transaction and rolled back with it
 * (DatabaseRolesHarness); against a database already switched over, the suite
 * can also log in as nonox_app and the same tests run unchanged.
 */

pest()->group('database-roles');

beforeEach(function () {
    DatabaseRolesHarness::becomeApp();

    // Everything below runs as nonox_app: posting works with the app's grants.
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);

    $this->first = Bookkeeping::post($this->owner, $this->company, 10000);
    $this->voucher = Bookkeeping::post($this->owner, $this->company, 25000);
    $this->last = Bookkeeping::post($this->owner, $this->company, 40000, series: 'B');
});

/** The posted voucher's stored rows and description, untouched. */
function expectUntouched(Voucher $voucher, int $total): void
{
    expect((int) DB::scalar('select sum(debit_ore) from voucher_rows where voucher_id = ?', [$voucher->id]))->toBe($total)
        ->and(DB::scalar('select description from vouchers where id = ?', [$voucher->id]))->toBe('Försäljning')
        ->and(DB::scalar('select count(*) from vouchers where id = ?', [$voucher->id]))->toBe(1);
}

function expectRefused(?Throwable $exception, string $message): void
{
    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception?->getMessage())->toContain($message);
}

it('runs as a role that neither owns the ledger tables nor is a superuser', function () {
    expect(DatabaseRoles::canAlterLedger(DB::connection()))->toBeFalse()
        ->and((bool) DB::scalar('select rolsuper from pg_roles where rolname = current_user'))->toBeFalse()
        ->and((bool) DB::scalar("select has_schema_privilege(current_user, current_schema(), 'CREATE')"))->toBeFalse()
        ->and((bool) DB::scalar("select has_table_privilege(current_user, 'vouchers', 'TRIGGER')"))->toBeFalse()
        ->and((bool) DB::scalar("select has_table_privilege(current_user, 'vouchers', 'TRUNCATE')"))->toBeFalse();
});

it('refuses to disable the triggers, so a posted voucher cannot be changed', function () {
    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::statement('ALTER TABLE vouchers DISABLE TRIGGER vouchers_protect_posted')), 'must be owner of table vouchers');
    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::statement('ALTER TABLE voucher_rows DISABLE TRIGGER voucher_rows_protect_posted')), 'must be owner of table voucher_rows');
    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::statement('ALTER TABLE vouchers DISABLE TRIGGER ALL')), 'must be owner of table vouchers');

    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::update('update voucher_rows set debit_ore = debit_ore * 2, credit_ore = credit_ore * 2 where voucher_id = ?', [$this->voucher->id])), 'cannot be changed');
    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::update('update vouchers set description = ? where id = ?', ['Skrivs om i efterhand', $this->voucher->id])), 'cannot be changed');

    expectUntouched($this->voucher, 25000);
});

it('refuses to drop the triggers, so a posted voucher can be neither changed nor deleted', function () {
    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::statement('DROP TRIGGER vouchers_protect_posted ON vouchers')), 'must be owner of');
    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::statement('DROP TRIGGER voucher_rows_protect_posted ON voucher_rows')), 'must be owner of');

    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::delete('delete from voucher_rows where voucher_id = ?', [$this->last->id])), 'cannot be');
    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::delete('delete from vouchers where id = ?', [$this->last->id])), 'cannot be deleted');

    expect(DB::scalar('select count(*) from vouchers where id = ?', [$this->last->id]))->toBe(1);
    expectUntouched($this->voucher, 25000);
});

it('refuses to replace the protect functions with empty ones', function () {
    foreach (['vouchers_protect_posted', 'voucher_rows_protect_posted'] as $function) {
        $exception = DatabaseRolesHarness::attempt(fn () => DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger
            LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            \$\$;
            SQL));

        // Either refusal is enough: no CREATE on the schema, and not the function's owner.
        expect($exception)->toBeInstanceOf(QueryException::class)
            ->and($exception?->getMessage())->toMatch('/permission denied for schema|must be owner of function/');
        expect((string) DB::scalar('select prosrc from pg_proc where proname = ?', [$function]))->toContain('RAISE EXCEPTION');
    }

    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::update('update vouchers set description = ? where id = ?', ['Skrivs om i efterhand', $this->voucher->id])), 'cannot be changed');
    expectUntouched($this->voucher, 25000);
});

it('refuses to rewrite the hash chain, so ledger:verify keeps reporting a clean, unchanged ledger', function () {
    $fiscalYear = FiscalYear::query()->sole();
    $head = $fiscalYear->ledger_head_hash;
    $vouchers = Voucher::query()->where('fiscal_year_id', $fiscalYear->id)->where('status', 'POSTED')
        ->with(['rows.account', 'series'])->get()->keyBy(fn (Voucher $voucher): string => $voucher->previous_hash ?? 'start');
    $first = $vouchers->get('start');

    // Step 1's recomputation writes LedgerHash::of() of rewritten rows; the rows cannot be rewritten here,
    // so write any other hash: the first UPDATE of a posted voucher's hash is already refused.
    $exception = DatabaseRolesHarness::attempt(fn () => DB::update(
        'update vouchers set previous_hash = ?, hash = ? where id = ?',
        [null, hash('sha256', 'omskriven '.LedgerHash::of($first, null)), $first->id],
    ));

    expectRefused($exception, 'cannot be changed');
    expect(FiscalYear::query()->sole()->ledger_head_hash)->toBe($head)
        ->and(app(LedgerVerifier::class)->verify($this->company))->toBe(['ok' => true, 'vouchers' => 3, 'problems' => []]);
});

it('refuses TRUNCATE, schema changes and session_replication_role', function (string $statement, string $message) {
    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::unprepared($statement)), $message);

    expectUntouched($this->voucher, 25000);
})->with([
    'truncate vouchers' => ['TRUNCATE vouchers CASCADE', 'permission denied for table'],
    'truncate audit_logs' => ['TRUNCATE audit_logs', 'permission denied for table audit_logs'],
    'add a column' => ['ALTER TABLE vouchers ADD COLUMN backdoor text', 'must be owner of table vouchers'],
    'take the table' => ['ALTER TABLE vouchers OWNER TO CURRENT_USER', 'must be owner of table vouchers'],
    'drop the table' => ['DROP TABLE voucher_rows', 'must be owner of table voucher_rows'],
    'create a table' => ['CREATE TABLE backdoor (id int)', 'permission denied for schema'],
    'create a trigger' => ['CREATE TRIGGER backdoor BEFORE UPDATE ON vouchers FOR EACH ROW EXECUTE FUNCTION ledger_append_only()', 'permission denied for table vouchers'],
    'skip the triggers' => ['SET session_replication_role = replica', 'permission denied to set parameter'],
    'shadow a table with a temporary one' => ['CREATE TEMPORARY TABLE vouchers (id uuid, status text)', 'permission denied to create temporary tables'],
    'store a large object' => ['SELECT lo_create(0)', 'permission denied for function lo_create'],
]);

it('refuses to change or delete the audit log, by grant and not only by trigger', function () {
    expect(DB::scalar('select count(*) from audit_logs'))->toBeGreaterThan(0);

    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::update("update audit_logs set action = 'tampered'")), 'permission denied for table audit_logs');
    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::delete('delete from audit_logs')), 'permission denied for table audit_logs');
});

it('lets the app lock a lockable append-only table but not change it', function () {
    $reportId = DB::table('vat_reports')->insertGetId([
        'company_id' => $this->company->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'boxes' => '{}',
        'voucher_id' => $this->voucher->id,
        'created_by' => $this->owner->id,
    ]);

    expect(DB::select('select id from vat_reports where id = ? for update', [$reportId]))->toHaveCount(1);
    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::update('update vat_reports set company_id = company_id where id = ?', [$reportId])), 'permission denied for table vat_reports');
    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::update('update vat_reports set id = id where id = ?', [$reportId])), 'cannot be changed or removed');
    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::delete('delete from vat_reports where id = ?', [$reportId])), 'permission denied for table vat_reports');
});

it('lets the reader role read and nothing else', function () {
    if (! DB::scalar("select pg_has_role(session_user, 'nonox_readonly', 'MEMBER')")) {
        $this->markTestSkipped('Switching to the reader needs a superuser session.');
    }

    DB::statement('SET LOCAL ROLE '.DatabaseRoles::READONLY);

    expect(DB::scalar('select count(*) from vouchers where company_id = ?', [$this->company->id]))->toBe(3);
    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::insert("insert into cost_centers (company_id, code, name) values (?, 'X', 'X')", [$this->company->id])), 'permission denied for table cost_centers');
    expectRefused(DatabaseRolesHarness::attempt(fn () => DB::update('update teams set name = name')), 'permission denied for table teams');
});
