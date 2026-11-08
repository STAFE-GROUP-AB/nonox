<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * The immutability triggers on the ledger and their functions are in place
 * and enabled. TriggerBypassGapTest disables, drops and replaces them inside
 * its rolled-back test transactions; this proves nothing of that survives.
 */

it('keeps the immutability triggers enabled and their functions intact', function () {
    $triggers = collect(DB::select(<<<'SQL'
        select tgname, tgenabled from pg_trigger
        where not tgisinternal and tgrelid in ('vouchers'::regclass, 'voucher_rows'::regclass)
        SQL))->mapWithKeys(fn (object $row): array => [$row->tgname => $row->tgenabled])->sortKeys()->all();

    expect($triggers)->toBe([
        'voucher_rows_no_truncate' => 'O',
        'voucher_rows_protect_posted' => 'O',
        'vouchers_check_balance_on_post' => 'O',
        'vouchers_no_truncate' => 'O',
        'vouchers_protect_posted' => 'O',
    ]);

    foreach (['vouchers_protect_posted', 'voucher_rows_protect_posted'] as $function) {
        expect((string) DB::scalar('select prosrc from pg_proc where proname = ?', [$function]))->toContain('RAISE EXCEPTION');
    }
});
