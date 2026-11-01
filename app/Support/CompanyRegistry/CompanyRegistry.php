<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry;

/**
 * A public register of companies that answers with the registered name,
 * legal form and postal address for an organisation number (ADR-035).
 * One driver per installation, chosen in services.company_registry.driver.
 */
interface CompanyRegistry
{
    /** The driver's key ('bolagsverket', 'fake'). */
    public function key(): string;

    /** The register's name, shown to the user as the source ("Bolagsverket"). */
    public function sourceName(): string;

    /**
     * The organisations registered on the identity: ten digits for an
     * organisation number, twelve (with century) for a personal number.
     * Empty when the register has nothing on it. A sole trader can have
     * more than one business on the same personal number.
     *
     * @return list<RegisteredCompany>
     *
     * @throws RegistryUnavailable when the register cannot answer
     */
    public function find(string $identity): array;
}
