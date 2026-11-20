<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\PriceAgreementStatus;
use App\Models\Company;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Ändra avtal" (ADR-082 §4, P5): an active agreement's terms are frozen, so
 * a change is a new version. Its head and terms are copied into a DRAFT
 * with a new number that supersedes the active one, starting tomorrow (or
 * the day after the old one starts, if later). When the new version becomes
 * active the old one is SUPERSEDED and ends the day before.
 *
 * One open draft per agreement: asking again opens the draft already made.
 */
class NewPriceAgreementVersion
{
    public function __invoke(User $user, Company $company, PriceAgreement $agreement): PriceAgreement
    {
        if ((int) $agreement->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        Gate::forUser($user)->authorize('create', [PriceAgreement::class, $company]);

        return DB::transaction(fn (): PriceAgreement => CurrentCompany::run($company, function () use ($user, $company, $agreement): PriceAgreement {
            $old = PriceAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if (! in_array($old->status, [PriceAgreementStatus::Active, PriceAgreementStatus::Terminated], true)) {
                throw ValidationException::withMessages(['agreement' => __('Bara ett avtal som gäller kan ändras med en ny version.')]);
            }

            $pending = PriceAgreement::query()->where('supersedes_id', $old->id)->whereIn('status', [PriceAgreementStatus::Draft->value, PriceAgreementStatus::AwaitingSignature->value])->first();

            if ($pending instanceof PriceAgreement) {
                return $pending;
            }

            $start = CarbonImmutable::tomorrow();

            if (! $start->greaterThan($old->valid_from)) {
                $start = $old->valid_from->addDay();
            }

            DB::select('select pg_advisory_xact_lock(hashtext(?), ?)', ['price_agreements.number', $company->id]);

            $new = PriceAgreement::create([
                'number' => (int) PriceAgreement::query()->max('number') + 1,
                'customer_id' => $old->customer_id,
                'title' => $old->title,
                'currency' => $old->currency,
                'valid_from' => $start->toDateString(),
                'valid_to' => $old->valid_to !== null && $old->valid_to->greaterThanOrEqualTo($start) ? $old->valid_to->toDateString() : null,
                'allow_campaigns' => $old->allow_campaigns,
                'price_floor_policy' => $old->price_floor_policy,
                'crm_agreement_id' => $old->crm_agreement_id,
                'supersedes_id' => $old->id,
                'created_by' => $user->id,
            ]);

            foreach (PriceAgreementItem::query()->where('price_agreement_id', $old->id)->orderBy('id')->get() as $item) {
                PriceAgreementItem::create([
                    ...$item->only(['article_id', 'article_group_id', 'applies_to_all', 'unit_id', 'min_quantity_milli', 'price_minor', 'discount_bp', 'base_price_list_id', 'max_quantity_milli']),
                    'price_agreement_id' => $new->id,
                ]);
            }

            Audit::record('price_agreement.version_created', $new, ['price_agreement_id' => $new->id, 'supersedes_id' => $old->id], $company, $user);

            return $new;
        }));
    }
}
