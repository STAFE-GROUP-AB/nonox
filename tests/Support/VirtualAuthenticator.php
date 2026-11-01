<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A software passkey for tests (ADR-029): an ES256 key pair that answers the
 * options NONOX sends to the browser exactly as a platform authenticator
 * would, with "none" attestation. laravel/passkeys ships no test helpers, so
 * the ceremonies are exercised end to end with real signatures.
 */
final class VirtualAuthenticator
{
    public readonly string $credentialId;

    private OpenSSLAsymmetricKey $key;

    private int $signCount = 0;

    public function __construct(public readonly string $origin = 'https://nonox.test', public bool $verifiesUser = true)
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Could not create a test key.');
        }

        $this->key = $key;
        $this->credentialId = random_bytes(32);
    }

    /**
     * The answer to navigator.credentials.create(), as resources/js/passkeys.js sends it.
     *
     * @param  array<array-key, mixed>  $options
     * @return array<string, mixed>
     */
    public function register(array $options): array
    {
        $clientData = $this->clientData('webauthn.create', (string) $options['challenge']);
        $authData = $this->authenticatorData((string) $options['rp']['id'], attested: true);
        $attestation = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new CborBytes($authData)]);

        return [
            'id' => self::b64url($this->credentialId),
            'rawId' => self::b64url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64url($clientData),
                'attestationObject' => self::b64url($attestation),
                'transports' => ['internal'],
            ],
            'clientExtensionResults' => [],
        ];
    }

    /**
     * The answer to navigator.credentials.get().
     *
     * @param  array<array-key, mixed>  $options
     * @return array<string, mixed>
     */
    public function authenticate(array $options, ?string $userHandle = null): array
    {
        $clientData = $this->clientData('webauthn.get', (string) $options['challenge']);
        $authData = $this->authenticatorData((string) $options['rpId'], attested: false);

        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        $response = [
            'clientDataJSON' => self::b64url($clientData),
            'authenticatorData' => self::b64url($authData),
            'signature' => self::b64url((string) $signature),
        ];

        if ($userHandle !== null) {
            $response['userHandle'] = self::b64url($userHandle);
        }

        return [
            'id' => self::b64url($this->credentialId),
            'rawId' => self::b64url($this->credentialId),
            'type' => 'public-key',
            'response' => $response,
            'clientExtensionResults' => [],
        ];
    }

    private function clientData(string $type, string $challenge): string
    {
        return (string) json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $this->origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
    }

    private function authenticatorData(string $rpId, bool $attested): string
    {
        // UP (user present), UV (user verified) and AT (attested credential data follows).
        $flags = 0x01 | ($this->verifiesUser ? 0x04 : 0) | ($attested ? 0x40 : 0);
        $data = hash('sha256', $rpId, true).chr($flags).pack('N', ++$this->signCount);

        if (! $attested) {
            return $data;
        }

        return $data.str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$this->publicKey();
    }

    /** The public key as a COSE_Key: EC2, ES256, P-256. */
    private function publicKey(): string
    {
        $details = openssl_pkey_get_details($this->key);

        if (! is_array($details)) {
            throw new RuntimeException('Could not read the test key.');
        }

        return self::cbor([
            1 => 2,
            3 => -7,
            -1 => 1,
            -2 => new CborBytes(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
            -3 => new CborBytes(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
        ]);
    }

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** The CBOR subset WebAuthn needs: integers, byte and text strings, maps. */
    private static function cbor(mixed $value): string
    {
        return match (true) {
            $value instanceof CborBytes => self::head(2, strlen($value->bytes)).$value->bytes,
            is_int($value) && $value >= 0 => self::head(0, $value),
            is_int($value) => self::head(1, -1 - $value),
            is_string($value) => self::head(3, strlen($value)).$value,
            is_array($value) => self::head(5, count($value)).implode('', array_map(
                fn (int|string $key, mixed $item): string => self::cbor($key).self::cbor($item),
                array_keys($value),
                $value,
            )),
            default => throw new RuntimeException('Unsupported CBOR value.'),
        };
    }

    private static function head(int $major, int $length): string
    {
        return match (true) {
            $length < 24 => chr(($major << 5) | $length),
            $length < 256 => chr(($major << 5) | 24).chr($length),
            default => chr(($major << 5) | 25).pack('n', $length),
        };
    }
}
