<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Domain\Accounting\VoucherStatus;
use App\Models\Account;
use App\Models\VoucherRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * "Rows on account X from date A to date B" (ADR-060, addendum 2026-11-07,
 * 2.3): what bulk tagging works on. Only rows of posted vouchers, in the
 * current company. Validated from the form in Swedish.
 */
final readonly class IntercompanyRowSelection
{
    private function __construct(
        public Account $account,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    /**
     * Must run inside the company: another company's account is never found.
     *
     * @param  array<string, mixed>  $input  account (number), from and to (Y-m-d)
     *
     * @throws ValidationException
     */
    public static function fromInput(array $input): self
    {
        $validated = Validator::make($input, [
            'account' => ['required', 'integer', 'between:1000,9999'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'to.after_or_equal' => __('Slutdatumet kan inte vara före startdatumet.'),
        ], [
            'account' => __('konto'),
            'from' => __('från och med'),
            'to' => __('till och med'),
        ])->validate();

        $account = Account::query()->where('number', (int) $validated['account'])->first();

        if (! $account instanceof Account) {
            throw ValidationException::withMessages(['account' => __('Kontot finns inte i kontoplanen.')]);
        }

        return new self($account, CarbonImmutable::parse((string) $validated['from']), CarbonImmutable::parse((string) $validated['to']));
    }

    /** @return Builder<VoucherRow> */
    public function rows(): Builder
    {
        return VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.company_id', $this->account->company_id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->where('voucher_rows.account_id', $this->account->id)
            ->whereBetween('vouchers.voucher_date', [$this->from->toDateString(), $this->to->toDateString()]);
    }
}
