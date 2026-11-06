<?php

declare(strict_types=1);

namespace App\Support\Review;

use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The confirmation "Jag har granskat siffrorna" that every filing to an
 * authority and every payment file requires (owner decision 10): the customer,
 * not NONOX, is responsible for the figures. Refused without the tick; the
 * audit log keeps that it was given (ids and the filing key only).
 */
final class ReviewConfirmation
{
    public const string LABEL = 'Jag har granskat siffrorna';

    /**
     * @throws ValidationException
     */
    public static function demand(bool $reviewed, string $field): void
    {
        if ($reviewed) {
            return;
        }

        throw ValidationException::withMessages([$field => __('Bekräfta att du har granskat siffrorna innan du skickar. Du som kund ansvarar för att uppgifterna är riktiga.')]);
    }

    public static function record(string $filing, Model $subject, Company|int $company, User $user): void
    {
        Audit::record('figures.reviewed', $subject, ['filing' => $filing], $company, $user);
    }

    /**
     * @throws ValidationException
     */
    public static function require(bool $reviewed, string $field, string $filing, Model $subject, Company|int $company, User $user): void
    {
        self::demand($reviewed, $field);
        self::record($filing, $subject, $company, $user);
    }
}
