<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Overtime and paid-out comp time are vacation-pay basis under procentregeln
 * (semesterlagen 16 b §) and variable pay under sammalöneregeln (16 a §
 * tredje stycket); the system pay codes were seeded without it. Gröna Riks
 * (§ 17 p. 1) and Detaljhandeln (§ 14.6 A) do not exclude overtime either.
 * Only system pay codes still at the old seeded value change; Visita–Unionen
 * and Handelns tjänstemannaavtal keep false (semesterlön is included in
 * their overtime pay). The vacation ledger reads the flag when it sums the
 * basis, so open earning years count paid overtime from now on; closed
 * semesterår keep their stored amounts.
 *
 * Enligt AI-utredning 2026-11-02, juristgranskning återstår.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pay_codes')
            ->where('is_system', true)
            ->where('is_vacation_basis', false)
            ->whereIn('code', ['OVERTID', 'KOMPTID', 'GR_OT_A', 'GR_OT_B', 'GR_OT_C', 'DH_OT_A', 'DH_OT_70', 'DH_OT_100'])
            ->update(['is_vacation_basis' => true, 'updated_at' => now()]);
    }
};
