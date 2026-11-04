<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM calendar, review round (ADR-049):
 * - `cursor_started_at`: when the connection last read its calendar from the
 *   start. Microsoft's delta keeps the window of that first read, so it is
 *   started again after 30 days.
 * - `cancelled_remotely_at`: a deal's last open step that was cancelled in
 *   the calendar stays open in NONOX; the event map remembers the
 *   cancellation, so the event is not written back until the activity
 *   changes in NONOX.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_calendar_connections', function (Blueprint $table) {
            $table->timestampTz('cursor_started_at')->nullable();
        });

        Schema::table('crm_calendar_events', function (Blueprint $table) {
            $table->timestampTz('cancelled_remotely_at')->nullable();
        });
    }
};
