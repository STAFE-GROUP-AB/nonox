<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Group\IntercompanyKind;
use App\Domain\Group\IntercompanyLedgerState;
use App\Domain\Group\IntercompanyRowSelection;
use App\Domain\Group\IntercompanyTagSource;
use App\Models\Company;
use App\Models\IntercompanyRowTag;
use App\Models\User;
use App\Policies\IntercompanyPolicy;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Changes or removes intercompany row tags (ADR-060, addendum 2026-11-07,
 * 2.3). A tag is metadata, so it may change; each change is audited with
 * ids and enums only. A tag a person changes becomes MANUAL, so "Uppdatera
 * koncernmarkeringar" leaves it alone afterwards. The voucher row is never
 * touched.
 */
final class EditIntercompanyTags
{
    /** @throws AuthorizationException */
    public function change(User $user, Company $company, int $tagId, IntercompanyKind $kind): IntercompanyRowTag
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, fn (): IntercompanyRowTag => DB::transaction(function () use ($user, $company, $tagId, $kind): IntercompanyRowTag {
            // Inside the company: another company's tag id answers 404.
            $tag = IntercompanyRowTag::query()->lockForUpdate()->findOrFail($tagId);
            $before = ['kind' => $tag->kind->value, 'source' => $tag->source->value];

            $tag->fill(['kind' => $kind, 'source' => IntercompanyTagSource::Manual, 'tagged_by' => $user->id]);

            if (! $tag->isDirty(['kind', 'source'])) {
                return $tag;
            }

            $tag->save();

            Audit::record('intercompany_tag.changed', $tag, [
                'voucher_row_id' => $tag->voucher_row_id,
                ...$this->state($company, $tag),
                ...Audit::changes($before, ['kind' => $tag->kind->value, 'source' => $tag->source->value]),
            ], $company, $user);

            return $tag;
        }));
    }

    /** @throws AuthorizationException */
    public function remove(User $user, Company $company, int $tagId): void
    {
        $this->authorize($user, $company);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $tagId): void {
            $tag = IntercompanyRowTag::query()->lockForUpdate()->findOrFail($tagId);

            Audit::record('intercompany_tag.removed', $tag, [
                'voucher_row_id' => $tag->voucher_row_id,
                ...$this->state($company, $tag),
                'kind' => $tag->kind->value,
                'source' => $tag->source->value,
            ], $company, $user);

            $tag->delete();
        }));
    }

    /**
     * Removes the tags on the selected rows: account, from, to.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     */
    public function removeSelected(User $user, Company $company, array $input): int
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($user, $company, $input): int {
            IntercompanyLedgerState::lock($company);
            $selection = IntercompanyRowSelection::fromInput($input);
            $flags = IntercompanyLedgerState::counts($company, $selection->rows()->whereExists(
                fn ($query) => $query->selectRaw('1')->from('intercompany_row_tags')->whereColumn('intercompany_row_tags.voucher_row_id', 'voucher_rows.id'),
            ));
            $removed = IntercompanyRowTag::query()
                ->whereIn('voucher_row_id', $selection->rows()->select('voucher_rows.id'))
                ->delete();

            if ($removed > 0) {
                Audit::record('intercompany_tags.removed', $selection->account, [
                    'removed' => $removed,
                    'account' => $selection->account->number,
                    'from' => $selection->from->toDateString(),
                    'to' => $selection->to->toDateString(),
                    ...$flags,
                ], $company, $user);
            }

            return $removed;
        }));
    }

    /**
     * Whether the tagged row is in a closed year or a locked period.
     *
     * @return array{in_closed_year: bool, in_locked_period: bool}
     */
    private function state(Company $company, IntercompanyRowTag $tag): array
    {
        $row = DB::table('voucher_rows')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('fiscal_years', 'fiscal_years.id', '=', 'vouchers.fiscal_year_id')
            ->where('voucher_rows.id', $tag->voucher_row_id)
            ->where('voucher_rows.company_id', $company->id)
            ->first(['vouchers.voucher_date', 'fiscal_years.status']);

        return [
            'in_closed_year' => $row !== null && IntercompanyLedgerState::closed((string) $row->status),
            'in_locked_period' => $row !== null && IntercompanyLedgerState::locked($company, substr((string) $row->voucher_date, 0, 10)),
        ];
    }

    /** @throws AuthorizationException */
    private function authorize(User $user, Company $company): void
    {
        if (! IntercompanyPolicy::mayWrite($user, $company)) {
            throw new AuthorizationException;
        }
    }
}
