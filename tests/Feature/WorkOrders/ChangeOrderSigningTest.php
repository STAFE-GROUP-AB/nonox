<?php

declare(strict_types=1);

/*
 * ADR-074 §4 and O4 on the shared signing domain (ADR-046, ADR-075 §7):
 * an ÄTA is approved by the customer's signature at a link (F1: "approved
 * by link signature before work"), declined there, or signed on the field
 * worker's phone only after a code sent to the customer's own e-mail.
 * Once signed it never changes; with the module off the link is invalid.
 */

use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Mail\SignedDocumentMail;
use App\Models\SigningRequest;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Actions\CreateInvoiceBasis;
use App\Modules\WorkOrders\Actions\SaveChangeOrder;
use App\Modules\WorkOrders\Actions\SignChangeOrder;
use App\Modules\WorkOrders\Domain\ChangeOrderStatus;
use App\Modules\WorkOrders\Livewire\FieldWorkOrderPage;
use App\Modules\WorkOrders\Mail\OnSiteCodeMail;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\OnSiteCode;
use App\Modules\WorkOrders\Models\WorkOrderAssignment;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\WorkOrders;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    WorkOrders::enable($this->company, $this->owner);
    $this->workOrder = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::privateCustomer($this->company, $this->owner), ['tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad Ekbacken 3:14']);
    $this->changeOrder = app(SaveChangeOrder::class)($this->owner, $this->company, $this->workOrder, ['title' => 'Byte av golvbrunn', 'description' => 'Ny golvbrunn.', 'labour' => '2 400', 'material' => '1 200']);
});

function ataSignInput(SigningRequest $request): array
{
    return ['name' => 'Eva Exempelsson', 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => 'Eva Exempelsson', 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)];
}

function tokenOf(string $url): string
{
    return basename((string) parse_url($url, PHP_URL_PATH));
}

it('F1: sends the ÄTA for signing, locks its content, and approves it when the customer signs at the link', function () {
    $sent = app(SignChangeOrder::class)->send($this->owner, $this->company, $this->changeOrder);
    $changeOrder = $this->changeOrder->fresh();

    expect($changeOrder->status)->toBe(ChangeOrderStatus::Sent)
        ->and($sent['request']->email)->toBe('eva@example.com')
        ->and($sent['request']->document_type)->toBe('ATA')
        ->and($sent['request']->signable_id)->toBe((string) $changeOrder->id);

    expect(fn () => DB::transaction(fn () => DB::table('change_orders')->where('id', $changeOrder->id)->update(['labour_ore' => 1])))->toThrow(QueryException::class);

    $token = tokenOf($sent['url']);
    $this->get("/signera/{$token}")->assertOk()->assertSee('ÄTA 1');
    $this->post("/signera/{$token}", ataSignInput($sent['request']))->assertOk();
    CurrentCompany::set($this->company);

    $signed = $changeOrder->fresh();
    expect($sent['request']->refresh()->status)->toBe(SigningStatus::Signed)
        ->and($signed->status)->toBe(ChangeOrderStatus::Approved)
        ->and($signed->approval_method)->toBe('LINK')
        ->and($signed->approved_by_name)->toBe('Eva Exempelsson')
        ->and(fn () => $signed->forceFill(['title' => 'Annat'])->save())->toThrow(LogicException::class);

    // Price before work: now its time can be invoiced.
    WorkOrders::hours($this->company, $this->owner, $this->workOrder, '4', changeOrderId: $signed->id);
    $invoice = app(CreateInvoiceBasis::class)($this->owner, $this->company, $this->workOrder);
    expect($invoice->tax_reduction_ore->ore)->toBe(90_000)->and($invoice->labour_hours_milli)->toBe(4000);
});

it('lets the office download the signed ÄTA and its certificate, but answers 404 to a field worker and to a role without work orders', function () {
    $sent = app(SignChangeOrder::class)->send($this->owner, $this->company, $this->changeOrder);
    $this->post('/signera/'.tokenOf($sent['url']), ataSignInput($sent['request']))->assertOk();
    $worker = Bookkeeping::member($this->company, 'field_worker');
    WorkOrderAssignment::create(['work_order_id' => $this->workOrder->id, 'user_id' => $worker->id]);
    $approver = Bookkeeping::member($this->company, 'approver');

    $this->actingAs($this->owner);
    $this->get(route('signing.file', ['signing' => $sent['request']->id, 'file' => 'signeringsbevis']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('signing.file', ['signing' => $sent['request']->id, 'file' => 'dokument']))->assertOk();

    $this->actingAs($worker);
    $this->get(route('signing.file', ['signing' => $sent['request']->id, 'file' => 'signeringsbevis']))->assertNotFound();

    $this->actingAs($approver);
    $this->get(route('signing.file', ['signing' => $sent['request']->id, 'file' => 'dokument']))->assertNotFound();
});

it('refuses to send an ÄTA without a price', function () {
    $empty = app(SaveChangeOrder::class)($this->owner, $this->company, $this->workOrder, ['title' => 'Utan pris', 'description' => 'Ännu inte prissatt.']);

    expect(fn () => app(SignChangeOrder::class)->send($this->owner, $this->company, $empty))->toThrow(ValidationException::class, 'Sätt ett pris');
});

it('makes the ÄTA declined when the customer declines at the link', function () {
    $sent = app(SignChangeOrder::class)->send($this->owner, $this->company, $this->changeOrder);

    $this->post('/signera/'.tokenOf($sent['url']).'/avboj', ['reason' => 'För dyrt'])->assertOk();
    CurrentCompany::set($this->company);

    expect($this->changeOrder->fresh()->status)->toBe(ChangeOrderStatus::Declined);
});

it('invalidates the link while the module is off', function () {
    $sent = app(SignChangeOrder::class)->send($this->owner, $this->company, $this->changeOrder);
    app(ModuleManager::class)->disable(WorkOrdersModule::KEY, $this->company, $this->owner, confirmed: true);

    $this->get('/signera/'.tokenOf($sent['url']))->assertDontSee('Byte av golvbrunn');
});

it('O4: opens the signing page on the field worker\'s phone only after the code sent to the customer\'s own e-mail', function () {
    $worker = Bookkeeping::member($this->company, 'field_worker');
    WorkOrderAssignment::create(['work_order_id' => $this->workOrder->id, 'user_id' => $worker->id]);

    // The office prices and sends; the worker presents it on site.
    app(SignChangeOrder::class)->send($this->owner, $this->company, $this->changeOrder);
    $this->actingAs($worker);

    $code = null;
    $page = Livewire::test(FieldWorkOrderPage::class, ['workOrderId' => $this->workOrder->id])
        ->set('tab', 'ata')
        ->call('startOnSite', $this->changeOrder->id)
        ->assertDispatched('toast', message: 'En kod är skickad till kundens e-post. Låt kunden skriva in den här.');

    Mail::assertSent(OnSiteCodeMail::class, function (OnSiteCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return $mail->hasTo('eva@example.com');
    });

    $page->set('code', $code === '000000' ? '111111' : '000000')->call('verifyCode')->assertHasErrors('code');
    $page->set('code', (string) $code)->call('verifyCode')->assertRedirect();

    $onSite = OnSiteCode::query()->sole();
    expect($onSite->verified_at)->not->toBeNull()->and($onSite->url)->toBeNull()->and($onSite->presented_by)->toBe($worker->id);

    $request = SigningRequest::query()->whereKey($onSite->signing_request_id)->firstOrFail();
    $sent = SigningRequest::query()->where('status', SigningStatus::Pending->value)->sole();
    expect($sent->id)->toBe($request->id);
});

it('never gives a field worker the ÄTA PDF with prices, nor the signed copy (review P2-1)', function () {
    $this->company->forceFill(['email' => null])->save();
    $worker = Bookkeeping::member($this->company, 'field_worker');
    WorkOrderAssignment::create(['work_order_id' => $this->workOrder->id, 'user_id' => $worker->id]);
    app(SignChangeOrder::class)->send($this->owner, $this->company, $this->changeOrder);

    $this->actingAs($worker);
    $this->get(route('workOrders.changeOrderPdf', [$this->workOrder->id, $this->changeOrder->id]))->assertNotFound();

    $onSite = app(SignChangeOrder::class)->onSite($worker, $this->company, ChangeOrder::query()->findOrFail($this->changeOrder->id));
    $url = null;
    Mail::assertSent(OnSiteCodeMail::class, function (OnSiteCodeMail $mail) use ($worker, $onSite, &$url): bool {
        $url = app(SignChangeOrder::class)->verifyOnSite($worker, $this->company, $onSite, $mail->code);

        return true;
    });
    $request = SigningRequest::query()->findOrFail($onSite->signing_request_id);
    $this->post('/signera/'.tokenOf((string) $url), ataSignInput($request))->assertOk();

    Mail::assertQueued(SignedDocumentMail::class, fn ($mail): bool => $mail->hasTo($this->owner->email) && ! $mail->hasTo($worker->email));
    CurrentCompany::set($this->company);
    expect($this->changeOrder->fresh()->approval_method)->toBe('ON_SITE');
});

it('stops the code after five wrong tries and never lets the worker sign an unsent ÄTA', function () {
    $worker = Bookkeeping::member($this->company, 'field_worker');
    WorkOrderAssignment::create(['work_order_id' => $this->workOrder->id, 'user_id' => $worker->id]);

    expect(fn () => app(SignChangeOrder::class)->onSite($worker, $this->company, $this->changeOrder))->toThrow(ValidationException::class, 'prissätta och skicka');

    app(SignChangeOrder::class)->send($this->owner, $this->company, $this->changeOrder);
    $onSite = app(SignChangeOrder::class)->onSite($worker, $this->company, ChangeOrder::query()->findOrFail($this->changeOrder->id));

    foreach (range(1, 5) as $try) {
        expect(fn () => app(SignChangeOrder::class)->verifyOnSite($worker, $this->company, $onSite, 'fel'))->toThrow(ValidationException::class);
    }

    expect(fn () => app(SignChangeOrder::class)->verifyOnSite($worker, $this->company, $onSite, 'fel'))->toThrow(ValidationException::class, 'gäller inte längre');
});
