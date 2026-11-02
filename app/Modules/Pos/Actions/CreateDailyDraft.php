<?php

declare(strict_types=1);

namespace App\Modules\Pos\Actions;

use App\Actions\Accounting\CreateDefaultVoucherSeries;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\ModuleManager;
use App\Modules\Pos\Models\PosFee;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\PosModule;
use App\Modules\Pos\Support\DailySettlement;
use App\Modules\Pos\Support\PosGate;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A day's till sales as one draft voucher in series K (ADR-042), never
 * posted here: a person checks it against the till's own daily report
 * (Z-rapport), attaches that report as the underlag and posts it. Every
 * sale and fee of the day not yet in a voucher goes in, and is marked with
 * the voucher, so a day is never proposed twice; deleting the draft frees
 * them again. Sales that arrive after the draft go into a new draft.
 *
 * TODO(legal-verify): whether the till's Z-report (kassaregisterlagen,
 * SKVFS 2014:9) must be the verifikation's underlag, and whether a summary
 * from the till's API may serve as one.
 */
class CreateDailyDraft
{
    public function __construct(private readonly SaveDraftVoucher $saveDraft) {}

    public function __invoke(User $user, Company $company, CarbonImmutable $date): Voucher
    {
        PosGate::draft($user, $company);

        $settings = [...PosModule::DEFAULTS, ...app(ModuleManager::class)->settings(PosModule::KEY, $company)];

        return CurrentCompany::run($company, fn (): Voucher => DB::transaction(function () use ($user, $company, $date, $settings): Voucher {
            $sales = PosSale::query()->whereNull('voucher_id')->whereDate('business_date', $date->toDateString())->orderBy('id')->lockForUpdate()->get();
            $fees = PosFee::query()->whereNull('voucher_id')->whereDate('business_date', $date->toDateString())->orderBy('id')->lockForUpdate()->get();

            if ($sales->isEmpty() && $fees->isEmpty()) {
                throw ValidationException::withMessages(['date' => __('Det finns ingen kassaförsäljning för :date som inte redan finns i en verifikation.', ['date' => $date->toDateString()])]);
            }

            $vatTotals = [];
            $payments = [];

            foreach ($sales as $sale) {
                foreach ($sale->vat_totals as $rate => $sums) {
                    $vatTotals[(int) $rate]['net'] = ($vatTotals[(int) $rate]['net'] ?? 0) + (int) $sums['net'];
                    $vatTotals[(int) $rate]['vat'] = ($vatTotals[(int) $rate]['vat'] ?? 0) + (int) $sums['vat'];
                }

                foreach ($sale->payments as $method => $amount) {
                    $payments[$method] = ($payments[$method] ?? 0) + (int) $amount;
                }
            }

            $built = DailySettlement::build($vatTotals, $payments, (int) $sales->sum('tips_ore'), (int) $fees->sum('amount_ore'), $sales->count(), $settings);

            if (! $built['balanced']) {
                throw ValidationException::withMessages(['date' => $built['errors'] === [] ? [__('Dagens belopp är noll.')] : $built['errors']]);
            }

            $chart = Account::query()->where('is_active', true)->pluck('number')->map(fn (mixed $number): int => (int) $number)->all();

            foreach ($built['rows'] as $row) {
                if (! in_array($row['account'], $chart, true)) {
                    throw ValidationException::withMessages(['date' => __('Konto :number finns inte i kontoplanen. Lägg till det eller välj ett annat under Moduler → Kassakopplingar.', ['number' => $row['account']])]);
                }
            }

            app(CreateDefaultVoucherSeries::class)($company, [PosModule::SERIES]);

            $voucher = ($this->saveDraft)($user, $company, [
                'voucher_date' => $date->toDateString(),
                'description' => __('Kassa :date, försäljning enligt kassan', ['date' => $date->toDateString()]),
                'series' => PosModule::SERIES,
                'rows' => $built['rows'],
            ]);

            PosSale::query()->whereKey($sales->modelKeys())->update(['voucher_id' => $voucher->id]);
            PosFee::query()->whereKey($fees->modelKeys())->update(['voucher_id' => $voucher->id]);

            Audit::record('pos.daily_draft_created', $voucher, [
                'date' => $date->toDateString(),
                'sales' => $sales->count(),
                'fees' => $fees->count(),
            ], $company, $user);

            return $voucher;
        }));
    }
}
