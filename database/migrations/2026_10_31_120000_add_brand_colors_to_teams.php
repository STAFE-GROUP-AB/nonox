<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company's own colours for its documents (invoices, quotes, orders,
 * payslips) and the employee portal. The logo already has logo_path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->char('brand_color', 7)->nullable();
            $table->char('brand_accent_color', 7)->nullable();
        });
    }
};
