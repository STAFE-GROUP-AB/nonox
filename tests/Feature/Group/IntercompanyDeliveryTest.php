<?php

declare(strict_types=1);

use App\Actions\Group\DeliverIntercompanyInvoice;
use App\Actions\Group\SetIntercompanyReceipt;
use App\Actions\Invoicing\SaveCustomer;
use App\Domain\Group\IntercompanyDeliveryStatus;
use App\Livewire\Group\IntercompanyDeliveries;
use App\Livewire\Group\IntercompanyDeliveryPanel;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\IntercompanyCounterparty;
use App\Models\IntercompanyDelivery;
use App\Models\IntercompanyReceiptSetting;
use App\Models\Invoice;
use App\Models\Voucher;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Models\InboxMessage;
use App\Support\Archive\ArchivePackageBuilder;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\E27Group;

/*
 * Intra-group invoice delivery (ADR-081 gap 1, E27). Company A issues an
 * invoice to its subsidiary B; B has turned on receipt from A. Delivered,
 * the invoice lies in B's inbox as a received e-invoice, read by the Peppol
 * reader into a supplier invoice proposal for B's own people. Nothing is
 * booked in B, nothing changes in A, and A only learns "levererad".
 */
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00'));
    $this->set = E27Group::make();
    $this->invoice = $this->set->invoice();
    CurrentCompany::set($this->set->a);
    $this->set->sara->switchCompany($this->set->a);
    $this->actingAs($this->set->sara->fresh());
});

/** @return list<InboxMessage> the messages in B's inbox */
function e27Inbox(E27Group $set): array
{
    return CurrentCompany::run($set->b, fn (): array => InboxMessage::query()->with('attachments')->orderBy('received_at')->get()->all());
}

it('delivers an issued invoice into the group company\'s inbox as an e-invoice, read into a supplier invoice proposal', function () {
    $bVouchers = CurrentCompany::run($this->set->b, fn (): int => Voucher::query()->count());
    $before = $this->invoice->fresh()->only(['status', 'total_ore', 'vat_ore', 'voucher_id', 'sent_at']);

    $delivery = app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice);
    $inbox = e27Inbox($this->set);

    expect($delivery->status)->toBe(IntercompanyDeliveryStatus::Delivered)
        ->and($delivery->company_id)->toBe($this->set->a->id)
        ->and($delivery->corporate_group_id)->toBe($this->set->group->id)
        ->and($delivery->recipient_org_number)->toBe($this->set->b->org_number)
        ->and($inbox)->toHaveCount(1)
        ->and($inbox[0]->company_id)->toBe($this->set->b->id)
        ->and($inbox[0]->channel)->toBe(Channel::GroupDelivery)
        ->and($inbox[0]->from_name)->toBe('Moder Exempel AB')
        ->and($inbox[0]->attachments->first()->mime)->toBe('application/xml')
        ->and($inbox[0]->proposal['kind'] ?? null)->toBe('supplier_invoice')
        ->and(in_array($inbox[0]->status, [MessageStatus::NeedsReview, MessageStatus::DraftReady], true))->toBeTrue()
        ->and(Storage::disk('local')->get($inbox[0]->eml_path))->toContain('urn:fdc:peppol.eu:2017:poacc:billing:3.0')
        // Nothing is booked in B, and nothing changes in A: the invoice was booked when it was issued.
        ->and(CurrentCompany::run($this->set->b, fn (): int => Voucher::query()->count()))->toBe($bVouchers)
        ->and($this->invoice->fresh()->only(['status', 'total_ore', 'vat_ore', 'voucher_id', 'sent_at']))->toEqual($before);
});

it('logs the delivery in the sender with ids only, and the receipt in the receiver without the sender\'s user', function () {
    $delivery = app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice);

    $sent = AuditLog::query()->where('company_id', $this->set->a->id)->where('action', 'invoice.group_delivered')->sole();
    $received = AuditLog::query()->where('company_id', $this->set->b->id)->where('action', 'inbox.received')->latest('id')->first();

    expect(array_keys($sent->payload))->toEqualCanonicalizing(['invoice_id', 'number', 'delivery_id', 'status'])
        ->and($sent->payload['delivery_id'])->toBe($delivery->id)
        ->and($sent->user_id)->toBe($this->set->sara->id)
        ->and($received->user_id)->toBeNull()
        ->and($received->ip_address)->toBeNull()
        ->and(AuditLog::query()->where('company_id', $this->set->b->id)->where('action', 'inbox.interpreted')->latest('id')->first()?->user_id)->toBeNull()
        ->and($received->payload['channel'])->toBe('GROUP_DELIVERY')
        ->and(array_keys($received->payload))->toEqualCanonicalizing(['channel', 'sha256', 'size', 'attachments', 'quarantine']);
});

it('delivers an invoice once; a second attempt is refused in Swedish', function () {
    app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice);

    expect(fn () => app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice))
        ->toThrow(ValidationException::class, 'är redan levererad till koncernföretaget')
        ->and(e27Inbox($this->set))->toHaveCount(1);
});

it('refuses a customer not marked as a group company, and an organisation number on the invoice that differs from the marking', function () {
    $other = CurrentCompany::run($this->set->a, fn () => app(SaveCustomer::class)($this->set->olle, $this->set->a, ['name' => 'Annan Kund AB', 'org_number' => E27Group::orgNumber('150'), 'payment_terms_days' => 30]));
    $unmarked = $this->set->invoice('500', $other);

    expect(fn () => app(DeliverIntercompanyInvoice::class)($this->set->sara, $unmarked))->toThrow(ValidationException::class, 'inte markerad som koncernföretag');

    // The marking now names another group company than the one on the issued invoice.
    CurrentCompany::run($this->set->a, fn () => IntercompanyCounterparty::query()->where('customer_id', $this->set->customer->id)->update(['counterparty_org_number' => E27Group::orgNumber('151')]));

    expect(fn () => app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice))->toThrow(ValidationException::class, 'stämmer inte med koncernmarkeringen')
        ->and(IntercompanyDelivery::query()->count())->toBe(0);
});

it('needs invoices:send in the sender', function () {
    $approver = Bookkeeping::member($this->set->a, 'approver');

    expect(fn () => app(DeliverIntercompanyInvoice::class)($approver, $this->invoice))->toThrow(AuthorizationException::class)
        ->and(e27Inbox($this->set))->toBeEmpty();
});

it('keeps a delivered invoice that the receiver rejects, as it keeps a Peppol e-invoice', function () {
    app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice);
    $message = e27Inbox($this->set)[0];
    CurrentCompany::run($this->set->b, fn () => InboxMessage::query()->whereKey($message->id)->update(['status' => MessageStatus::Rejected->value, 'handled_at' => now()->subDays(30)]));

    Artisan::call('inbox:prune');

    expect(CurrentCompany::run($this->set->b, fn (): bool => InboxMessage::query()->whereKey($message->id)->exists()))->toBeTrue();
});

it('lets only the receiver\'s owner or administrator change the receipt setting, and logs no organisation number', function () {
    $bookkeeper = Bookkeeping::member($this->set->b, 'bookkeeper');

    expect(fn () => app(SetIntercompanyReceipt::class)->enable($bookkeeper, $this->set->b, E27Group::orgNumber('160')))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetIntercompanyReceipt::class)->enable($this->set->bea, $this->set->b, (string) $this->set->b->org_number))->toThrow(ValidationException::class, 'eget organisationsnummer');

    $setting = CurrentCompany::run($this->set->b, fn () => IntercompanyReceiptSetting::query()->sole());
    app(SetIntercompanyReceipt::class)->disable($this->set->bea, $this->set->b, $setting->id);
    $log = AuditLog::query()->where('company_id', $this->set->b->id)->where('action', 'intercompany.receipt_disabled')->sole();

    expect($setting->fresh()->enabled)->toBeFalse()
        ->and(json_encode($log->payload))->not->toContain(str_replace('-', '', (string) $this->set->a->org_number))
        ->and(json_encode($log->payload))->not->toContain((string) $this->set->a->org_number);
});

it('keeps the sender\'s own copy of the delivered file, and puts it in the sender\'s archive package', function () {
    $delivery = app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice);
    $original = e27Inbox($this->set)[0];
    $copy = Storage::disk('local')->get((string) $delivery->xml_path);

    expect($delivery->xml_path)->toBe("koncern/{$this->set->a->id}/utgaende/{$delivery->id}.xml")
        ->and($delivery->xml_sha256)->toBe(hash('sha256', (string) $copy))
        ->and($delivery->xml_sha256)->toBe($original->eml_sha256)
        ->and(fn () => DB::transaction(fn () => DB::table('intercompany_deliveries')->insert([
            'company_id' => $this->set->a->id, 'invoice_id' => $this->invoice->id, 'recipient_org_number' => $this->set->b->org_number,
            'status' => 'DELIVERED', 'delivered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(Exception::class, 'intercompany_deliveries_copy_check');

    $year = FiscalYear::query()->where('start_date', '<=', '2026-03-02')->where('end_date', '>=', '2026-03-02')->firstOrFail();
    $package = app(ArchivePackageBuilder::class)->build($this->set->olle, $year);
    $zipPath = (string) tempnam(sys_get_temp_dir(), 'e27arkiv');
    file_put_contents($zipPath, Storage::disk('local')->get($package['path']));
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $found = null;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_contains((string) $zip->getNameIndex($i), '/kundfakturor/koncernleveranser/')) {
            $found = (string) $zip->getFromIndex($i);
        }
    }

    $zip->close();
    unlink($zipPath);

    expect($package['contents']['koncernleveranser'])->toBe(1)
        ->and($found)->toBe($copy);
});

it('keeps no copy of a refused attempt', function () {
    CurrentCompany::run($this->set->b, fn () => IntercompanyReceiptSetting::query()->delete());

    $delivery = app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice);

    expect($delivery->status)->toBe(IntercompanyDeliveryStatus::Refused)
        ->and($delivery->xml_path)->toBeNull()
        ->and($delivery->xml_sha256)->toBeNull()
        ->and(Storage::disk('local')->allFiles("koncern/{$this->set->a->id}"))->toBeEmpty();
});

it('locks both memberships and the receiver\'s receipt setting while it delivers', function () {
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice);
    $queries = array_map(fn (array $query): string => mb_strtolower($query['query']), DB::getQueryLog());
    DB::disableQueryLog();

    $group = collect($queries)->search(fn (string $query): bool => str_contains($query, 'from "corporate_groups"') && str_contains($query, 'for share'));
    $members = collect($queries)->search(fn (string $query): bool => str_contains($query, 'from "corporate_group_members"') && str_contains($query, 'for share'));
    $setting = collect($queries)->search(fn (string $query): bool => str_contains($query, 'from "intercompany_receipt_settings"') && str_contains($query, 'for update'));

    // Lock order group → members → receipt setting, the same group-first order as RemoveGroupMember.
    expect($group)->toBeInt()
        ->and($members)->toBeInt()
        ->and($setting)->toBeInt()
        ->and($group < $members && $members < $setting)->toBeTrue();

    expect(collect($queries)->contains(fn (string $query): bool => str_contains($query, 'from "corporate_group_members"') && str_contains($query, 'for share')))->toBeTrue()
        ->and(collect($queries)->contains(fn (string $query): bool => str_contains($query, 'from "intercompany_receipt_settings"') && str_contains($query, 'for update')))->toBeTrue();
});

it('switches receipt on and off on Koncernmarkeringar, with a Swedish toast', function () {
    CurrentCompany::set($this->set->b);
    $this->set->bea->switchCompany($this->set->b);
    $this->actingAs($this->set->bea->fresh());
    $setting = CurrentCompany::run($this->set->b, fn () => IntercompanyReceiptSetting::query()->sole());

    Livewire::test(IntercompanyDeliveries::class)
        ->assertSee($this->set->a->org_number)
        ->call('disableReceipt', $setting->id)
        ->assertDispatched('toast', message: __('Företaget tar inte längre emot koncernfakturor från det koncernföretaget.'))
        ->assertSee(__('Tar inte emot'))
        ->call('enableReceipt', $this->set->a->org_number)
        ->assertDispatched('toast', message: __('Företaget tar nu emot koncernfakturor från det koncernföretaget.'))
        ->set('senderOrgNumber', '123')
        ->call('enableReceipt')
        ->assertHasErrors('sender_org_number');

    expect($setting->fresh()->enabled)->toBeTrue();
});

it('offers delivery on the invoice and tells the sender in Swedish how it went', function () {
    Livewire::test(IntercompanyDeliveryPanel::class, ['invoiceId' => $this->invoice->id])
        ->assertSee(__('Leverera till koncernföretaget'))
        ->call('deliver')
        ->assertDispatched('toast', message: __(':title är levererad till koncernföretagets inkorg.', ['title' => $this->invoice->title()]))
        ->assertSee(__('Levererad'));

    $this->get(route('invoicing.invoices.show', $this->invoice))->assertOk()->assertSee(__('Leverera till koncernföretaget'));
});

it('lists the receipt settings, deliveries and pairs on Koncernmarkeringar in a fixed number of queries', function () {
    $this->actingAs($this->set->olle);
    $this->set->olle->switchCompany($this->set->a);
    app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice);

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(IntercompanyDeliveries::class);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $short = $count();

    foreach (range(1, 6) as $i) {
        app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->set->invoice((string) (100 + $i)));
    }

    expect($count())->toBe($short);

    $this->get(route('accounting.intercompany'))->assertOk()->assertSee(__('Levererade koncernfakturor'))->assertSee(__('Ta emot koncernfakturor'));
});

it('opens the invoice page in a fixed number of queries in an active group, however many attempts there are', function () {
    CurrentCompany::run($this->set->b, fn () => IntercompanyReceiptSetting::query()->delete());
    app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice);

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('invoicing.invoices.show', $this->invoice))->assertOk()->assertSee(__('Inte levererad'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count();
    $short = $count();

    foreach (range(1, 6) as $i) {
        app(DeliverIntercompanyInvoice::class)($this->set->sara, $this->invoice);
    }

    expect($count())->toBe($short);
});
