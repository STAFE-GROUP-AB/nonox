<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\SegmentFilter;
use App\Modules\Crm\Models\Segment;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Adds, changes or removes a segment (ADR-048), with crm:settings. A
 * segment used by a mailing that is sent keeps the mailing's statistics:
 * removing the segment only clears the mailing's link to it.
 */
final class SaveSegment
{
    /** @param  array<string, mixed>  $input  name and filters */
    public function __invoke(User $user, Company $company, array $input, ?Segment $segment = null): Segment
    {
        Gate::forUser($user)->authorize('settings', [Segment::class, $company]);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'filters' => ['array'],
            'filters.owner_id' => ['nullable', 'integer', Rule::in(CompanyMembers::ids($company))],
        ], ['filters.owner_id.in' => __('Ansvarig säljare måste vara med i företaget.')], ['name' => __('namn')])->validate();

        $filters = SegmentFilter::from((array) ($validated['filters'] ?? []))->toArray();

        return DB::transaction(fn (): Segment => CurrentCompany::run($company, function () use ($user, $company, $validated, $filters, $segment): Segment {
            if ($segment === null) {
                $segment = Segment::create(['name' => trim((string) $validated['name']), 'filters' => $filters]);
                Audit::record('crm.segment_created', $segment, ['segment_id' => $segment->id], $company, $user);

                return $segment;
            }

            $segment = Segment::query()->findOrFail($segment->id);
            $segment->fill(['name' => trim((string) $validated['name']), 'filters' => $filters])->save();
            Audit::record('crm.segment_updated', $segment, ['segment_id' => $segment->id], $company, $user);

            return $segment;
        }));
    }

    public function delete(User $user, Company $company, Segment $segment): void
    {
        Gate::forUser($user)->authorize('settings', [Segment::class, $company]);

        CurrentCompany::run($company, function () use ($user, $company, $segment): void {
            Segment::query()->findOrFail($segment->id)->delete();
            Audit::record('crm.segment_deleted', null, ['segment_id' => $segment->id], $company, $user);
        });
    }
}
