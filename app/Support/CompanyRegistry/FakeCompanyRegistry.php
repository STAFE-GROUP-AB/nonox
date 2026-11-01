<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry;

/**
 * An invented register for local demos and tests: every valid number is a
 * made-up company with an obviously made-up address, unless a test has
 * given another answer for it. Refused in production (CompanyRegistries).
 */
final class FakeCompanyRegistry implements CompanyRegistry
{
    public const string KEY = 'fake';

    /** How many times the register was asked, so tests can see the cache work. */
    public int $calls = 0;

    /** @var array<string, list<RegisteredCompany>|RegistryUnavailable> */
    private array $answers = [];

    /** @param list<RegisteredCompany>|RegistryUnavailable $answer an empty list means "not found" */
    public function answer(string $identity, array|RegistryUnavailable $answer): self
    {
        $this->answers[$identity] = $answer;

        return $this;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function sourceName(): string
    {
        return 'Bolagsverket (demo)';
    }

    public function find(string $identity): array
    {
        $this->calls++;

        $answer = $this->answers[$identity] ?? null;

        if ($answer instanceof RegistryUnavailable) {
            throw $answer;
        }

        if ($answer !== null) {
            return $answer;
        }

        $suffix = substr($identity, -4);

        if (strlen($identity) === 12) {
            return [new RegisteredCompany(
                name: "Demofirman {$suffix}",
                legalFormCode: 'E',
                legalFormText: 'Enskild näringsidkare',
                street: 'Exempelvägen 1',
                postalCode: '12345',
                city: 'DEMOSTAD',
                country: 'Sverige',
                registeredOn: '2020-01-01',
            )];
        }

        return [new RegisteredCompany(
            name: "Demobolaget {$suffix} AB",
            legalFormCode: 'AB',
            legalFormText: 'Aktiebolag',
            street: 'Exempelgatan 1',
            coAddress: null,
            postalCode: '12345',
            city: 'DEMOSTAD',
            country: 'Sverige',
            registeredOn: '2020-01-01',
        )];
    }
}
