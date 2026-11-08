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
 *   service (Driftbolaget). The companies become its customers and the
 *   queue applicants its CRM prospects; no other company receives them,
 *   since another legal entity would be a disclosure without a basis.
 *   Empty = the feature is off, the default for a self-hosted installation.
 * - waitlist.open: whether /ko takes new applications (default open).
 * - migration.guides_public: whether the per-program moving guides at
 *   /flytta-fran/{program} are published (default off until the legal
 *   review ADR-020 asks for, ADR-076).
 * - registration.open: whether an unknown address may create an account
 *   on the sign-in form (default closed, ADR-038).
 */
final class InstanceSettings
{
    public const string OPERATOR = 'operator.company_id';

    public const string WAITLIST_OPEN = 'waitlist.open';

    public const string REGISTRATION_OPEN = 'registration.open';

    public const string MIGRATION_GUIDES_PUBLIC = 'migration.guides_public';

    /** @var array<string, mixed> the defaults, which are also the only keys */
    public const array DEFAULTS = [
        self::OPERATOR => null,
        self::WAITLIST_OPEN => true,
        self::REGISTRATION_OPEN => false,
        self::MIGRATION_GUIDES_PUBLIC => false,
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

    /** The operator company: where every company becomes a customer and every queue applicant a prospect. */
    public function operatorCompanyId(): ?int
    {
        $value = $this->get(self::OPERATOR);

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    public function waitlistOpen(): bool
    {
        return (bool) $this->get(self::WAITLIST_OPEN);
    }

    public function migrationGuidesPublic(): bool
    {
        return (bool) $this->get(self::MIGRATION_GUIDES_PUBLIC);
    }

    public function selfRegistrationOpen(): bool
    {
        return (bool) $this->get(self::REGISTRATION_OPEN);
    }
}
