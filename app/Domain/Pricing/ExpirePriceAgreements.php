<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\PriceAgreement;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\Agreement;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The daily upkeep of a company's price agreements (ADR-082 §4, P5), run by
 * `pricing:expire` in the company's own context:
 *
 * 1. While CRM is on, a draft or active agreement linked to an agreement in
 *    the CRM register follows that agreement's end date (a renewal moves it
 *    forward), unless that would make it overlap another agreement of the
 *    customer or end before it starts. An agreement awaiting a signature is
 *    left alone, since its document is out for signing.
 * 2. An ACTIVE agreement whose last day has passed becomes ENDED. It still
 *    prices documents dated inside its validity.
 *
 * Idempotent: a second run the same day changes nothing.
 */
final class ExpirePriceAgreements
{
    /** @return array{ended: int, followed: int} */
    public static function forCompany(int $companyId, CarbonImmutable $today): array
    {
        return CurrentCompany::run($companyId, function () use ($companyId, $today): array {
            $followed = app(ModuleManager::class)->isEnabled(CrmModule::KEY, $companyId) ? self::followCrm() : 0;
            $ended = 0;

            $due = PriceAgreement::query()
                ->where('status', PriceAgreementStatus::Active->value)
                ->whereNotNull('valid_to')
                ->where('valid_to', '<', $today->toDateString())
                ->pluck('id');

            foreach ($due as $id) {
                $ended += DB::transaction(function () use ($id, $today): int {
                    $locked = PriceAgreement::query()->whereKey($id)->lockForUpdate()->first();

                    $end = $locked?->valid_to;

                    if ($locked === null || $end === null || $locked->status !== PriceAgreementStatus::Active || ! $end->lessThan($today)) {
                        return 0;
                    }

                    $locked->forceFill(['status' => PriceAgreementStatus::Ended])->save();
                    Audit::record('price_agreement.ended', $locked, ['price_agreement_id' => $locked->id, 'valid_to' => $end->toDateString()]);

                    return 1;
                });
            }

            return ['ended' => $ended, 'followed' => $followed];
        });
    }

    /** Linked agreements take the CRM agreement's end date; the number changed. */
    private static function followCrm(): int
    {
        $linked = PriceAgreement::query()
            ->whereNotNull('crm_agreement_id')
            ->whereIn('status', [PriceAgreementStatus::Draft->value, PriceAgreementStatus::Active->value])
            ->get(['id', 'crm_agreement_id', 'valid_to']);

        if ($linked->isEmpty()) {
            return 0;
        }

        $ends = Agreement::query()->whereIn('id', $linked->pluck('crm_agreement_id')->unique()->values()->all())->get(['id', 'end_date'])->keyBy('id');
        $activator = app(PriceAgreementActivator::class);
        $changed = 0;

        foreach ($linked as $agreement) {
            $crm = $ends->get($agreement->crm_agreement_id);

            if ($crm === null || $crm->end_date?->toDateString() === $agreement->valid_to?->toDateString()) {
                continue;
            }

            $changed += DB::transaction(function () use ($agreement, $crm, $activator): int {
                $locked = PriceAgreement::query()->whereKey($agreement->id)->lockForUpdate()->first();

                if ($locked === null || ! in_array($locked->status, [PriceAgreementStatus::Draft, PriceAgreementStatus::Active], true)) {
                    return 0;
                }

                if ($crm->end_date !== null && $crm->end_date->lessThan($locked->valid_from)) {
                    return 0;
                }

                $locked->valid_to = $crm->end_date;

                if ($activator->overlapping($locked) !== null) {
                    return 0;
                }

                $locked->save();
                Audit::record('price_agreement.end_followed', $locked, ['price_agreement_id' => $locked->id, 'crm_agreement_id' => $crm->id, 'valid_to' => $crm->end_date?->toDateString()]);

                return 1;
            });
        }

        return $changed;
    }
}
