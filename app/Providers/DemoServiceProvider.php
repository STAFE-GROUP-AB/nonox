<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Support\Demo\DemoMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Psr\Http\Message\RequestInterface;

/**
 * The outer walls of the demo (ADR-077): whatever an Action forgets, a demo
 * company still cannot reach anything outside NONOX. The inner walls are in
 * the Actions themselves (invitations, payment files, new companies) and in
 * ModuleManager (the modules that talk to the outside world).
 */
final class DemoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Any request through Laravel's HTTP client while a demo company is the current company: refused before it is sent.
        Http::globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
            if (DemoMode::active()) {
                throw new ConnectionException(DemoMode::message());
            }

            return $request;
        });

        // Any mail made while a demo is open, and any mail to a guest's placeholder address, in any process.
        Event::listen(MessageSending::class, function (MessageSending $event): bool {
            if (DemoMode::active() || DemoMode::guest()) {
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

        // A guest makes no API token (ADR-077); the token page of a demo company is not a way out.
        PersonalAccessToken::creating(function (PersonalAccessToken $token): bool {
            $owner = $token->tokenable;

            return ! ($owner instanceof User && $owner->isDemoGuest() && ! DemoMode::isSeeding());
        });
    }
}
