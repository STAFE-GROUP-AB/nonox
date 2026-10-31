<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

use App\Modules\StaffLedger\Models\Person;

/**
 * A person's personal QR code: NXPL1.{person id}.{nonce}.{signature}, signed
 * with the application key. A new nonce invalidates the old code. The kiosk
 * receives only sha256 of each token, so it can recognise a scanned code
 * without the network and without being able to make one.
 */
final class QrToken
{
    public const string PREFIX = 'NXPL1';

    public static function issue(Person $person): string
    {
        $person->forceFill([
            'qr_nonce' => bin2hex(random_bytes(12)),
        ]);
        $person->forceFill(['qr_secret_hash' => hash('sha256', self::for($person))])->save();

        return self::for($person);
    }

    public static function for(Person $person): string
    {
        $nonce = (string) $person->qr_nonce;

        return implode('.', [self::PREFIX, $person->id, $nonce, self::signature($person->company_id, $person->id, $nonce)]);
    }

    /** The person id when the token is well formed and signed for this company. */
    public static function personId(string $token, int $companyId): ?int
    {
        $parts = explode('.', trim($token));

        if (count($parts) !== 4 || $parts[0] !== self::PREFIX || ! ctype_digit($parts[1])) {
            return null;
        }

        return hash_equals(self::signature($companyId, (int) $parts[1], $parts[2]), $parts[3]) ? (int) $parts[1] : null;
    }

    private static function signature(int $companyId, int $personId, string $nonce): string
    {
        return substr(hash_hmac('sha256', "staff-ledger-qr:{$companyId}.{$personId}.{$nonce}", (string) config('app.key')), 0, 32);
    }
}
