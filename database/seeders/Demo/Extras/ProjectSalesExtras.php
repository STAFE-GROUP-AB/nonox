<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Accounting\SaveProjectDetails;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Models\Customer;
use App\Models\Project;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Validation\ValidationException;

/**
 * The project register and cost centre and project on invoice rows (ADR-081
 * gap 6, E6), in the consultancy: the projects of LedgerExtras get their
 * customer, project manager, price form and sums; one customer gets a
 * default project; one invoice is issued with its revenue on the projects,
 * and one draft shows the columns in the invoice editor.
 */
final class ProjectSalesExtras extends DemoExtra
{
    /** The draft the manual's screenshot opens. */
    public const string DRAFT_REFERENCE = 'Systembyte, etapp 2';

    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Projektregister', function () use ($c): void {
            $customer = Customer::query()->where('name', 'Kunden Exempel AB')->first()
                ?? throw ValidationException::withMessages(['customer' => 'Kunden Exempel AB saknas.']);
            $other = Customer::query()->where('name', 'Mjukvaruhuset Exempel AB')->first();
            $first = Project::query()->where('code', 'P-101')->firstOrFail();
            $second = Project::query()->where('code', 'P-102')->firstOrFail();
            $start = $c->today->subMonths(3)->startOfMonth();

            app(SaveProjectDetails::class)($c->user, $c->company, $first, [
                'customer_id' => (string) $customer->id, 'manager_user_id' => (string) $c->user->id, 'status' => 'ACTIVE',
                'starts_on' => $start->toDateString(), 'ends_on' => $start->addMonths(8)->subDay()->toDateString(),
                'price_form' => 'FIXED', 'contract_sum' => '450 000', 'estimated_cost' => '310 000', 'default_cost_center' => 'RADG',
            ]);
            app(SaveProjectDetails::class)($c->user, $c->company, $second, [
                'customer_id' => (string) $other?->id, 'manager_user_id' => (string) $c->user->id, 'status' => 'ACTIVE',
                'starts_on' => $start->addMonth()->toDateString(), 'price_form' => 'TIME_AND_MATERIAL', 'default_cost_center' => 'UTB',
            ]);

            app(SaveCustomer::class)($c->user, $c->company, [...$customer->only(['name', 'payment_terms_days']), 'default_cost_center' => 'RADG', 'default_project' => 'P-101'], $customer);

            $date = $c->today->subDays(10)->toDateString();
            app(IssueInvoice::class)($c->user, app(SaveDraftInvoice::class)($c->user, $c->company, [
                'customer_id' => $customer->id, 'invoice_date' => $date, 'your_reference' => 'Systembyte, etapp 1',
                'rows' => [
                    ['description' => 'Kravanalys och upphandlingsstöd', 'quantity' => '24', 'price' => '1 250', 'vat_rate' => 2500, 'cost_center' => 'RADG', 'project' => 'P-101'],
                    ['description' => 'Utbildning av nyckelanvändare', 'quantity' => '1', 'price' => '18 000', 'vat_rate' => 2500, 'cost_center' => 'UTB', 'project' => 'P-101'],
                ],
            ]));

            app(SaveDraftInvoice::class)($c->user, $c->company, [
                'customer_id' => $customer->id, 'invoice_date' => $c->today->toDateString(), 'your_reference' => self::DRAFT_REFERENCE,
                'rows' => [
                    ['description' => 'Projektledning, införande', 'quantity' => '16', 'price' => '1 250', 'vat_rate' => 2500],
                    ['description' => 'Workshop för ledningsgruppen', 'quantity' => '1', 'price' => '12 500', 'vat_rate' => 2500, 'cost_center' => 'UTB', 'project' => 'P-102'],
                ],
            ]);
        });
    }
}
