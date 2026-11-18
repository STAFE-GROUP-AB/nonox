<?php

declare(strict_types=1);

namespace App\Domain\Documents\Sources;

use App\Models\Company;
use App\Models\User;
use App\Policies\SupplierInvoicePolicy;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Database\Query\Builder;

/**
 * The files of supplier invoices. The same check as
 * SupplierInvoicePolicy::view for an unlimited member
 * (SupplierInvoicePolicy::canSeePayables); a limited member is refused the
 * whole source, as for vouchers.
 */
final class SupplierInvoiceSource extends BaseSource
{
    public function key(): string
    {
        return 'supplier-invoice';
    }

    public function label(): string
    {
        return __('Leverantörsfakturor');
    }

    public function allows(User $user, Company $company): bool
    {
        return ! DimensionScope::limits($user, $company) && SupplierInvoicePolicy::canSeePayables($user, $company);
    }

    public function query(User $user, Company $company, string $search): Builder
    {
        $query = $this->select('supplier_invoices as i', [
            'source_id' => 'i.id',
            'title' => "coalesce(i.file_name, '')",
            'doc_date' => 'coalesce(i.invoice_date::timestamp, i.created_at)',
            'mime' => 'i.file_mime',
            'size' => 'i.file_size',
            'ref' => "coalesce(i.number::text, '')",
        ])->where('i.company_id', $company->id)->whereNotNull('i.file_path');

        return $this->search($query, 'i.file_name', $search);
    }

    public function url(DocumentRef $row): string
    {
        return route('payables.invoices.file', ['supplierInvoice' => $row->sourceId]);
    }

    public function subtitle(DocumentRef $row): string
    {
        return $row->ref === '' ? __('Leverantörsfaktura') : __('Leverantörsfaktura :number', ['number' => $row->ref]);
    }
}
