<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Approval\ManageApprovalDelegations;
use App\Actions\Approval\SaveApprovalPolicy;
use App\Actions\Approval\SetDimensionOwner;
use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Approval\ApprovalDimension;
use App\Models\CostCenter;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * Attestflöden in the consultancy (ADR-081 gap 8): purchases from 20 000 kr
 * are approved by the owner of the cost centre and then by the owner or an
 * administrator; a substitute stands in for the cost centre's owner during a
 * week off; one invoice has passed its first step and waits for the second.
 * Runs after every invoice of the year is attested, so only the new invoice
 * follows the policy. The people are made up (example.test).
 */
final class ApprovalExtras extends DemoExtra
{
    public const array APPROVER = ['name' => 'Maja Attestsson', 'email' => 'maja.attestsson@example.test'];

    public const array PREPARER = ['name' => 'Elin Inköpare', 'email' => 'elin.inkopare@example.test'];

    public const string INVOICE_NUMBER = 'ATT-2026-17';

    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        config(['mail.default' => 'log']);

        $this->atomically($c, 'attestflöden', function () use ($c): void {
            CurrentCompany::run($c->company, function () use ($c): void {
                $approver = $this->member($c, self::APPROVER, 'approver');
                $preparer = $this->member($c, self::PREPARER, 'bookkeeper');
                $company = $c->company->fresh() ?? $c->company;

                $center = CostCenter::query()->where('code', 'RADG')->firstOrFail();
                app(SetDimensionOwner::class)($c->user, $company, ApprovalDimension::CostCenter, $center->id, $approver->id);

                app(SaveApprovalPolicy::class)($c->user, $company, [
                    'subject_type' => 'SUPPLIER_INVOICE',
                    'name' => 'Inköp från 20 000 kr',
                    'priority' => 10,
                    'is_active' => true,
                    'steps' => [
                        ['min_amount' => '20 000', 'approver_kind' => 'DIMENSION_OWNER', 'approver_dimension' => 'COST_CENTER', 'required_count' => 1, 'exclude_preparer' => true],
                        ['min_amount' => '20 000', 'approver_kind' => 'PERMISSION', 'approver_permission' => 'approvals:manage', 'required_count' => 1, 'exclude_preparer' => true],
                    ],
                ]);

                app(ManageApprovalDelegations::class)->add($approver, $company, [
                    'user_id' => $approver->id,
                    'substitute_user_id' => $c->user->id,
                    'starts_on' => $c->today->addWeeks(2)->toDateString(),
                    'ends_on' => $c->today->addWeeks(3)->toDateString(),
                ]);

                $supplier = Supplier::query()->where('name', 'Molntjänster Demo AB')->firstOrFail();
                $draft = app(SaveSupplierInvoice::class)($preparer, $company, [
                    'supplier_id' => $supplier->id,
                    'invoice_number' => self::INVOICE_NUMBER,
                    'invoice_date' => $c->today->subDays(3)->toDateString(),
                    'description' => 'Konsultplattform, licenser 2027',
                    'total' => '32500.00',
                    'vat' => '6500.00',
                    'vat_treatment' => 'DOMESTIC',
                    'rows' => [['account' => '5420', 'amount' => '26000.00', 'description' => 'Licenser 2027', 'cost_center' => 'RADG']],
                ]);
                $invoice = app(RegisterSupplierInvoice::class)($preparer, $draft);

                // The cost centre's owner has approved the first step; the second waits for the owner.
                app(ApproveSupplierInvoice::class)($approver, $invoice->fresh() ?? $invoice);
            });
        });
    }

    /** @param  array{name: string, email: string}  $person */
    private function member(DemoContext $c, array $person, string $role): User
    {
        $user = User::query()->firstOrCreate(['email' => $person['email']], ['name' => $person['name'], 'email_verified_at' => now()]);

        if (! $c->company->users()->whereKey($user->id)->exists()) {
            app(AddTeamMember::class)->add($c->user, $c->company, $person['email'], $role);
        }

        return $user->fresh() ?? $user;
    }
}
