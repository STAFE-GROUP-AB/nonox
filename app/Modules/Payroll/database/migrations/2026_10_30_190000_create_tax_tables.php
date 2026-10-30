<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Skatteverket's withholding tax tables (PAYROLL_MODULE.md B.7), read from
 * the official text file with its record layout (postbeskrivning allmänna
 * tabeller). Global, not per company. Each import keeps the file's SHA-256.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_table_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->enum('period_type', ['MONTH', 'TWO_WEEKS']);
            $table->string('file_name');
            $table->char('file_sha256', 64);
            $table->unsignedInteger('row_count');
            $table->unsignedSmallInteger('table_count');
            $table->timestamps();

            $table->unique(['year', 'period_type']);
        });

        Schema::create('tax_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_table_import_id')->constrained('tax_table_imports');
            $table->unsignedSmallInteger('year');
            $table->enum('period_type', ['MONTH', 'TWO_WEEKS']);
            $table->unsignedSmallInteger('table_no');
            $table->unsignedTinyInteger('column_no');
            // Whole kronor, as in the file; an open upper bound is null.
            $table->unsignedInteger('income_from');
            $table->unsignedInteger('income_to')->nullable();
            $table->enum('value_type', ['AMOUNT', 'PERCENT']);
            $table->unsignedInteger('value');

            $table->index(['year', 'period_type', 'table_no', 'column_no', 'income_from'], 'tax_tables_lookup');
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER tax_table_imports_append_only
                BEFORE UPDATE OR DELETE ON tax_table_imports
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();

            CREATE TRIGGER tax_tables_append_only
                BEFORE UPDATE OR DELETE ON tax_tables
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            SQL);
    }
};
