<?php

declare(strict_types=1);

namespace App\Modules\Banking\Providers;

use App\Modules\Banking\Support\ProviderFailed;

/**
 * The JSON Web Token that authenticates NONOX to Enable Banking: RS256,
 * signed with the application's private key, the application id as `kid`,
 * issuer enablebanking.com, audience api.enablebanking.com, and at most 24
 * hours long (Enable Banking API reference, "Authentication"). Built with
 * openssl, so no JWT package is needed.
 */
final class EnableBankingJwt
{
    public const int LIFETIME_SECONDS = 3600;

    public static function make(string $applicationId, string $privateKeyPem, int $now): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new ProviderFailed('The Enable Banking private key cannot be read.');
        }

        $header = ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $applicationId];
        $payload = ['iss' => 'enablebanking.com', 'aud' => 'api.enablebanking.com', 'iat' => $now, 'exp' => $now + self::LIFETIME_SECONDS];
        $input = self::encode((string) json_encode($header)).'.'.self::encode((string) json_encode($payload));

        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new ProviderFailed('The Enable Banking token could not be signed.');
        }

        return $input.'.'.self::encode($signature);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
