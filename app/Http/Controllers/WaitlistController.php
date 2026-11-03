<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Instance\InstanceSettings;
use App\Support\Landing\LandingPage;
use Illuminate\View\View;

/**
 * "Ställ dig i kö" (/ko, ADR-038): the waiting list of the open beta, on
 * installations with public pages. An installation without them has no
 * queue; its users are invited by the companies they work for.
 */
class WaitlistController
{
    public function __invoke(): View
    {
        abort_unless((bool) config('app.landing.enabled'), 404);

        return view('start.waitlist', [
            ...LandingPage::data(),
            // HQ can close the queue (ADR-044): the page then says so and shows no form.
            'closed' => ! InstanceSettings::current()->waitlistOpen(),
            'registrationOpen' => InstanceSettings::current()->selfRegistrationOpen(),
            'pageTitle' => 'Ställ dig i kö – NONOX öppen beta',
            'pageDescription' => 'NONOX är i öppen beta. Ställ dig i kö så släpper vi in dig. Skarp lansering av version 1.0 den 1 januari 2027.',
        ]);
    }
}
