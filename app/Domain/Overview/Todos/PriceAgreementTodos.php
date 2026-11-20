<?php

declare(strict_types=1);

namespace App\Domain\Overview\Todos;

use App\Domain\Overview\Contracts\TodoProvider;
use App\Domain\Overview\TodoItem;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Models\Company;
use App\Models\PriceAgreement;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;

/**
 * Price agreements that run out within 30 days (ADR-082 §4, P5), for those
 * who may make a new version. An agreement linked to an agreement in the
 * CRM register is left out while CRM is on, since the register's own
 * reminders cover it, so nobody gets two reminders; so is one that already
 * has a new version on the way. One query, plus the module check only
 * when an agreement linked to the CRM register runs out.
 */
final class PriceAgreementTodos implements TodoProvider
{
    public const int DAYS_AHEAD = 30;

    public function todos(User $user, Company $company, CarbonImmutable $today): array
    {
        if (! $user->hasTeamPermission($company, 'price-agreements:manage')) {
            return [];
        }

        $agreements = PriceAgreement::query()
            ->where('company_id', $company->id)
            ->where('status', PriceAgreementStatus::Active->value)
            ->whereNotNull('valid_to')
            ->whereBetween('valid_to', [$today->toDateString(), $today->addDays(self::DAYS_AHEAD)->toDateString()])
            ->whereNotExists(fn ($query) => $query->from('price_agreements as next')
                ->whereColumn('next.supersedes_id', 'price_agreements.id')
                ->whereIn('next.status', [PriceAgreementStatus::Draft->value, PriceAgreementStatus::AwaitingSignature->value, PriceAgreementStatus::Active->value]))
            ->with('customer:id,name')
            ->orderBy('valid_to')
            ->orderBy('number')
            ->get();

        // Linked to the CRM register: its reminders cover them while CRM is on. Asked only when there is one.
        if ($agreements->contains(fn (PriceAgreement $agreement): bool => $agreement->crm_agreement_id !== null)
            && app(ModuleManager::class)->isEnabled(CrmModule::KEY, $company)) {
            $agreements = $agreements->whereNull('crm_agreement_id');
        }

        return array_values($agreements->map(fn (PriceAgreement $agreement): TodoItem => new TodoItem(
            "price-agreement-expiry-{$agreement->id}",
            'calendar',
            __('Prisavtalet :reference med :customer löper ut :date', [
                'reference' => $agreement->reference(),
                'customer' => $agreement->customer->name ?? '',
                'date' => $agreement->valid_to?->toDateString(),
            ]),
            __('Gör en ny version med Ändra avtal om priserna ska fortsätta gälla, annars upphör avtalet av sig självt.'),
            route('pricing.agreements.show', $agreement->id),
            TodoItem::PRIORITY_OTHER,
            $agreement->valid_to,
        ))->all());
    }

    public function invalidatedBy(): array
    {
        return [PriceAgreement::class];
    }
}
