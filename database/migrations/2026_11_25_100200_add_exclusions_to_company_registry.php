<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company register after review (ADR-047):
 *
 * - company_registry_exclusions: an objection or an erasure request
 *   (GDPR art. 21 and 17) about data in the register. Applied before every
 *   write, so a re-import never brings the data back. ALL leaves the
 *   company out, NAME replaces its name, PHONE and EMAIL blank the field.
 * - A company that has left SCB's register is deleted at the next
 *   complete import instead of being marked, so gone_at is dropped.
 * - The import row keeps a warning (the opt-out sanity check).
 * - Search orders by name and organisation number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_registry_exclusions', function (Blueprint $table) {
            $table->id();
            $table->char('org_number', 10);
            // ALL, NAME, PHONE or EMAIL.
            $table->string('field', 10);
            // OBJECTION (art. 21) or ERASURE (art. 17); a code, never free text.
            $table->string('reason', 20);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['org_number', 'field']);
        });

        Schema::table('company_registry', function (Blueprint $table) {
            $table->dropColumn('gone_at');
            $table->index(['name', 'org_number']);
        });

        Schema::table('company_registry_imports', function (Blueprint $table) {
            $table->string('warning', 1000)->nullable();
        });
    }
};
