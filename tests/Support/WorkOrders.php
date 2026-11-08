<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Invoicing\SaveCustomer;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Actions\ChangeWorkOrderStatus;
use App\Modules\WorkOrders\Actions\RecordTime;
use App\Modules\WorkOrders\Actions\SaveWorkOrder;
use App\Modules\WorkOrders\Domain\WorkOrderStatus;
use App\Modules\WorkOrders\Models\LabourKind;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;

/**
 * Arbetsorder in tests (ADR-074): the module on, synthetic customers (a
 * private ROT buyer, a construction business under reverse charge, a
 * housing association) and work orders. Every name, number and property
 * is invented; personal and organisation numbers get a valid Luhn digit.
 */
final class WorkOrders
{
    public static function enable(Company $company, User $owner): void
    {
        app(ModuleManager::class)->enable(WorkOrdersModule::KEY, $company, $owner);
        CurrentCompany::set($company);
    }

    public static function personalNumber(string $nine = '850101123'): string
    {
        return '19'.substr($nine, 0, 6).'-'.substr($nine, 6).Luhn::checkDigit($nine);
    }

    public static function orgNumber(string $nine = '559900123'): string
    {
        return substr($nine, 0, 6).'-'.substr($nine, 6).Luhn::checkDigit($nine);
    }

    /** @param  array<string, mixed>  $extra */
    public static function privateCustomer(Company $company, User $owner, string $name = 'Eva Exempelsson', string $nine = '850101123', array $extra = []): Customer
    {
        return app(SaveCustomer::class)($owner, $company, [
            'name' => $name, 'personal_number' => self::personalNumber($nine), 'email' => 'eva@example.com',
            'address_line1' => 'Ekbacksvägen 3', 'postal_code' => '123 45', 'city' => 'Exempelstad', 'payment_terms_days' => 30,
            ...$extra,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    public static function business(Company $company, User $owner, string $name = 'Huvudentreprenören Exempel AB', string $nine = '559900123', array $extra = []): Customer
    {
        $org = self::orgNumber($nine);

        return app(SaveCustomer::class)($owner, $company, [
            'name' => $name, 'org_number' => $org, 'vat_number' => 'SE'.str_replace('-', '', $org).'01', 'email' => 'bygg@example.com',
            'address_line1' => 'Byggvägen 1', 'postal_code' => '123 46', 'city' => 'Exempelstad', 'payment_terms_days' => 30,
            ...$extra,
        ]);
    }

    /** @param  array<string, mixed>  $input */
    public static function workOrder(Company $company, User $owner, Customer $customer, array $input = []): WorkOrder
    {
        $workOrder = app(SaveWorkOrder::class)($owner, $company, [
            'customer_id' => $customer->id,
            'title' => 'Badrumsrenovering',
            'price_form' => 'TIME_AND_MATERIAL',
            ...$input,
        ]);

        return app(ChangeWorkOrderStatus::class)($owner, $company, $workOrder, WorkOrderStatus::InProgress);
    }

    public static function kind(string $code = 'ARBETE'): LabourKind
    {
        return LabourKind::query()->where('code', $code)->firstOrFail();
    }

    public static function hours(Company $company, User $user, WorkOrder $workOrder, string $hours, string $date = '2026-03-02', ?int $changeOrderId = null, string $rate = '600'): TimeEntry
    {
        return app(RecordTime::class)($user, $company, $workOrder, [
            'labour_kind_id' => self::kind()->id, 'work_date' => $date, 'hours' => $hours, 'change_order_id' => $changeOrderId, 'rate' => $rate,
        ]);
    }
}
