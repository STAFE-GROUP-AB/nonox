<?php

declare(strict_types=1);

namespace App\Modules\Signing\Livewire;

use App\Actions\Signing\SendForSigning;
use App\Domain\Signing\Fields\FieldType;
use App\Domain\Signing\Fields\SigningFields;
use App\Domain\Signing\Pdf\PdfEngine;
use App\Domain\Signing\Pdf\PdfEngineException;
use App\Domain\Signing\RestrictsEnvelopes;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SigningDeadlines;
use App\Domain\Signing\Verification\SignerCheck;
use App\Domain\Signing\Verification\SignerChecks;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Customer;
use App\Models\SalesDocument;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\Supplier;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Models\Deal;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\DeleteSigningDocument;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Modules\Signing\Support\ChosenDocument;
use App\Modules\Signing\Support\FieldLayout;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Toast;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Signering → Skicka dokument (ADR-046 amendment, gap 10, package S7): the
 * send wizard. Dokument (the PDF is posted by an ordinary form first, see
 * UploadController, or an earlier upload is picked, or the wizard is opened
 * from a quote's or an agreement's panel with "Fler undertecknare") →
 * Undertecknare och steg → Fält i dokumentet (the field editor, S8: PDF.js
 * pages with boxes placed by drag, by keys and by a form; optional) →
 * Kontroller → Tid och påminnelser → Granska och skicka.
 *
 * The wizard sends through the engine's own SendForSigning::envelope with
 * one to ten signers, so the same rules, limits and checks apply as for
 * "Fler undertecknare" in the panel. A standalone document defaults to a
 * code to the signer's e-mail (owner decision 9); a quote or an agreement
 * to none, as before. Only a standalone document can be confidential.
 */
class SendWizard extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    public const int LAST_STEP = 6;

    /** How many customers or suppliers the pickers of step 1 list; more are said to be cut. */
    public const int PICKER_LIMIT = 500;

    /** The step names, in order. */
    public const array STEPS = [1 => 'Dokument', 2 => 'Undertecknare', 3 => 'Fält i dokumentet', 4 => 'Kontroller', 5 => 'Tid och påminnelser', 6 => 'Granska och skicka'];

    #[Locked]
    public int $step = 1;

    /** QUOTE, AGREEMENT or DOCUMENT when a document is chosen; null on the first step of a new upload. */
    #[Locked]
    public ?string $type = null;

    #[Locked]
    public ?string $documentId = null;

    /** @var list<array{email: string, step: int|string, verification: string, access_code: string}> */
    public array $signers = [];

    /** @var list<array{email: string, name: string}> */
    public array $copies = [];

    public bool $notifyCopies = false;

    public string $expiresOn = '';

    public bool $autoReminders = true;

    public string $reminderDays = '3';

    public bool $confidential = false;

    public string $dealId = '';

    public bool $createInvoice = false;

    /**
     * The fields placed in the document (S8), in the shape SigningFields::validate takes (signer is the index in
     * `signers`), plus `options_text` (a dropdown's alternatives one per line, a radio button's value). Boxes are in
     * 1/100 PDF points from the lower left of the page; the form speaks millimetres from the upper left.
     *
     * @var list<array<string, mixed>>
     */
    public array $fields = [];

    /**
     * The page sizes in 1/100 PDF points, measured by the PDF engine once the field step is opened. Empty
     * while unknown; then the step says why ($pagesNote).
     *
     * @var list<array{width: int, height: int, rotation: int}>
     */
    #[Locked]
    public array $pageSizes = [];

    #[Locked]
    public ?string $pagesNote = null;

    /**
     * The form that adds a field: type, signer index, page and where on the page.
     *
     * @var array<string, int|string>
     */
    public array $new = ['type' => 'SIGNATURE', 'signer' => 0, 'page' => 1, 'preset' => 'bottom-right'];

    public ?int $selected = null;

    /**
     * The selected field's box in millimetres from the page's upper left corner.
     *
     * @var array<string, string>
     */
    public array $geometry = ['left' => '', 'top' => '', 'width' => '', 'height' => ''];

    /** Said to assistive technology after an action (a polite live region). */
    public string $announce = '';

    public function mount(?string $type = null, ?string $documentId = null): void
    {
        abort_unless($this->allowed(SigningModule::SEND), 404);

        if ($type === null || $documentId === null) {
            return;
        }

        abort_unless(in_array($type, [SigningRequest::QUOTE, 'AGREEMENT', SigningDocument::SIGNING_TYPE], true), 404);
        $this->type = $type;
        $this->documentId = $documentId;

        $document = $this->document();
        $document->authorizeSigning($this->user());
        abort_unless($document->signingIsOpen(), 404);

        $standalone = $document instanceof RestrictsEnvelopes;
        $this->signers = [['email' => $this->defaultEmail($document), 'step' => 1, 'verification' => $standalone ? SignerCheck::EmailCode->value : SignerCheck::None->value, 'access_code' => '']];
        $deals = $this->deals($document);
        $this->dealId = $deals->count() === 1 ? (string) $deals->first()?->id : '';
        $this->step = 2;
    }

    public function next(): void
    {
        $this->resetErrorBag();

        if ($this->type === null) {
            return;
        }

        if ($this->step === 2) {
            $this->validate([
                'signers' => ['required', 'array', 'min:1', 'max:'.SigningEnvelope::MAX_SIGNERS],
                'signers.*.email' => ['required', 'email', 'max:255'],
                'signers.*.step' => ['required', 'integer', 'min:1', 'max:'.SigningEnvelope::MAX_STEP],
                'copies' => ['array', 'max:'.SigningEnvelope::MAX_COPIES],
                'copies.*.email' => ['required', 'email', 'max:255'],
                'copies.*.name' => ['nullable', 'string', 'max:120'],
            ], [], ['signers.*.email' => __('e-post'), 'signers.*.step' => __('steg'), 'copies.*.email' => __('e-post'), 'copies.*.name' => __('namn')]);
        }

        if ($this->step === 3 && ! $this->fieldsAreValid()) {
            return;
        }

        $this->step = min(self::LAST_STEP, $this->step + 1);

        if ($this->step === 3) {
            $this->loadPageSizes();
        }
    }

    public function back(): void
    {
        $this->resetErrorBag();
        $this->step = max(1, $this->step - 1);
    }

    public function addSigner(): void
    {
        if (count($this->signers) >= SigningEnvelope::MAX_SIGNERS) {
            return;
        }

        $last = $this->signers === [] ? 0 : (int) end($this->signers)['step'];
        $this->signers[] = ['email' => '', 'step' => min(SigningEnvelope::MAX_STEP, max(1, $last + 1)), 'verification' => $this->defaultCheck(), 'access_code' => ''];
    }

    public function removeSigner(int $index): void
    {
        if (count($this->signers) <= 1) {
            return;
        }

        $this->signers = array_values(array_filter($this->signers, fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));

        // The removed signer's fields go with them and the later signers' fields keep their signer.
        $this->fields = array_values(array_map(function (array $field) use ($index): array {
            $field['signer'] = (int) ($field['signer'] ?? 0) > $index ? (int) ($field['signer'] ?? 0) - 1 : (int) ($field['signer'] ?? 0);

            return $field;
        }, array_filter($this->fields, fn (array $field): bool => (int) ($field['signer'] ?? 0) !== $index)));
        $this->selected = null;
        $this->new['signer'] = min((int) $this->new['signer'], count($this->signers) - 1);
    }

    /** "I ordning": the signers sign one after the other, in the order they are listed. */
    public function inOrder(): void
    {
        foreach (array_keys($this->signers) as $index) {
            $this->signers[$index]['step'] = min(SigningEnvelope::MAX_STEP, $index + 1);
        }
    }

    /** "Alla samtidigt": everyone is on step 1. */
    public function allAtOnce(): void
    {
        foreach (array_keys($this->signers) as $index) {
            $this->signers[$index]['step'] = 1;
        }
    }

    public function addCopy(): void
    {
        if (count($this->copies) < SigningEnvelope::MAX_COPIES) {
            $this->copies[] = ['email' => '', 'name' => ''];
        }
    }

    public function removeCopy(int $index): void
    {
        $this->copies = array_values(array_filter($this->copies, fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
    }

    /** "Föreslå kod" for one signer: ten characters that are easy to read out over the phone. */
    public function suggestCode(int $index): void
    {
        if (! isset($this->signers[$index])) {
            return;
        }

        $this->resetErrorBag("signers.{$index}.access_code");
        $this->signers[$index]['access_code'] = SignerChecks::suggestAccessCode();
    }

    /** "Ta bort" an uploaded document that has not been sent: the file goes with it. */
    public function deleteDocument(string $id, DeleteSigningDocument $delete): void
    {
        abort_unless(Str::isUuid($id), 404);
        $delete($this->user(), $this->company(), SigningDocument::query()->findOrFail($id));

        $this->toast(__('Dokumentet är borttaget.'));
    }

    public function send(SendForSigning $send): void
    {
        $this->resetErrorBag();
        $document = $this->document();

        try {
            $envelope = $send->envelope($this->user(), $this->company(), $document, [
                'signers' => $this->signers,
                'copies' => $this->copies,
                'notify_copies' => $this->notifyCopies,
                'expires_on' => $this->expiresOn === '' ? null : $this->expiresOn,
                'auto_reminders' => $this->autoReminders,
                'reminder_days' => $this->reminderDays,
                'confidential' => $document instanceof RestrictsEnvelopes && $this->confidential,
                'deal_id' => $this->dealId === '' ? null : (int) $this->dealId,
                'create_invoice' => $document instanceof SalesDocument && $this->createInvoice,
                'fields' => $this->definitions(),
            ], minSigners: 1);
        } catch (ValidationException $exception) {
            $this->step = $this->stepOf(array_keys($exception->errors()));

            throw $exception;
        }

        Toast::flash(count($this->signers) === 1
            ? __('Dokumentet är skickat för signering. Undertecknaren har fått sin länk.')
            : __('Dokumentet är skickat för signering. Undertecknarna i första steget har fått sin länk; :count undertecknare totalt.', ['count' => count($this->signers)]));

        $this->redirectRoute('signing.envelope', ['envelope' => $envelope->id]);
    }

    /** "Lägg till fält": the form's type at its preset position on the chosen page. */
    public function addField(): void
    {
        $type = FieldType::tryFrom((string) ($this->new['type'] ?? ''));
        $page = (int) ($this->new['page'] ?? 1);
        $size = $this->pageSizes[$page - 1] ?? null;

        if ($type === null || $size === null) {
            return;
        }

        [$width, $height] = FieldLayout::defaultSize($type);
        [$x, $y] = FieldLayout::preset((string) ($this->new['preset'] ?? ''), $width, $height, $size['width'], $size['height']);

        $this->placeField($type, (int) ($this->new['signer'] ?? 0), $page, $x, $y);
    }

    /** A field of a type dropped on a page: x and y are the point it was dropped on, in 1/100 points from the page's lower left. */
    public function addFieldAt(string $type, int $page, int $x, int $y): void
    {
        $type = FieldType::tryFrom($type);

        if ($type === null) {
            return;
        }

        [$width, $height] = FieldLayout::defaultSize($type);

        $this->placeField($type, (int) ($this->new['signer'] ?? 0), $page, $x - intdiv($width, 2), $y - intdiv($height, 2));
    }

    public function selectField(int $index): void
    {
        if (! isset($this->fields[$index])) {
            return;
        }

        $this->selected = $index;
        $this->refreshGeometry();
    }

    /** A box dragged or resized with the pointer: the new box, clamped into the page like every other way of changing it. */
    public function moveField(int $index, int $x, int $y, int $width, int $height): void
    {
        if (! isset($this->fields[$index])) {
            return;
        }

        $this->setBox($index, ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height]);
        $this->selected = $index;
        $this->refreshGeometry();
        $this->announce = $this->describePosition($index);
    }

    /**
     * A key press on a box: $dx and $dy in millimetres (right and down are positive); with $resize the box's lower
     * right corner moves and the upper left stays.
     */
    public function nudge(int $index, int $dx, int $dy, bool $resize = false): void
    {
        $field = $this->fields[$index] ?? null;

        if ($field === null) {
            return;
        }

        $dx = max(-100, min(100, $dx));
        $dy = max(-100, min(100, $dy));
        $x = FieldLayout::mmToUnits($dx);
        $y = FieldLayout::mmToUnits($dy);

        if ($resize) {
            $type = FieldType::from((string) ($field['type'] ?? ''));
            $size = $this->pageSizes[(int) ($field['page'] ?? 1) - 1] ?? null;

            if ($size !== null) {
                $this->fields[$index] = [...$field, ...FieldLayout::resize($type, [
                    'x' => (int) ($field['x'] ?? 0),
                    'y' => (int) ($field['y'] ?? 0),
                    'width' => (int) ($field['width'] ?? 0),
                    'height' => (int) ($field['height'] ?? 0),
                ], (int) ($field['width'] ?? 0) + $x, (int) ($field['height'] ?? 0) + $y, $size['width'], $size['height'])];
            }
        } else {
            $this->setBox($index, ['x' => (int) ($field['x'] ?? 0) + $x, 'y' => (int) ($field['y'] ?? 0) - $y, 'width' => (int) ($field['width'] ?? 0), 'height' => (int) ($field['height'] ?? 0)]);
        }

        $this->selected = $index;
        $this->refreshGeometry();
        $this->announce = $this->describePosition($index);
    }

    public function removeField(int $index): void
    {
        if (! isset($this->fields[$index])) {
            return;
        }

        $label = (string) ($this->fields[$index]['label'] ?? '');
        $this->fields = array_values(array_filter($this->fields, fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
        $this->selected = null;
        $this->geometry = ['left' => '', 'top' => '', 'width' => '', 'height' => ''];
        $this->resetErrorBag();
        $this->announce = __('Fältet ”:label” är borttaget.', ['label' => $label]);
    }

    /** The millimetre boxes of the selected field, applied: the same clamp as a drag. */
    public function applyGeometry(): void
    {
        $index = $this->selected;
        $field = $index === null ? null : ($this->fields[$index] ?? null);
        $size = $field === null ? null : ($this->pageSizes[(int) ($field['page'] ?? 1) - 1] ?? null);

        if ($index === null || $field === null || $size === null) {
            return;
        }

        $number = fn (string $key): ?float => is_numeric($value = str_replace(',', '.', trim((string) ($this->geometry[$key] ?? '')))) ? (float) $value : null;
        $left = $number('left');
        $top = $number('top');
        $width = $number('width');
        $height = $number('height');

        if ($left === null || $top === null || $width === null || $height === null) {
            $this->refreshGeometry();

            return;
        }

        $heightUnits = FieldLayout::mmToUnits($height);

        $this->setBox($index, [
            'x' => FieldLayout::mmToUnits($left),
            'y' => $size['height'] - FieldLayout::mmToUnits($top) - $heightUnits,
            'width' => FieldLayout::mmToUnits($width),
            'height' => $heightUnits,
        ]);
        $this->refreshGeometry();
        $this->announce = $this->describePosition($index);
    }

    /** Any edit in the field list (page, signer, label, rules): the boxes are put back inside their pages. */
    public function updatedFields(): void
    {
        /** @var list<array<string, mixed>> $kept */
        $kept = [];

        foreach ($this->fields as $field) {
            $type = FieldType::tryFrom((string) ($field['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            [$width, $height] = FieldLayout::defaultSize($type);
            $field = [
                'x' => 0,
                'y' => 0,
                'width' => $width,
                'height' => $height,
                'label' => $type->label(),
                'options_text' => '',
                'group_key' => null,
                ...$field,
                'rules' => [...['max_length' => '', 'min' => '', 'max' => '', 'decimals' => '0'], ...(array) ($field['rules'] ?? [])],
            ];
            $page = (int) ($field['page'] ?? 1);
            $field['page'] = isset($this->pageSizes[$page - 1]) ? $page : 1;
            $field['signer'] = isset($this->signers[(int) ($field['signer'] ?? 0)]) ? (int) ($field['signer'] ?? 0) : 0;
            $field['required'] = (bool) ($field['required'] ?? false);
            $size = $this->pageSizes[(int) $field['page'] - 1] ?? null;

            if ($size !== null) {
                $field = [...$field, ...FieldLayout::clamp($type, [
                    'x' => (int) ($field['x'] ?? 0),
                    'y' => (int) ($field['y'] ?? 0),
                    'width' => (int) ($field['width'] ?? 0),
                    'height' => (int) ($field['height'] ?? 0),
                ], $size['width'], $size['height'])];
            }

            $kept[] = $field;
        }

        $this->fields = $kept;

        if ($this->selected !== null && ! isset($this->fields[$this->selected])) {
            $this->selected = null;
        }

        $this->refreshGeometry();
    }

    /** Measures the document's pages once, so that the boxes can be placed on them. */
    private function loadPageSizes(): void
    {
        if ($this->pageSizes !== [] || $this->type === null) {
            return;
        }

        $engine = app(PdfEngine::class);
        $this->pagesNote = null;

        if (! $engine->isInstalled()) {
            $this->pagesNote = __('Fält i dokumentet kräver PDF-motorn, som inte är installerad här. Dokumentet kan skickas utan fält.');

            return;
        }

        $document = $this->document();
        $pdf = CurrentCompany::run($this->company(), fn (): array => $document->signingDocument());
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonox-fields-'.Str::uuid()->toString().'.pdf';
        file_put_contents($file, $pdf['bytes']);

        try {
            $sizes = $engine->inspect($file)->pageSizes;
        } catch (PdfEngineException) {
            $this->pagesNote = __('PDF-motorn kunde inte läsa dokumentet, så fält kan inte placeras. Dokumentet kan skickas utan fält.');

            return;
        } finally {
            @unlink($file);
        }

        $this->pageSizes = array_map(fn (array $size): array => [
            'width' => (int) round($size['width'] * 100),
            'height' => (int) round($size['height'] * 100),
            'rotation' => (int) $size['rotation'],
        ], $sizes);
        $this->new['page'] = 1;
    }

    private function placeField(FieldType $type, int $signer, int $page, int $x, int $y): void
    {
        $size = $this->pageSizes[$page - 1] ?? null;

        if ($size === null || ! isset($this->signers[$signer])) {
            return;
        }

        if ($size['rotation'] % 360 !== 0) {
            $this->addError('fields', __('Fält kan inte placeras på en roterad sida.'));

            return;
        }

        if (count($this->fields) >= SigningFields::MAX_FIELDS) {
            $this->addError('fields', __('Högst :max fält i ett dokument.', ['max' => SigningFields::MAX_FIELDS]));

            return;
        }

        $this->resetErrorBag();
        [$width, $height] = FieldLayout::defaultSize($type);
        $box = FieldLayout::clamp($type, ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height], $size['width'], $size['height']);

        $this->fields[] = [
            'signer' => $signer,
            'type' => $type->value,
            'page' => $page,
            ...$box,
            'label' => $type->label(),
            'required' => true,
            'options_text' => match ($type) {
                FieldType::Dropdown => __('Alternativ 1')."\n".__('Alternativ 2'),
                FieldType::Radio => __('Alternativ 1'),
                default => '',
            },
            'group_key' => $type === FieldType::Radio ? $this->freeGroupKey() : null,
            'rules' => ['max_length' => '', 'min' => '', 'max' => '', 'decimals' => '0'],
        ];

        $this->selectField(count($this->fields) - 1);
        $this->announce = __('Fältet ”:label” är tillagt. :where', ['label' => $type->label(), 'where' => $this->describePosition(count($this->fields) - 1)]);
    }

    /** @param  array{x: int, y: int, width: int, height: int}  $box */
    private function setBox(int $index, array $box): void
    {
        $type = FieldType::from((string) ($this->fields[$index]['type'] ?? ''));
        $size = $this->pageSizes[(int) ($this->fields[$index]['page'] ?? 1) - 1] ?? null;

        if ($size === null) {
            return;
        }

        $this->fields[$index] = [...$this->fields[$index], ...FieldLayout::clamp($type, $box, $size['width'], $size['height'])];
    }

    private function refreshGeometry(): void
    {
        $field = $this->selected === null ? null : ($this->fields[$this->selected] ?? null);
        $size = $field === null ? null : ($this->pageSizes[(int) ($field['page'] ?? 1) - 1] ?? null);

        if ($field === null || $size === null) {
            $this->geometry = ['left' => '', 'top' => '', 'width' => '', 'height' => ''];

            return;
        }

        $this->geometry = [
            'left' => (string) FieldLayout::unitsToMm((int) ($field['x'] ?? 0)),
            'top' => (string) FieldLayout::unitsToMm($size['height'] - (int) ($field['y'] ?? 0) - (int) ($field['height'] ?? 0)),
            'width' => (string) FieldLayout::unitsToMm((int) ($field['width'] ?? 0)),
            'height' => (string) FieldLayout::unitsToMm((int) ($field['height'] ?? 0)),
        ];
    }

    /** Where a field is, in words, for the live region. */
    private function describePosition(int $index): string
    {
        $field = $this->fields[$index];
        $size = $this->pageSizes[(int) ($field['page'] ?? 1) - 1] ?? ['height' => 0];

        return __('Sida :page, :left mm från vänster och :top mm från överkanten, :width × :height mm.', [
            'page' => $field['page'] ?? 1,
            'left' => FieldLayout::unitsToMm((int) ($field['x'] ?? 0)),
            'top' => FieldLayout::unitsToMm($size['height'] - (int) ($field['y'] ?? 0) - (int) ($field['height'] ?? 0)),
            'width' => FieldLayout::unitsToMm((int) ($field['width'] ?? 0)),
            'height' => FieldLayout::unitsToMm((int) ($field['height'] ?? 0)),
        ]);
    }

    private function freeGroupKey(): string
    {
        $used = array_column($this->fields, 'group_key');

        for ($number = 1; $number <= SigningFields::MAX_FIELDS + 1; $number++) {
            if (! in_array("grupp{$number}", $used, true)) {
                return "grupp{$number}";
            }
        }

        return 'grupp';
    }

    /**
     * The fields as SigningFields::validate takes them: what the form holds as text becomes options and rules,
     * and nothing the editor keeps for itself is passed on.
     *
     * @return list<array<string, mixed>>
     */
    private function definitions(): array
    {
        return array_map(function (array $field): array {
            $type = FieldType::from((string) ($field['type'] ?? ''));
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($field['options_text'] ?? '')) ?: []), fn (string $line): bool => $line !== ''));
            $rules = (array) ($field['rules'] ?? []);
            $decimal = fn (mixed $value): ?string => trim((string) $value) === '' ? null : str_replace(',', '.', trim((string) $value));

            return [
                'signer' => (int) ($field['signer'] ?? 0),
                'type' => $type->value,
                'page' => (int) ($field['page'] ?? 1),
                'x' => (int) ($field['x'] ?? 0),
                'y' => (int) ($field['y'] ?? 0),
                'width' => (int) ($field['width'] ?? 0),
                'height' => (int) ($field['height'] ?? 0),
                'label' => (string) ($field['label'] ?? ''),
                'required' => (bool) ($field['required'] ?? false),
                'options' => match ($type) {
                    FieldType::Dropdown => $lines,
                    FieldType::Radio => array_slice($lines, 0, 1),
                    default => [],
                },
                'group_key' => $type === FieldType::Radio ? trim((string) ($field['group_key'] ?? '')) : null,
                'rules' => match ($type) {
                    FieldType::Text => trim((string) ($rules['max_length'] ?? '')) === '' ? [] : ['max_length' => (int) $rules['max_length']],
                    FieldType::Number => array_filter(['min' => $decimal($rules['min'] ?? ''), 'max' => $decimal($rules['max'] ?? ''), 'decimals' => (int) ($rules['decimals'] ?? 0)], fn (mixed $value): bool => $value !== null),
                    default => [],
                },
            ];
        }, $this->fields);
    }

    /**
     * The same validation the send makes (SigningFields, which also requires a signature field for every signer),
     * run when the sender leaves the step so that the errors appear there and not at the send.
     */
    private function fieldsAreValid(): bool
    {
        $this->resetErrorBag();
        $definitions = $this->definitions();

        if ($definitions === []) {
            return true;
        }

        try {
            $clean = SigningFields::validate($definitions, count($this->signers));
            SigningFields::checkGeometry($clean, array_map(fn (array $size): array => [
                'width' => $size['width'] / 100,
                'height' => $size['height'] / 100,
                'rotation' => $size['rotation'],
            ], $this->pageSizes));
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());

            return false;
        }

        return true;
    }

    public function render(): View
    {
        $company = $this->company();
        $user = $this->user();
        $document = $this->type === null ? null : $this->document();
        $first = $this->type === null;

        return view('signing-module::livewire.wizard', [
            'document' => $document,
            'steps' => self::STEPS,
            'isQuote' => $document instanceof SalesDocument,
            'standalone' => $document instanceof RestrictsEnvelopes || $first,
            'checks' => SignerCheck::choosable(),
            'intervals' => SigningDeadlines::INTERVALS,
            'minDays' => SigningDeadlines::MIN_DAYS,
            'maxDays' => SigningDeadlines::MAX_DAYS,
            'maxReminders' => SigningDeadlines::MAX_REMINDERS,
            'maxSigners' => SigningEnvelope::MAX_SIGNERS,
            'maxCopies' => SigningEnvelope::MAX_COPIES,
            'maxStep' => SigningEnvelope::MAX_STEP,
            'deals' => $document === null ? collect() : $this->deals($document),
            'canInvoice' => $document instanceof SalesDocument && $user->hasTeamPermission($company, 'invoices:create'),
            'customers' => $first && $user->can('viewAny', [Customer::class, $company]) ? Customer::query()->orderBy('name')->limit(self::PICKER_LIMIT + 1)->get(['id', 'name']) : collect(),
            'suppliers' => $first && $user->can('viewAny', [Supplier::class, $company]) ? Supplier::query()->orderBy('name')->limit(self::PICKER_LIMIT + 1)->get(['id', 'name']) : collect(),
            'pickerLimit' => self::PICKER_LIMIT,
            'recent' => $first ? $this->unsent($company->id) : collect(),
            'pages' => $document instanceof SigningDocument ? $document->page_count : null,
            'pdfUrl' => $this->type === null ? null : route('signing.new.pdf', ['typ' => $this->type, 'dokument' => $this->documentId]),
            'fieldTypes' => FieldType::cases(),
            'presets' => FieldLayout::PRESETS,
            'maxFields' => SigningFields::MAX_FIELDS,
        ]);
    }

    /** The chosen document, in the company; a quote needs invoices:view, an agreement CRM, a standalone document nothing more. */
    private function document(): Signable
    {
        return ChosenDocument::resolve($this->user(), $this->company(), $this->type, $this->documentId);
    }

    private function defaultCheck(): string
    {
        return $this->type === SigningDocument::SIGNING_TYPE ? SignerCheck::EmailCode->value : SignerCheck::None->value;
    }

    private function defaultEmail(Signable $document): string
    {
        return match (true) {
            $document instanceof SalesDocument => (string) ($document->sent_to ?? Customer::query()->whereKey($document->customer_id)->value('email')),
            $document instanceof Agreement => (string) Account::query()->whereKey($document->account_id)->value('email'),
            default => '',
        };
    }

    /**
     * The step whose fields a validation error names, so the wizard opens
     * where the mistake is.
     *
     * @param  list<string>  $keys
     */
    private function stepOf(array $keys): int
    {
        $key = (string) ($keys[0] ?? '');

        return match (true) {
            str_starts_with($key, 'fields') => 3,
            str_contains($key, 'verification'), str_contains($key, 'access_code') => 4,
            str_starts_with($key, 'signers'), str_starts_with($key, 'copies') => 2,
            $key === 'document' => 6,
            default => 5,
        };
    }

    /** @return Collection<int, Deal> */
    private function deals(Signable $document): Collection
    {
        $company = $this->company();

        if (! app(ModuleManager::class)->isEnabled(CrmModule::KEY, $company) || ! ($document instanceof SalesDocument || $document instanceof Agreement)) {
            return collect();
        }

        $accountId = $document instanceof Agreement ? $document->account_id : Account::query()->where('customer_id', $document->customer_id)->value('id');

        if ($accountId === null || ! $this->user()->can('viewAny', [Deal::class, $company])) {
            return collect();
        }

        return Deal::query()->where('account_id', $accountId)->where('status', DealStatus::Open->value)->orderBy('title')->get(['id', 'title']);
    }

    /**
     * Uploaded documents that have never been sent, newest first: a document
     * picked up again. One query.
     *
     * @return Collection<int, SigningDocument>
     */
    private function unsent(int $companyId): Collection
    {
        return SigningDocument::query()
            ->whereNull('archived_at')
            ->when(! $this->allowed(SigningModule::MANAGE), fn ($query) => $query->where('uploaded_by', $this->user()->id))
            ->whereRaw("id::text not in (select signable_id from signing_envelopes where company_id = ? and document_type = 'DOCUMENT' and signable_id is not null)", [$companyId])
            ->latest('created_at')
            ->limit(15)
            ->get(['id', 'title', 'page_count', 'created_at']);
    }
}
