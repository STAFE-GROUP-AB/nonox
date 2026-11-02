<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foreign identity numbers in the staff ledger (9 kap. 5 §
 * skatteförfarandeförordningen: "motsvarande utländska nummer"). A person's
 * number may be one issued in another country: `identity_country` holds
 * the issuing country (ISO 3166-1 alpha-2) and is null for a Swedish
 * personal or coordination number. The same for an employer:
 * `employer_country` is null for a Swedish organisation number. The
 * employer number column is widened from 11 to 30 characters to hold a
 * foreign number; widening a varchar does not rewrite the rows, so the
 * append-only trigger on the entries is not touched. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_ledger_people', function (Blueprint $table) {
            $table->char('identity_country', 2)->nullable()->after('personal_number_index');
            $table->char('employer_country', 2)->nullable()->after('employer_org_number');
        });

        Schema::table('staff_ledger_people', function (Blueprint $table) {
            $table->string('employer_org_number', 30)->nullable()->change();
        });

        Schema::table('staff_ledger_entries', function (Blueprint $table) {
            $table->char('identity_country', 2)->nullable()->after('personal_number');
            $table->char('employer_country', 2)->nullable()->after('employer_org_number');
        });

        Schema::table('staff_ledger_entries', function (Blueprint $table) {
            $table->string('employer_org_number', 30)->nullable()->change();
        });
    }
};
