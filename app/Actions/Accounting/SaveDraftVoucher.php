<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Actions\Assets\AssetProposals;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Models\VoucherSeries;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a draft voucher. A draft may be unbalanced; balance and
 * every other posting rule are checked by PostVoucher.
 *
 * A date in the year right after the latest one, at most thirty days ahead,
 * creates that year first when it does not exist yet (CreateNextFiscalYear::
 * covering): invoices, payments and vouchers all pass through here, so
 * none of them stops at the turn of the year. Lock order: the caller's own
 * locks, then the company's fiscal-year advisory lock, then PostVoucher's.
 */
class SaveDraftVoucher
{
    public function __construct(
        private NormaliseVoucherRows $normaliseRows,
        private AssetProposals $assetProposals,
        private CreateNextFiscalYear $createNextFiscalYear,
    ) {}

    /**
     * @param  array<string, mixed>  $input  voucher_date (Y-m-d), description, series (code, default A),
     *                                       rows (list of account, debit_ore, credit_ore, description,
     *                                       cost_center and project codes, and optionally asset: the
     *                                       register's fields for an asset to create when it is posted),
     *                                       with_assets (true when the rows' asset fields replace the
     *                                       draft's proposals; without it they are left as they are)
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, array $input, ?Voucher $draft = null): Voucher
    {
        $draft === null
            ? Gate::forUser($user)->authorize('create', [Voucher::class, $company])
            : Gate::forUser($user)->authorize('update', $draft);

        $validated = Validator::make($input, [
            'voucher_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'series' => ['nullable', 'string', 'max:3'],
            'rows' => ['required', 'array', 'min:1'],
        ], [
            'rows.required' => __('Lägg till minst en rad.'),
            'rows.min' => __('Lägg till minst en rad.'),
        ], [
            'voucher_date' => __('datum'),
            'description' => __('beskrivning'),
        ])->validate();

        return CurrentCompany::run($company, fn (): Voucher => DB::transaction(function () use ($validated, $user, $company, $input, $draft): Voucher {
            $date = CarbonImmutable::createFromFormat('Y-m-d', (string) $validated['voucher_date'])?->startOfDay()
                ?? throw ValidationException::withMessages(['voucher_date' => __('Datumet ska skrivas som ÅÅÅÅ-MM-DD.')]);

            // The safety net when the morning run has not created the next year yet (CreateNextFiscalYear::covering).
            $fiscalYear = FiscalYear::query()
                ->where('start_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->first()
                ?? $this->createNextFiscalYear->covering($company, $date, CarbonImmutable::today(), $user)
                ?? throw ValidationException::withMessages([
                    'voucher_date' => __('Det finns inget räkenskapsår som omfattar :date.', ['date' => $date->toDateString()]),
                ]);

            $seriesCode = strtoupper((string) ($validated['series'] ?? 'A'));
            $series = VoucherSeries::query()->where('code', $seriesCode)->first()
                ?? throw ValidationException::withMessages(['series' => __('Verifikationsserien :code finns inte.', ['code' => $seriesCode])]);

            /** @var list<array<string, mixed>> $rowInput */
            $rowInput = array_values((array) $validated['rows']);
            $rows = ($this->normaliseRows)($rowInput);

            $voucher = $draft ?? new Voucher(['created_by' => $user->id]);
            $voucher->fill([
                'fiscal_year_id' => $fiscalYear->id,
                'series_id' => $series->id,
                'voucher_date' => $date,
                'description' => trim((string) $validated['description']),
            ])->save();

            VoucherRow::query()->where('voucher_id', $voucher->id)->delete();

            foreach ($rows as $row) {
                VoucherRow::create(['voucher_id' => $voucher->id, ...$row]);
            }

            if (($input['with_assets'] ?? false) === true) {
                $this->assetProposals->sync($user, $company, $voucher, array_map(fn (array $row): mixed => $row['asset'] ?? null, $rowInput));
            }

            return $voucher->load('rows');
        }));
    }
}
