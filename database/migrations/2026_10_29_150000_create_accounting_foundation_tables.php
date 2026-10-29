<?php

declare(strict_types=1);

use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\FiscalYearStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of accounts, fiscal years and voucher series (AGENTS.md §6.1, §6.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('name');
            $table->enum('type', array_column(AccountType::cases(), 'value'));
            $table->string('sru_code', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_cost_center')->default(false);
            $table->boolean('requires_project')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });

        DB::statement('ALTER TABLE accounts ADD CONSTRAINT accounts_number_range CHECK (number BETWEEN 1000 AND 9999)');

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', array_column(FiscalYearStatus::cases(), 'value'))->default(FiscalYearStatus::Open->value);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'start_date']);
        });

        DB::statement('ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_end_after_start CHECK (end_date > start_date)');

        Schema::create('voucher_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('code', 3);
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }
};
