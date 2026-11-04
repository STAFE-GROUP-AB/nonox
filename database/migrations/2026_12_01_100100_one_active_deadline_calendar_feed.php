<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One active Mina datum calendar link per user and company (ADR-051, stage
 * 2): two first links made at once (a double click) can no longer both stay
 * active. A partial unique index, so stopped links are not counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX deadline_calendar_feeds_one_active ON deadline_calendar_feeds (company_id, user_id) WHERE revoked_at IS NULL');
    }
};
