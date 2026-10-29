<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reminders (påminnelser) sent for overdue invoices: when, to whom, and the
 * new date to pay by. A record of what was sent, so it can only be added to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->unsignedSmallInteger('sequence');
            $table->date('sent_on');
            $table->date('pay_by');
            $table->bigInteger('remaining_ore');
            $table->string('sent_to');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['invoice_id', 'sequence']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER invoice_reminders_append_only
                BEFORE UPDATE OR DELETE ON invoice_reminders
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            SQL);
    }
};
