<?php

declare(strict_types=1);

namespace App\Domain\Documents\Sources;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * Staff documents: payslips and payslips imported from an earlier system.
 * The check of PayslipPolicy::view and ImportedPayslipController for payroll
 * staff (payroll:view or payroll:archive-view), plus payroll:view-sensitive
 * on top, because a payslip is personnel data (owner decision 21). An
 * employee's own payslip (self:payslips) is not listed here; it is reached
 * through the employee's own page. The list fetches no names from the staff
 * register, only the employee number.
 */
final class StaffSource extends BaseSource
{
    public function key(): string
    {
        return 'staff';
    }

    public function label(): string
    {
        return __('Lönebesked');
    }

    public function allows(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'payroll:view-sensitive')
            && ($user->hasTeamPermission($company, 'payroll:view') || $user->hasTeamPermission($company, 'payroll:archive-view'));
    }

    public function query(User $user, Company $company, string $search): Builder
    {
        $payslips = $this->select(
            'payslips as p join payroll_runs as r on r.id = p.payroll_run_id and r.company_id = p.company_id join employees as e on e.id = p.employee_id and e.company_id = p.company_id',
            [
                'source_id' => 'p.id',
                'parent_id' => "'payslip'",
                'title' => "'lonebesked-' || r.period::text || '.pdf'",
                'doc_date' => 'p.created_at',
                'mime' => "'application/pdf'",
                'ref' => 'e.employee_number::text',
            ],
        )->where('p.company_id', $company->id);

        $imported = $this->select(
            'imported_payslips as p join employees as e on e.id = p.employee_id and e.company_id = p.company_id',
            [
                'source_id' => 'p.id',
                'parent_id' => "'imported'",
                'title' => 'p.original_name',
                'doc_date' => 'p.created_at',
                'mime' => "'application/pdf'",
                'ref' => 'e.employee_number::text',
            ],
        )->where('p.company_id', $company->id);

        $this->search($payslips, "('lonebesked-' || r.period::text || '.pdf')", $search);
        $this->search($imported, 'p.original_name', $search);

        return $payslips->unionAll($imported);
    }

    public function url(DocumentRef $row): string
    {
        return $row->parentId === 'imported'
            ? route('payroll.imported-payslip', ['payslip' => $row->sourceId])
            : route('payroll.archive.payslip', ['payslip' => $row->sourceId]);
    }

    public function subtitle(DocumentRef $row): string
    {
        return $row->ref === '' || $row->ref === null ? __('Lönebesked') : __('Lönebesked, anställningsnummer :number', ['number' => $row->ref]);
    }
}
