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
 * Stage 5 (ADR-048): an unsubscribe from a mailing, or a spam complaint,
 * adds the address with the reason MARKETING. Such an entry stops mailings
 * only (art. 21(3), direct marketing); it does not mark anyone Kontakta inte
 * for other contact, so match() leaves it out and marketingBlocked() reads
 * every entry. A stronger reason replaces MARKETING on the same hash, and
 * withdrawing a Kontakta inte objection never removes a MARKETING entry.
 *
 * TODO(legal-verify): how long the list is kept; see CrmSettings
 * suppression_review_years for the periodic review.
 */
final class SuppressionList
{
    /** The reason stored for contact details kept after an erasure on request (ADR-044). */
    public const string ERASURE = 'ERASURE';

    /** An unsubscribe from mailings or a spam complaint: no more direct marketing to the address (ADR-048). */
    public const string MARKETING = 'MARKETING';

    /**
     * Keeps these contact details on the list after the person was erased
     * on their own request, so a later sync from the queue cannot add them
     * again (ADR-044). Hashes only, like an objection; and like one, a
     * person added by hand with these details is marked Kontakta inte.
     */
    public static function suppress(string $reason, ?string $email, ?string ...$phones): void
    {
        foreach (self::hashes(self::currentKey(), $email, ...$phones) as $hash => $kind) {
            self::store($hash, $kind, $reason);
        }
    }

    /** No more mailings to this address (an unsubscribe or a complaint); other contact is unaffected. */
    public static function suppressMarketing(string $email): void
    {
        foreach (self::hashes(self::currentKey(), $email) as $hash => $kind) {
            self::store($hash, $kind, self::MARKETING);
        }
    }

    /**
     * Which of these addresses must get no mailing: any entry counts, under
     * any key. One query for the whole list.
     *
     * @param  array<int, string>  $emails
     * @return array<string, true> lower-case address => true
     */
    public static function marketingBlocked(array $emails): array
    {
        $byHash = [];

        foreach (array_unique(array_map(fn (string $email): string => mb_strtolower(trim($email)), $emails)) as $email) {
            foreach (self::keys() as $key) {
                foreach (array_keys(self::hashes($key, $email)) as $hash) {
                    $byHash[$hash] = $email;
                }
            }
        }

        if ($byHash === []) {
            return [];
        }

        $blocked = [];

        foreach (array_chunk(array_keys($byHash), 1000) as $chunk) {
            foreach (Suppression::query()->whereIn('hash', $chunk)->pluck('hash') as $hash) {
                $blocked[$byHash[$hash]] = true;
            }
        }

        return $blocked;
    }

    /** Adds the person's e-mail and numbers to the list when they objected; a restriction keeps nothing. */
    public static function remember(Person $person): void
    {
        if ($person->do_not_contact_reason !== DoNotContact::Objection) {
            return;
        }

        foreach (self::hashes(self::currentKey(), $person->email, $person->phone, $person->mobile) as $hash => $kind) {
            self::store($hash, $kind, DoNotContact::Objection->value);
        }
    }

    /** Whether any of these contact details belongs to someone who objected, under any of the keys. */
    public static function match(?string $email, ?string ...$phones): bool
    {
        $hashes = [];

        foreach (self::keys() as $key) {
            $hashes = [...$hashes, ...array_keys(self::hashes($key, $email, ...$phones))];
        }

        // A MARKETING entry stops mailings only, not other contact.
        return $hashes !== [] && Suppression::query()->whereIn('hash', $hashes)->where('reason', '<>', self::MARKETING)->exists();
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

        // Someone who also unsubscribed from mailings keeps that: the e-mail entry goes back to MARKETING instead.
        if ($person->marketing_opt_out_at !== null && $hashes !== []) {
            $marketing = [];

            foreach (self::keys() as $key) {
                foreach ($emails as $email) {
                    $marketing = [...$marketing, ...array_keys(self::hashes($key, $email))];
                }
            }

            $marketing = array_values(array_intersect($hashes, $marketing));

            if ($marketing !== []) {
                Suppression::query()->whereIn('hash', $marketing)->update(['reason' => self::MARKETING, 'updated_at' => now()]);
                $hashes = array_values(array_diff($hashes, $marketing));
            }
        }

        if ($hashes !== []) {
            // Withdrawing an objection does not subscribe anyone to mailings again.
            Suppression::query()->whereIn('hash', $hashes)->where('reason', '<>', self::MARKETING)->delete();
        }
    }

    /** One entry per hash; a stronger reason replaces MARKETING, never the other way. */
    private static function store(string $hash, string $kind, string $reason): void
    {
        $entry = Suppression::query()->firstOrCreate(['hash' => $hash], ['kind' => $kind, 'reason' => $reason]);

        if (! $entry->wasRecentlyCreated && $entry->reason === self::MARKETING && $reason !== self::MARKETING) {
            $entry->forceFill(['reason' => $reason])->save();
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
