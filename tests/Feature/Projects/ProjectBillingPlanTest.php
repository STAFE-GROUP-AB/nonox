<?php

declare(strict_types=1);

/*
 * ADR-081 gap 6 scope 5, package E8: a billing plan per project. Milestones
 * with a date and an amount or a share of the contract sum; "Fakturera"
 * makes a draft invoice with the project on its row, once, from the version
 * the person saw. Nothing is sent or booked.
 */

use App\Actions\Accounting\SaveDimensionObject;
use App\Actions\Accounting\SaveProjectDetails;
use App\Actions\Company\SetDimensionScope;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Projects\DeleteBillingMilestone;
use App\Actions\Projects\InvoiceBillingMilestone;
use App\Actions\Projects\SaveBillingMilestone;
use App\Domain\Accounting\Dimension;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Projects\MilestoneStatus;
use App\Livewire\Projects\ProjectBillingPlan;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Beställaren Exempel AB', 'payment_terms_days' => 30]);
    $project = app(SaveDimensionObject::class)($this->owner, $this->company, Dimension::Project, ['code' => 'P1', 'name' => 'Nytt kontor']);
    $this->project = app(SaveProjectDetails::class)($this->owner, $this->company, $project, [
        'customer_id' => (string) $this->customer->id, 'status' => 'ACTIVE', 'price_form' => 'FIXED', 'contract_sum' => '333 333,33',
    ]);
});

/** @param  array<string, string>  $input */
function e8Milestone(array $input = []): ProjectBillingMilestone
{
    return app(SaveBillingMilestone::class)(test()->owner, test()->company, test()->project, [
        'description' => 'Etapp 1', 'due_on' => '2026-07-01', 'kind' => 'AMOUNT', 'amount' => '50 000', 'share' => '', 'status' => 'PLANNED', ...$input,
    ]);
}

it('keeps milestones with an amount or a share of the contract sum, in order', function () {
    $first = e8Milestone();
    $second = e8Milestone(['description' => 'Etapp 2', 'kind' => 'SHARE', 'share' => '12,5', 'amount' => '']);

    expect([$first->position, $second->position])->toBe([1, 2])
        ->and($first->amount_ore?->ore)->toBe(5_000_000)
        ->and($first->share_bp)->toBeNull()
        ->and($second->share_bp)->toBe(1_250)
        ->and($second->amount_ore)->toBeNull()
        // 12,5 % of 333 333,33 kr = 41 666,67 kr, half up to the öre.
        ->and($second->amountFor($this->project->contract_sum_ore?->ore))->toBe(4_166_667)
        ->and(AuditLog::query()->where('action', 'projects.milestone_created')->count())->toBe(2);

    expect(AuditLog::query()->where('action', 'projects.milestone_created')->latest('id')->first()->payload)
        ->toEqual(['project_id' => $this->project->id, 'amount_ore' => null, 'share_bp' => 1_250, 'status' => 'PLANNED', 'due_on' => '2026-07-01']);
});

it('refuses shares over 100 %, a share without a contract sum, a bad amount and a date that does not exist', function () {
    e8Milestone(['kind' => 'SHARE', 'share' => '60', 'amount' => '']);

    expect(fn () => e8Milestone(['kind' => 'SHARE', 'share' => '41', 'amount' => '']))->toThrow(ValidationException::class, 'mer än 100 %')
        ->and(fn () => e8Milestone(['amount' => '0']))->toThrow(ValidationException::class, 'större än noll')
        ->and(fn () => e8Milestone(['amount' => 'mycket']))->toThrow(ValidationException::class)
        ->and(fn () => e8Milestone(['due_on' => '2026-02-30']))->toThrow(ValidationException::class)
        ->and(fn () => e8Milestone(['description' => '']))->toThrow(ValidationException::class);

    $this->project->update(['contract_sum_ore' => null]);
    expect(fn () => e8Milestone(['kind' => 'SHARE', 'share' => '10', 'amount' => '']))->toThrow(ValidationException::class, 'kontraktssumma');
});

it('turns a ready milestone into a draft invoice to the project\'s customer with the project on the row, once', function () {
    $milestone = e8Milestone(['kind' => 'SHARE', 'share' => '12,5', 'amount' => '', 'status' => 'READY']);

    $invoice = app(InvoiceBillingMilestone::class)($this->owner, $this->company, $milestone, $milestone->versionToken());

    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->customer_id)->toBe($this->customer->id)
        ->and($invoice->rows()->sole()->only(['description', 'project_id']))->toBe(['description' => 'Etapp 1', 'project_id' => $this->project->id])
        ->and($invoice->rows()->sole()->net_ore->ore)->toBe(4_166_667);

    $milestone->refresh();
    expect($milestone->status)->toBe(MilestoneStatus::Invoiced)
        ->and($milestone->invoice_id)->toBe($invoice->id)
        ->and(AuditLog::query()->where('action', 'projects.milestone_invoiced')->sole()->payload)
        ->toEqual(['project_id' => $this->project->id, 'invoice_id' => $invoice->id, 'amount_ore' => 4_166_667]);

    expect(fn () => app(InvoiceBillingMilestone::class)($this->owner, $this->company, $milestone, $milestone->versionToken()))
        ->toThrow(ValidationException::class, 'redan fakturerad')
        ->and(Invoice::query()->count())->toBe(1);
});

it('refuses to invoice a planned milestone, a changed one, and a project without a customer', function () {
    $planned = e8Milestone();
    expect(fn () => app(InvoiceBillingMilestone::class)($this->owner, $this->company, $planned, $planned->versionToken()))
        ->toThrow(ValidationException::class, 'klar att fakturera');

    $ready = e8Milestone(['description' => 'Etapp 2', 'status' => 'READY']);
    $seen = $ready->versionToken();
    $this->travel(1)->seconds();
    app(SaveBillingMilestone::class)($this->owner, $this->company, $this->project, ['description' => 'Etapp 2', 'due_on' => '', 'kind' => 'AMOUNT', 'amount' => '60 000', 'share' => '', 'status' => 'READY'], $ready, $seen);
    expect(fn () => app(InvoiceBillingMilestone::class)($this->owner, $this->company, $ready, $seen))
        ->toThrow(ValidationException::class, 'har ändrats');

    $this->project->update(['customer_id' => null]);
    $ready->refresh();
    expect(fn () => app(InvoiceBillingMilestone::class)($this->owner, $this->company, $ready, $ready->versionToken()))
        ->toThrow(ValidationException::class, 'saknar kund')
        ->and(Invoice::query()->count())->toBe(0);
});

it('cannot change or delete an invoiced milestone, and frees it again when its draft is deleted', function () {
    $milestone = e8Milestone(['status' => 'READY']);
    $invoice = app(InvoiceBillingMilestone::class)($this->owner, $this->company, $milestone, $milestone->versionToken());
    $milestone->refresh();

    expect(fn () => app(DeleteBillingMilestone::class)($this->owner, $this->company, $milestone, $milestone->versionToken()))
        ->toThrow(ValidationException::class, 'redan fakturerad')
        ->and(fn () => app(SaveBillingMilestone::class)($this->owner, $this->company, $this->project, ['description' => 'X', 'kind' => 'AMOUNT', 'amount' => '1', 'status' => 'PLANNED'], $milestone, $milestone->versionToken()))
        ->toThrow(ValidationException::class, 'redan fakturerad');

    $invoice->rows()->delete();
    $invoice->delete();
    $milestone->refresh();

    expect($milestone->invoice_id)->toBeNull()
        ->and($milestone->effectiveStatus())->toBe(MilestoneStatus::Ready);

    app(InvoiceBillingMilestone::class)($this->owner, $this->company, $milestone, $milestone->versionToken());
    expect(Invoice::query()->count())->toBe(1);
});

it('deletes a milestone that is not invoiced', function () {
    $milestone = e8Milestone();
    app(DeleteBillingMilestone::class)($this->owner, $this->company, $milestone, $milestone->versionToken());

    expect(ProjectBillingMilestone::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'projects.milestone_deleted')->sole()->payload)->toEqual(['project_id' => $this->project->id]);
});

it('lets a bookkeeper invoice a milestone but not keep the plan, and an auditor only read it', function () {
    $milestone = e8Milestone(['status' => 'READY']);
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $auditor = Bookkeeping::member($this->company, 'auditor');

    expect(fn () => app(SaveBillingMilestone::class)($bookkeeper, $this->company, $this->project, ['description' => 'X', 'kind' => 'AMOUNT', 'amount' => '1', 'status' => 'PLANNED']))
        ->toThrow(AuthorizationException::class);
    app(InvoiceBillingMilestone::class)($bookkeeper, $this->company, $milestone, $milestone->versionToken());

    $this->actingAs($auditor);
    Livewire::test(ProjectBillingPlan::class, ['project' => $this->project])
        ->assertSee('Etapp 1')
        ->assertDontSee('Lägg till delfakturering')
        ->assertDontSee('Fakturera</button>', false);
});

it('leaves the billing plan out for a member limited to the project', function () {
    $member = Bookkeeping::member($this->company, 'unit_manager');
    app(SetDimensionScope::class)($this->owner, $this->company, $member->id, [], [$this->project->id]);
    e8Milestone();

    $this->actingAs($member)->get(route('projects.show', $this->project))->assertOk()->assertDontSee('Faktureringsplan');
    $this->actingAs($this->owner)->get(route('projects.show', $this->project))->assertOk()->assertSee('Faktureringsplan')->assertSee('Etapp 1');
});

it('adds, marks ready, invoices and deletes from the project page with toasts', function () {
    $page = Livewire::test(ProjectBillingPlan::class, ['project' => $this->project])
        ->call('create')
        ->set('form.description', 'Projektering')
        ->set('form.amount', '40 000')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $milestone = ProjectBillingMilestone::query()->sole();
    $page->call('markReady', $milestone->id, $milestone->versionToken())->assertDispatched('toast');
    $milestone->refresh();
    $page->call('invoice', $milestone->id, $milestone->versionToken())->assertRedirect();

    expect($milestone->fresh()?->status)->toBe(MilestoneStatus::Invoiced);

    $other = e8Milestone(['description' => 'Slutbesiktning']);
    Livewire::test(ProjectBillingPlan::class, ['project' => $this->project])
        ->call('delete', $other->id, $other->versionToken())
        ->assertDispatched('toast');
    expect(ProjectBillingMilestone::query()->count())->toBe(1);

    Livewire::test(ProjectBillingPlan::class, ['project' => $this->project])
        ->call('create')
        ->set('form.description', '')
        ->call('save')
        ->assertHasErrors('description');
});

it('counts only issued invoices as invoiced from the plan, and drafts apart', function () {
    $milestone = e8Milestone(['status' => 'READY']);
    $invoice = app(InvoiceBillingMilestone::class)($this->owner, $this->company, $milestone, $milestone->versionToken());

    Livewire::test(ProjectBillingPlan::class, ['project' => $this->project])
        ->assertViewHas('invoiced', 0)
        ->assertViewHas('drafted', 5_000_000)
        ->assertSee('Utkast:');

    app(IssueInvoice::class)($this->owner, $invoice);

    Livewire::test(ProjectBillingPlan::class, ['project' => $this->project])
        ->assertViewHas('invoiced', 5_000_000)
        ->assertViewHas('drafted', 0);
});

it('runs a fixed number of queries whatever the number of milestones', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ProjectBillingPlan::class, ['project' => $this->project]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $first = e8Milestone(['status' => 'READY']);
    app(InvoiceBillingMilestone::class)($this->owner, $this->company, $first, $first->versionToken());
    // Warm up: the first render resolves what the request caches.
    $count();
    $short = $count();

    foreach (range(2, 12) as $number) {
        $milestone = e8Milestone(['description' => "Etapp {$number}", 'status' => 'READY', 'amount' => '1 000']);

        if ($number % 3 === 0) {
            app(InvoiceBillingMilestone::class)($this->owner, $this->company, $milestone, $milestone->versionToken());
        }
    }

    expect($count())->toBe($short);
});

it('answers 404 for another company\'s milestone', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    $foreign = CurrentCompany::run($other, function () use ($otherOwner, $other): ProjectBillingMilestone {
        $project = Project::create(['code' => 'P1', 'name' => 'Hemligt projekt', 'contract_sum_ore' => 1_000_000]);

        return app(SaveBillingMilestone::class)($otherOwner, $other, $project, ['description' => 'Hemlig etapp', 'kind' => 'AMOUNT', 'amount' => '1 000', 'status' => 'READY']);
    });
    CurrentCompany::set($this->company);

    Livewire::test(ProjectBillingPlan::class, ['project' => $this->project])
        ->assertDontSee('Hemlig etapp')
        ->call('edit', $foreign->id)
        ->assertNotFound();

    expect(ProjectBillingMilestone::query()->whereKey($foreign->id)->exists())->toBeFalse();
});
