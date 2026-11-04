<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM stage 5, review round (ADR-048): the account that put a mailing's
 * recipient in the segment, so {företag} names that company and not
 * another one the person is also at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_mailing_recipients', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->constrained('crm_accounts')->nullOnDelete();
        });
    }
};
