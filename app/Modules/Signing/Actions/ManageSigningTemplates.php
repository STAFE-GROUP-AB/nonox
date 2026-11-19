<?php

declare(strict_types=1);

namespace App\Modules\Signing\Actions;

use App\Domain\Signing\Fields\SigningFields;
use App\Domain\Signing\Pdf\PdfEngine;
use App\Domain\Signing\Pdf\PdfEngineException;
use App\Domain\Signing\SigningDeadlines;
use App\Domain\Signing\Verification\SignerCheck;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\SigningField;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\Models\SigningTemplateRole;
use App\Modules\Signing\SigningModule;
use App\Support\Audit\Audit;
use App\Support\Files\StoredFiles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Templates with roles for signing (ADR-046 amendment 2026-11-18, gap 8,
 * package S14): create a template from an uploaded PDF (with the fields the
 * sender placed, one role per signer) or from a quote's or an agreement's
 * document type (roles and defaults only: the document itself is made when
 * it is sent), change its name, roles, defaults and fields, archive it, and
 * start a send from it. The file is copied once and never changes; a new
 * file is a new template.
 *
 * Who may: signing:send creates, and changes and archives their own;
 * signing:manage does the same for everyone's. The module must be on. The
 * audit entries hold ids, counts and enums, never the name, a role's label
 * or a copy recipient's address.
 *
 * Input (create and update): name, document_type (create only), roles (list
 * of key?, label, step, verification, internal), deadline_days,
 * auto_reminders, reminder_days, language, confidential, copies (email,
 * name, language) and, for a document with a file, fields in the shape
 * SigningFields::validate takes, where `signer` is the index of the role.
 * On update `fields` left out keeps the fields; given, it replaces them.
 */
final class ManageSigningTemplates
{
    public function __construct(private readonly PdfEngine $engine) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function create(User $user, Company $company, array $input, ?SigningDocument $from = null): SigningTemplate
    {
        $this->authorizeSend($user, $company);

        $type = (string) ($input['document_type'] ?? ($from instanceof SigningDocument ? SigningTemplate::DOCUMENT : ''));

        if (! in_array($type, SigningTemplate::TYPES, true)) {
            throw ValidationException::withMessages(['document_type' => __('Välj vilken sorts dokument mallen är för.')]);
        }

        if (($type === SigningTemplate::DOCUMENT) !== ($from instanceof SigningDocument)) {
            throw ValidationException::withMessages(['document_type' => __('En mall för ett eget dokument skapas från ett uppladdat dokument. Offerter och avtal har ingen fil.')]);
        }

        $clean = $this->validated($input, $type);
        $roles = $this->roleRows($clean['roles'], []);
        $bytes = null;
        $sizes = [];
        $fields = [];

        if ($from instanceof SigningDocument) {
            abort_unless($from->company_id === $company->id, 404);
            $bytes = $from->signingDocument()['bytes'];
            $fields = $this->fields($input, count($roles), $bytes, $sizes);
        }

        return DB::transaction(fn (): SigningTemplate => CurrentCompany::run($company, function () use ($user, $company, $type, $clean, $roles, $from, $bytes, $fields, $sizes): SigningTemplate {
            if (SigningTemplate::query()->whereNull('archived_at')->count() >= SigningTemplate::MAX_PER_COMPANY) {
                throw ValidationException::withMessages(['name' => __('Det finns redan :count mallar. Ta bort eller arkivera några innan du sparar fler.', ['count' => SigningTemplate::MAX_PER_COMPANY])]);
            }

            $stored = null;

            if ($from instanceof SigningDocument) {
                $stored = "signing-templates/{$company->id}/".Str::uuid()->toString().'.pdf';
                Storage::disk(SigningRequest::DISK)->put($stored, $bytes);
            }

            $template = SigningTemplate::create([
                'company_id' => $company->id,
                'name' => $clean['name'],
                'document_type' => $type,
                'path' => $stored,
                'file_name' => $from?->name,
                'sha256' => $from?->sha256,
                'bytes' => $from?->bytes,
                'page_count' => $from?->page_count,
                'created_by' => $user->id,
                ...$this->defaults($clean, $type),
            ]);

            $this->writeRoles($template, $roles);
            $this->writeFields($template, $roles, $fields, $sizes);

            Audit::record('signing.template_created', $template, ['signing_template_id' => $template->id, 'document_type' => $type, 'roles' => count($roles), 'fields' => count($fields)], $company, $user);

            return $template;
        }));
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function update(User $user, Company $company, SigningTemplate $template, array $input): SigningTemplate
    {
        $this->authorizeEdit($user, $company, $template);

        $clean = $this->validated($input, $template->document_type);

        return DB::transaction(fn (): SigningTemplate => CurrentCompany::run($company, function () use ($user, $company, $template, $clean, $input): SigningTemplate {
            $locked = SigningTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $existing = $locked->roles()->pluck('label', 'key')->all();
            $roles = $this->roleRows($clean['roles'], array_keys($existing));
            $replaceFields = $locked->hasFile() && array_key_exists('fields', $input);

            // Fields drawn on another PDF would land in the wrong places: only the template's own file may change them.
            if ($replaceFields && ! hash_equals((string) $locked->sha256, (string) ($input['document_sha256'] ?? ''))) {
                throw ValidationException::withMessages(['fields' => __('Fälten kan bara ändras med mallens egen PDF.')]);
            }

            $sizes = [];
            $fields = [];

            if ($replaceFields) {
                $bytes = Storage::disk(SigningRequest::DISK)->get((string) $locked->path);

                if (! is_string($bytes) || hash('sha256', $bytes) !== $locked->sha256) {
                    throw ValidationException::withMessages(['fields' => __('Mallens fil saknas eller har ändrats.')]);
                }

                $fields = $this->fields($input, count($roles), $bytes, $sizes);
            }

            $locked->forceFill([
                'name' => $clean['name'],
                ...$this->defaults($clean, $locked->document_type),
            ])->save();

            $keep = array_column($roles, 'key');
            SigningTemplateRole::query()->where('template_id', $locked->id)->whereNotIn('key', $keep)->delete();
            $this->writeRoles($locked, $roles);

            if ($replaceFields) {
                SigningField::query()->where('template_id', $locked->id)->delete();
                $this->writeFields($locked, $roles, $fields, $sizes);
            }

            Audit::record('signing.template_updated', $locked, ['signing_template_id' => $locked->id, 'roles' => count($roles), 'fields_replaced' => $replaceFields ? count($fields) : null], $company, $user);

            return $locked;
        }));
    }

    public function archive(User $user, Company $company, SigningTemplate $template): SigningTemplate
    {
        return $this->setArchived($user, $company, $template, true);
    }

    public function restore(User $user, Company $company, SigningTemplate $template): SigningTemplate
    {
        return $this->setArchived($user, $company, $template, false);
    }

    /**
     * A template that has never been used is removed with its file; one that has been used is evidence of how
     * envelopes were set up and is archived instead.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function delete(User $user, Company $company, SigningTemplate $template): void
    {
        $this->authorizeEdit($user, $company, $template);

        $path = DB::transaction(fn (): ?string => CurrentCompany::run($company, function () use ($user, $company, $template): ?string {
            $locked = SigningTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();

            if (DB::table('signing_envelopes')->where('company_id', $company->id)->where('template_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['template' => __('Mallen har använts och kan bara arkiveras.')]);
            }

            $path = $locked->path;
            $id = $locked->id;
            $locked->delete();
            Audit::record('signing.template_deleted', null, ['signing_template_id' => $id], $company, $user);

            return $path;
        }));

        if ($path !== null) {
            StoredFiles::deleteUnreferenced([$path]);
        }
    }

    /**
     * Copies the template's file into a new standalone document of the sender, so that the send wizard can go on
     * with it (the fields and the roles are applied by the wizard). The copy is an ordinary uploaded document:
     * the sender may delete it unsent.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function start(User $user, Company $company, SigningTemplate $template): SigningDocument
    {
        $this->authorizeSend($user, $company);
        abort_unless($template->company_id === $company->id, 404);
        abort_unless(SigningTemplate::query()->visibleTo($user, $company)->whereKey($template->id)->exists(), 404);

        if ($template->isArchived() || ! $template->hasFile()) {
            throw ValidationException::withMessages(['template' => __('Mallen kan inte användas.')]);
        }

        $bytes = Storage::disk(SigningRequest::DISK)->get((string) $template->path);

        if (! is_string($bytes) || hash('sha256', $bytes) !== $template->sha256) {
            throw ValidationException::withMessages(['template' => __('Mallens fil saknas eller har ändrats.')]);
        }

        return DB::transaction(fn (): SigningDocument => CurrentCompany::run($company, function () use ($user, $company, $template, $bytes): SigningDocument {
            $unsent = SigningDocument::query()->whereRaw("id::text not in (select signable_id from signing_envelopes where company_id = ? and document_type = 'DOCUMENT' and signable_id is not null)", [$company->id])->count();

            if ($unsent >= StoreSigningDocument::MAX_UNSENT) {
                throw ValidationException::withMessages(['template' => __('Det finns redan :count uppladdade dokument som inte är skickade. Skicka eller ta bort några innan du fortsätter.', ['count' => StoreSigningDocument::MAX_UNSENT])]);
            }

            $stored = "signing-documents/{$company->id}/".Str::uuid()->toString().'.pdf';
            Storage::disk(SigningRequest::DISK)->put($stored, $bytes);

            $document = SigningDocument::create([
                'company_id' => $company->id,
                'title' => $template->name,
                'path' => $stored,
                'name' => (string) $template->file_name,
                'sha256' => (string) $template->sha256,
                'bytes' => (int) $template->bytes,
                'page_count' => (int) $template->page_count,
                'uploaded_by' => $user->id,
            ]);

            Audit::record('signing.document_started_from_template', $document, ['signing_document_id' => $document->id, 'signing_template_id' => $template->id], $company, $user);

            return $document;
        }));
    }

    private function setArchived(User $user, Company $company, SigningTemplate $template, bool $archived): SigningTemplate
    {
        $this->authorizeEdit($user, $company, $template);

        return DB::transaction(fn (): SigningTemplate => CurrentCompany::run($company, function () use ($user, $company, $template, $archived): SigningTemplate {
            $locked = SigningTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['archived_at' => $archived ? now() : null])->save();
            Audit::record($archived ? 'signing.template_archived' : 'signing.template_restored', $locked, ['signing_template_id' => $locked->id], $company, $user);

            return $locked;
        }));
    }

    private function authorizeSend(User $user, Company $company): void
    {
        abort_unless(app(ModuleManager::class)->isEnabled(SigningModule::KEY, $company), 404);

        if (! $user->hasTeamPermission($company, SigningModule::SEND)) {
            throw new AuthorizationException;
        }
    }

    /** The creator changes their own template; signing:manage changes everyone's. */
    public function authorizeEdit(User $user, Company $company, SigningTemplate $template): void
    {
        $this->authorizeSend($user, $company);
        abort_unless($template->company_id === $company->id, 404);

        if ($template->created_by !== $user->id && ! $user->hasTeamPermission($company, SigningModule::MANAGE)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $input, string $type): array
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:120', 'regex:/^[^\p{C}]*$/u'],
            'roles' => ['required', 'array', 'min:1', 'max:'.SigningEnvelope::MAX_SIGNERS],
            'roles.*.key' => ['nullable', 'string', 'regex:/^[a-z0-9-]{1,40}$/'],
            'roles.*.label' => ['required', 'string', 'max:60', 'regex:/^[^\p{C}]+$/u'],
            'roles.*.step' => ['required', 'integer', 'min:1', 'max:'.SigningEnvelope::MAX_STEP],
            'roles.*.verification' => ['nullable', 'string', Rule::in(array_map(fn (SignerCheck $check): string => $check->value, SignerCheck::choosable()))],
            'roles.*.internal' => ['sometimes', 'boolean'],
            'deadline_days' => ['nullable', 'integer', 'min:'.SigningDeadlines::MIN_DAYS, 'max:'.SigningDeadlines::MAX_DAYS],
            'auto_reminders' => ['sometimes', 'boolean'],
            'reminder_days' => ['nullable', 'integer', Rule::in(SigningDeadlines::INTERVALS)],
            'language' => ['nullable', 'string', 'in:sv,en'],
            'confidential' => ['sometimes', 'boolean', $type === SigningTemplate::DOCUMENT ? 'nullable' : 'declined'],
            'copies' => ['nullable', 'array', 'max:'.SigningEnvelope::MAX_COPIES],
            'copies.*.email' => ['required', 'email', 'max:255'],
            'copies.*.name' => ['nullable', 'string', 'max:120', 'regex:/^[^\p{C}]*$/u'],
            'copies.*.language' => ['nullable', 'string', 'in:sv,en'],
        ], [
            'roles.min' => __('Lägg till minst en roll.'),
            'roles.max' => __('Högst :max roller.', ['max' => SigningEnvelope::MAX_SIGNERS]),
            'confidential.declined' => __('Bara egna dokument kan vara konfidentiella.'),
        ], [
            'name' => __('mallens namn'),
            'roles.*.label' => __('rollens namn'),
            'roles.*.step' => __('steg'),
            'deadline_days' => __('antal dagar att signera'),
            'copies.*.email' => __('e-post'),
        ])->validate();

        $labels = array_map(fn (array $role): string => mb_strtolower(trim((string) $role['label'])), array_values((array) $validated['roles']));

        if (count(array_unique($labels)) !== count($labels)) {
            throw ValidationException::withMessages(['roles' => __('Varje roll behöver ett eget namn.')]);
        }

        $copies = array_values((array) ($validated['copies'] ?? []));
        $emails = array_map(fn (array $copy): string => mb_strtolower(trim((string) $copy['email'])), $copies);

        if (count(array_unique($emails)) !== count($emails)) {
            throw ValidationException::withMessages(['copies' => __('En kopiemottagare kan inte stå två gånger.')]);
        }

        $validated['name'] = trim((string) $validated['name']);

        return $validated;
    }

    /**
     * The columns the defaults live in.
     *
     * @param  array<string, mixed>  $clean
     * @return array<string, mixed>
     */
    private function defaults(array $clean, string $type): array
    {
        return [
            'deadline_days' => isset($clean['deadline_days']) ? (int) $clean['deadline_days'] : null,
            'auto_reminders' => (bool) ($clean['auto_reminders'] ?? true),
            'reminder_days' => isset($clean['reminder_days']) ? (int) $clean['reminder_days'] : null,
            'language' => (string) ($clean['language'] ?? 'sv') === 'en' ? 'en' : 'sv',
            'confidential' => $type === SigningTemplate::DOCUMENT && (bool) ($clean['confidential'] ?? false),
            'copies' => array_map(fn (array $copy): array => [
                'email' => mb_strtolower(trim((string) $copy['email'])),
                'name' => blank($copy['name'] ?? null) ? null : trim((string) $copy['name']),
                'language' => (string) ($copy['language'] ?? 'sv') === 'en' ? 'en' : 'sv',
            ], array_values((array) ($clean['copies'] ?? []))),
        ];
    }

    /**
     * The roles as rows with a key each: a given key that the template already has is kept (the fields stay with
     * it), a new role gets a key from its name.
     *
     * @param  list<array<string, mixed>>  $roles
     * @param  list<string>  $existingKeys
     * @return list<array{key: string, label: string, step: int, verification: string, internal: bool, position: int}>
     */
    private function roleRows(array $roles, array $existingKeys): array
    {
        $rows = [];
        $used = [];

        foreach ($roles as $position => $role) {
            $given = (string) ($role['key'] ?? '');
            $key = $given !== '' && in_array($given, $existingKeys, true) && ! in_array($given, $used, true) ? $given : null;

            if ($key === null) {
                $base = Str::limit(Str::slug((string) $role['label']), 30, '') ?: 'roll';
                $key = $base;

                for ($number = 2; in_array($key, $used, true) || (in_array($key, $existingKeys, true) && $key !== $given); $number++) {
                    $key = "{$base}-{$number}";
                }
            }

            $used[] = $key;
            $rows[] = [
                'key' => $key,
                'label' => trim((string) $role['label']),
                'step' => (int) $role['step'],
                'verification' => (string) ($role['verification'] ?? SignerCheck::None->value) ?: SignerCheck::None->value,
                'internal' => (bool) ($role['internal'] ?? false),
                'position' => $position + 1,
            ];
        }

        return $rows;
    }

    /** @param  list<array{key: string, label: string, step: int, verification: string, internal: bool, position: int}>  $roles */
    private function writeRoles(SigningTemplate $template, array $roles): void
    {
        foreach ($roles as $role) {
            SigningTemplateRole::query()->updateOrCreate(
                ['template_id' => $template->id, 'key' => $role['key']],
                ['company_id' => $template->company_id, ...Arr::except($role, ['key'])],
            );
        }
    }

    /**
     * The template's fields: validated like an envelope's, and measured against the stored file's pages.
     *
     * @param  array<string, mixed>  $input
     * @param  list<array{width: float, height: float, rotation: int}>  $sizes  set to the pages' sizes
     * @return list<array<string, mixed>>
     */
    private function fields(array $input, int $roles, string $bytes, array &$sizes): array
    {
        $definitions = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        $fields = SigningFields::validate($definitions, $roles);

        if ($fields === []) {
            return [];
        }

        if (! $this->engine->isInstalled()) {
            throw ValidationException::withMessages(['fields' => __('Fält i dokumentet kräver PDF-motorn.')]);
        }

        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonox-template-'.Str::uuid()->toString().'.pdf';
        file_put_contents($file, $bytes);

        try {
            $sizes = $this->engine->inspect($file)->pageSizes;
        } catch (PdfEngineException) {
            throw ValidationException::withMessages(['fields' => __('Dokumentet kunde inte läsas av PDF-motorn, så fält kan inte placeras.')]);
        } finally {
            @unlink($file);
        }

        SigningFields::checkGeometry($fields, $sizes);

        return $fields;
    }

    /**
     * @param  list<array{key: string, label: string, step: int, verification: string, internal: bool, position: int}>  $roles
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array{width: float, height: float, rotation: int}>  $sizes
     */
    private function writeFields(SigningTemplate $template, array $roles, array $fields, array $sizes): void
    {
        foreach ($fields as $position => $field) {
            $size = $sizes[$field['page'] - 1];

            SigningField::create([
                'company_id' => $template->company_id,
                'template_id' => $template->id,
                'role_key' => $roles[$field['signer']]['key'],
                'type' => $field['type'],
                'page' => $field['page'],
                'x' => $field['x'],
                'y' => $field['y'],
                'width' => $field['width'],
                'height' => $field['height'],
                'page_width' => (int) round($size['width'] * 100),
                'page_height' => (int) round($size['height'] * 100),
                'label' => $field['label'],
                'required' => $field['required'],
                'options' => $field['options'],
                'group_key' => $field['group_key'],
                'rules' => $field['rules'],
                'position' => $position + 1,
            ]);
        }
    }
}
