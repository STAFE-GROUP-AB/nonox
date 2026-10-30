<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panels a user has chosen to hide, per company: a list of "panel:company
 * id" keys, for example "getting-started:12". Only presentation, never data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('hidden_panels')->nullable();
        });
    }
};
