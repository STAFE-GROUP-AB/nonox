<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Demo\DemoMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
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
    /** The default connection before a demo job, to put back (one job at a time per worker). */
    private static ?string $before = null;

    public function boot(): void
    {
        DemoMode::rememberMainConnection();

        // Any request through Laravel's HTTP client while the default connection is the demo's: refused before it is sent.
        Http::globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
            if (DemoMode::active()) {
                throw new ConnectionException(DemoMode::message());
            }

            return $request;
        });

        // Any mail made in the demo database, and any mail to a guest's placeholder address, in any process.
        Event::listen(MessageSending::class, function (MessageSending $event): bool {
            if (DemoMode::active()) {
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

        // A job of the demo queue runs on the demo database, and the worker goes back to the main one after it.
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($event->connectionName === DemoMode::CONNECTION && self::$before === null) {
                self::$before = DB::getDefaultConnection();
                DB::setDefaultConnection(DemoMode::CONNECTION);
            }
        });

        Event::listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], function (): void {
            if (self::$before !== null) {
                DB::setDefaultConnection(self::$before);
                self::$before = null;
            }
        });
    }
}
