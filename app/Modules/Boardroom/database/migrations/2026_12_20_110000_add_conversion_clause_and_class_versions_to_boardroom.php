<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Aktiebok och styrelserum (ADR-070, review 2026-11-09): the articles may
 * carry an omvandlingsförbehåll (ABL 4 kap. 6 §), which the register prints
 * with the other clauses (5 kap. 5 § 5), and a share class belongs to the
 * version of the articles that introduced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boardroom_articles', function (Blueprint $table) {
            $table->boolean('conversion_clause')->default(false)->after('redemption_clause');
        });

        Schema::table('boardroom_share_classes', function (Blueprint $table) {
            $table->foreignId('articles_version_id')->nullable()->after('company_id')->constrained('boardroom_articles')->nullOnDelete();
        });
    }
};
