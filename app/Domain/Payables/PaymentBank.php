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
    case Danske = 'DANSKE';
    case Handelsbanken = 'HANDELSBANKEN';
    case Lansforsakringar = 'LANSFORSAKRINGAR';
    case Nordea = 'NORDEA';
    case Seb = 'SEB';
    case Swedbank = 'SWEDBANK';

    public function label(): string
    {
        return match ($this) {
            self::Danske => 'Danske Bank',
            self::Handelsbanken => 'Handelsbanken',
            self::Lansforsakringar => 'Länsförsäkringar',
            self::Nordea => 'Nordea',
            self::Seb => 'SEB',
            self::Swedbank => 'Swedbank och sparbankerna',
        };
    }

    /** The bank's BIC, used as debtor agent. */
    public function bic(): string
    {
        return match ($this) {
            self::Danske => 'DABASESX',
            self::Handelsbanken => 'HANDSESS',
            self::Lansforsakringar => 'ELLFSESS',
            self::Nordea => 'NDEASESS',
            self::Seb => 'ESSESESS',
            self::Swedbank => 'SWEDSESS',
        };
    }

    /** Danske Bank's examples give the bank's country with every BIC. */
    public function agentCountry(): bool
    {
        return $this === self::Danske;
    }

    /**
     * The scheme code for the agreement id in InitgPty/Id/OrgId/Othr, or null
     * when the bank's guide gives none (Länsförsäkringar).
     */
    public function initiatingPartyScheme(): ?string
    {
        return match ($this) {
            self::Handelsbanken, self::Swedbank => 'BANK',
            self::Lansforsakringar => null,
            self::Danske, self::Nordea, self::Seb => 'CUST',
        };
    }

    /**
     * PmtTpInf/SvcLvl: a code (NURG), SEB's proprietary MPNS for giro
     * payments, or nothing (Länsförsäkringar accepts only NPCA if sent).
     *
     * @return array{Cd?: string, Prtry?: string}|null
     */
    public function serviceLevel(): ?array
    {
        return match ($this) {
            self::Danske, self::Handelsbanken, self::Nordea, self::Swedbank => ['Cd' => 'NURG'],
            self::Seb => ['Prtry' => 'MPNS'],
            self::Lansforsakringar => null,
        };
    }

    /** CategoryPurpose for supplier payments, where the bank asks for it. */
    public function categoryPurpose(): ?string
    {
        return in_array($this, [self::Handelsbanken, self::Swedbank], true) ? 'SUPP' : null;
    }

    /**
     * How the creditor agent is given for a payment to a bankgiro (BGNR) or
     * plusgiro (PGNR) number: a clearing member id under SESBA, or a BIC.
     *
     * @return array{bic?: string, clearing?: string}
     */
    public function creditorAgent(string $scheme): array
    {
        $bankgiro = $scheme === 'BGNR';

        return match ($this) {
            self::Handelsbanken => ['clearing' => $bankgiro ? '9900' : '9500'],
            self::Lansforsakringar, self::Seb, self::Swedbank => ['clearing' => $bankgiro ? '9900' : '9960'],
            self::Nordea => ['bic' => $bankgiro ? 'BGABSESS' : 'NDEASESS'],
            self::Danske => ['bic' => 'DABASESX'],
        };
    }

    /** What the bank calls the id that goes in InitgPty, for the settings form. */
    public function agreementHint(): string
    {
        return match ($this) {
            self::Danske => __('Kund- eller avtalsnumret för filtjänsten hos Danske Bank.'),
            self::Handelsbanken => __('Organisationsnumret eller ert SHB-nummer.'),
            self::Lansforsakringar => __('16 följt av organisationsnumret, tolv siffror, till exempel 165566778899. Kontot ska vara kopplat till Betaltjänst via fil.'),
            self::Nordea => __('Avtals-id:t (kundnumret) som Nordea gav för Corporate eGateway eller filöverföring.'),
            self::Seb => __('EDI-avtalets kundnummer från SEB, 10 eller 14 tecken.'),
            self::Swedbank => __('Avtals-id:t för betalfilsavtalet, 16 tecken, till exempel 123456789123B001. Företagets bankgiro används som avsändarkonto.'),
        };
    }

    /**
     * Swedbank takes the company's own bankgiro number as the debtor account
     * for domestic payments ("For Domestic payments use Othr/BGNR").
     */
    public function debtorAccountIsBankgiro(): bool
    {
        return $this === self::Swedbank;
    }

    /**
     * Swedbank requires the paid amount (RfrdDocAmt/RmtdAmt) next to an OCR
     * reference for bankgiro payments.
     */
    public function remittedAmountWithReference(): bool
    {
        return $this === self::Swedbank;
    }
}
