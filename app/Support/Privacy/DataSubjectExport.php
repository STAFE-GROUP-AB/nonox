<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Domain\IncomeTax\IncomeTaxForms;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\IncomeTaxPartner;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Modules\Crm\Support\CrmExport;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\SalaryChange;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Registerutdrag (GDPR art. 15 and 20): everything NONOX holds about one
 * person, as a machine-readable array. For a user it covers the account
 * across companies; for a customer, supplier or employee, what one company
 * holds (the company is the controller and answers the request).
 */
final class DataSubjectExport
{
    /** @return array<string, mixed> */
    public static function user(User $user): array
    {
        $companies = $user->companies();

        return [
            'utdrag' => self::meta('Användarkonto i NONOX'),
            'konto' => [
                'namn' => $user->name,
                'e_post' => $user->email,
                'skapat' => $user->created_at?->toIso8601String(),
                'aktuellt_foretag' => $user->current_team_id,
            ],
            'foretag' => $companies->map(fn (Company $company): array => [
                'namn' => $company->name,
                'organisationsnummer' => $company->org_number,
                'roll' => $company->user_id === $user->id ? 'owner' : DB::table('team_user')->where('team_id', $company->id)->where('user_id', $user->id)->value('role'),
            ])->values()->all(),
            'anstalld_i' => CompanyScope::bypass(fn (): array => Employee::query()->where('user_id', $user->id)->get()
                ->map(fn (Employee $employee): array => CurrentCompany::run($employee->company_id, fn (): array => self::employee($employee)))
                ->all()),
            'behandlingshistorik' => AuditLog::query()->where('user_id', $user->id)->latest('id')->limit(5000)->get(['action', 'subject_type', 'created_at', 'ip_address', 'user_agent'])
                ->map(fn (AuditLog $entry): array => [
                    'tid' => $entry->created_at->toIso8601String(),
                    'atgard' => $entry->action,
                    'avser' => class_basename((string) $entry->subject_type),
                    'ip_adress' => $entry->getAttribute('ip_address'),
                    'webblasare' => $entry->getAttribute('user_agent'),
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function customer(Customer $customer): array
    {
        return [
            'utdrag' => self::meta('Kund'),
            'kund' => [...$customer->only(['number', 'name', 'org_number', 'vat_number', 'email', 'phone', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'peppol_id', 'invoice_delivery']), 'personal_number' => $customer->personal_number],
            'fakturor' => Invoice::query()->where('customer_id', $customer->id)->orderBy('invoice_date')->get()
                ->map(fn (Invoice $invoice): array => ['nummer' => $invoice->number, 'datum' => $invoice->invoice_date->toDateString(), 'belopp_ore' => $invoice->total_ore->ore, 'status' => $invoice->status->value])->all(),
            // CRM (ADR-043): the customer's account and activities, when it has one.
            'crm' => CrmExport::forCustomer($customer),
        ];
    }

    /** @return array<string, mixed> */
    public static function supplier(Supplier $supplier): array
    {
        return [
            'utdrag' => self::meta('Leverantör'),
            'leverantor' => $supplier->only(['number', 'name', 'org_number', 'vat_number', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'country', 'bankgiro', 'plusgiro', 'iban', 'bic']),
            'leverantorsfakturor' => SupplierInvoice::query()->where('supplier_id', $supplier->id)->orderBy('invoice_date')->get()
                ->map(fn (SupplierInvoice $invoice): array => ['nummer' => $invoice->invoice_number, 'datum' => $invoice->invoice_date->toDateString(), 'belopp_ore' => $invoice->total_ore->ore, 'status' => $invoice->status->value])->all(),
        ];
    }

    /**
     * A partner in a handelsbolag or kommanditbolag (INK4DU): every fiscal
     * year of the company where the same personnummer or organisationsnummer
     * is a partner. The identity is encrypted, so the match is made in PHP
     * over the company's own partner rows.
     *
     * @return array<string, mixed>
     */
    public static function incomeTaxPartner(IncomeTaxPartner $partner): array
    {
        $rows = IncomeTaxPartner::query()->where('company_id', $partner->company_id)->orderBy('fiscal_year_id')->orderBy('position')->get()
            ->filter(fn (IncomeTaxPartner $row): bool => $row->identity === $partner->identity);
        $years = FiscalYear::query()->whereIn('id', $rows->pluck('fiscal_year_id'))->get()->keyBy('id');

        return [
            'utdrag' => self::meta('Delägare i handelsbolag eller kommanditbolag'),
            'delagare' => $rows->map(fn (IncomeTaxPartner $row): array => [
                'rakenskapsar' => $years->get($row->fiscal_year_id)?->label(),
                'nummer' => $row->position,
                'namn' => $row->name,
                'personnummer_eller_organisationsnummer' => $row->identity,
                'juridisk_person' => $row->legal_person,
                'andel_procent' => IncomeTaxForms::percent($row->share_bp),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function employee(Employee $employee): array
    {
        $periods = PayrollRun::query()->pluck('period', 'id');

        return [
            'utdrag' => self::meta('Anställd'),
            'anstalld' => [
                ...$employee->only(['employee_number', 'first_name', 'last_name', 'email', 'phone', 'address', 'postal_code', 'city', 'country', 'tax_residence', 'tax_table', 'tax_column', 'church_member', 'status']),
                'personnummer' => $employee->personal_number,
                ...$employee->only(['birth_date', 'birth_place', 'birth_country', 'citizenship', 'foreign_tin_country']),
                'utlandskt_skatteregistreringsnummer' => $employee->foreign_tin,
                'clearingnummer' => $employee->bank_clearing,
                'kontonummer' => $employee->bank_account,
                'iban' => $employee->getAttribute('iban'),
                'anhorig' => $employee->getAttribute('emergency_contact'),
            ],
            'anstallningar' => Employment::query()->where('employee_id', $employee->id)->orderBy('start_date')->get()
                ->map(fn (Employment $employment): array => [
                    'fran' => $employment->start_date->toDateString(),
                    'till' => $employment->end_date?->toDateString(),
                    'form' => $employment->employment_type,
                    'befattning' => $employment->position_title,
                ])->all(),
            'loneandringar' => SalaryChange::query()->where('employee_id', $employee->id)->orderBy('valid_from')->get()
                ->map(fn (SalaryChange $change): array => ['fran' => $change->valid_from->toDateString(), 'manadslon_ore' => $change->monthly_salary_ore?->ore, 'timlon_ore' => $change->hourly_rate_ore?->ore, 'skal' => $change->reason])->all(),
            'lonebesked' => Payslip::query()->where('employee_id', $employee->id)->orderBy('id')->get()
                ->map(fn (Payslip $payslip): array => ['period' => $periods[$payslip->payroll_run_id] ?? null, 'brutto_ore' => $payslip->gross_ore->ore, 'skatt_ore' => $payslip->tax_ore->ore, 'netto_ore' => $payslip->net_ore->ore])->all(),
            'franvaro' => Absence::query()->where('employee_id', $employee->id)->orderBy('date_from')->get()
                ->map(fn (Absence $absence): array => ['typ' => $absence->type, 'fran' => $absence->date_from->toDateString(), 'till' => $absence->date_to->toDateString(), 'status' => $absence->status, 'anteckning' => $absence->note])->all(),
            'utlagg' => Expense::query()->where('employee_id', $employee->id)->orderBy('date')->get()
                ->map(fn (Expense $expense): array => ['datum' => $expense->date->toDateString(), 'beskrivning' => $expense->description, 'belopp_ore' => $expense->amount_ore->ore, 'status' => $expense->status])->all(),
        ];
    }

    /**
     * A person in the staff ledger: the register entry and every entry in
     * the ledger about them, corrections included. The kiosk PIN, the QR key
     * and the control key are never part of it, and the photo is described
     * (stored or not, consent given when) rather than included.
     *
     * @return array<string, mixed>
     */
    public static function staffLedgerPerson(Person $person): array
    {
        $locations = Location::query()->pluck('name', 'id');
        $entries = Entry::query()->where('person_id', $person->id)->orderBy('occurred_at')->orderBy('id')->get();
        $uuids = $entries->pluck('uuid', 'id');

        return [
            'utdrag' => self::meta('Person i personalliggaren'),
            'person' => [
                'fornamn' => $person->first_name,
                'efternamn' => $person->last_name,
                'personnummer' => $person->personal_number,
                'identitetsnummer_utfardat_i' => $person->identity_country,
                'e_post' => $person->email,
                'sprak' => $person->language,
                'arbetsgivare' => $person->employer_name,
                'arbetsgivarens_organisationsnummer' => $person->employer_org_number,
                'arbetsgivarens_land' => $person->employer_country,
                'personalansvarig' => $person->is_manager,
                'arkiverad' => $person->archived_at?->toIso8601String(),
                'har_pinkod' => $person->hasPin(),
            ],
            'foto' => [
                'sparat' => $person->photo_path !== null,
                'samtycke_lamnat' => $person->photo_consent_at?->toIso8601String(),
                'kommentar' => 'Fotot visas på kiosken bara med personens samtycke. Samtycket kan återkallas när som helst, och då raderas fotot.',
            ],
            'registreringar' => $entries->map(fn (Entry $entry): array => [
                'id' => $entry->uuid,
                'typ' => $entry->type->value,
                'riktning' => $entry->effectiveDirection()?->value,
                'rattelse' => $entry->correction?->value,
                'rattar_registrering' => $entry->corrects_entry_id === null ? null : ($uuids[$entry->corrects_entry_id] ?? null),
                'metod' => $entry->method->value,
                'tidpunkt' => $entry->occurred_at->toIso8601String(),
                'mottagen' => $entry->received_at->toIso8601String(),
                'lokal' => $locations[$entry->location_id] ?? null,
                'namn' => $entry->person_name,
                'personnummer' => $entry->personal_number,
                'identitetsnummer_utfardat_i' => $entry->identity_country,
                'arbetsgivare' => $entry->employer_name,
                'arbetsgivarens_organisationsnummer' => $entry->employer_org_number,
                'arbetsgivarens_land' => $entry->employer_country,
                'avstand_meter' => $entry->geo_distance_meters,
                'flaggor' => $entry->flags,
                'skal' => $entry->reason,
            ])->values()->all(),
        ];
    }

    /**
     * Messages the current company's inbox received from one address: the
     * metadata of each message and its attachments. The text and the files
     * themselves are not part of the extract.
     *
     * @return array<string, mixed>
     */
    public static function inboxSender(string $email): array
    {
        return [
            'utdrag' => self::meta('Avsändare till inkorgen'),
            'avsandare' => mb_strtolower(trim($email)),
            'meddelanden' => self::inboxMessages($email),
        ];
    }

    /**
     * One row per message, for both the JSON and the CSV extract.
     *
     * @return list<array{mottaget: string, avsandare: string, avsandarnamn: ?string, amne: ?string, status: string, dokumenttyp: ?string, bilagor: list<array{filnamn: string, typ: string, storlek_byte: int, blockerad: bool}>}>
     */
    public static function inboxMessages(string $email): array
    {
        return array_values(InboxMessage::query()
            ->whereRaw('lower(from_email) = ?', [mb_strtolower(trim($email))])
            ->with('attachments')
            ->orderBy('received_at')
            ->get()
            ->map(fn (InboxMessage $message): array => [
                'mottaget' => $message->received_at->toIso8601String(),
                'avsandare' => $message->from_email,
                'avsandarnamn' => $message->from_name,
                'amne' => $message->subject,
                'status' => $message->status->value,
                'dokumenttyp' => $message->document_type?->value,
                'bilagor' => array_values($message->attachments->map(fn (InboxAttachment $attachment): array => [
                    'filnamn' => $attachment->filename,
                    'typ' => $attachment->mime,
                    'storlek_byte' => $attachment->size,
                    'blockerad' => $attachment->isBlocked(),
                ])->all()),
            ])
            ->values()
            ->all());
    }

    /** @return array{typ: string, skapat: string, rattslig_grund: string} */
    private static function meta(string $type): array
    {
        return [
            'typ' => $type,
            'skapat' => now()->toIso8601String(),
            'rattslig_grund' => 'Registerutdrag enligt artikel 15 och dataportabilitet enligt artikel 20 i dataskyddsförordningen (GDPR).',
        ];
    }
}
