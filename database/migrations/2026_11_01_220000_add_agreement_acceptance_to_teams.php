<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On the hosted service, who accepted the terms and the data processing
 * agreement (GDPR art. 28) for a company, when and which version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('agreement_accepted_at')->nullable();
            $table->string('agreement_version', 20)->nullable();
            $table->foreignId('agreement_accepted_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }
};
