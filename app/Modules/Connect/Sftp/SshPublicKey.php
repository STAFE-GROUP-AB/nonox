<?php

declare(strict_types=1);

namespace App\Modules\Connect\Sftp;

/**
 * An SSH public key in OpenSSH's one-line format ("type base64 comment"),
 * read from the key's own wire encoding (RFC 4253 §6.6, RFC 8709 §4), so the
 * type and the size are those of the key, not of the text in front of it.
 *
 * Only ed25519 and RSA of at least 3072 bits are accepted (ADR-032, "SFTP"):
 * DSA is broken, ECDSA depends on the quality of the signer's random numbers,
 * certificates and security-key types would need their own rules, and RSA
 * below 3072 bits is below current recommendations. RSA above 8192 bits is
 * refused as well, so a key cannot be used to make the server work hard.
 */
final class SshPublicKey
{
    public const int MIN_RSA_BITS = 3072;

    public const int MAX_RSA_BITS = 8192;

    private function __construct(
        public readonly string $algorithm,
        public readonly int $bits,
        public readonly string $blob,
    ) {}

    /**
     * The key, or null when the text is not an accepted public key. A
     * comment after the key is allowed and dropped; options in front of it
     * (authorized_keys style) are not.
     */
    public static function parse(string $text): ?self
    {
        $text = trim($text);

        if ($text === '' || strlen($text) > 4096 || str_contains($text, "\n")) {
            return null;
        }

        $parts = preg_split('/\s+/', $text) ?: [];

        if (count($parts) < 2) {
            return null;
        }

        [$type, $encoded] = $parts;

        if (! in_array($type, ['ssh-ed25519', 'ssh-rsa'], true) || preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $encoded) !== 1) {
            return null;
        }

        $blob = base64_decode($encoded, true);

        if ($blob === false) {
            return null;
        }

        $reader = new SshWireReader($blob);
        $innerType = $reader->string();

        if ($innerType !== $type) {
            return null;
        }

        if ($type === 'ssh-ed25519') {
            $point = $reader->string();

            return $point !== null && strlen($point) === 32 && $reader->atEnd() ? new self('ssh-ed25519', 256, $blob) : null;
        }

        $exponent = $reader->string();
        $modulus = $reader->string();

        if ($exponent === null || $modulus === null || ! $reader->atEnd() || ltrim($exponent, "\0") === '') {
            return null;
        }

        $bits = self::bitLength($modulus);

        if ($bits < self::MIN_RSA_BITS || $bits > self::MAX_RSA_BITS) {
            return null;
        }

        return new self('ssh-rsa', $bits, $blob);
    }

    /** Why a text was refused, in Swedish, for the form. */
    public static function refusal(string $text): string
    {
        $type = preg_split('/\s+/', trim($text))[0] ?? '';

        return match (true) {
            str_contains($text, 'PRIVATE KEY') => __('Det där är en privat nyckel. Klistra bara in den publika nyckeln (filen som slutar på .pub) och håll den privata hemlig.'),
            $type === 'ssh-rsa' => __('RSA-nyckeln är för kort eller felaktig. Den måste vara minst :bits bitar; skapa helst en ed25519-nyckel.', ['bits' => self::MIN_RSA_BITS]),
            in_array($type, ['ssh-dss', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521'], true) || str_starts_with($type, 'sk-') || str_contains($type, '-cert-') => __('Den nyckeltypen tas inte emot. Använd ed25519 (eller RSA med minst :bits bitar).', ['bits' => self::MIN_RSA_BITS]),
            default => __('Det ser inte ut som en publik SSH-nyckel. Den ska börja med ssh-ed25519 eller ssh-rsa och stå på en rad.'),
        };
    }

    /** "type base64", the form SFTP servers compare. */
    public function line(): string
    {
        return $this->algorithm.' '.base64_encode($this->blob);
    }

    /** OpenSSH's fingerprint: "SHA256:" and the unpadded base64 of the key's SHA-256. */
    public function fingerprint(): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $this->blob, true)), '=');
    }

    public function label(): string
    {
        return $this->algorithm === 'ssh-ed25519' ? 'ED25519' : "RSA {$this->bits}";
    }

    private static function bitLength(string $unsigned): int
    {
        $bytes = ltrim($unsigned, "\0");

        if ($bytes === '') {
            return 0;
        }

        $first = ord($bytes[0]);
        $bits = (strlen($bytes) - 1) * 8;

        while ($first > 0) {
            $bits++;
            $first >>= 1;
        }

        return $bits;
    }
}
