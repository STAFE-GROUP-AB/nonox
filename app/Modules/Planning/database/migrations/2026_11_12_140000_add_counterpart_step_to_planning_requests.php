<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A swap asks the colleague first: it waits as AWAITING_COUNTERPART until
 * the colleague accepts (then PENDING for the planner) or declines
 * (DECLINED). When and how the colleague answered is kept on the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planning_requests', function (Blueprint $table) {
            $table->string('status', 24)->default('PENDING')->change();
            $table->timestampTz('counterpart_answered_at')->nullable();
            $table->foreignId('counterpart_answered_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement('ALTER TABLE planning_requests DROP CONSTRAINT planning_requests_status');
        DB::statement("ALTER TABLE planning_requests ADD CONSTRAINT planning_requests_status CHECK (status IN ('AWAITING_COUNTERPART', 'PENDING', 'APPROVED', 'REJECTED', 'DECLINED', 'WITHDRAWN'))");
        DB::statement("ALTER TABLE planning_requests ADD CONSTRAINT planning_requests_counterpart_step CHECK (status <> 'AWAITING_COUNTERPART' OR kind = 'SWAP')");

        // One open request (with the colleague or the planner) per employee and shift.
        DB::statement('DROP INDEX planning_requests_one_pending');
        DB::statement("CREATE UNIQUE INDEX planning_requests_one_open ON planning_requests (shift_id, requested_by) WHERE status IN ('AWAITING_COUNTERPART', 'PENDING')");
    }
};
