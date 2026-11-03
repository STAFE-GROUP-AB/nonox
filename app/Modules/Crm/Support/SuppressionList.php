<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Domain\DoNotContact;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Suppression;

/**
 * The company's list of people who objected to being contacted (GDPR art.
 * 21(3)), kept after they are erased so the objection is respected if they
 * are added again (ADR-043). Only objections: a restriction (art. 18) ends
 * with the erasure and leaves nothing here.
 *
 * Each e-mail address (lowercase) and phone number (digits only, 0 for +46)
 * is kept as an HMAC-SHA256. This is pseudonymisation, not anonymisation:
 * whoever has the key and a candidate address can test it. The key is
 * services.crm.suppression_key (NONOX_CRM_SUPPRESSION_KEY), or one derived
 * from APP_KEY when that is not set. Matching also tries keys derived from
 * APP_KEY and APP_PREVIOUS_KEYS, so rotating a key does not drop a flag.
 *
 * TODO(legal-verify): how long the list is kept; see CrmSettings
 * suppression_review_years for the periodic review.
 */
final class SuppressionList
{
    /** Adds the person's e-mail and numbers to the list when they objected; a restriction keeps nothing. */
    public static function remember(Person $person): void
    {
        if ($person->do_not_contact_reason !== DoNotContact::Objection) {
            return;
        }

        foreach (self::hashes(self::currentKey(), $person->email, $person->phone, $person->mobile) as $hash => $kind) {
            Suppression::query()->firstOrCreate(['hash' => $hash], ['kind' => $kind, 'reason' => DoNotContact::Objection->value]);
        }
    }

    /** Whether any of these contact details belongs to someone who objected, under any of the keys. */
    public static function match(?string $email, ?string ...$phones): bool
    {
        $hashes = [];

        foreach (self::keys() as $key) {
            $hashes = [...$hashes, ...array_keys(self::hashes($key, $email, ...$phones))];
        }

        return $hashes !== [] && Suppression::query()->whereIn('hash', $hashes)->exists();
    }

    /**
     * Removes the hashes of these contact details (the person withdrew the
     * objection), under every key, except those another person who still
     * objects uses.
     *
     * @param  list<?string>  $emails  the e-mail addresses, old and new
     * @param  list<?string>  $phones  the phone numbers, old and new
     */
    public static function forget(Person $person, array $emails, array $phones): void
    {
        $remove = [];

        foreach (self::keys() as $key) {
            $remove = [...$remove, ...array_keys(self::hashes($key, null, ...$phones))];

            foreach ($emails as $email) {
                $remove = [...$remove, ...array_keys(self::hashes($key, $email))];
            }
        }

        $keep = [];
        $others = Person::query()
            ->whereKeyNot($person->id)
            ->where('do_not_contact_reason', DoNotContact::Objection->value)
            ->get(['id', 'email', 'phone', 'mobile']);

        foreach ($others as $other) {
            foreach (self::keys() as $key) {
                $keep = [...$keep, ...array_keys(self::hashes($key, $other->email, $other->phone, $other->mobile))];
            }
        }

        $hashes = array_values(array_diff(array_unique($remove), $keep));

        if ($hashes !== []) {
            Suppression::query()->whereIn('hash', $hashes)->delete();
        }
    }

    /** @return array<string, string> hash => kind */
    private static function hashes(string $key, ?string $email, ?string ...$phones): array
    {
        $hashes = [];
        $email = mb_strtolower(trim((string) $email));

        if ($email !== '') {
            $hashes[hash_hmac('sha256', "crm-suppression:email:{$email}", $key)] = 'EMAIL';
        }

        foreach ($phones as $phone) {
            $digits = (string) preg_replace('/\D/', '', (string) $phone);
            $digits = str_starts_with($digits, '46') && strlen($digits) > 9 ? '0'.substr($digits, 2) : $digits;

            if (strlen($digits) >= 6) {
                $hashes[hash_hmac('sha256', "crm-suppression:phone:{$digits}", $key)] = 'PHONE';
            }
        }

        return $hashes;
    }

    private static function currentKey(): string
    {
        return self::keys()[0];
    }

    /** @return non-empty-list<string> the key new hashes use first, then those older hashes may have used */
    private static function keys(): array
    {
        $derived = fn (string $appKey): string => hash_hmac('sha256', 'nonox-crm-suppression', $appKey);
        $dedicated = config('services.crm.suppression_key');
        $keys = is_string($dedicated) && $dedicated !== '' ? [$dedicated] : [];
        $keys[] = $derived((string) config('app.key'));

        foreach ((array) config('app.previous_keys', []) as $previous) {
            if (is_string($previous) && $previous !== '') {
                $keys[] = $derived($previous);
            }
        }

        return array_values(array_unique($keys));
    }
}
