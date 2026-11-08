<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A trip in the körjournal can be corrected once (ADR-072): the database
 * refuses a second correcting trip for the same original, also when two
 * corrections are sent at the same moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX vehicle_trips_corrects_trip_unique ON vehicle_trips (corrects_trip_id) WHERE corrects_trip_id IS NOT NULL');
    }
};
