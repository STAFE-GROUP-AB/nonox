<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Restriction of processing (GDPR art. 18): while set, the entry is only
 * stored and cannot be used for new documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'suppliers'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->timestamp('processing_restricted_at')->nullable();
            });
        }
    }
};
