<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Test companies (Testföretag, ADR-032): made for trying NONOX and its API
 * with demo data. The flag never changes after creation; it marks the
 * company in the UI, keeps its documents from being e-mailed to anyone and
 * lets statistics and billing leave it out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('is_test_company')->default(false)->index();
        });
    }
};
