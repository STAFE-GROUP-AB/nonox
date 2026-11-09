<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kök (ADR-073): the waste log and plate-waste measurements, staff meals
 * and the benefit lines proposed to payroll from them, and the record of
 * which till rows have been drawn from stock through their recipe. The
 * waste log, the measurements and the staff-meal log are append-only like
 * the stock ledger: a wrong entry is corrected by a new one. Quantities are
 * thousandths, money whole öre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_waste_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('registered_on');
            // INGREDIENT (an article as it is) or DISH (a dish or sub-recipe, exploded through its recipe).
            $table->string('kind', 12);
            $table->foreignId('article_id')->constrained('articles');
            // Thousandths of the article's unit, or of portions for a dish. Negative on a correction.
            $table->bigInteger('quantity_milli');
            $table->foreignId('location_id')->constrained('stock_locations');
            // UTGANGET, FORDARVAT, BEREDNING, OVERPRODUKTION, FEL, SKANKT, OVRIGT
            $table->string('reason', 20);
            // The receiving organisation for SKANKT (an organisation, never a person).
            $table->string('recipient', 120)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('corrects_entry_id')->nullable()->constrained('kitchen_waste_entries');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'registered_on']);
            $table->unique('corrects_entry_id');
        });

        DB::statement('ALTER TABLE kitchen_waste_entries ADD CONSTRAINT kitchen_waste_entries_quantity_not_zero CHECK (quantity_milli <> 0)');

        Schema::create('kitchen_waste_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('measured_on');
            // Plate waste in thousandths of a kg (grams); no stock movement, the food left stock with the sale.
            $table->bigInteger('kg_milli');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'measured_on']);
        });

        DB::statement('ALTER TABLE kitchen_waste_measurements ADD CONSTRAINT kitchen_waste_measurements_positive CHECK (kg_milli > 0)');

        Schema::create('kitchen_staff_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('served_on');
            // BREAKFAST, LUNCH, DINNER
            $table->string('meal', 12);
            // The dish, or null for "valfri rätt" at the company's average portion cost.
            $table->foreignId('article_id')->nullable()->constrained('articles');
            // Number of meals in thousandths. Negative on a correction.
            $table->bigInteger('quantity_milli');
            // PERSONALMAT, REPRESENTATION, AGARE
            $table->string('reason', 20);
            // Named only while payroll is on (ADR-073 §7, §12).
            $table->foreignId('employee_id')->nullable()->constrained('employees');
            // What the employee pays per meal, by deduction from pay.
            $table->bigInteger('paid_per_meal_ore')->default(0);
            $table->foreignId('location_id')->nullable()->constrained('stock_locations');
            // Raw-material cost of the entry when it was registered: underlag only, never a VAT base.
            $table->bigInteger('raw_cost_ore')->nullable();
            $table->foreignId('corrects_meal_id')->nullable()->constrained('kitchen_staff_meals');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'served_on']);
            $table->index(['company_id', 'employee_id', 'served_on']);
            $table->unique('corrects_meal_id');
        });

        DB::statement('ALTER TABLE kitchen_staff_meals ADD CONSTRAINT kitchen_staff_meals_quantity_not_zero CHECK (quantity_milli <> 0)');

        Schema::create('kitchen_payroll_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->char('period', 7);
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('pay_code', 30);
            $table->decimal('quantity', 12, 3)->nullable();
            $table->bigInteger('amount_ore')->nullable();
            // Days, meals, what was paid and the cap per meal: the trail behind the line.
            $table->jsonb('details');
            // PROPOSED, APPROVED, REJECTED
            $table->string('status', 12);
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs');
            $table->foreignId('payroll_input_id')->nullable()->constrained('payroll_inputs')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'period', 'status']);
        });

        Schema::create('kitchen_consumption_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('business_date');
            $table->foreignId('location_id')->constrained('stock_locations');
            $table->unsignedInteger('sale_rows')->default(0);
            $table->unsignedInteger('movements')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'business_date']);
        });

        Schema::create('kitchen_sale_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // Each till row is drawn from stock exactly once.
            $table->foreignId('pos_sale_row_id')->unique()->constrained('pos_sale_rows');
            $table->foreignId('kitchen_consumption_run_id')->constrained('kitchen_consumption_runs');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('kitchen_consumption_waits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->date('business_date');
            $table->foreignId('location_id')->constrained('stock_locations');
            $table->foreignId('article_id')->constrained('articles');
            // How much the day's sales should have drawn, still waiting for stock.
            $table->bigInteger('quantity_milli');
            $table->foreignId('kitchen_consumption_run_id')->constrained('kitchen_consumption_runs');
            // WAITING, POSTED, DISMISSED
            $table->string('status', 12);
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        // The logs are records: never changed or removed (Lager's inventory_append_only guard).
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER kitchen_waste_entries_append_only
                BEFORE UPDATE OR DELETE ON kitchen_waste_entries
                FOR EACH ROW EXECUTE FUNCTION inventory_append_only();

            CREATE TRIGGER kitchen_waste_measurements_append_only
                BEFORE UPDATE OR DELETE ON kitchen_waste_measurements
                FOR EACH ROW EXECUTE FUNCTION inventory_append_only();

            CREATE TRIGGER kitchen_staff_meals_append_only
                BEFORE UPDATE OR DELETE ON kitchen_staff_meals
                FOR EACH ROW EXECUTE FUNCTION inventory_append_only();
        SQL);
    }
};
