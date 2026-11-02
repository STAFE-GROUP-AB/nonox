<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The employee's own payment for a benefit (FORMAN_EGENBETALNING) was
 * credited to 2890 Övriga kortfristiga skulder, a debt nobody holds. It is
 * the company's recovery of the benefit's cost: FAR's guidance credits the
 * cost account or "konto 7388 Anställdas ersättning för erhållna förmåner".
 * Each company whose system pay code still books on 2890 gets account 7388
 * (when missing) and the pay code moves to it; a company that chose another
 * account keeps it. Vouchers already posted are not touched (they are
 * immutable); a balance left on 2890 is corrected with a voucher of its own.
 *
 * Enligt granskning 2026-11-02, juristgranskning återstår (AUD-LON-13, #80).
 */
return new class extends Migration
{
    public function up(): void
    {
        $codes = DB::table('pay_codes')
            ->join('accounts', 'accounts.id', '=', 'pay_codes.credit_account_id')
            ->where('pay_codes.code', 'FORMAN_EGENBETALNING')
            ->where('pay_codes.is_system', true)
            ->where('accounts.number', 2890)
            ->get(['pay_codes.id', 'pay_codes.company_id']);

        foreach ($codes as $code) {
            $accountId = DB::table('accounts')->where('company_id', $code->company_id)->where('number', 7388)->value('id')
                ?? DB::table('accounts')->insertGetId([
                    'company_id' => $code->company_id,
                    'number' => 7388,
                    'name' => 'Anställdas ersättning för erhållna förmåner',
                    'type' => 'EXPENSE',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('pay_codes')->where('id', $code->id)->update(['credit_account_id' => $accountId, 'updated_at' => now()]);
        }
    }
};
