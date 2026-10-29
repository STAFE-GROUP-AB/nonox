<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * The first-run guide on "/" creates the first user and their company. It is
 * only open on a local installation without any users, so nobody can claim a
 * fresh server before its owner does (ADR-004).
 */
final class FirstRun
{
    public static function allowed(): bool
    {
        return app()->environment('local') && User::query()->doesntExist();
    }
}
