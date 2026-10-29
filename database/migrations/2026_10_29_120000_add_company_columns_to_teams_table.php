<?php

declare(strict_types=1);

use App\Domain\Company\AccountingMethod;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\VatPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('org_number', 11)->unique();
            $table->enum('legal_form', array_column(LegalForm::cases(), 'value'));
            $table->string('legal_name');
            $table->string('vat_number')->nullable();

            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->char('country', 2)->default('SE');

            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();

            $table->unsignedSmallInteger('fiscal_year_start_month')->default(1);
            $table->enum('accounting_method', array_column(AccountingMethod::cases(), 'value'));
            $table->enum('vat_period', array_column(VatPeriod::cases(), 'value'));
            $table->boolean('vat_registered')->default(true);
            $table->enum('reporting_framework', array_column(ReportingFramework::cases(), 'value'));
            $table->string('bas_version');
            $table->boolean('f_skatt')->default(true);
            $table->boolean('employer_registered')->default(false);
            $table->char('base_currency', 3)->default('SEK');

            $table->string('bankgiro')->nullable();
            $table->string('plusgiro')->nullable();
            $table->string('iban')->nullable();
            $table->string('bic')->nullable();

            $table->string('logo_path')->nullable();
            $table->date('locked_until_date')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }
};
