<?php

declare(strict_types=1);

namespace App\Domain\Payables;

/**
 * The banks that payment files (pain.001.001.03) can be made for, and what
 * differs between them. Each profile follows the bank's own published guide
 * (ADR-014); a bank without a read guide is not offered.
 *
 * TODO(verify-with-bank): every company must send a test file through its
 * bank's test service before the first real payment file.
 */
enum PaymentBank: string
{
    case Handelsbanken = 'HANDELSBANKEN';
    case Nordea = 'NORDEA';

    public function label(): string
    {
        return match ($this) {
            self::Handelsbanken => 'Handelsbanken',
            self::Nordea => 'Nordea',
        };
    }

    /** The bank's BIC, used as debtor agent. */
    public function bic(): string
    {
        return match ($this) {
            self::Handelsbanken => 'HANDSESS',
            self::Nordea => 'NDEASESS',
        };
    }

    /**
     * The scheme code for the agreement id in InitgPty/Id/OrgId/Othr:
     * Handelsbanken "BANK", Nordea "CUST".
     */
    public function initiatingPartyScheme(): string
    {
        return match ($this) {
            self::Handelsbanken => 'BANK',
            self::Nordea => 'CUST',
        };
    }

    /** CategoryPurpose for supplier payments, where the bank asks for it. */
    public function categoryPurpose(): ?string
    {
        return match ($this) {
            self::Handelsbanken => 'SUPP',
            self::Nordea => null,
        };
    }

    /**
     * How the creditor agent is given for a payment to a bankgiro or plusgiro
     * number: a clearing member id under SESBA (Handelsbanken), or a BIC
     * (Nordea's examples).
     *
     * @return array{bic?: string, clearing?: string}
     */
    public function creditorAgent(string $scheme): array
    {
        return match ($this) {
            self::Handelsbanken => ['clearing' => $scheme === 'BGNR' ? '9900' : '9500'],
            self::Nordea => ['bic' => $scheme === 'BGNR' ? 'BGABSESS' : 'NDEASESS'],
        };
    }
}
