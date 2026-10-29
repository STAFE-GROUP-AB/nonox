<?php

declare(strict_types=1);

namespace App\Domain\Accounting\SieImport;

use App\Support\Sie\SieAmount;
use App\Support\Sie\SieDate;
use App\Support\Sie\SieRecord;
use Carbon\CarbonImmutable;

/**
 * One row of a SIE voucher (#TRANS, #RTRANS or #BTRANS, SIE 4C §11):
 * kontonr {objektlista} belopp transdat transtext kvantitet sign.
 */
final class SieTransaction
{
    public const int TEXT_LENGTH = 255;

    /**
     * @param  array<int|string, string>  $objects
     */
    private function __construct(
        public readonly string $label,
        public readonly int $account,
        public readonly int $amount,
        public readonly array $objects,
        public readonly ?CarbonImmutable $date,
        public readonly ?string $text,
        public readonly ?string $quantity,
        public readonly ?string $signature,
        public readonly bool $truncated,
    ) {}

    /**
     * The row, or a Swedish explanation of what is wrong with it.
     */
    public static function fromRecord(SieRecord $record): self|string
    {
        $account = (string) $record->field(0);
        $amount = SieAmount::toOre((string) $record->field(2));

        if (preg_match('/^\d{4}$/', $account) !== 1 || (int) $account < 1000) {
            return __('kontot ":account" är inte ett fyrsiffrigt kontonummer.', ['account' => $account]);
        }

        if ($amount === null) {
            return __('beloppet ":amount" går inte att läsa.', ['amount' => (string) $record->field(2)]);
        }

        $date = (string) $record->field(3);
        $text = (string) $record->field(4);
        $quantity = (string) $record->field(5);
        $signature = (string) $record->field(6);

        return new self(
            label: $record->label,
            account: (int) $account,
            amount: $amount,
            objects: $record->objects(1) ?? [],
            date: $date === '' ? null : SieDate::parse($date),
            text: $text === '' ? null : mb_substr($text, 0, self::TEXT_LENGTH),
            quantity: preg_match('/^-?\d{1,11}(\.\d{1,4})?$/', $quantity) === 1 ? $quantity : null,
            signature: $signature === '' ? null : mb_substr($signature, 0, 255),
            truncated: mb_strlen($text) > self::TEXT_LENGTH,
        );
    }

    public function debit(): int
    {
        return max($this->amount, 0);
    }

    public function credit(): int
    {
        return max(-$this->amount, 0);
    }

    /**
     * #RTRANS is followed by an identical #TRANS for programs that do not
     * know #RTRANS; that copy is skipped (SIE 4C §11).
     */
    public function sameAs(self $other): bool
    {
        return $this->account === $other->account
            && $this->amount === $other->amount
            && $this->objects == $other->objects
            && $this->date?->toDateString() === $other->date?->toDateString()
            && $this->text === $other->text
            && $this->quantity === $other->quantity;
    }
}
