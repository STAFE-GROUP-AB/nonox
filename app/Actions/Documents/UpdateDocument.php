<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Domain\Documents\DocumentDetails;
use App\Domain\Documents\DocumentRetention;
use App\Domain\Documents\RetentionClass;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentTag;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Changes a document's title, category, retention and tags. The retention
 * rule is the floor: a chosen date may be lowered down to the rule (GDPR
 * art. 5.1 e), but räkenskapsinformation cannot be reclassified out of its
 * seven years. A document never moves into or out of the personnel class,
 * and a running contract keeps its class until it has an end date.
 */
class UpdateDocument
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Document $document, array $input): Document
    {
        Gate::forUser($user)->authorize('update', $document);

        $company = Company::query()->findOrFail($document->company_id);
        $details = DocumentDetails::validate($user, $company, $input);

        return CurrentCompany::run($company->id, fn (): Document => DB::transaction(function () use ($user, $document, $details): Document {
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $old = $locked->retain_until;
            $new = $details['attributes']['retain_until'] === null ? null : CarbonImmutable::parse($details['attributes']['retain_until']);
            $newClass = RetentionClass::from($details['attributes']['retention_class']);

            // In or out of personnel: the search text and the employee link would follow; upload it again instead.
            if (($locked->retention_class === RetentionClass::Personnel) !== ($newClass === RetentionClass::Personnel)) {
                throw ValidationException::withMessages(['retention_class' => __('Ett dokument kan inte flyttas till eller från personaldokument. Ladda upp det på nytt i rätt klass.')]);
            }

            if ($locked->retention_class === RetentionClass::Contract && $locked->contract_ends_on === null && $newClass !== RetentionClass::Contract) {
                throw ValidationException::withMessages(['retention_class' => __('Avtalet löper tills vidare. Ange när det upphör innan dokumentet sparas som något annat.')]);
            }

            // The floor of räkenskapsinformation: a new class, mark or fiscal year must keep one at least as late (no two-step escape).
            $oldFloor = DocumentRetention::accountingFloor($locked->retention_class, $locked->is_accounting_record, $locked->fiscal_year_id);
            $newFloor = DocumentRetention::accountingFloor($newClass, $details['attributes']['is_accounting_record'], $details['attributes']['fiscal_year_id']);

            if (! DocumentRetention::keepsFloor($oldFloor, $newFloor, $new)) {
                throw ValidationException::withMessages([
                    'retention_class' => __('Dokumentet är räkenskapsinformation och ska sparas till och med :date. Det kan inte sparas som något annat, flyttas till ett tidigare räkenskapsår eller få kortare tid förrän dess.', ['date' => $oldFloor?->toDateString()]),
                ]);
            }

            $attributes = $details['attributes'];

            // An encrypted title is encrypted anew on every write: leave an unchanged one alone.
            if ($attributes['title'] === $locked->title) {
                unset($attributes['title']);
            }

            $locked->fill($attributes);
            $changed = array_keys($locked->getDirty());
            $locked->save();

            $existing = DocumentTag::query()->where('document_id', $locked->id)->pluck('tag')->all();

            if ($existing !== $details['tags']) {
                DocumentTag::query()->where('document_id', $locked->id)->delete();

                foreach ($details['tags'] as $tag) {
                    DocumentTag::create(['company_id' => $locked->company_id, 'document_id' => $locked->id, 'tag' => $tag]);
                }

                $changed[] = 'tags';
            }

            if ($changed !== []) {
                Audit::record('document.updated', $locked, [
                    'fields' => array_values(array_unique(array_map(fn (string $field): string => $field === 'title_encrypted' ? 'title' : $field, array_diff($changed, ['updated_at'])))),
                    'retention_class' => $locked->retention_class->value,
                    'retain_until_before' => $old?->toDateString(),
                    'retain_until' => $locked->retain_until?->toDateString(),
                    'tag_count' => count($details['tags']),
                ], $locked->company_id, $user);
            }

            return $locked;
        }));
    }
}
