<?php

declare(strict_types=1);

namespace App\Support\Landing;

use App\Support\Instance\InstanceSettings;

/**
 * Where the public pages' calls to action lead (ADR-038, ADR-044): to the
 * sign-in form while HQ has opened self-registration, else to the queue,
 * which shows a closed message when HQ has closed it.
 */
final class SignupPath
{
    public static function url(): string
    {
        return InstanceSettings::current()->selfRegistrationOpen() ? route('login') : route('waitlist');
    }

    public static function label(): string
    {
        $settings = InstanceSettings::current();

        return match (true) {
            $settings->selfRegistrationOpen() => 'Kom igång',
            $settings->waitlistOpen() => 'Ställ dig i kö',
            default => 'Om betan',
        };
    }
}
