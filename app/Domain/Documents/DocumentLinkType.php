<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Models\Company;
use App\Models\Customer;
use App\Models\FixedAsset;
use App\Models\Project;
use App\Models\Supplier;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\WorkOrdersModule;
use Illuminate\Database\Eloquent\Model;

/**
 * The records a company document may be linked to (the allow-list of
 * document_links.linkable_type). A voucher is not on the list: a document
 * that is underlag for a voucher is attached through voucher_attachments as
 * any underlag is, with the same stored file (ADR-081 gap 9, accounting
 * impact), so there is no second route to underlag.
 */
enum DocumentLinkType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Project = 'project';
    case FixedAsset = 'fixed_asset';
    case Employee = 'employee';
    case WorkOrder = 'work_order';

    public function label(): string
    {
        return match ($this) {
            self::Customer => __('Kund'),
            self::Supplier => __('Leverantör'),
            self::Project => __('Projekt'),
            self::FixedAsset => __('Anläggningstillgång'),
            self::Employee => __('Anställd'),
            self::WorkOrder => __('Arbetsorder'),
        };
    }

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Customer => Customer::class,
            self::Supplier => Supplier::class,
            self::Project => Project::class,
            self::FixedAsset => FixedAsset::class,
            self::Employee => Employee::class,
            self::WorkOrder => WorkOrder::class,
        };
    }

    /** @return list<string> the columns needed for the record's name */
    public function columns(): array
    {
        return match ($this) {
            self::Customer, self::Supplier => ['id', 'number', 'name'],
            self::Project => ['id', 'code', 'name'],
            self::FixedAsset => ['id', 'number', 'name'],
            self::Employee => ['id', 'employee_number', 'first_name', 'last_name'],
            self::WorkOrder => ['id', 'number', 'title'],
        };
    }

    public function orderColumn(): string
    {
        return match ($this) {
            self::Project => 'code',
            self::Employee => 'employee_number',
            default => 'number',
        };
    }

    public function name(Model $record): string
    {
        return match ($this) {
            self::Customer, self::Supplier, self::FixedAsset => "{$record->getAttribute('number')} {$record->getAttribute('name')}",
            self::Project => "{$record->getAttribute('code')} {$record->getAttribute('name')}",
            self::Employee => "{$record->getAttribute('first_name')} {$record->getAttribute('last_name')}",
            self::WorkOrder => "{$record->getAttribute('number')} {$record->getAttribute('title')}",
        };
    }

    /**
     * Whether the type can be used for a document of this class in the
     * company: employees only on personnel documents and with payroll on,
     * work orders only with the work order module on.
     */
    public function availableFor(Company $company, RetentionClass $class): bool
    {
        return match ($this) {
            self::Employee => $class === RetentionClass::Personnel && app(ModuleManager::class)->isEnabled(PayrollModule::KEY, $company),
            self::WorkOrder => app(ModuleManager::class)->isEnabled(WorkOrdersModule::KEY, $company),
            default => true,
        };
    }

    /** @return list<self> */
    public static function availableCases(Company $company, RetentionClass $class): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->availableFor($company, $class)));
    }
}
