<?php

declare(strict_types=1);

/*
 * ADR-074 §4 and rule sheet §D: ÄTA numbered per work order; approval
 * recorded orally (weaker evidence) or performed without the customer's
 * answer under KtjL 8 § with a reason, a logged contact attempt and a
 * justification (AO-R4, F2); the customer always gets the PDF (AO-R10);
 * decided ÄTA never change (AO-R25), in code and in the database.
 */

use App\Modules\WorkOrders\Actions\DecideChangeOrder;
use App\Modules\WorkOrders\Actions\SaveChangeOrder;
use App\Modules\WorkOrders\Domain\ChangeOrderStatus;
use App\Modules\WorkOrders\Mail\ChangeOrderMail;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\WorkOrderAssignment;
use App\Modules\WorkOrders\Support\ChangeOrderPdf;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\WorkOrders;

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    WorkOrders::enable($this->company, $this->owner);
    $this->customer = WorkOrders::privateCustomer($this->company, $this->owner);
    $this->workOrder = WorkOrders::workOrder($this->company, $this->owner, $this->customer, ['tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad Ekbacken 3:14', 'standard_contract' => 'Hantverkarformuläret 17']);
});

function draftChange(string $title = 'Byte av golvbrunn'): ChangeOrder
{
    return app(SaveChangeOrder::class)(test()->owner, test()->company, test()->workOrder, [
        'title' => $title, 'description' => 'Kunden vill ha en ny golvbrunn.', 'basis' => 'CHANGE_ORDERED', 'price_form' => 'FIXED',
        'labour' => '2 400', 'material' => '1 200', 'time_effect' => 'En dag till',
    ]);
}

it('numbers ÄTA per work order and shows price, VAT, the expected ROT and what the customer pays (§D)', function () {
    $first = draftChange();
    $second = draftChange('Extra eluttag');

    expect([$first->number, $second->number])->toBe([1, 2]);

    $html = ChangeOrderPdf::html($first);
    expect($html)->toContain('ÄTA 1: Byte av golvbrunn')
        ->toContain('Ändring som kunden beställt')
        ->toContain(Money::ofOre(450_000)->format())
        ->toContain('900,00')
        ->toContain(Money::ofOre(360_000)->format())
        ->toContain('Hantverkarformuläret 17')
        ->toContain('En dag till')
        ->not->toContain((string) $this->customer->fresh()->personal_number);
});

it('records an oral approval as weaker evidence, fixes the document hash and e-mails the customer a copy (AO-R3, AO-R10)', function () {
    $changeOrder = app(DecideChangeOrder::class)->approveOrally($this->owner, $this->company, draftChange(), ['name' => 'Eva Exempelsson', 'channel' => 'PHONE', 'at' => '2026-03-02 09:30']);

    expect($changeOrder->status)->toBe(ChangeOrderStatus::ApprovedOrally)
        ->and($changeOrder->approval_method)->toBe('ORAL')
        ->and($changeOrder->document_sha256)->toBe(hash('sha256', ChangeOrderPdf::html($changeOrder)))
        ->and(ChangeOrderPdf::html($changeOrder, withDecision: true))->toContain('Muntligt godkännande');

    Mail::assertSent(ChangeOrderMail::class, fn (ChangeOrderMail $mail): bool => $mail->hasTo('eva@example.com'));
});

it('F2: records work performed without the customer\'s answer only with a reason, a logged attempt and a justification', function () {
    $changeOrder = draftChange('Läckande rör bakom vägg');
    $decide = app(DecideChangeOrder::class);
    $input = ['reason' => 'URGENT_DAMAGE', 'justification' => 'Vatten läckte in i väggen; risk för vattenskada.'];

    expect(fn () => $decide->performWithoutAnswer($this->owner, $this->company, $changeOrder, $input))->toThrow(ValidationException::class, 'försök att nå kunden');

    $decide->recordAttempt($this->owner, $this->company, $changeOrder, ['channel' => 'PHONE', 'at' => '2026-03-02 09:12']);
    $decide->recordAttempt($this->owner, $this->company, $changeOrder, ['channel' => 'EMAIL', 'at' => '2026-03-02 09:20']);

    expect(fn () => $decide->performWithoutAnswer($this->owner, $this->company, $changeOrder, ['reason' => 'URGENT_DAMAGE', 'justification' => '']))->toThrow(ValidationException::class, 'Skriv varför');

    $performed = $decide->performWithoutAnswer($this->owner, $this->company, $changeOrder, $input);

    expect($performed->status)->toBe(ChangeOrderStatus::PerformedWithoutAnswer)
        ->and($performed->status->allowsInvoicing())->toBeTrue()
        ->and(ChangeOrderPdf::html($performed, withDecision: true))->toContain('Akut, för att undvika allvarlig skada')->toContain('09:12')->toContain('09:20');

    Mail::assertSent(ChangeOrderMail::class);
});

it('never changes a decided ÄTA, through the action, Eloquent or SQL (AO-R25)', function () {
    $changeOrder = app(DecideChangeOrder::class)->approveOrally($this->owner, $this->company, draftChange(), ['name' => 'Eva', 'channel' => 'PHONE']);

    expect(fn () => app(SaveChangeOrder::class)($this->owner, $this->company, $this->workOrder, ['title' => 'Annat', 'description' => 'Annat'], $changeOrder))->toThrow(ValidationException::class);
    expect(fn () => $changeOrder->forceFill(['title' => 'Annat'])->save())->toThrow(LogicException::class);
    expect(fn () => $changeOrder->delete())->toThrow(LogicException::class);
    expect(fn () => DB::transaction(fn () => DB::table('change_orders')->where('id', $changeOrder->id)->update(['labour_ore' => 1])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('change_orders')->where('id', $changeOrder->id)->delete()))->toThrow(QueryException::class);
});

it('keeps the content of a sent ÄTA, letting only the decision change (trigger)', function () {
    $changeOrder = draftChange();
    DB::table('change_orders')->where('id', $changeOrder->id)->update(['status' => 'SENT']);

    expect(fn () => DB::transaction(fn () => DB::table('change_orders')->where('id', $changeOrder->id)->update(['labour_ore' => 1])))->toThrow(QueryException::class);
    expect(DB::table('change_orders')->where('id', $changeOrder->id)->update(['status' => 'DECLINED']))->toBe(1);
});

it('declines and withdraws, and refuses a second decision', function () {
    $decide = app(DecideChangeOrder::class);
    $declined = $decide->decline($this->owner, $this->company, draftChange(), 'Eva');
    $withdrawn = $decide->withdraw($this->owner, $this->company, draftChange('Extra'));

    expect($declined->status)->toBe(ChangeOrderStatus::Declined)
        ->and($withdrawn->status)->toBe(ChangeOrderStatus::Withdrawn)
        ->and(fn () => $decide->approveOrally($this->owner, $this->company, $declined, ['name' => 'Eva', 'channel' => 'PHONE']))->toThrow(ValidationException::class);
});

it('lets a field worker start an ÄTA without a price, but not decide it', function () {
    $worker = Bookkeeping::member($this->company, 'field_worker');
    WorkOrderAssignment::create(['work_order_id' => $this->workOrder->id, 'user_id' => $worker->id]);

    $changeOrder = app(SaveChangeOrder::class)($worker, $this->company, $this->workOrder, ['title' => 'Extra uttag', 'description' => 'Kunden vill ha ett uttag till.', 'labour' => '99 999']);

    expect($changeOrder->labour_ore->ore)->toBe(0)
        ->and(fn () => app(DecideChangeOrder::class)->approveOrally($worker, $this->company, $changeOrder, ['name' => 'Eva', 'channel' => 'PHONE']))->toThrow(AuthorizationException::class);
});

it('lets the ÄTA opt out of ROT (insurance-paid part, AO-R17)', function () {
    $changeOrder = app(SaveChangeOrder::class)($this->owner, $this->company, $this->workOrder, ['title' => 'Försäkringsskada', 'description' => 'Betalas av försäkringen.', 'labour' => '1 000', 'tax_reduction_applies' => false]);

    expect(ChangeOrderPdf::amounts($changeOrder, $this->workOrder)['deduction'])->toBe(0)
        ->and(ChangeOrderPdf::html($changeOrder))->toContain('Nej, inte på detta ÄTA');
});
