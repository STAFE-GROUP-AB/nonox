<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A web form submission that arrived while the form's or the company's
 * hourly cap was reached is held for review instead of dropped (ADR-048,
 * security review 2026-11-04): no person, account or task until someone
 * accepts it. What was typed is kept encrypted on the submission until then,
 * and a held submission is removed after 14 days (crm:prune-mail).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_form_submissions', function (Blueprint $table) {
            $table->timestampTz('quarantined_at')->nullable();
            $table->text('held_payload')->nullable();

            $table->index(['company_id', 'quarantined_at']);
        });
    }
};
