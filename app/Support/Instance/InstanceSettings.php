<?php

declare(strict_types=1);

namespace App\Support\Instance;

use App\Models\InstanceSetting;
use Illuminate\Support\Facades\Schema;

/**
 * The settings of the installation itself (ADR-044), kept in
 * instance_settings and edited only in /hq. Read once per request or job
 * (a scoped binding), so asking many times costs one query.
 *
 * - operator.company_id: the company in this installation that runs the
 *   service (Driftbolaget). Empty = the feature is off, the default for a
 *   self-hosted installation.
 * - operator.customers_company_id / operator.waitlist_company_id: where
 *   the companies (as customers) and the queue applicants (as CRM
 *   prospects) go; empty = the operator company.
 * - waitlist.open: whether /ko takes new applications (default open).
 * - registration.open: whether an unknown address may create an account
 *   on the sign-in form (default closed, ADR-038).
 */
final class InstanceSettings
{
    public const string OPERATOR = 'operator.company_id';

    public const string CUSTOMERS_TARGET = 'operator.customers_company_id';

    public const string WAITLIST_TARGET = 'operator.waitlist_company_id';

    public const string WAITLIST_OPEN = 'waitlist.open';

    public const string REGISTRATION_OPEN = 'registration.open';

    /** @var array<string, mixed> the defaults, which are also the only keys */
    public const array DEFAULTS = [
        self::OPERATOR => null,
        self::CUSTOMERS_TARGET => null,
        self::WAITLIST_TARGET => null,
        self::WAITLIST_OPEN => true,
        self::REGISTRATION_OPEN => false,
    ];

    /** @var ?array<string, mixed> */
    private ?array $values = null;

    /** Once the table is there it stays, so the check is made once per process. */
    private static bool $tableExists = false;

    public static function current(): self
    {
        return app(self::class);
    }

    public function get(string $key): mixed
    {
        return array_key_exists($key, $this->all()) ? $this->all()[$key] : null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $values = self::DEFAULTS;

        // Model events on companies ask before this table exists, while older migrations run.
        self::$tableExists = self::$tableExists || Schema::hasTable('instance_settings');

        if (self::$tableExists) {
            foreach (InstanceSetting::query()->whereIn('key', array_keys(self::DEFAULTS))->get() as $setting) {
                $values[$setting->key] = $setting->value;
            }
        }

        return $this->values = $values;
    }

    /** Forgets what was read, after a change. */
    public function forget(): void
    {
        $this->values = null;
    }

    public function operatorCompanyId(): ?int
    {
        return self::id($this->get(self::OPERATOR));
    }

    /** Where every company in the installation becomes a customer; null while there is no operator company. */
    public function customersCompanyId(): ?int
    {
        $operator = $this->operatorCompanyId();

        return $operator === null ? null : (self::id($this->get(self::CUSTOMERS_TARGET)) ?? $operator);
    }

    /** Where the queue applicants become CRM prospects; null while there is no operator company. */
    public function waitlistCompanyId(): ?int
    {
        $operator = $this->operatorCompanyId();

        return $operator === null ? null : (self::id($this->get(self::WAITLIST_TARGET)) ?? $operator);
    }

    public function waitlistOpen(): bool
    {
        return (bool) $this->get(self::WAITLIST_OPEN);
    }

    public function selfRegistrationOpen(): bool
    {
        return (bool) $this->get(self::REGISTRATION_OPEN);
    }

    private static function id(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
