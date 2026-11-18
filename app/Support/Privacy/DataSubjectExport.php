<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Domain\IncomeTax\IncomeTaxForms;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\IncomeTaxPartner;
use App\Models\Invoice;
use App\Models\K10Owner;
use App\Models\MileageRoute;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\VehicleTrip;
use App\Modules\Crm\Support\CrmExport;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderVersion;
use App\Modules\Inventory\Models\Shipment;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\Manufacturing\Models\WorkshopTimeEntry;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\SalaryChange;
use App\Modules\Planning\Models\Message as ScheduleMessage;
use App\Modules\Planning\Models\MessageRecipient as ScheduleMessageRecipient;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftTask;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Stripe\Models\StripeCustomerLink;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\DimensionScope;
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
                // The cost centres and projects the user is limited to (ADR-081 gap 10); null when not limited.
                'begransad_till' => self::dimensionScope($user, $company),
            ])->values()->all(),
            'anstalld_i' => CompanyScope::bypass(fn (): array => Employee::query()->where('user_id', $user->id)->get()
                ->map(fn (Employee $employee): array => CurrentCompany::run($employee->company_id, fn (): array => self::employee($employee)))
                ->all()),
            // Schedule messages the user wrote as a planner (ADR-037 gap 5), in every company: the
            // author's own text. Across companies because the extract covers the account.
            'skrivna_schemameddelanden' => CompanyScope::bypass(fn (): array => ScheduleMessage::query()->where('author_id', $user->id)->orderBy('id')->get()
                ->map(fn (ScheduleMessage $message): array => ['foretag' => $message->company_id, 'skickat' => $message->created_at?->toIso8601String(), 'till' => $message->scope, 'fran' => $message->date_from->toDateString(), 'till_och_med' => $message->date_to->toDateString(), 'meddelande' => $message->body, 'bekraftelse_begard' => $message->requires_ack])
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
                ->map(fn (Invoice $invoice): array => ['nummer' => $invoice->number, 'nummer_i_tidigare_program' => $invoice->external_ref, 'datum' => $invoice->invoice_date->toDateString(), 'belopp_ore' => $invoice->total_ore->ore, 'status' => $invoice->status->value])->all(),
            // Lager (ADR-081 E14): shipments to the customer with their packages, batches and serial numbers.
            'forsandelser' => Shipment::query()->where('customer_id', $customer->id)->orderBy('number')
                ->with(['packages.lines.article:id,number,name', 'packages.lines.lot:id,code,kind'])->get()
                ->map(fn (Shipment $shipment): array => [
                    'nummer' => $shipment->number,
                    'status' => $shipment->status,
                    'skickad' => $shipment->shipped_at?->toDateString(),
                    'transportor' => $shipment->carrier,
                    'sparningsnummer' => $shipment->tracking_number,
                    'mottagare' => $shipment->ship_to,
                    'kollin' => $shipment->packages->map(fn ($package): array => [
                        'kolli' => $package->position,
                        'vikt_gram' => $package->weight_grams,
                        'innehall' => $package->lines->map(fn ($line): array => [
                            'artikel' => $line->article?->number,
                            'antal_tusendelar' => $line->quantity_milli,
                            'batch_eller_serienummer' => $line->lot?->code,
                        ])->all(),
                    ])->all(),
                ])->all(),
            // CRM (ADR-043): the customer's account and activities, when it has one.
            'crm' => CrmExport::forCustomer($customer),
            // Stripe in NONOX Connect (ADR-080 step 2): the Stripe customers linked to the customer.
            'stripe' => StripeCustomerLink::query()->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(fn (StripeCustomerLink $link): array => self::stripeCustomer($link))->all(),
        ];
    }

    /**
     * One Stripe customer as NONOX holds it: for a linked customer's extract,
     * and on its own for a Stripe customer that is not linked.
     *
     * @return array<string, mixed>
     */
    public static function stripeCustomer(StripeCustomerLink $link): array
    {
        return [
            'stripe_kund_id' => $link->stripe_customer_id,
            'namn' => $link->name,
            'e_post' => $link->email,
            'land' => $link->country,
            'adress' => $link->address,
            'skatte_id' => $link->tax_ids,
            'kopplad_genom' => $link->method,
            'granskad' => $link->confirmed_at?->toIso8601String(),
            'raderad' => $link->erased_at?->toIso8601String(),
            // What Återkommande intäkter recorded for the Stripe customer's subscriptions: ids, amounts and dates (re-check P3c).
            'prenumerationer' => SaasContractVersion::query()
                ->where('source_type', SourceType::StripeSubscription->value)
                ->whereRaw("source_ref->>'connection' = ?", [(string) $link->connection_id])
                ->whereRaw("source_ref->>'customer' = ?", [$link->stripe_customer_id])
                ->orderBy('source_id')
                ->orderBy('id')
                ->get()
                ->map(fn (SaasContractVersion $version): array => [
                    'prenumeration' => $version->source_id,
                    'galler_fran' => $version->effective_from->toDateString(),
                    'registrerad' => $version->recorded_at->toIso8601String(),
                    'orsak' => $version->cause->value,
                    'status' => $version->status->value,
                    'startar' => $version->starts_on?->toDateString(),
                    'upphor' => $version->cancel_effective_on?->toDateString(),
                    'raknas_inte' => $version->excluded?->value,
                    'rader' => array_map(fn (array $line): array => [
                        'valuta' => $line['currency'] ?? null,
                        'belopp_hundradelar' => $line['amount_minor'] ?? null,
                        'intervall' => $line['interval_unit'] ?? null,
                        'antal_intervall' => $line['interval_count'] ?? null,
                        'forbrukning' => $line['usage'] ?? false,
                        'rabatt_till' => $line['discount_until'] ?? null,
                        'provperiod_till' => $line['trial_until'] ?? null,
                    ], (array) $version->lines),
                ])->all(),
        ];
    }

    /** @return array<string, mixed> a Stripe customer that is not linked to a customer (ADR-080 step 2) */
    public static function unlinkedStripeCustomer(StripeCustomerLink $link): array
    {
        return ['utdrag' => self::meta('Stripe-kund'), 'stripe_kund' => self::stripeCustomer($link)];
    }

    /** @return array<string, mixed> */
    public static function supplier(Supplier $supplier): array
    {
        return [
            'utdrag' => self::meta('Leverantör'),
            'leverantor' => $supplier->only(['number', 'name', 'org_number', 'vat_number', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'country', 'bankgiro', 'plusgiro', 'iban', 'bic']),
            'leverantorsfakturor' => SupplierInvoice::query()->where('supplier_id', $supplier->id)->orderBy('invoice_date')->get()
                ->map(fn (SupplierInvoice $invoice): array => ['nummer' => $invoice->invoice_number, 'datum' => $invoice->invoice_date->toDateString(), 'belopp_ore' => $invoice->total_ore->ore, 'status' => $invoice->status->value])->all(),
            // Inköpsorder (ADR-071 §2): the orders and to which address each version went.
            'inkopsorder' => PurchaseOrder::query()->where('supplier_id', $supplier->id)->with('versions')->orderBy('number')->get()
                ->map(fn (PurchaseOrder $order): array => [
                    'nummer' => $order->number,
                    'datum' => $order->order_date->toDateString(),
                    'status' => $order->status->value,
                    'leveransadress' => $order->delivery_address,
                    'var_referens' => $order->our_reference,
                    'skickade_versioner' => $order->versions->map(fn (PurchaseOrderVersion $version): array => ['version' => $version->version, 'skickad' => $version->sent_at->toDateString(), 'satt' => $version->method, 'till' => $version->sent_to])->values()->all(),
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function k10Owner(K10Owner $owner): array
    {
        $rows = K10Owner::query()->where('company_id', $owner->company_id)->orderBy('income_year')->orderBy('id')->get()
            ->filter(fn (K10Owner $row): bool => $row->id === $owner->id || (filled($owner->identity) && $row->identity === $owner->identity));

        return [
            'utdrag' => self::meta('Ägare med K10-underlag'),
            'k10_agare' => $rows->map(fn (K10Owner $row): array => [
                'inkomstar' => $row->income_year,
                'namn' => $row->name,
                'personnummer' => $row->identity,
                'kopplad_anstalld_id' => $row->employee_id,
                'make_eller_maka_agare_id' => $row->spouse_owner_id,
                'antal_aktier_vid_arets_ingang' => $row->shares_owned,
                'kopte_aktierna' => $row->acquired_on?->toDateString(),
                'agarandelar_i_andra_famansforetag_procent' => $row->other_holdings_bp === null ? null : $row->other_holdings_bp / 100,
                'omkostnadsbelopp_kronor' => $row->cost_basis_kronor,
                'sparat_utdelningsutrymme_kronor' => $row->saved_space_kronor,
                'egen_kontant_ersattning_kronor' => $row->own_pay_kronor,
                'narstaendes_kontanta_ersattning_kronor' => $row->related_pay_kronor,
                'loner_i_foretaget_kronor' => $row->company_pay_kronor,
                'loner_i_dotterforetag_kronor' => $row->subsidiary_pay_kronor,
                'utdelning_kronor' => $row->dividend_kronor,
                'har_overlatit_aktier' => $row->transferred_shares,
            ])->values()->all(),
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
                'tillskott_angivet_kronor' => $row->contributions_kronor,
                'uttag_angivet_kronor' => $row->withdrawals_kronor,
                'andel_avdrag_arbetsgivaravgift_kronor' => $row->employer_deduction_kronor,
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
            'sparade_vagar' => $employee->user_id === null ? [] : MileageRoute::query()->where('user_id', $employee->user_id)->orderBy('id')->get()
                ->map(fn (MileageRoute $route): array => ['vag' => $route->label, 'stracka_hm' => $route->distance_hm])->all(),
            // Tasks on shifts the person ticked off in Planering (ADR-037 gap 4): when, which task and which shift.
            'avbockade_uppgifter' => ShiftTask::query()->where('company_id', $employee->company_id)
                ->where(fn ($query) => $query->whereIn('shift_id', Shift::query()->select('id')->where('company_id', $employee->company_id)->where('employee_id', $employee->id))
                    ->orWhere('done_by', $employee->user_id ?? 0))
                ->whereNotNull('done_at')->orderBy('done_at')->get()
                ->map(fn (ShiftTask $task): array => ['tid' => $task->done_at?->toIso8601String(), 'uppgift' => $task->title, 'pass' => $task->shift_id, 'avbockad_av_kontot' => $employee->user_id !== null && $task->done_by === $employee->user_id])->all(),
            // Schedule messages the person received (ADR-037 gap 5): the text and their own receipts, never the other recipients.
            'schemameddelanden' => ScheduleMessageRecipient::query()->with('message')->where('company_id', $employee->company_id)->where('employee_id', $employee->id)->orderBy('id')->get()
                ->filter(fn (ScheduleMessageRecipient $receipt): bool => $receipt->message !== null)
                ->map(fn (ScheduleMessageRecipient $receipt): array => ['skickat' => $receipt->message?->created_at?->toIso8601String(), 'fran' => $receipt->message?->date_from->toDateString(), 'till_och_med' => $receipt->message?->date_to->toDateString(), 'meddelande' => $receipt->message?->body, 'aviserad' => $receipt->notified_at?->toIso8601String(), 'last' => $receipt->read_at?->toIso8601String(), 'bekraftad' => $receipt->acknowledged_at?->toIso8601String()])->values()->all(),
            // The körjournal (ADR-072 §9): every trip the person logged, with what it holds.
            'korjournal' => VehicleTrip::query()->where('employee_id', $employee->id)->orderBy('trip_date')->get()
                ->map(fn (VehicleTrip $trip): array => ['datum' => $trip->trip_date->toDateString(), 'typ' => $trip->kind->value, 'stracka_hm' => $trip->distance_hm, 'fran' => $trip->from_place, 'till' => $trip->to_place, 'arende' => $trip->purpose, 'matare_start_km' => $trip->odometer_start_km, 'matare_slut_km' => $trip->odometer_end_km, 'status' => $trip->status->value])->all(),
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
     * A person on the workshop terminal (ADR-071 §7): the register entry and
     * every time they reported, corrections included. The code is never part
     * of it, and the photo is described (stored or not, consent given when)
     * rather than included. Time older than the retention period has no
     * person any more and is not here.
     *
     * @return array<string, mixed>
     */
    public static function workshopPerson(WorkshopPerson $person): array
    {
        $devices = WorkshopDevice::query()->pluck('name', 'id');
        $entries = WorkshopTimeEntry::query()->where('person_id', $person->id)->with(['order', 'orderOperation.operation'])->orderBy('entered_at')->orderBy('id')->get();

        return [
            'utdrag' => self::meta('Person på verkstadsterminalen'),
            'person' => [
                'namn' => $person->name,
                'arkiverad' => $person->archived_at?->toIso8601String(),
                'har_kod' => $person->hasCode(),
            ],
            'foto' => [
                'sparat' => $person->photo_path !== null,
                'samtycke_lamnat' => $person->photo_consent_at?->toIso8601String(),
                'samtyckestext_version' => $person->photo_consent_version,
                'kommentar' => 'Fotot visas på verkstadsterminalen bara med personens samtycke. Samtycket kan återkallas när som helst, och då raderas fotot.',
            ],
            'rapporterad_tid' => $entries->map(fn (WorkshopTimeEntry $entry): array => [
                'id' => $entry->id,
                'order' => $entry->order?->reference(),
                'arbetssteg' => $entry->orderOperation->operation->name ?? null,
                'dag' => $entry->worked_on->toDateString(),
                'minuter' => $entry->minutes,
                'rattar_rapport' => $entry->corrects_entry_id,
                'surfplatta' => $devices[$entry->device_id] ?? null,
                'inmatad' => $entry->entered_at->toIso8601String(),
            ])->values()->all(),
            'kommentar' => 'Tiden är produktionstid på en tillverkningsorder, inte närvaro och inte lönetid. Namnet tas bort ur rapporterna 24 månader efter inmatningen; minuterna finns kvar som kostnad på ordern.',
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

    /** @return ?array{kostnadsstallen: list<string>, projekt: list<string>} */
    private static function dimensionScope(User $user, Company $company): ?array
    {
        $scope = DimensionScope::for($user, $company);

        if (! $scope->restricted) {
            return null;
        }

        return CurrentCompany::run($company, fn (): array => [
            'kostnadsstallen' => array_values(CostCenter::query()->whereIn('id', $scope->costCenterIds)->orderBy('code')->get()->map(fn (CostCenter $object): string => $object->label())->all()),
            'projekt' => array_values(Project::query()->whereIn('id', $scope->projectIds)->orderBy('code')->get()->map(fn (Project $object): string => $object->label())->all()),
        ]);
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
