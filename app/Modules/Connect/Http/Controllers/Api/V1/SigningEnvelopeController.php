<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Actions\Signing\ChangeSigningDeadline;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SigningReminders;
use App\Domain\Signing\Fields\SigningFields;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SigningPdfs;
use App\Models\SigningEnvelope;
use App\Models\SigningField;
use App\Models\SigningRequest;
use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Http\Resources\SigningPresenter;
use App\Modules\Signing\Actions\DeleteSigningDocument;
use App\Modules\Signing\Actions\ManageSigningTemplates;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\Support\ChosenDocument;
use App\Modules\Signing\Support\EnvelopeVisibility;
use App\Modules\Signing\Support\TemplateSend;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Envelopes for signing over the API (ADR-046 S19, ADR-032): the list and one
 * envelope with its recipients, fields and signed files, and, with
 * signing:send, creating and sending one, reminding a recipient, moving the
 * last day and withdrawing it. The same Actions as the screens
 * (SendForSigning, SigningReminders, ChangeSigningDeadline), so every rule,
 * check, quota and audit entry is the same.
 *
 * The visibility rule is the screens' (EnvelopeVisibility): an envelope the
 * credential's person may not see answers 404, as another company's does, and
 * a confidential one is seen only by its sender and with signing:manage.
 *
 * A person decides what is sent (ADR-046 principle 9): each call is one
 * envelope to the recipients it names. There is no endpoint that sends by a
 * rule or in bulk; the link goes only to the recipient's own e-mail address
 * and is never returned.
 */
final class SigningEnvelopeController
{
    use ActsAsCredential;

    private const array STATUSES = ['OUT', 'COMPLETED', 'DECLINED', 'VOIDED', 'EXPIRED'];

    /** What an envelope sent without a template may carry (SendForSigning::validateEnvelope reads the rest). */
    private const array ENVELOPE_FIELDS = ['signers', 'copies', 'notify_copies', 'expires_on', 'auto_reminders', 'reminder_days', 'confidential', 'create_invoice', 'deal_id', 'fields'];

    public function index(Request $request): JsonResponse
    {
        $filters = Validator::make($request->query(), [
            'status' => ['nullable', 'string', 'in:'.implode(',', self::STATUSES)],
            'document_type' => ['nullable', 'string', 'in:DOCUMENT,QUOTE,AGREEMENT,ATA'],
            'document_id' => ['nullable', 'uuid'],
            'quote_id' => ['nullable', 'uuid'],
            'agreement_id' => ['nullable', 'integer'],
        ])->validate();

        $query = EnvelopeVisibility::query($this->user(), $this->company())->with(['signers', 'copies']);

        if (isset($filters['status'])) {
            $now = CarbonImmutable::now()->format('Y-m-d H:i:sP');

            if ($filters['status'] === 'EXPIRED') {
                $query->where('status', 'OUT')->whereNotNull('deadline_at')->where('deadline_at', '<=', $now);
            } else {
                $query->where('status', $filters['status']);

                if ($filters['status'] === 'OUT') {
                    $query->where(fn ($live) => $live->whereNull('deadline_at')->orWhere('deadline_at', '>', $now));
                }
            }
        }

        if (isset($filters['document_type'])) {
            $query->where('document_type', $filters['document_type']);
        }

        if (isset($filters['document_id'])) {
            $query->where('document_type', SigningDocument::SIGNING_TYPE)->where('signable_id', $filters['document_id']);
        }

        if (isset($filters['quote_id'])) {
            $query->where('sales_document_id', $filters['quote_id']);
        }

        if (isset($filters['agreement_id'])) {
            $query->where('agreement_id', (int) $filters['agreement_id']);
        }

        return CursorList::respond($request, $query, fn (SigningEnvelope $envelope): array => SigningPresenter::envelope($envelope));
    }

    public function show(string $envelope): JsonResponse
    {
        return CursorList::one(SigningPresenter::envelope($this->envelope($envelope)->load(['signers', 'copies'])));
    }

    public function recipients(string $envelope): JsonResponse
    {
        $found = $this->envelope($envelope)->load('signers');

        return new JsonResponse(['data' => $found->signers->map(fn (SigningRequest $signer): array => SigningPresenter::recipient($signer))->values()->all()], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function recipient(string $envelope, int $recipient): JsonResponse
    {
        return CursorList::one(SigningPresenter::recipient($this->recipientOf($this->envelope($envelope), $recipient)));
    }

    /** The fields placed in the document, by recipient. The values the signers filled in are not part of this. */
    public function fields(string $envelope): JsonResponse
    {
        $found = $this->envelope($envelope);
        $fields = SigningField::query()->where('envelope_id', $found->id)->orderBy('position')->orderBy('id')->get();

        return new JsonResponse(['data' => $fields->map(fn (SigningField $field): array => SigningPresenter::field($field))->values()->all()], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Creates the envelope and sends it: exactly one of `document_id` (an
     * uploaded document), `quote_id`, `agreement_id` or a `template_id`.
     * From a template the recipients are named by the template's roles
     * (`recipients`, keyed by role key) and the template's steps, checks,
     * fields and defaults apply; otherwise `signers` and the rest of the
     * envelope are given here. The links are mailed to the recipients.
     */
    public function store(Request $request, SendForSigning $send, ManageSigningTemplates $templates): JsonResponse
    {
        $user = $this->user();
        $company = $this->company();
        $body = $request->json()->all();

        $template = $this->template($body);
        $created = null;

        try {
            [$document, $input] = $template instanceof SigningTemplate
                ? $this->fromTemplate($body, $template, $templates, $created)
                : [$this->chosen($body), array_intersect_key($body, array_flip(self::ENVELOPE_FIELDS))];

            $envelope = $send->envelope($user, $company, $document, $input, minSigners: 1);
        } catch (Throwable $exception) {
            // A document made from the template for this send is removed again when the send fails (as the bulk send does).
            if ($created instanceof SigningDocument) {
                try {
                    app(DeleteSigningDocument::class)($user, $company, $created);
                } catch (Throwable) {
                    // Left unsent, as any uploaded document; the template send is retried with a new one.
                }
            }

            throw $exception;
        }

        return CursorList::one(SigningPresenter::envelope($envelope->load(['signers', 'copies'])), 201)
            ->header('Location', route('connect.api.signing.envelopes.show', $envelope->id));
    }

    /** `expires_on` (YYYY-MM-DD) moves the last day of an envelope that is out; the recipients who can sign now are told by e-mail. */
    public function update(Request $request, string $envelope, ChangeSigningDeadline $change): JsonResponse
    {
        $found = $this->envelope($envelope);
        $body = $request->json()->all();

        if (! array_key_exists('expires_on', $body)) {
            throw ValidationException::withMessages(['expires_on' => __('Skriv sista dag att signera som ÅÅÅÅ-MM-DD.')]);
        }

        $changed = $change($this->user(), $this->company(), $found, is_string($body['expires_on']) ? $body['expires_on'] : '');

        return CursorList::one(SigningPresenter::envelope($changed->load(['signers', 'copies'])));
    }

    public function void(string $envelope, SendForSigning $send): JsonResponse
    {
        $voided = $send->void($this->user(), $this->company(), $this->envelope($envelope));

        return CursorList::one(SigningPresenter::envelope($voided->load(['signers', 'copies'])));
    }

    /** Reminds one recipient who can sign now, at most once an hour, as "Påminn nu" does. */
    public function remind(string $envelope, int $recipient, SigningReminders $reminders): JsonResponse
    {
        $signer = $this->recipientOf($this->envelope($envelope), $recipient);
        $reminded = $reminders->manual($this->user(), $this->company(), $signer);

        return CursorList::one(SigningPresenter::recipient($reminded));
    }

    /** The finished document: the PDF that was sent, with every signer's field values once they have signed. */
    public function document(string $envelope): Response
    {
        $found = $this->envelope($envelope);

        return $this->pdf(SigningFields::finalVersion($found), $found->document_name);
    }

    /** One recipient's signing certificate, once they have signed. */
    public function certificate(string $envelope, int $recipient): Response
    {
        $signer = $this->recipientOf($this->envelope($envelope), $recipient);
        abort_if($signer->signed_document_path === null, 404);

        return $this->pdf($signer->signed_document_path, SigningPdfs::certificateFilename($signer), $signer->signed_document_sha256);
    }

    /** The envelope, or 404: another company's, one that does not exist and one this person may not see look the same. */
    private function envelope(string $id): SigningEnvelope
    {
        $envelope = SigningEnvelope::query()->findOrFail($id);
        abort_unless(EnvelopeVisibility::allows($this->user(), $this->company(), $envelope), 404);

        return $envelope;
    }

    private function recipientOf(SigningEnvelope $envelope, int $id): SigningRequest
    {
        return SigningRequest::query()->where('envelope_id', $envelope->id)->findOrFail($id);
    }

    private function pdf(string $path, string $name, ?string $sha256 = null): Response
    {
        $bytes = Storage::disk(SigningRequest::DISK)->get($path);
        abort_unless(is_string($bytes), 404);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.SigningDocumentController::headerName($name).'"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Content-SHA256' => $sha256 ?? hash('sha256', $bytes),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function template(array $body): ?SigningTemplate
    {
        if (blank($body['template_id'] ?? null)) {
            return null;
        }

        $id = $body['template_id'];
        abort_unless(is_string($id) && Str::isUuid($id), 404);

        // A template this person may not see (a confidential one of another member) is not found, never silently ignored.
        return SigningTemplate::query()->visibleTo($this->user(), $this->company())->with(['roles', 'fields'])->findOrFail($id);
    }

    /**
     * The document an envelope without a template is sent for: exactly one of
     * document_id, quote_id and agreement_id, found and authorised as the
     * send wizard does (a 404 for what the person may not see).
     *
     * @param  array<string, mixed>  $body
     */
    private function chosen(array $body): Signable
    {
        $given = array_filter([
            SigningDocument::SIGNING_TYPE => $body['document_id'] ?? null,
            SigningRequest::QUOTE => $body['quote_id'] ?? null,
            SigningRequest::AGREEMENT => $body['agreement_id'] ?? null,
        ], fn (mixed $value): bool => ! blank($value));

        if (count($given) !== 1) {
            throw ValidationException::withMessages(['document_id' => __('Ange exakt ett av document_id, quote_id, agreement_id och template_id.')]);
        }

        $key = reset($given);

        return ChosenDocument::resolve($this->user(), $this->company(), (string) array_key_first($given), is_scalar($key) ? (string) $key : null);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: Signable, 1: array<string, mixed>}
     */
    private function fromTemplate(array $body, SigningTemplate $template, ManageSigningTemplates $templates, ?SigningDocument &$created): array
    {
        if ($template->isArchived()) {
            throw ValidationException::withMessages(['template_id' => __('Mallen kan inte användas.')]);
        }

        $recipients = $this->recipientsByRole($body['recipients'] ?? null, $template);

        $input = [
            'signers' => $template->roles->map(fn ($role): array => [
                'email' => (string) ($recipients[$role->key]['email'] ?? ''),
                'step' => $role->step,
                'verification' => $role->verification,
                'access_code' => (string) ($recipients[$role->key]['access_code'] ?? ''),
                'language' => (string) ($recipients[$role->key]['language'] ?? $template->language),
            ])->values()->all(),
            'copies' => array_key_exists('copies', $body) ? $body['copies'] : (array) ($template->copies ?? []),
            'notify_copies' => (bool) ($body['notify_copies'] ?? false),
            'expires_on' => array_key_exists('expires_on', $body) ? $body['expires_on'] : TemplateSend::expiresOn($template),
            ...TemplateSend::reminders($template),
            'confidential' => $template->confidential || (bool) ($body['confidential'] ?? false),
            'fields' => TemplateSend::fields($template),
            'template_id' => $template->id,
            'create_invoice' => $body['create_invoice'] ?? false,
            'deal_id' => $body['deal_id'] ?? null,
        ];

        if ($template->document_type === SigningTemplate::DOCUMENT) {
            if (! blank($body['document_id'] ?? null) || ! blank($body['quote_id'] ?? null) || ! blank($body['agreement_id'] ?? null)) {
                throw ValidationException::withMessages(['template_id' => __('En mall för ett eget dokument har sin egen fil. Skicka inte med document_id, quote_id eller agreement_id.')]);
            }

            // The template's file becomes a document of its own for this envelope, as in the bulk send.
            $created = $templates->start($this->user(), $this->company(), $template);

            return [$created, $input];
        }

        $key = $template->document_type === SigningTemplate::QUOTE ? 'quote_id' : 'agreement_id';

        if (blank($body[$key] ?? null) || ! blank($body[$template->document_type === SigningTemplate::QUOTE ? 'agreement_id' : 'quote_id'] ?? null) || ! blank($body['document_id'] ?? null)) {
            throw ValidationException::withMessages([$key => __('Skriv vilken offert eller vilket avtal mallen ska skickas för.')]);
        }

        $input['fields'] = [];
        $key = $template->document_type === SigningTemplate::QUOTE ? $body['quote_id'] : $body['agreement_id'];

        return [ChosenDocument::resolve($this->user(), $this->company(), $template->document_type, is_scalar($key) ? (string) $key : null), $input];
    }

    /**
     * The people of a send from a template, keyed by the role's key: every
     * role needs one, and a key the template does not have is refused.
     *
     * @return array<string, array<string, mixed>>
     */
    private function recipientsByRole(mixed $given, SigningTemplate $template): array
    {
        $roles = $template->roles->pluck('key')->all();

        if (! is_array($given) || array_is_list($given) && $given !== []) {
            throw ValidationException::withMessages(['recipients' => __('Skriv recipients som ett objekt med en person per roll i mallen: :roles.', ['roles' => implode(', ', $roles)])]);
        }

        $unknown = array_diff(array_map('strval', array_keys($given)), $roles);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['recipients' => __('Mallen har inte rollen :role.', ['role' => (string) reset($unknown)])]);
        }

        foreach ($roles as $key) {
            if (! is_array($given[$key] ?? null)) {
                throw ValidationException::withMessages(["recipients.{$key}" => __('Skriv vem som är :role.', ['role' => (string) $key])]);
            }
        }

        return $given;
    }
}
