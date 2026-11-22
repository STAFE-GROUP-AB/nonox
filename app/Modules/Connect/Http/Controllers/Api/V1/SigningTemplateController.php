<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Modules\Connect\Http\Controllers\Api\V1\Concerns\ActsAsCredential;
use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Connect\Http\Resources\SigningPresenter;
use App\Modules\Signing\Models\SigningTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Templates for signing over the API (ADR-046 S19, S14): read only, with
 * signing:send as the screen needs. A template the person may not see (a
 * confidential template of another member, unless signing:manage) answers
 * 404 here and when it is named in an envelope, never silently ignored.
 * Templates are made and changed in the app, where the fields are placed on
 * the document; an envelope is sent from one with POST /signing/envelopes.
 */
final class SigningTemplateController
{
    use ActsAsCredential;

    public function index(Request $request): JsonResponse
    {
        $filters = Validator::make($request->query(), [
            'document_type' => ['nullable', 'string', 'in:'.implode(',', SigningTemplate::TYPES)],
        ])->validate();

        $query = SigningTemplate::query()->visibleTo($this->user(), $this->company());

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        if (isset($filters['document_type'])) {
            $query->where('document_type', $filters['document_type']);
        }

        return CursorList::respond($request, $query, fn (SigningTemplate $template): array => SigningPresenter::template($template));
    }

    public function show(string $template): JsonResponse
    {
        $found = SigningTemplate::query()->visibleTo($this->user(), $this->company())->with(['roles', 'fields'])->findOrFail($template);

        return CursorList::one(SigningPresenter::template($found, detail: true));
    }
}
