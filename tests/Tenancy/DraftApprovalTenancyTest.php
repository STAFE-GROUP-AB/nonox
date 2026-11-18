<?php

declare(strict_types=1);

use App\Actions\Accounting\PostManualVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Approval\ApproveDraft;
use App\Actions\Approval\RejectApproval;
use App\Actions\Approval\ResubmitApproval;
use App\Actions\Approval\SubmitForApproval;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\DraftApprovals;
use App\Domain\Review\ReviewSources;
use App\Livewire\Approval\DraftApprovalCard;
use App\Models\ApprovalRequest;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\ApprovalFixtures;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for manual voucher and credit invoice approvals and the
 * approval queue (ADR-081 E21): another company's draft answers 404 from
 * every action and the card, and its requests never reach the queue.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->ownerA, $this->a] = Bookkeeping::company();
    [$this->ownerB, $this->b] = Bookkeeping::company();

    [$this->voucherB, $this->subjectB] = CurrentCompany::run($this->b, function (): array {
        ApprovalFixtures::policy($this->ownerB, $this->b->fresh(), [['approver_kind' => 'PERMISSION', 'approver_permission' => 'vouchers:post']], type: 'MANUAL_VOUCHER');
        $voucher = app(SaveDraftVoucher::class)($this->ownerB, $this->b, [
            'voucher_date' => '2026-03-05', 'description' => 'Hemlig', 'series' => 'A',
            'rows' => [['account' => 6110, 'debit_ore' => 50000], ['account' => 1930, 'credit_ore' => 50000]],
        ]);
        $subject = DraftApprovals::voucherSubjectId($voucher);
        app(SubmitForApproval::class)($this->ownerB, $this->b->fresh(), ApprovalSubjectType::ManualVoucher, $subject);

        return [$voucher, $subject];
    });

    CurrentCompany::set($this->a);
    $this->actingAs($this->ownerA);
    ApprovalFixtures::policy($this->ownerA, $this->a->fresh(), [['approver_kind' => 'PERMISSION', 'approver_permission' => 'vouchers:post']], type: 'MANUAL_VOUCHER');
});

it('answers 404 from every action for another company\'s draft', function () {
    expect(fn () => app(ApproveDraft::class)($this->ownerA, $this->a, ApprovalSubjectType::ManualVoucher, $this->subjectB))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SubmitForApproval::class)($this->ownerA, $this->a, ApprovalSubjectType::ManualVoucher, $this->subjectB))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RejectApproval::class)($this->ownerA, $this->a, ApprovalSubjectType::ManualVoucher, $this->subjectB))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ResubmitApproval::class)($this->ownerA, $this->a, ApprovalSubjectType::ManualVoucher, $this->subjectB))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ApproveDraft::class)($this->ownerA, $this->a, ApprovalSubjectType::CreditInvoice, $this->subjectB))->toThrow(ModelNotFoundException::class);

    CurrentCompany::run($this->b, fn () => expect(ApprovalRequest::query()->sole()->decisions()->count())->toBe(0));
});

it('refuses to post another company\'s draft and never lists its requests', function () {
    expect(fn () => app(PostManualVoucher::class)($this->ownerA, Voucher::query()->withoutGlobalScopes()->findOrFail($this->voucherB->id)))->toThrow(AuthorizationException::class)
        ->and(app(ReviewSources::class)->items($this->ownerA, $this->a))->toBe([]);
});

it('shows nothing on the card for another company\'s draft', function () {
    Livewire::test(DraftApprovalCard::class, ['type' => 'MANUAL_VOUCHER', 'draftId' => $this->voucherB->id])
        ->assertDontSee('Attest')
        ->assertDontSee('Hemlig');
});
