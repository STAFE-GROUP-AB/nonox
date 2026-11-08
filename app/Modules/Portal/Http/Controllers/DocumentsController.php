<?php

declare(strict_types=1);

namespace App\Modules\Portal\Http\Controllers;

use App\Actions\Signing\SignInSession;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Invoicing\IssuedInvoicePdf;
use App\Domain\Invoicing\SalesDocumentPdf;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Models\Invoice;
use App\Models\SigningRequest;
use App\Modules\Portal\Actions\PortalSelfService;
use App\Modules\Portal\Actions\RecordPortalEvent;
use App\Modules\Portal\Domain\EventType;
use App\Modules\Portal\Domain\ThreadKind;
use App\Modules\Portal\Models\PortalPaymentNote;
use App\Modules\Portal\Models\PortalThread;
use App\Modules\Portal\Support\PortalContext;
use App\Modules\Portal\Support\PortalDocuments;
use App\Modules\Portal\Support\PortalDownloads;
use App\Modules\Portal\Support\PortalStatement;
use App\Support\Branding\Brand;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The portal's pages for one customer (ADR-075 §4, §5, §7): Översikt,
 * invoices and credit invoices with their payments, reminders and PDF, the
 * statement, payment details, "Jag har betalat", and quotes and orders,
 * with accepting or declining a quote. Everything through PortalDocuments,
 * so an id outside the customer answers 404. Lists are paginated with a
 * fixed number of queries.
 */
final class DocumentsController
{
    private const int PER_PAGE = 25;

    public function __construct(
        private readonly PortalContext $context,
        private readonly PortalDocuments $documents,
        private readonly RecordPortalEvent $events,
    ) {}

    public function home(): View
    {
        $today = CarbonImmutable::today();
        $open = $this->documents->invoices()->where('kind', Invoice::INVOICE)->where('status', 'ISSUED')->get(['id', 'total_ore', 'paid_ore', 'due_date', 'kind', 'status']);

        return $this->view('home', [
            'openAmount' => (int) $open->sum(fn (Invoice $invoice): int => $invoice->remaining()->ore),
            'overdueAmount' => (int) $open->filter(fn (Invoice $invoice): bool => $invoice->due_date->lessThan($today))->sum(fn (Invoice $invoice): int => $invoice->remaining()->ore),
            'nextDue' => $open->filter(fn (Invoice $invoice): bool => ! $invoice->due_date->lessThan($today))->min('due_date'),
            'openQuotes' => $this->documents->openQuotes()->count(),
            'unread' => PortalThread::query()->where('customer_id', $this->context->customer()->id)->where('status', PortalThread::OPEN)->where('answered', true)->count(),
        ]);
    }

    public function invoices(): View
    {
        $invoices = $this->documents->invoices()->orderByDesc('invoice_date')->orderByDesc('number')->paginate(self::PER_PAGE);

        return $this->view('invoices', ['invoices' => $invoices]);
    }

    public function invoice(int $number): View
    {
        $invoice = $this->documents->invoice($number)->load(['rows', 'payments', 'reminders', 'credits', 'creditInvoices' => fn ($query) => $query->whereNotNull('number')->where('status', '<>', 'DRAFT')]);
        ($this->events)(EventType::ViewedInvoice, PortalThread::INVOICE, $invoice->id);
        $company = $this->context->company();

        return $this->view('invoice', [
            'invoice' => $invoice,
            'vat' => InvoiceCalculator::totals($invoice->rows->map(fn ($row): array => ['net' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->all()),
            'paidNote' => PortalPaymentNote::query()->where('invoice_id', $invoice->id)->latest('id')->first(),
            'disputed' => PortalThread::query()->where('subject_type', PortalThread::INVOICE)->where('subject_id', $invoice->id)->where('kind', ThreadKind::Dispute->value)->where('status', PortalThread::OPEN)->exists(),
            'payment' => [
                'bankgiro' => $company->bankgiro,
                'plusgiro' => $company->plusgiro,
                'iban' => $invoice->isForeign() ? $company->iban : null,
                'bic' => $invoice->isForeign() ? $company->bic : null,
            ],
            // Stored and its bytes match their hash; an invoice issued with stored PDFs is stored now if it was not yet.
            'stored' => IssuedInvoicePdf::get($invoice)['stored'],
        ]);
    }

    public function invoicePdf(int $number): Response
    {
        $invoice = $this->documents->invoice($number);
        $pdf = IssuedInvoicePdf::get($invoice);
        ($this->events)(EventType::DownloadedPdf, PortalThread::INVOICE, $invoice->id);

        return $this->download($pdf['bytes'], InvoicePdf::filename($invoice));
    }

    public function markPaid(Request $request, int $number, PortalSelfService $self): RedirectResponse
    {
        $invoice = $this->documents->invoice($number);
        $self->markPaid($this->context, $invoice, (string) $request->input('paid_on'));
        Toast::flash(__('Tack! Vi har meddelat :company att fakturan är betald. Den visas som betald när betalningen har kommit in.', ['company' => $this->companyName()]));

        return redirect()->route('portal.invoices.show', ['portalKey' => $this->context->key(), 'number' => $number]);
    }

    public function statement(Request $request): View
    {
        $date = $this->statementDate($request);
        ($this->events)(EventType::ViewedStatement);

        return $this->view('statement', ['statement' => app(PortalStatement::class)->on($date)]);
    }

    public function statementPdf(Request $request): Response
    {
        $date = $this->statementDate($request);
        ($this->events)(EventType::ViewedStatement);
        $html = view('portal::portal.statement-pdf', [
            'statement' => app(PortalStatement::class)->on($date),
            'company' => $this->context->company(),
            'customer' => $this->context->customer(),
            'brand' => Brand::for($this->context->company()),
        ])->render();

        return $this->download(SigningPdfs::render($html), "Kontoutdrag-{$date->toDateString()}.pdf");
    }

    public function quotes(): View
    {
        $documents = $this->documents->salesDocuments()->orderByDesc('document_date')->orderByDesc('number')->paginate(self::PER_PAGE);

        return $this->view('quotes', ['documents' => $documents]);
    }

    public function quote(string $document): View
    {
        $quote = $this->documents->salesDocument($document)->load('rows');

        return $this->view('quote', [
            'document' => $quote,
            'vat' => InvoiceCalculator::totals($quote->rows->map(fn ($row): array => ['net' => $row->net_ore->ore, 'vat_rate' => $row->vat_rate])->all()),
            'decision' => SigningRequest::query()->where('sales_document_id', $quote->id)->whereIn('status', [SigningStatus::Signed->value, SigningStatus::Declined->value])->latest('id')->first(),
            'canAccept' => $quote->isQuote() && $quote->isOpen() && $this->context->contact()->can_accept_quotes,
            'acceptance' => $quote->isQuote() ? $quote->signingAcceptance() : '',
            'fingerprint' => $quote->isQuote() ? $quote->signingFingerprint() : '',
        ]);
    }

    public function quotePdf(string $document): Response
    {
        $quote = $this->documents->salesDocument($document);
        ($this->events)(EventType::DownloadedPdf, PortalThread::QUOTE, $quote->id);

        return $this->download(SalesDocumentPdf::render($quote), SalesDocumentPdf::filename($quote));
    }

    public function decideQuote(Request $request, string $document, SignInSession $sign): RedirectResponse
    {
        $quote = $this->documents->salesDocument($document);
        $contact = $this->context->contact();
        abort_unless($quote->isQuote() && $contact->can_accept_quotes, 403);
        $accept = $request->input('decision') === 'accept';
        $signer = ['email' => $this->context->identity()->email, 'name' => (string) $request->input('name'), 'contact_id' => $contact->id, 'ip' => $request->ip(), 'user_agent' => $request->userAgent()];

        if ($accept) {
            Validator::make($request->all(), ['accept' => ['accepted'], 'name' => ['required', 'string', 'max:120']], ['accept.accepted' => __('Bekräfta att du har läst och godkänner offerten.'), 'name.required' => __('Skriv ditt namn.')])->validate();

            // The confirmation stored is the one the page showed: it is recomputed, and the fingerprint the form carries must still match.
            $sign->accept($quote, $signer, $quote->signingAcceptance(), (string) $request->input('fingerprint'));
            ($this->events)(EventType::AcceptedQuote, PortalThread::QUOTE, $quote->id);
            Toast::flash(__('Tack! Du har godkänt :title. :company får besked direkt, och du får en kopia med e-post.', ['title' => $quote->title(), 'company' => $this->companyName()]));
        } else {
            $sign->decline($quote, $signer, $request->filled('reason') ? (string) $request->input('reason') : null, (string) $request->input('fingerprint'));
            ($this->events)(EventType::DeclinedQuote, PortalThread::QUOTE, $quote->id);
            Toast::flash(__('Du har avböjt :title. :company får besked.', ['title' => $quote->title(), 'company' => $this->companyName()]));
        }

        return redirect()->route('portal.quotes.show', ['portalKey' => $this->context->key(), 'document' => $quote->id]);
    }

    private function statementDate(Request $request): CarbonImmutable
    {
        $input = $request->query('datum');

        if (! is_string($input) || $input === '') {
            return CarbonImmutable::today();
        }

        $validator = Validator::make(['datum' => $input], ['datum' => ['date_format:Y-m-d', 'before_or_equal:today', 'after:2000-01-01']]);

        if ($validator->fails()) {
            throw ValidationException::withMessages(['datum' => __('Välj ett datum som inte har passerat dagens datum.')]);
        }

        return CarbonImmutable::parse($input);
    }

    private function download(string $bytes, string $name): Response
    {
        return PortalDownloads::bytes($bytes, $name, 'application/pdf');
    }

    private function companyName(): string
    {
        $company = $this->context->company();

        return (string) ($company->legal_name ?: $company->name);
    }

    /** @param  array<string, mixed>  $data */
    private function view(string $page, array $data): View
    {
        /** @var view-string $name */
        $name = "portal::portal.{$page}";

        return view($name, $data);
    }
}
