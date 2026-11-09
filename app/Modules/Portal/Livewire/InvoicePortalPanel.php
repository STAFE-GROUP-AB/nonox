<?php

declare(strict_types=1);

namespace App\Modules\Portal\Livewire;

use App\Models\Invoice;
use App\Modules\Portal\Domain\ThreadKind;
use App\Modules\Portal\Models\PortalEvent;
use App\Modules\Portal\Models\PortalPaymentNote;
use App\Modules\Portal\Models\PortalThread;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Kundportal" on an issued invoice (ADR-075 §5, §6, §8): when the customer
 * opened it or downloaded the PDF, a "Jag har betalat" note (which books
 * nothing), and an open dispute, with a warning to think before sending a
 * reminder. TODO(legal-verify): L8 — whether a disputed invoice must be
 * kept out of reminders; until answered NONOX only warns.
 */
class InvoicePortalPanel extends Component
{
    public const string NAME = 'portal.invoice-panel';

    #[Locked]
    public string $invoiceId;

    public function mount(string $invoiceId): void
    {
        // Read again inside the current company: another company's invoice answers 404.
        $this->invoiceId = Invoice::query()->findOrFail($invoiceId)->id;
    }

    public function render(): View
    {
        $events = PortalEvent::query()->where('subject_type', PortalThread::INVOICE)->where('subject_id', $this->invoiceId)->orderByDesc('id')->limit(20)->get(['event', 'created_at']);

        return view('portal::livewire.invoice-panel', [
            'firstOpened' => PortalEvent::query()->where('subject_type', PortalThread::INVOICE)->where('subject_id', $this->invoiceId)->where('event', 'VIEWED_INVOICE')->min('created_at'),
            'events' => $events,
            'notes' => PortalPaymentNote::query()->where('invoice_id', $this->invoiceId)->orderByDesc('id')->get(['paid_on', 'created_at']),
            'disputes' => PortalThread::query()->where('subject_type', PortalThread::INVOICE)->where('subject_id', $this->invoiceId)->where('kind', ThreadKind::Dispute->value)->orderByDesc('id')->get(['id', 'status', 'created_at']),
        ]);
    }
}
