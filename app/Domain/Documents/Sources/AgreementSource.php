<?php

declare(strict_types=1);

namespace App\Domain\Documents\Sources;

use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\Actions\AgreementAi;
use Illuminate\Database\Query\Builder;

/**
 * Documents NONOX AI reads for the agreement setup (NONOX Avtal,
 * agreement_documents). The same check as the page that lists them
 * (AgreementAi::availableFor). The file has no address of its own, so a row
 * opens that page. A document that belongs to one employee (an employment
 * contract, a payslip) is personnel data and is listed only with
 * payroll:view-sensitive, which the page itself does not ask for. The file
 * name is stored encrypted and is therefore not searchable.
 */
final class AgreementSource extends BaseSource
{
    public function key(): string
    {
        return 'agreement';
    }

    public function label(): string
    {
        return __('Kollektivavtal (NONOX Avtal)');
    }

    public function allows(User $user, Company $company): bool
    {
        return AgreementAi::availableFor($user, $company);
    }

    public function query(User $user, Company $company, string $search): Builder
    {
        $query = $this->select('agreement_documents as a', [
            'source_id' => 'a.id',
            'title' => "''",
            'title_encrypted' => 'a.original_name',
            'doc_date' => 'a.created_at',
            'mime' => 'a.mime',
            'size' => 'a.size',
            'ref' => 'a.document_type',
        ])->where('a.company_id', $company->id);

        if (! $user->hasTeamPermission($company, 'payroll:view-sensitive')) {
            $query->whereNull('a.employee_id');
        }

        if ($search !== '') {
            $query->whereRaw('false');
        }

        return $query;
    }

    public function url(DocumentRef $row): string
    {
        return route('agreements.ai');
    }

    public function subtitle(DocumentRef $row): string
    {
        return __('Dokument för NONOX Avtal');
    }
}
