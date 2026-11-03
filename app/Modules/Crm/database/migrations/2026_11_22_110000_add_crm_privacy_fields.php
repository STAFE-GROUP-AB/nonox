<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GDPR in the CRM (ADR-043): when a contact person was given the art. 14
 * information, the "Kontakta inte" flag (objection, art. 21, or restriction,
 * art. 18), and the suppression list that keeps the flag when the person is
 * erased: a keyed hash of each e-mail address and phone number, never the
 * value itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_people', function (Blueprint $table) {
            $table->date('informed_at')->nullable();
            $table->timestampTz('do_not_contact_at')->nullable();
            // OBJECTION (art. 21) or RESTRICTION (art. 18).
            $table->string('do_not_contact_reason', 20)->nullable();
        });

        Schema::create('crm_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // EMAIL or PHONE.
            $table->string('kind', 10);
            // HMAC-SHA256 of the normalised value with the app key.
            $table->char('hash', 64);
            $table->string('reason', 20);
            $table->timestamps();

            $table->unique(['company_id', 'hash']);
        });
    }
};
