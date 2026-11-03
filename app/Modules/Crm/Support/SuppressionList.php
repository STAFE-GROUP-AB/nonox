<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Suppression;

/**
 * The company's list of people who asked not to be contacted, kept after
 * they are erased (ADR-043). It holds an HMAC-SHA256 of each normalised
 * e-mail address (lowercase) and phone number (digits only, 0 for +46)
 * with the app key: enough to recognise the same address or number if the
 * person is added again, never enough to read it back. Kept as long as the
 * objection stands, which is the purpose. TODO(legal-verify): the retention
 * of the suppression list.
 */
final class SuppressionList
{
    /** @return array<string, string> hash => kind for the person's e-mail and numbers */
    public static function hashes(?string $email, ?string ...$phones): array
    {
        $hashes = [];
        $email = mb_strtolower(trim((string) $email));

        if ($email !== '') {
            $hashes[self::hash("email:{$email}")] = 'EMAIL';
        }

        foreach ($phones as $phone) {
            $digits = (string) preg_replace('/\D/', '', (string) $phone);
            $digits = str_starts_with($digits, '46') && strlen($digits) > 9 ? '0'.substr($digits, 2) : $digits;

            if (strlen($digits) >= 6) {
                $hashes[self::hash("phone:{$digits}")] = 'PHONE';
            }
        }

        return $hashes;
    }

    /** Adds the person's e-mail and numbers to the list, in the current company. */
    public static function remember(Person $person, string $reason): void
    {
        foreach (self::hashes($person->email, $person->phone, $person->mobile) as $hash => $kind) {
            Suppression::query()->firstOrCreate(['hash' => $hash], ['kind' => $kind, 'reason' => $reason]);
        }
    }

    /** The reason on the list for any of these contact details, or null. */
    public static function match(?string $email, ?string ...$phones): ?string
    {
        $hashes = array_keys(self::hashes($email, ...$phones));

        if ($hashes === []) {
            return null;
        }

        $reason = Suppression::query()->whereIn('hash', $hashes)->value('reason');

        return is_string($reason) ? $reason : null;
    }

    private static function hash(string $value): string
    {
        return hash_hmac('sha256', 'crm-suppression:'.$value, (string) config('app.key'));
    }
}
