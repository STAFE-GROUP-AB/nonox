<?php

declare(strict_types=1);

namespace App\Support\Instance;

use App\Models\InstanceSetting;
use App\Support\Demo\DemoMode;
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
 * - demo.enabled: whether /prova lets a visitor try NONOX on a demo company
 *   (ADR-077). Off by default everywhere, also on a hosted installation: the
 *   owner switches it on in /hq after confirming that the demo database may be
 *   emptied (demo:prune does nothing while it is off).
 */
final class InstanceSettings
{
    public const string OPERATOR = 'operator.company_id';

    public const string WAITLIST_OPEN = 'waitlist.open';

    public const string REGISTRATION_OPEN = 'registration.open';

    /** Where vulnerability reports go: an e-mail address or an https address (ADR-078, RFC 9116 Contact). */
    public const string SECURITY_CONTACT = 'security.contact';

    public const string DEMO_ENABLED = 'demo.enabled';

    public const string MIGRATION_GUIDES_PUBLIC = 'migration.guides_public';

    /** @var array<string, mixed> the defaults, which are also the only keys */
    public const array DEFAULTS = [
        self::OPERATOR => null,
        self::WAITLIST_OPEN => true,
        self::REGISTRATION_OPEN => false,
        self::SECURITY_CONTACT => null,
        self::DEMO_ENABLED => null,
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

        // The installation's settings are the main database's, also when a guest's request has the demo database as default (ADR-077).
        DemoMode::onMainConnection(function () use (&$values): void {
            // Model events on companies ask before this table exists, while older migrations run.
            self::$tableExists = self::$tableExists || Schema::hasTable('instance_settings');

            if (self::$tableExists) {
                foreach (InstanceSetting::query()->whereIn('key', array_keys(self::DEFAULTS))->get() as $setting) {
                    $values[$setting->key] = $setting->value;
                }
            }
        });

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

    public function securityContact(): ?string
    {
        $value = $this->get(self::SECURITY_CONTACT);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function migrationGuidesPublic(): bool
    {
        return (bool) $this->get(self::MIGRATION_GUIDES_PUBLIC);
    }

    public function selfRegistrationOpen(): bool
    {
        return (bool) $this->get(self::REGISTRATION_OPEN);
    }

    /** Whether strangers may try NONOX on a demo company (ADR-077). */
    public function demoEnabled(): bool
    {
        return (bool) $this->get(self::DEMO_ENABLED);
    }
}
