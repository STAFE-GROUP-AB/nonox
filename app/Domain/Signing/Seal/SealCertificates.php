<?php

declare(strict_types=1);

namespace App\Domain\Signing\Seal;

use App\Models\SigningSealCertificate;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use SensitiveParameter;

/**
 * Seal certificates (ADR-046 amendment, gap 5, package S10): reads and checks
 * a PKCS#12, and picks the key an envelope is sealed with: the company's own
 * active certificate when it has one that has not expired, else the
 * instance's self-signed seal (a key file named in config, made by
 * `php artisan signing:seal-certificate`).
 *
 * Nothing here logs, audits or returns the private key or the password; a
 * failure says what is wrong in plain Swedish and never echoes either.
 * Relied on (PHP manual and the installed ext/openssl): openssl_pkcs12_read
 * gives the PEM certificate under "cert" and the key under "pkey";
 * openssl_x509_parse gives subject, issuer, serialNumberHex,
 * validFrom_time_t, validTo_time_t and extensions; openssl_x509_fingerprint
 * hashes the DER form.
 */
final class SealCertificates
{
    /** A to-do is raised this many days before a company certificate expires (gap 5, "Security"). */
    public const int EXPIRY_WARNING_DAYS = 60;

    /** A PKCS#12 with one certificate and its chain is a few kilobytes; 64 KB leaves room for a long chain. */
    public const int MAX_BYTES = 65536;

    /** The smallest RSA key accepted. */
    public const int MIN_RSA_BITS = 2048;

    /** @var array<string, ?SealCredentials> the instance seal, read once per process and path */
    private static array $instance = [];

    /**
     * The facts of a PKCS#12 after checking it: readable with the password, one certificate and its matching
     * private key, an RSA key of at least 2048 bits or an EC key, usable for signatures, and valid now.
     *
     * @throws ValidationException with the key "certificate"
     */
    public static function read(#[SensitiveParameter] string $pkcs12, #[SensitiveParameter] string $password, ?CarbonImmutable $now = null, bool $checkDates = true): SealCertificateInfo
    {
        $now ??= CarbonImmutable::now();

        if ($pkcs12 === '' || strlen($pkcs12) > self::MAX_BYTES) {
            self::refuse(__('Filen är för stor eller tom. Ett förseglingscertifikat (PKCS#12, .p12 eller .pfx) är högst 64 kB.'));
        }

        $bundle = [];

        if (! @openssl_pkcs12_read($pkcs12, $bundle, $password)) {
            // Clears OpenSSL's error queue, so no message about the file leaks into a later call.
            while (openssl_error_string() !== false) {
            }

            self::refuse(__('Filen kunde inte läsas. Kontrollera lösenordet. Är filen gammal kan den behöva exporteras på nytt med modern kryptering (AES).'));
        }

        $certificate = isset($bundle['cert']) && is_string($bundle['cert']) ? openssl_x509_read($bundle['cert']) : false;
        $key = isset($bundle['pkey']) && is_string($bundle['pkey']) ? openssl_pkey_get_private($bundle['pkey']) : false;

        if (! $certificate instanceof OpenSSLCertificate || ! $key instanceof OpenSSLAsymmetricKey) {
            self::refuse(__('Filen måste innehålla både certifikatet och dess privata nyckel.'));
        }

        if (! openssl_x509_check_private_key($certificate, $key)) {
            self::refuse(__('Den privata nyckeln hör inte till certifikatet i filen.'));
        }

        $details = openssl_pkey_get_details($key);

        if (! is_array($details) || ! self::keyIsStrongEnough($details)) {
            self::refuse(__('Nyckeln är för svag. Använd en RSA-nyckel på minst 2048 bitar eller en EC-nyckel.'));
        }

        $info = self::info($certificate);

        if ($info === null) {
            self::refuse(__('Certifikatet i filen kunde inte läsas.'));
        }

        $parsed = (array) openssl_x509_parse($certificate);
        $usage = (string) ($parsed['extensions']['keyUsage'] ?? '');

        if ($usage !== '' && ! str_contains($usage, 'Digital Signature') && ! str_contains($usage, 'Non Repudiation')) {
            self::refuse(__('Certifikatet får inte användas för underskrifter eller stämplar (nyckelanvändningen saknar digital signatur).'));
        }

        if (! $checkDates) {
            return $info;
        }

        if ($info->notBefore->greaterThan($now->addMinutes(5))) {
            self::refuse(__('Certifikatet gäller inte ännu. Det börjar gälla :date.', ['date' => $info->notBefore->setTimezone((string) config('app.timezone'))->format('Y-m-d')]));
        }

        if ($info->isExpired($now)) {
            self::refuse(__('Certifikatet har gått ut.'));
        }

        return $info;
    }

    /** The public facts of an X.509 certificate, or null when it cannot be read. */
    public static function info(OpenSSLCertificate|string $certificate): ?SealCertificateInfo
    {
        $parsed = openssl_x509_parse($certificate);
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');

        if (! is_array($parsed) || ! is_string($fingerprint) || ! isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])) {
            return null;
        }

        $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        $issuer = is_array($parsed['issuer'] ?? null) ? $parsed['issuer'] : [];
        $publicKey = openssl_pkey_get_public($certificate);
        $selfSigned = $subject === $issuer && $publicKey !== false && openssl_x509_verify($certificate, $publicKey) === 1;
        $commonName = $subject['CN'] ?? $subject['O'] ?? '';

        return new SealCertificateInfo(
            subject: mb_substr(self::name($subject), 0, 500),
            issuer: mb_substr(self::name($issuer), 0, 500),
            serial: mb_substr((string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''), 0, 100),
            notBefore: CarbonImmutable::createFromTimestampUTC((int) $parsed['validFrom_time_t']),
            notAfter: CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t']),
            fingerprintSha256: strtolower($fingerprint),
            selfSigned: $selfSigned,
            commonName: mb_substr(is_array($commonName) ? (string) end($commonName) : (string) $commonName, 0, 200),
        );
    }

    /**
     * What an envelope of the company is sealed with now: its own active certificate that has not expired, else
     * the instance seal, else nothing (the envelope is then not sealed). Must run inside the company.
     */
    public static function forCompany(int $companyId, ?CarbonImmutable $now = null): ?SealCredentials
    {
        $now ??= CarbonImmutable::now();
        $own = SigningSealCertificate::query()->where('company_id', $companyId)->active()->first();

        if ($own instanceof SigningSealCertificate && ! $own->isExpired($now) && $own->pkcs12 !== null && $own->password !== null) {
            $info = new SealCertificateInfo($own->subject, $own->issuer, $own->serial, $own->not_before, $own->not_after, $own->fingerprint_sha256, $own->self_signed, self::commonNameOf($own->subject));
            $bytes = base64_decode($own->pkcs12, true);

            if (is_string($bytes)) {
                return new SealCredentials(SealCredentials::COMPANY, $info, $bytes, $own->password, $own->id);
            }
        }

        $instance = self::instance();

        return $instance instanceof SealCredentials && ! $instance->info->isExpired($now) ? $instance : null;
    }

    /**
     * Whether the company has a seal certificate now, decided from the rows and the file system alone: the
     * company's own active certificate that has not expired, or a configured, readable instance seal file. Never
     * decrypts or parses a key, so it is safe inside the signing transaction (ADR-046 S10 review); the job reads
     * the key and gives the seal up when it cannot. Must run inside the company.
     */
    public static function available(int $companyId, ?CarbonImmutable $now = null): bool
    {
        $own = SigningSealCertificate::query()->where('company_id', $companyId)->active()->where('not_after', '>', $now ?? CarbonImmutable::now())->exists();

        if ($own) {
            return true;
        }

        $path = self::instancePath();

        return $path !== '' && (string) config('nonox.signing.seal.instance_password', '') !== '' && is_file($path) && ! is_link($path) && is_readable($path);
    }

    /** The instance's self-signed seal, when its file and password are configured and readable. */
    public static function instance(): ?SealCredentials
    {
        $path = self::instancePath();
        $password = (string) config('nonox.signing.seal.instance_password', '');

        if ($path === '' || $password === '' || ! is_file($path) || is_link($path) || ! is_readable($path)) {
            return null;
        }

        // A file replaced by signing:seal-certificate --force is noticed by its time and size (a worker lives long).
        clearstatcache(true, $path);
        $cacheKey = $path.'|'.hash('sha256', $password).'|'.(int) filemtime($path).'|'.(int) filesize($path);

        if (array_key_exists($cacheKey, self::$instance)) {
            return self::$instance[$cacheKey];
        }

        self::$instance = [];

        if ((int) filesize($path) > self::MAX_BYTES) {
            return self::$instance[$cacheKey] = null;
        }

        $bytes = (string) file_get_contents($path);

        try {
            // Its dates are checked by the caller: an expired instance seal is reported, not silently used.
            $info = self::read($bytes, $password, checkDates: false);
        } catch (ValidationException) {
            return self::$instance[$cacheKey] = null;
        }

        return self::$instance[$cacheKey] = new SealCredentials(SealCredentials::INSTANCE, $info, $bytes, $password);
    }

    /**
     * The configured path of the instance seal's PKCS#12 file, or '' when it is not usable: it must be absolute,
     * have no ".." segment and lie outside the public web root (resolved with realpath, so a symlinked or
     * relative way into public/ is refused too).
     */
    public static function instancePath(): string
    {
        $path = (string) config('nonox.signing.seal.instance_pkcs12', '');

        return self::usablePath($path) ? $path : '';
    }

    /** Whether a path may hold the instance seal: absolute, no "..", and not inside public/. */
    public static function usablePath(string $path): bool
    {
        if ($path === '' || ! str_starts_with($path, DIRECTORY_SEPARATOR) || in_array('..', explode(DIRECTORY_SEPARATOR, $path), true)) {
            return false;
        }

        return ! self::insidePublic($path);
    }

    /** Whether a path resolves into the public web root, through the nearest directory that exists. */
    public static function insidePublic(string $path): bool
    {
        $public = realpath(public_path());

        if ($public === false) {
            return false;
        }

        $existing = $path;
        $rest = '';

        while (! file_exists($existing) && $existing !== dirname($existing)) {
            $rest = DIRECTORY_SEPARATOR.basename($existing).$rest;
            $existing = dirname($existing);
        }

        $resolved = (realpath($existing) ?: $existing).$rest;

        return $resolved === $public || str_starts_with($resolved.DIRECTORY_SEPARATOR, rtrim($public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    /**
     * Whether a certificate's subject names the company: its O equals the company's legal name, or its
     * organizationIdentifier holds the organisation number (for example NTRSE-5561234567). Nothing here verifies
     * the certificate; a mismatch only warns (ADR-046 S10, legal review F2).
     */
    public static function namesCompany(string $subject, ?string $legalName, ?string $orgNumber): bool
    {
        $digits = preg_replace('/\D/', '', (string) $orgNumber) ?? '';
        $name = mb_strtolower(trim((string) $legalName));

        foreach (explode(', ', $subject) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');

            if ($key === 'O' && $name !== '' && mb_strtolower(trim($value)) === $name) {
                return true;
            }

            if (in_array($key, ['organizationIdentifier', '2.5.4.97'], true) && strlen($digits) >= 10 && str_contains(preg_replace('/\D/', '', $value) ?? '', substr($digits, -10))) {
                return true;
            }
        }

        return false;
    }

    /** Forgets the instance seal read in this process (after the command made a new one, and in tests). */
    public static function forget(): void
    {
        self::$instance = [];
    }

    /**
     * "CN=…, O=…, C=SE", in the certificate's own order.
     *
     * @param  array<array-key, mixed>  $parts
     */
    private static function name(array $parts): string
    {
        $out = [];

        foreach ($parts as $key => $value) {
            foreach ((array) $value as $one) {
                $out[] = $key.'='.(is_scalar($one) ? (string) $one : '');
            }
        }

        return implode(', ', $out);
    }

    private static function commonNameOf(string $subject): string
    {
        foreach (['CN', 'O'] as $wanted) {
            foreach (explode(', ', $subject) as $part) {
                if (str_starts_with($part, $wanted.'=')) {
                    return substr($part, strlen($wanted) + 1);
                }
            }
        }

        return $subject;
    }

    /** @param  array<string, mixed>  $details */
    private static function keyIsStrongEnough(array $details): bool
    {
        return match ($details['type'] ?? null) {
            OPENSSL_KEYTYPE_RSA => (int) ($details['bits'] ?? 0) >= self::MIN_RSA_BITS,
            OPENSSL_KEYTYPE_EC => (int) ($details['bits'] ?? 0) >= 256,
            default => false,
        };
    }

    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages(['certificate' => $message]);
    }
}
