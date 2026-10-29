<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\VoucherSource;
use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Posts a voucher made by the system (an invoice, a payment) from signed
 * amounts per account, through the same SaveDraftVoucher and PostVoucher as
 * a voucher entered by hand, so every posting rule applies.
 */
class BookVoucher
{
    public function __construct(
        private readonly SaveDraftVoucher $saveDraft,
        private readonly PostVoucher $postVoucher,
    ) {}

    /**
     * @param  array<int, int>  $lines  account number => öre, debit positive
     */
    public function __invoke(User $user, Company $company, CarbonImmutable $date, string $description, string $series, array $lines, VoucherSource $source, Model $subject): Voucher
    {
        $draft = ($this->saveDraft)($user, $company, [
            'voucher_date' => $date->toDateString(),
            'description' => mb_substr($description, 0, 255),
            'series' => $series,
            'rows' => array_map(fn (int $account, int $amount): array => [
                'account' => $account,
                'debit_ore' => max($amount, 0),
                'credit_ore' => max(-$amount, 0),
            ], array_keys($lines), array_values($lines)),
        ]);

        $draft->forceFill([
            'source' => $source,
            'source_type' => $subject->getMorphClass(),
            'source_id' => (string) $subject->getKey(),
        ])->save();

        return ($this->postVoucher)($user, $draft);
    }
}
