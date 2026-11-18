<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Group\IntercompanyOrgNumber;
use App\Models\Company;
use App\Models\IntercompanyReceiptSetting;
use App\Models\User;
use App\Policies\IntercompanyDeliveryPolicy;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The receiving company's consent to intra-group invoices (ADR-081 E27):
 * from which group companies, named by organisation number, invoices may be
 * delivered into its inbox. Only the company's owner or administrator
 * (company:update) turns it on or off. Nothing is delivered without it, and
 * the sender is never told whether it is on.
 *
 * The audit entry holds the setting's id and whether it is on, not the
 * organisation number.
 */
class SetIntercompanyReceipt
{
    /** Turns receipt from the sender on (a new or an existing setting). */
    public function enable(User $user, Company $company, string $senderOrgNumber): IntercompanyReceiptSetting
    {
        $this->authorize($user, $company);
        $number = IntercompanyOrgNumber::validated($company, $senderOrgNumber, 'sender_org_number');

        return CurrentCompany::run($company, fn (): IntercompanyReceiptSetting => DB::transaction(function () use ($user, $company, $number): IntercompanyReceiptSetting {
            $setting = IntercompanyReceiptSetting::query()->where('sender_org_number', $number)->lockForUpdate()->first()
                ?? new IntercompanyReceiptSetting(['sender_org_number' => $number]);
            $setting->fill(['enabled' => true, 'enabled_by' => $user->id])->save();

            Audit::record('intercompany.receipt_enabled', $setting, ['setting_id' => $setting->id, 'enabled' => true], $company, $user);

            return $setting;
        }));
    }

    /** Turns receipt from the sender off; the setting stays, switched off, so the history reads plainly. */
    public function disable(User $user, Company $company, int $settingId): IntercompanyReceiptSetting
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, fn (): IntercompanyReceiptSetting => DB::transaction(function () use ($user, $company, $settingId): IntercompanyReceiptSetting {
            $setting = IntercompanyReceiptSetting::query()->whereKey($settingId)->lockForUpdate()->firstOrFail();
            $setting->fill(['enabled' => false, 'enabled_by' => $user->id])->save();

            Audit::record('intercompany.receipt_disabled', $setting, ['setting_id' => $setting->id, 'enabled' => false], $company, $user);

            return $setting;
        }));
    }

    /** @throws AuthorizationException */
    private function authorize(User $user, Company $company): void
    {
        if (! IntercompanyDeliveryPolicy::mayManageReceipt($user, $company)) {
            throw new AuthorizationException(__('Bara företagets ägare eller administratör kan ändra vilka koncernföretag som får leverera fakturor hit.'));
        }
    }
}
