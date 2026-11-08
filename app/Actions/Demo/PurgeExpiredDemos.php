<?php

declare(strict_types=1);

namespace App\Actions\Demo;

use App\Support\Demo\DemoDatabase;
use App\Support\Demo\DemoMode;
use App\Support\Demo\DemoSessions;
use Illuminate\Support\Facades\DB;

/**
 * Clears out expired demos (ADR-077, addendum 2026-11-08) by building the demo
 * database again, never by deleting rows. The demo database has every
 * immutability trigger of the real schema, and none of them has an
 * exception, so rows of posted vouchers cannot be removed one by one. Dropping
 * the schema of a database that holds nothing but demos can, and it is the
 * simplest thing that is also safe:
 *
 *  - it only ever runs against the demo connection, and only after
 *    DemoDatabase::assertSafe() has checked that the database is not the main
 *    one and that its name ends in _demo;
 *  - it waits until no demo is live and no build is running, so it cannot
 *    interrupt a visitor;
 *  - at most once a day (nonox.demo.rebuild_after_hours) it stops new demos
 *    from starting (draining) so that the last live ones expire and the
 *    rebuild can happen even on a busy day. A demo lasts two hours.
 */
final class PurgeExpiredDemos
{
    /**
     * @return array{live: int, expired: int, draining: bool, rebuilt: bool}
     *
     * @throws \RuntimeException when the demo connection is not a database that may be emptied
     */
    public function __invoke(): array
    {
        DemoDatabase::assertSafe();

        $sessions = fn () => DB::connection(DemoMode::CONNECTION)->table('demo_sessions');

        $live = $sessions()->where('expires_at', '>', now())->count();
        $expired = $sessions()->where('expires_at', '<=', now())->count();

        $builtAt = DemoDatabase::builtAt();

        if ($builtAt === null) {
            DemoDatabase::markBuilt();
            $builtAt = DemoDatabase::builtAt();
        }

        $due = $builtAt !== null && $builtAt->addHours((int) config('nonox.demo.rebuild_after_hours'))->lessThanOrEqualTo(now());

        if ($live > 0) {
            if ($due) {
                DemoDatabase::startDraining();
            }

            return ['live' => $live, 'expired' => $expired, 'draining' => DemoDatabase::draining(), 'rebuilt' => false];
        }

        if (($expired === 0 && ! DemoDatabase::draining()) || DemoSessions::building() > 0) {
            return ['live' => 0, 'expired' => $expired, 'draining' => DemoDatabase::draining(), 'rebuilt' => false];
        }

        DemoDatabase::rebuild();
        DemoDatabase::stopDraining();

        return ['live' => 0, 'expired' => $expired, 'draining' => false, 'rebuilt' => true];
    }
}
