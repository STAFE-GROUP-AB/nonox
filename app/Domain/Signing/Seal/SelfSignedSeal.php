<?php

declare(strict_types=1);

namespace App\Domain\Signing\Seal;

use RuntimeException;
use SensitiveParameter;

/**
 * Makes the instance's self-signed seal certificate (ADR-046 amendment, gap
 * 5, package S10): an RSA 3072 key and an X.509 certificate signed with
 * itself, with the key usages digital signature and non-repudiation and
 * no CA rights, exported as a password-protected PKCS#12.
 *
 * PDF readers show a self-signed seal as of unknown validity: it proves that
 * the file has not changed since it was sealed and by which key, not who
 * holds the key. The manual says so.
 *
 * Relied on (PHP manual, ext/openssl): openssl_csr_new and openssl_csr_sign
 * take "config" (an OpenSSL configuration file) and "x509_extensions" (the
 * section with the extensions) among their options; openssl_csr_sign with a
 * null CA certificate makes a self-signed certificate.
 */
final class SelfSignedSeal
{
    public const int MIN_DAYS = 30;

    public const int MAX_DAYS = 3650;

    /**
     * @return array{pkcs12: string, info: SealCertificateInfo}
     */
    public static function make(string $commonName, ?string $organisation, int $days, #[SensitiveParameter] string $password): array
    {
        if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            throw new RuntimeException('The seal must be valid for 30 to 3650 days.');
        }

        $config = tempnam(sys_get_temp_dir(), 'nonox-seal-conf-');

        if ($config === false) {
            throw new RuntimeException('Could not write a temporary OpenSSL configuration.');
        }

        file_put_contents($config, implode("\n", [
            '[ req ]',
            'distinguished_name = dn',
            '[ dn ]',
            '[ nonox_seal ]',
            'basicConstraints = critical,CA:FALSE',
            'keyUsage = critical,digitalSignature,nonRepudiation',
            'subjectKeyIdentifier = hash',
            '',
        ]));

        try {
            $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'nonox_seal', 'private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            $key = openssl_pkey_new($options);

            if ($key === false) {
                throw new RuntimeException('OpenSSL could not make a key.');
            }

            $name = array_filter([
                'commonName' => mb_substr(trim($commonName), 0, 64),
                'organizationName' => $organisation === null || trim($organisation) === '' ? null : mb_substr(trim($organisation), 0, 64),
                'countryName' => 'SE',
            ], fn (?string $value): bool => $value !== null && $value !== '');

            $csr = openssl_csr_new($name, $key, $options);

            if ($csr === false || $csr === true) {
                throw new RuntimeException('OpenSSL could not make a certificate request.');
            }

            $certificate = openssl_csr_sign($csr, null, $key, $days, $options, random_int(1, PHP_INT_MAX));

            if ($certificate === false) {
                throw new RuntimeException('OpenSSL could not sign the certificate.');
            }

            $pkcs12 = '';

            if (! openssl_pkcs12_export($certificate, $pkcs12, $key, $password)) {
                throw new RuntimeException('OpenSSL could not export the PKCS#12.');
            }

            $info = SealCertificates::info($certificate);

            if ($info === null) {
                throw new RuntimeException('The new certificate could not be read back.');
            }

            return ['pkcs12' => $pkcs12, 'info' => $info];
        } finally {
            @unlink($config);
        }
    }
}
