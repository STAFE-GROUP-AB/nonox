<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Email addresses are compared without regard to case (security review
 * 2026-11-01). Existing addresses are lowercased, except where that would
 * make two rows collide: those are left as they are and logged for a person
 * to sort out. No row is ever deleted. Users then get a unique index on
 * lower(email), but only when no such collision remains.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE users AS u SET email = lower(u.email)
            WHERE u.email <> lower(u.email)
              AND NOT EXISTS (SELECT 1 FROM users AS o WHERE o.id <> u.id AND lower(o.email) = lower(u.email))
            SQL);

        DB::statement(<<<'SQL'
            UPDATE team_invitations AS i SET email = lower(i.email)
            WHERE i.email <> lower(i.email)
              AND NOT EXISTS (
                  SELECT 1 FROM team_invitations AS o
                  WHERE o.id <> i.id AND o.team_id = i.team_id AND lower(o.email) = lower(i.email)
              )
            SQL);

        $invitationCollisions = DB::table('team_invitations')
            ->selectRaw("array_to_string(array_agg(id ORDER BY id), ',') AS ids")
            ->groupByRaw('team_id, lower(email)')
            ->havingRaw('count(*) > 1')
            ->pluck('ids');

        if ($invitationCollisions->isNotEmpty()) {
            Log::warning('Email lowercasing: invitations that differ only in case were left unchanged.', [
                'team_invitation_ids' => $invitationCollisions->all(),
            ]);
        }

        $userCollisions = DB::table('users')
            ->selectRaw("array_to_string(array_agg(id ORDER BY id), ',') AS ids")
            ->groupByRaw('lower(email)')
            ->havingRaw('count(*) > 1')
            ->pluck('ids');

        if ($userCollisions->isNotEmpty()) {
            Log::warning('Email lowercasing: users whose addresses differ only in case were left unchanged, and the unique index on lower(email) was not created. Merge or rename them, then create it.', [
                'user_ids' => $userCollisions->all(),
            ]);

            return;
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique ON users (lower(email))');
    }
};
