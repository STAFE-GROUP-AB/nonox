<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Semesterersättning with every salary instead of paid vacation days, for
 * employments where that is allowed (semesterlagen 5 §, or a collective
 * agreement for hourly staff).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employments', function (Blueprint $table) {
            $table->boolean('vacation_payout_with_salary')->default(false);
        });
    }
};
