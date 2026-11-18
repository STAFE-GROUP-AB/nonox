<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * What to seal with. The PKCS#12 and its password reach the helper on
 * standard input only; this object never prints them ({@see __debugInfo}).
 */
final readonly class SealRequest
{
    /**
     * @param  list<string>  $timestampUrls  tried in order; the first that answers wins
     */
    public function __construct(
        public SealLevel $level,
        #[SensitiveParameter] public string $pkcs12,
        #[SensitiveParameter] public string $password,
        public string $fieldName = 'NonoxSeal',
        public ?string $reason = null,
        public ?string $location = null,
        public array $timestampUrls = [],
        public int $timestampTimeoutSeconds = 10,
    ) {
        if ($pkcs12 === '') {
            throw new InvalidArgumentException('The PKCS#12 is empty.');
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,59}$/', $fieldName) !== 1) {
            throw new InvalidArgumentException('The field name must be 1 to 60 letters, digits or underscores.');
        }

        if ($level->needsTimestamp() && $timestampUrls === []) {
            throw new InvalidArgumentException('This seal level needs at least one timestamp URL.');
        }

        foreach ($timestampUrls as $url) {
            if (! self::isTimestampUrl($url)) {
                throw new InvalidArgumentException('A timestamp URL must be http(s).');
            }
        }

        if (count($timestampUrls) > 5) {
            throw new InvalidArgumentException('At most five timestamp URLs.');
        }

        if ($timestampTimeoutSeconds < 1 || $timestampTimeoutSeconds > 60) {
            throw new InvalidArgumentException('The timestamp timeout must be 1 to 60 seconds.');
        }
    }

    public static function isTimestampUrl(mixed $url): bool
    {
        if (! is_string($url) || strlen($url) > 500) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && is_string($host) && $host !== '';
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'level' => $this->level->value,
            'fieldName' => $this->fieldName,
            'timestampUrls' => $this->timestampUrls,
            'pkcs12' => '[hidden]',
            'password' => '[hidden]',
        ];
    }
}
