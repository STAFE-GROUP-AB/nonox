<?php

declare(strict_types=1);

use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;

/*
 * The protect and check triggers look up other tables by name (the balance
 * check reads voucher_rows, the rows' trigger reads vouchers). A session may
 * create temporary tables, and pg_temp comes first in the search path unless
 * it is named, so a temporary table called vouchers or voucher_rows used to
 * shadow the real one inside the trigger function. Every trigger function now
 * runs with search_path = public, pg_temp. This holds for any database role,
 * with or without the database roles of ADR-079.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
});

/**
 * Creates the shadowing temporary table, or returns false when the role may not create
 * temporary tables at all (the database roles of ADR-079 revoke TEMP): the attack is then
 * refused one step earlier.
 */
function shadowWith(string $statement): bool
{
    $refused = refusal(fn () => DB::statement($statement));

    if ($refused === null) {
        return true;
    }

    expect($refused)->toContain('permission denied to create temporary tables');

    return false;
}

/** Runs the statement in a savepoint; returns the database's refusal, or null when it went through. */
function refusal(callable $statement): ?string
{
    try {
        DB::transaction($statement);
    } catch (QueryException $exception) {
        return $exception->getMessage();
    }

    return null;
}

it('pins the search path of every trigger function in the schema', function () {
    $functions = DB::select(<<<'SQL'
        select p.proname, p.proconfig from pg_proc p
        where p.pronamespace = current_schema()::regnamespace
          and p.prorettype = 'trigger'::regtype
          and not exists (select 1 from pg_depend d where d.classid = 'pg_proc'::regclass and d.objid = p.oid and d.deptype = 'e')
        order by p.proname
        SQL);

    expect($functions)->not->toBeEmpty();

    foreach ($functions as $function) {
        expect(str_replace(['{', '}', '"'], '', (string) $function->proconfig))->toBe('search_path=public, pg_temp', "{$function->proname}: declare SET search_path = public, pg_temp on every trigger function");
    }
});

it('refuses to change the rows of a posted voucher behind a temporary vouchers table', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company, 25000);

    if (! shadowWith('CREATE TEMPORARY TABLE vouchers (id uuid, status text)')) {
        return;
    }

    $message = refusal(fn () => DB::update(
        'update public.voucher_rows set debit_ore = debit_ore * 2, credit_ore = credit_ore * 2 where voucher_id = ?',
        [$voucher->id],
    ));

    expect($message)->toContain('cannot be changed')
        ->and((int) DB::scalar('select sum(debit_ore) from public.voucher_rows where voucher_id = ?', [$voucher->id]))->toBe(25000);
});

it('refuses to post an unbalanced one-row voucher behind a temporary voucher_rows table', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company, 25000);
    DB::delete('delete from public.voucher_rows where voucher_id = ? and credit_ore > 0', [$draft->id]);

    if (! shadowWith('CREATE TEMPORARY TABLE voucher_rows (voucher_id uuid, debit_ore bigint, credit_ore bigint)')) {
        return;
    }

    DB::insert('insert into pg_temp.voucher_rows values (?, 100, 0), (?, 0, 100)', [$draft->id, $draft->id]);

    $message = refusal(fn () => DB::update(
        "update public.vouchers set status = 'POSTED', number = 999, posted_at = now(), posted_by = ?, hash = repeat('0', 64) where id = ?",
        [$this->owner->id, $draft->id],
    ));

    expect($message)->toContain('needs at least two rows')
        ->and(Voucher::query()->findOrFail($draft->id)->status->value)->toBe('DRAFT');
});
