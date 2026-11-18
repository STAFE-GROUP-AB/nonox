<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-081 gap 6, package E6: the project register (customer, project
 * manager, status, dates, price form, contract sum, estimated cost, default
 * cost centre) and cost centre and project on invoice, quote and order rows,
 * with defaults on the article and the customer. Additive only: nullable
 * columns, existing projects become ACTIVE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('ACTIVE');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('price_form', 30)->nullable();
            $table->bigInteger('contract_sum_ore')->nullable();
            $table->bigInteger('estimated_cost_ore')->nullable();
            $table->foreignId('default_cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();

            $table->index(['company_id', 'status']);
        });

        foreach (['invoice_rows', 'sales_document_rows'] as $rows) {
            Schema::table($rows, function (Blueprint $table) {
                $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
                $table->foreignId('project_id')->nullable()->constrained('projects');
            });
        }

        foreach (['articles', 'customers'] as $register) {
            Schema::table($register, function (Blueprint $table) {
                $table->foreignId('default_cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
                $table->foreignId('default_project_id')->nullable()->constrained('projects')->nullOnDelete();
            });
        }
    }
};
