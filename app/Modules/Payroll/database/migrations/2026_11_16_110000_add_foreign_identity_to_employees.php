<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payee without a Swedish personal or coordination number (SINK or
 * A-SINK) is declared by birth date, birthplace and citizenship (förordning
 * (2011:1261) om skatteförfarandet 6 kap. 1 § andra stycket), with name and
 * address (Skatteverket, rutor 222, 077, 078, 081, 216–220, 221, 230), and a
 * foreign tax identification number when there is one (rutor 252, 076).
 * The personal number may therefore be missing; the birth date column
 * already exists (AUD-LON-15, #74).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->text('personal_number')->nullable()->change();
            $table->string('birth_place', 50)->nullable()->after('birth_date');
            $table->char('birth_country', 2)->nullable()->after('birth_place');
            $table->char('citizenship', 2)->nullable()->after('birth_country');
            $table->text('foreign_tin')->nullable()->after('citizenship');
            $table->char('foreign_tin_country', 2)->nullable()->after('foreign_tin');
        });
    }
};
