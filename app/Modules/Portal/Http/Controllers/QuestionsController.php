<?php

declare(strict_types=1);

namespace App\Modules\Portal\Http\Controllers;

use App\Models\Invoice;
use App\Models\SalesDocument;
use App\Modules\Portal\Actions\PortalQuestions;
use App\Modules\Portal\Models\PortalAttachment;
use App\Modules\Portal\Models\PortalThread;
use App\Modules\Portal\Support\PortalContext;
use App\Modules\Portal\Support\PortalDocuments;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Frågor (ADR-075 §6): the customer's threads with the company. Only the
 * context customer's threads and attachments; anything else is 404.
 */
final class QuestionsController
{
    public function __construct(
        private readonly PortalContext $context,
        private readonly PortalDocuments $documents,
    ) {}

    public function index(): View
    {
        $threads = $this->threads()->orderByDesc('last_message_at')->paginate(25);

        return view('portal::portal.questions', ['threads' => $threads]);
    }

    public function create(Request $request): View
    {
        [$invoice, $quote] = $this->subject($request);

        return view('portal::portal.question-new', [
            'invoice' => $invoice,
            'quote' => $quote,
            'dispute' => $invoice instanceof Invoice && $request->query('bestrid') === '1',
        ]);
    }

    public function store(Request $request, PortalQuestions $questions): RedirectResponse
    {
        [$invoice, $quote] = $this->subject($request);
        $thread = $questions->open($this->context, $request->only(['subject', 'body', 'kind']), $invoice, $quote?->id, $this->files($request));
        Toast::flash(__('Ditt meddelande är skickat. Du får ett mejl när :company har svarat.', ['company' => $this->companyName()]));

        return redirect()->route('portal.questions.show', ['portalKey' => $this->context->key(), 'thread' => $thread->id]);
    }

    public function show(int $thread): View
    {
        $model = $this->threads()->whereKey($thread)->with(['messages.attachments', 'messages.user:id,name', 'messages.contact.identity'])->firstOrFail();

        return view('portal::portal.question', ['thread' => $model]);
    }

    public function reply(Request $request, int $thread, PortalQuestions $questions): RedirectResponse
    {
        $model = $this->threads()->whereKey($thread)->firstOrFail();
        $questions->post($this->context, $model, (string) $request->input('body'), $this->files($request));
        Toast::flash(__('Ditt meddelande är skickat.'));

        return redirect()->route('portal.questions.show', ['portalKey' => $this->context->key(), 'thread' => $model->id]);
    }

    public function attachment(int $thread, int $attachment): Response
    {
        $model = $this->threads()->whereKey($thread)->firstOrFail();
        $file = PortalAttachment::query()->whereKey($attachment)->whereIn('message_id', $model->messages()->select('id'))->firstOrFail();
        $bytes = Storage::disk(PortalAttachment::DISK)->get($file->path);
        abort_unless(is_string($bytes), 404);

        return response($bytes, 200, [
            'Content-Type' => $file->mime,
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $file->original_name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return Builder<PortalThread> */
    private function threads()
    {
        return PortalThread::query()->where('customer_id', $this->context->customer()->id);
    }

    /** @return array{0: ?Invoice, 1: ?SalesDocument} */
    private function subject(Request $request): array
    {
        $invoice = $request->filled('faktura') ? $this->documents->invoice((int) $request->input('faktura')) : null;
        $quote = $request->filled('offert') ? $this->documents->salesDocument((string) $request->input('offert')) : null;

        return [$invoice, $quote];
    }

    /** @return list<UploadedFile> */
    private function files(Request $request): array
    {
        $files = $request->allFiles()['files'] ?? [];
        $valid = [];

        foreach (is_array($files) ? $files : [$files] as $file) {
            if ($file->isValid()) {
                $valid[] = $file;
            }
        }

        return $valid;
    }

    private function companyName(): string
    {
        $company = $this->context->company();

        return (string) ($company->legal_name ?: $company->name);
    }
}
