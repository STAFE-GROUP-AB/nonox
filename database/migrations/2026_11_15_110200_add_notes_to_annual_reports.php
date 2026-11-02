<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The K2 notes a person enters (ÅRL 5 kap. 14–16, 22 §§; BFNAR 2016:10
 * p. 18.13–18.15, 18.20A; #92):
 *
 * - pledged_assets: öre per kind of ställd säkerhet (PledgedAssetKind),
 *   with pledged_other_text describing "annan ställd säkerhet".
 * - contingent_liabilities_ore and _text: the sum of eventualförpliktelser.
 * - group_commitments: säkerheter, garantier and åtaganden for group and
 *   associated companies (5 kap. 16 §).
 * - events_after and events_effect_unknown: significant events after the
 *   end of the year, and that their effect cannot be estimated (18.20A).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annual_reports', function (Blueprint $table) {
            $table->json('pledged_assets')->nullable();
            $table->text('pledged_other_text')->nullable();
            $table->bigInteger('contingent_liabilities_ore')->nullable();
            $table->text('contingent_liabilities_text')->nullable();
            $table->text('group_commitments')->nullable();
            $table->text('events_after')->nullable();
            $table->boolean('events_effect_unknown')->default(false);
        });
    }
};
