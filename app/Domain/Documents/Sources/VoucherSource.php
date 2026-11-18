<?php

declare(strict_types=1);

namespace App\Domain\Documents\Sources;

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Database\Query\Builder;

/**
 * Underlag on vouchers (voucher_attachments). The same check as
 * VoucherPolicy::view for an unlimited member: vouchers:view. A member
 * limited to cost centres or projects is refused the whole source: the
 * policy decides per voucher, and a list never guesses (gap 10).
 */
final class VoucherSource extends BaseSource
{
    public function key(): string
    {
        return 'voucher';
    }

    public function label(): string
    {
        return __('Underlag på verifikationer');
    }

    public function allows(User $user, Company $company): bool
    {
        return ! DimensionScope::limits($user, $company) && $user->hasTeamPermission($company, 'vouchers:view');
    }

    public function query(User $user, Company $company, string $search): Builder
    {
        $query = $this->select(
            'voucher_attachments as a join vouchers as v on v.id = a.voucher_id and v.company_id = a.company_id left join voucher_series as s on s.id = v.series_id and s.company_id = v.company_id',
            [
                'source_id' => 'a.id',
                'parent_id' => 'a.voucher_id',
                'title' => 'a.original_name',
                'doc_date' => 'a.created_at',
                'mime' => 'a.mime',
                'size' => 'a.size',
                'ref' => "case when v.number is null then '' else coalesce(s.code, '') || v.number::text end",
            ],
        )->where('a.company_id', $company->id);

        return $this->search($query, 'a.original_name', $search);
    }

    public function url(DocumentRef $row): string
    {
        return route('accounting.vouchers.attachment', ['voucher' => $row->parentId, 'attachment' => $row->sourceId]);
    }

    public function subtitle(DocumentRef $row): string
    {
        return $row->ref === '' ? __('Verifikationsutkast') : __('Verifikation :number', ['number' => $row->ref]);
    }
}
