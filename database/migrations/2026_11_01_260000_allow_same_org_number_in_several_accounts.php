<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A hosted installation serves many unrelated customers: a unique index
 * would let anyone lock a real company out by registering its number first.
 * Uniqueness is now a validation rule (per instance when self-hosted, per
 * user when hosted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropUnique(['org_number']);
            $table->index('org_number');
        });
    }
};
