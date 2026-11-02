<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The fastställelseintyg on the copy of the annual report filed with
 * Bolagsverket (ÅRL 8 kap. 3 §, #91): the day the meeting adopted the
 * income statement and balance sheet, its decision on the profit or loss,
 * and the board member or managing director who certifies it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annual_reports', function (Blueprint $table) {
            $table->date('adoption_date')->nullable();
            $table->text('adoption_decision')->nullable();
            $table->string('certifier_name')->nullable();
            $table->string('certifier_role')->nullable();
        });
    }
};
