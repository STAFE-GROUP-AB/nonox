<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Projects\MilestoneStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Fakturera" on a milestone that is ready (ADR-081 gap 6, E8): an ordinary
 * DRAFT invoice to the project's customer with one row, the milestone's
 * description, its amount excluding VAT (or its share of the contract sum,
 * half up to the öre) and the project on the row, so the revenue is booked on
 * the project when the invoice is issued (E6). Nothing is sent or booked
 * here. VAT, reverse charge and foreign sale follow the customer as on any
 * draft, and the person checks the draft before issuing it.
 *
 * Made only from the version the person saw, once: the milestone is locked,
 * its token compared, and a milestone with a linked invoice is refused.
 *
 * Neither customers nor projects carry a default VAT rate, and a milestone
 * has no article, so the row gets 25 %; the toast asks the person to check it.
 *
 * TODO(legal-verify): an invoice for work not yet performed is an advance
 * invoice; whether it needs other wording. Its VAT arises when the payment
 * is received (Mervärdesskattelag (2023:200) 7 kap. 7 §, read on
 * riksdagen.se 2026-11-18) and is reported for that period (7 kap. 14 §).
 */
class InvoiceBillingMilestone
{
    /** No customer, project or milestone carries a default rate yet. */
    public const int VAT_RATE = 2500;

    public function __construct(private readonly SaveDraftInvoice $save) {}

    public function __invoke(User $user, Company $company, ProjectBillingMilestone $milestone, string $token, ?CarbonImmutable $invoiceDate = null): Invoice
    {
        $project = CurrentCompany::run($company, fn (): Project => Project::query()->findOrFail($milestone->project_id));
        Gate::forUser($user)->authorize('invoiceMilestone', $project);

        return DB::transaction(fn (): Invoice => CurrentCompany::run($company, function () use ($user, $company, $milestone, $token, $invoiceDate): Invoice {
            /** @var Project $project */
            $project = Project::query()->whereKey($milestone->project_id)->lockForUpdate()->firstOrFail();
            $current = ProjectBillingMilestone::query()->whereKey($milestone->id)->lockForUpdate()->firstOrFail();

            if (! hash_equals($current->versionToken(), $token)) {
                throw ValidationException::withMessages(['milestone' => __('Delfaktureringen har ändrats sedan du öppnade sidan. Granska den igen.')]);
            }

            if ($current->effectiveStatus() !== MilestoneStatus::Ready) {
                throw ValidationException::withMessages(['milestone' => $current->effectiveStatus() === MilestoneStatus::Invoiced
                    ? __('Delfaktureringen är redan fakturerad.')
                    : __('Markera delfaktureringen som klar att fakturera först.')]);
            }

            if ($project->customer_id === null) {
                throw ValidationException::withMessages(['milestone' => __('Projektet saknar kund. Välj kund i projektregistret först.')]);
            }

            $amount = $current->amountFor($project->contract_sum_ore?->ore);

            if ($amount === null || $amount <= 0) {
                throw ValidationException::withMessages(['milestone' => __('En andel kräver en kontraktssumma i projektregistret.')]);
            }

            $invoice = ($this->save)($user, $company, [
                'customer_id' => $project->customer_id,
                'invoice_date' => ($invoiceDate ?? CarbonImmutable::today())->toDateString(),
                'rows' => [[
                    'description' => mb_substr($current->description, 0, 255),
                    'quantity' => '1',
                    'price' => intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT),
                    'vat_rate' => self::VAT_RATE,
                    'project' => $project->code,
                ]],
            ]);

            $current->update(['status' => MilestoneStatus::Invoiced->value, 'invoice_id' => $invoice->id]);
            Audit::record('projects.milestone_invoiced', $current, ['project_id' => $project->id, 'invoice_id' => $invoice->id, 'amount_ore' => $amount], $company, $user);

            return $invoice;
        }));
    }
}
