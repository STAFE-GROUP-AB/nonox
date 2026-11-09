<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Database\DatabaseRoles;
use App\Support\Demo\DemoDatabase;
use App\Support\Demo\DemoMode;
use App\Support\Demo\DemoSessionHandler;
use App\Support\Demo\DemoSessions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Psr\Http\Message\RequestInterface;

/**
 * The outer walls of the demo (ADR-077): whatever an Action forgets, work in
 * the demo database still cannot reach anything outside NONOX. The inner
 * walls are in the Actions themselves (invitations, payment files, new
 * companies) and in ModuleManager (the modules that talk to the outside
 * world). It also makes a queue worker for the demo queue run its jobs on
 * the demo database.
 */
final class DemoServiceProvider extends ServiceProvider
{
    /** @var array<int, true> the demo jobs that have entered the demo switch, by job object */
    private static array $entered = [];

    public function boot(): void
    {
        DemoMode::rememberMainConnection();

        // With separate database roles (ADR-079 §6) schema commands run only as the owner, except on the demo
        // database: it may be migrated on its own connection, and only when DemoDatabase::assertSafe() holds
        // (it throws with the reason otherwise). The grants are never applied there.
        DatabaseRoles::approveConnectionsUsing('demo', function (string $connection): bool {
            if ($connection !== DemoMode::CONNECTION) {
                return false;
            }

            DemoDatabase::assertSafe();

            return true;
        });

        // Any request through Laravel's HTTP client while the default connection is the demo's: refused before it is sent.
        Http::globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
            if (DemoMode::active()) {
                throw new ConnectionException(DemoMode::message());
            }

            return $request;
        });

        // Any mail made in the demo database, and any mail to a guest's placeholder address, in any process.
        Event::listen(MessageSending::class, function (MessageSending $event): bool {
            // Also while the demo is built: nothing a demo makes is mailed.
            if (DemoMode::onDemoDatabase()) {
                return false;
            }

            $suffix = '@'.DemoMode::GUEST_DOMAIN;

            foreach ([...$event->message->getTo(), ...$event->message->getCc(), ...$event->message->getBcc()] as $address) {
                if (str_ends_with(mb_strtolower($address->getAddress()), $suffix)) {
                    return false;
                }
            }

            return true;
        });

        // No API token in the demo database (ADR-077); the token page of a demo company is not a way out.
        PersonalAccessToken::creating(fn (PersonalAccessToken $token): bool => ! DemoMode::active());

        // A job of the demo queue runs with exactly the switch of a guest's request, and the worker goes back after it.
        // Only jobs of the demo connection, and each one leaves what it entered, also when jobs nest (a sync job in a job).
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($event->connectionName === DemoMode::CONNECTION) {
                self::$entered[spl_object_id($event->job)] = true;
                DemoMode::enter();
            }
        });

        Event::listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], function (JobProcessed|JobFailed|JobExceptionOccurred $event): void {
            $id = spl_object_id($event->job);

            if ($event->connectionName === DemoMode::CONNECTION && isset(self::$entered[$id])) {
                unset(self::$entered[$id]);
                DemoMode::leave();
            }
        });

        // The heartbeat of a running build: every query it makes keeps its "building" key alive (ADR-077).
        DB::listen(fn () => DemoSessions::heartbeat());

        // A guest's session handler stores no IP address and no browser name.
        Session::extend('demo-database', fn ($app): DemoSessionHandler => new DemoSessionHandler(
            $app['db']->connection(config('session.connection')),
            (string) config('session.table'),
            (int) config('session.lifetime'),
            $app,
        ));
    }
}
