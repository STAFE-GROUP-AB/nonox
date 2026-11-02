<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A bonus is förfallen lön: semesterlagen 16 b § counts twelve per cent of
 * all förfallen lön in the earning year and excludes only semesterlön, pay
 * for semesterlönegrundande frånvaro and certain permitteringslön; under
 * sammalöneregeln a bonus is a rörlig lönedel (16 a § tredje stycket). The
 * system pay code BONUS was seeded without vacation basis. Only a system
 * BONUS still at the old seeded value changes; a company or agreement that
 * set it otherwise keeps its value. The vacation ledger reads the flag when
 * it sums the basis, so open earning years count paid bonuses from now on;
 * closed semesterår keep their stored amounts.
 *
 * Enligt granskning 2026-11-02, juristgranskning återstår (AUD-LON-10, #77).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pay_codes')
            ->where('is_system', true)
            ->where('is_vacation_basis', false)
            ->where('code', 'BONUS')
            ->update(['is_vacation_basis' => true, 'updated_at' => now()]);
    }
};
