<?php

declare(strict_types=1);

namespace App\Domain\Guide;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The figures of Momsbufferten (MoneyNotYours). Every line is one account of
 * the chart with its balance in öre, signed so that positive is money the
 * company has (bank, tax account) or owes (VAT, employer liabilities). A
 * part is null when the chart has none of its accounts, so it is not shown.
 *
 * @phpstan-type Line array{number: int, name: string, amount: int}
 * @phpstan-type PreliminaryTax array{monthly: int, count: int, total: int, until: ?string}
 */
final readonly class MoneyNotYoursFigures
{
    /**
     * @param  list<Line>|null  $bank
     * @param  list<Line>|null  $vatUnreported  the VAT accounts the settlement moves to 2650
     * @param  Line|null  $vatReported  2650 Redovisningskonto för moms
     * @param  list<Line>|null  $employer
     * @param  Line|null  $taxAccount  1630 Skattekonto
     * @param  PreliminaryTax|null  $preliminaryTax
     */
    public function __construct(
        public CarbonImmutable $asOf,
        public ?CarbonImmutable $lastVoucher,
        public ?array $bank,
        public ?array $vatUnreported,
        public ?array $vatReported,
        public ?array $employer,
        public ?array $taxAccount,
        public ?array $preliminaryTax,
    ) {}

    public function bankTotal(): ?int
    {
        return $this->bank === null ? null : array_sum(array_column($this->bank, 'amount'));
    }

    public function vatUnreportedTotal(): int
    {
        return array_sum(array_column($this->vatUnreported ?? [], 'amount'));
    }

    /** VAT to pay (positive) or to get back (negative): not yet declared plus declared on 2650. */
    public function vatTotal(): ?int
    {
        if ($this->vatUnreported === null && $this->vatReported === null) {
            return null;
        }

        return $this->vatUnreportedTotal() + ($this->vatReported['amount'] ?? 0);
    }

    public function employerTotal(): ?int
    {
        return $this->employer === null ? null : array_sum(array_column($this->employer, 'amount'));
    }

    /**
     * What is left in the bank after the VAT to pay, the employer's taxes and
     * the coming preliminary tax, with the tax account's balance counted in
     * (money already there pays the taxes; a debt there adds to them). VAT to
     * get back is not counted: it is not in the bank yet. Null without a bank
     * account in the chart.
     */
    public function remaining(): ?int
    {
        $bank = $this->bankTotal();

        if ($bank === null) {
            return null;
        }

        return $bank
            + ($this->taxAccount['amount'] ?? 0)
            - max($this->vatTotal() ?? 0, 0)
            - max($this->employerTotal() ?? 0, 0)
            - ($this->preliminaryTax['total'] ?? 0);
    }

    /** What is not the company's: VAT to pay, employer taxes and the coming preliminary tax. */
    public function notYours(): int
    {
        return max($this->vatTotal() ?? 0, 0) + max($this->employerTotal() ?? 0, 0) + ($this->preliminaryTax['total'] ?? 0);
    }

    /** The VAT to pay is more than the money in the bank. */
    public function vatExceedsBank(): bool
    {
        $bank = $this->bankTotal();

        return $bank !== null && ($this->vatTotal() ?? 0) > max($bank, 0);
    }

    /** Whole kronor, rounded half up, for a summary: "18 432 kr", "-1 200 kr". */
    public static function kronor(int $ore): string
    {
        return self::withoutOre(Money::ofOre($ore)->roundedToKronor());
    }

    /** About this much, to the nearest hundred kronor: "ca 18 400 kr" on Mina datum. */
    public static function approximately(int $ore): string
    {
        // Half up in whole öre, never through a float (AGENTS.md §1.2).
        $hundreds = intdiv(abs($ore) + 5_000, 10_000) * 10_000;

        return self::withoutOre(Money::ofOre($ore < 0 ? -$hundreds : $hundreds));
    }

    private static function withoutOre(Money $money): string
    {
        return (string) preg_replace('/,00(?=\x{00A0}kr$)/u', '', $money->format());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'as_of' => $this->asOf->toDateString(),
            'last_voucher' => $this->lastVoucher?->toDateString(),
            'bank' => $this->bank,
            'vat_unreported' => $this->vatUnreported,
            'vat_reported' => $this->vatReported,
            'employer' => $this->employer,
            'tax_account' => $this->taxAccount,
            'preliminary_tax' => $this->preliminaryTax,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        /** @var array{as_of: string, last_voucher: ?string, bank: list<Line>|null, vat_unreported: list<Line>|null, vat_reported: Line|null, employer: list<Line>|null, tax_account: Line|null, preliminary_tax: PreliminaryTax|null} $data */
        return new self(
            CarbonImmutable::parse($data['as_of']),
            $data['last_voucher'] === null ? null : CarbonImmutable::parse($data['last_voucher']),
            $data['bank'],
            $data['vat_unreported'],
            $data['vat_reported'],
            $data['employer'],
            $data['tax_account'],
            $data['preliminary_tax'],
        );
    }
}
