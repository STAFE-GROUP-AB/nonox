<?php

declare(strict_types=1);

namespace App\Actions\IncomeTax;

use App\Domain\Company\LegalForm;
use App\Domain\IncomeTax\K10Books;
use App\Domain\IncomeTax\K10Codes;
use App\Domain\IncomeTax\K10Parameters;
use App\Domain\IncomeTax\K10Sheet;
use App\Models\Company;
use App\Models\K10Owner;
use App\Models\K10Year;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Support\Audit\Audit;
use App\Support\Luhn;
use App\Support\Money\Money;
use App\Support\Sru\SruFile;
use App\Support\Sru\SruZip;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * K10 underlag for the owners of an aktiebolag (ADR-062): what a person
 * saves for the year and per owner, the worksheet as a page or PDF, and the
 * SRU file of one owner's K10. Owner, administratör and redovisningskonsult
 * (fiscal-years:manage). Nothing is booked or filed; the audit log records
 * which fields were saved, never names, numbers or amounts.
 */
final class K10Worksheets
{
    private const int MAX_KRONOR = 100_000_000_000;

    private const int MAX_SHARES = 1_000_000_000_000;

    private const array OWNER_AMOUNTS = ['cost_basis_kronor', 'saved_space_kronor', 'own_pay_kronor', 'related_pay_kronor', 'company_pay_kronor', 'subsidiary_pay_kronor', 'dividend_kronor'];

    /** @param  array<string, mixed>  $input  shares_total, wage_subsidy, dividend_total, qualified_confirmed */
    public function saveYear(User $user, Company $company, int $incomeYear, array $input): K10Year
    {
        $this->authorize($user, $company, $incomeYear);

        $values = [
            'shares_total' => $this->shares($input['shares_total'] ?? '', 'year.shares_total'),
            'wage_subsidy_kronor' => $this->kronor($input['wage_subsidy'] ?? '', 'year.wage_subsidy'),
            'dividend_total_kronor' => $this->kronor($input['dividend_total'] ?? '', 'year.dividend_total'),
            'qualified_confirmed' => (bool) ($input['qualified_confirmed'] ?? false),
        ];

        return CurrentCompany::run($company, fn (): K10Year => DB::transaction(function () use ($user, $company, $incomeYear, $values): K10Year {
            $year = K10Year::query()->updateOrCreate(['income_year' => $incomeYear], [...$values, 'updated_by' => $user->id]);
            Audit::record('k10.year_saved', $year, ['income_year' => $incomeYear, 'fields' => array_keys(array_filter($values, fn (mixed $value): bool => $value !== null && $value !== false))], $company, $user);

            return $year;
        }));
    }

    /**
     * Saves one owner. A personnummer is kept as stored unless a new one is
     * typed; `remove_identity` deletes it. A spouse is linked both ways.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveOwner(User $user, Company $company, int $incomeYear, array $input, ?int $ownerId = null): K10Owner
    {
        $this->authorize($user, $company, $incomeYear);

        return CurrentCompany::run($company, function () use ($user, $company, $incomeYear, $input, $ownerId): K10Owner {
            $owner = $ownerId === null ? null : K10Owner::query()->where('income_year', $incomeYear)->findOrFail($ownerId);
            $name = trim((string) ($input['name'] ?? ''));

            if ($name === '' || mb_strlen($name) > 200) {
                throw ValidationException::withMessages(['owner.name' => __('Skriv ägarens namn.')]);
            }

            $shares = $this->shares($input['shares_owned'] ?? '', 'owner.shares_owned');

            if ($shares === null || $shares < 1) {
                throw ValidationException::withMessages(['owner.shares_owned' => __('Skriv hur många aktier ägaren hade vid årets ingång.')]);
            }

            $typed = trim((string) ($input['identity'] ?? ''));
            $identity = match (true) {
                (bool) ($input['remove_identity'] ?? false) => null,
                $typed !== '' => self::personnummer($typed),
                default => $owner?->identity,
            };

            $values = [
                'name' => $name,
                'identity' => $identity,
                'shares_owned' => $shares,
                'employee_id' => $this->employee($user, $company, $input['employee_id'] ?? null, $owner),
                'acquired_on' => $this->date($input['acquired_on'] ?? '', 'owner.acquired_on'),
                'other_holdings_bp' => $this->percent($input['other_holdings'] ?? '', 'owner.other_holdings'),
                'transferred_shares' => (bool) ($input['transferred_shares'] ?? false),
                'topped_up_previous_year' => (bool) ($input['topped_up_previous_year'] ?? false),
            ];

            foreach (self::OWNER_AMOUNTS as $field) {
                $key = substr($field, 0, -7);
                $values[$field] = $this->kronor($input[$key] ?? '', "owner.{$key}");
            }

            // A saved space carried over from last year's underlag stays marked as such until a person changes it.
            $values['saved_space_carried'] = $owner !== null && $owner->saved_space_carried && $values['saved_space_kronor'] === $owner->saved_space_kronor;

            $spouseId = (int) ($input['spouse_owner_id'] ?? 0);
            $spouse = $spouseId === 0 ? null : K10Owner::query()->where('income_year', $incomeYear)->find($spouseId);

            if ($spouseId !== 0 && ($spouse === null || $spouse->id === $owner?->id)) {
                throw ValidationException::withMessages(['owner.spouse_owner_id' => __('Välj en annan ägare i företaget som make eller maka.')]);
            }

            return DB::transaction(function () use ($user, $company, $incomeYear, $owner, $values, $spouse): K10Owner {
                $owner ??= new K10Owner(['income_year' => $incomeYear]);
                $previousSpouse = $owner->spouse_owner_id;
                $owner->fill([...$values, 'spouse_owner_id' => $spouse?->id, 'updated_by' => $user->id])->save();

                // A spouse is one person's: unlink the old pair, link the new one both ways.
                K10Owner::query()->where('income_year', $incomeYear)->where('spouse_owner_id', $owner->id)->whereKeyNot($spouse->id ?? 0)->update(['spouse_owner_id' => null]);

                if ($previousSpouse !== null && $previousSpouse !== $spouse?->id) {
                    K10Owner::query()->whereKey($previousSpouse)->update(['spouse_owner_id' => null]);
                }

                if ($spouse !== null) {
                    K10Owner::query()->where('income_year', $incomeYear)->where('spouse_owner_id', $spouse->id)->whereKeyNot($owner->id)->update(['spouse_owner_id' => null]);
                    $spouse->forceFill(['spouse_owner_id' => $owner->id])->save();
                }

                $filled = array_keys(array_filter($values, fn (mixed $value): bool => $value !== null && $value !== false && $value !== ''));
                Audit::record('k10.owner_saved', $owner, ['income_year' => $incomeYear, 'fields' => array_values(array_diff($filled, ['name']))], $company, $user);

                return $owner;
            });
        });
    }

    /** Erasure works whatever the legal form or the year's parameters (GDPR art. 17): only the permission is checked. */
    public function deleteOwner(User $user, Company $company, K10Owner $owner): void
    {
        $this->authorizePermission($user, $company);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $owner): void {
            K10Owner::query()->where('spouse_owner_id', $owner->id)->update(['spouse_owner_id' => null]);
            Audit::record('k10.owner_deleted', $owner, ['income_year' => $owner->income_year], $company, $user);
            $owner->delete();
        }));
    }

    /**
     * Last year's owners as a starting point: name, link, spouse and shares,
     * and last year's sparat utdelningsutrymme for next year as this year's
     * saved space when the calculation could be made. The personnummer is
     * copied too, encrypted as before.
     */
    public function copyFromPreviousYear(User $user, Company $company, int $incomeYear): int
    {
        $this->authorize($user, $company, $incomeYear);

        return CurrentCompany::run($company, function () use ($user, $company, $incomeYear): int {
            if (K10Owner::query()->where('income_year', $incomeYear)->exists()) {
                throw ValidationException::withMessages(['owners' => __('Det finns redan ägare för året.')]);
            }

            $previous = K10Owner::query()->where('income_year', $incomeYear - 1)->orderBy('id')->get();

            if ($previous->isEmpty()) {
                throw ValidationException::withMessages(['owners' => __('Det finns inga ägare för förra året att hämta.')]);
            }

            $sheets = $this->buildSheets($company, $incomeYear - 1);

            return DB::transaction(function () use ($user, $company, $incomeYear, $previous, $sheets): int {
                $map = [];
                $lastYear = K10Year::query()->where('income_year', $incomeYear - 1)->first();

                if ($lastYear !== null && K10Year::query()->where('income_year', $incomeYear)->doesntExist()) {
                    K10Year::query()->create(['income_year' => $incomeYear, 'shares_total' => $lastYear->shares_total, 'qualified_confirmed' => false, 'updated_by' => $user->id]);
                }

                foreach ($previous as $old) {
                    $saved = $sheets->get($old->id)?->k10()?->savedForNextYearKronor();
                    $map[$old->id] = K10Owner::query()->create([
                        'income_year' => $incomeYear,
                        'name' => $old->name,
                        'identity' => $old->identity,
                        'employee_id' => $old->employee_id,
                        'shares_owned' => $old->shares_owned,
                        'acquired_on' => $old->acquired_on,
                        'other_holdings_bp' => $old->other_holdings_bp,
                        // The omkostnadsbelopp is not copied: it is the value at the new year's start (12 §).
                        'saved_space_kronor' => $saved,
                        'saved_space_carried' => $saved !== null,
                        'updated_by' => $user->id,
                    ]);
                }

                foreach ($previous as $old) {
                    if ($old->spouse_owner_id !== null && isset($map[$old->spouse_owner_id])) {
                        $map[$old->id]->forceFill(['spouse_owner_id' => $map[$old->spouse_owner_id]->id])->save();
                    }
                }

                Audit::record('k10.owners_copied', $company, ['income_year' => $incomeYear, 'owners' => count($map)], $company, $user);

                return count($map);
            });
        });
    }

    /**
     * Every owner's worksheet for the year, keyed by owner id, for someone
     * with fiscal-years:manage. Three queries for the owners and the year
     * plus the books' few.
     *
     * @return Collection<int, K10Sheet>
     */
    public function sheets(User $user, Company $company, int $incomeYear): Collection
    {
        $this->authorizePermission($user, $company);

        return $this->buildSheets($company, $incomeYear);
    }

    /** @return Collection<int, K10Sheet> */
    private function buildSheets(Company $company, int $incomeYear): Collection
    {
        return CurrentCompany::run($company, function () use ($company, $incomeYear): Collection {
            $owners = K10Owner::query()->where('income_year', $incomeYear)->orderBy('id')->get();
            $year = K10Year::query()->where('income_year', $incomeYear)->first();
            $books = K10Books::for($company, $incomeYear);

            return $owners->keyBy('id')->map(fn (K10Owner $owner): K10Sheet => K10Sheet::for($company, $year, $owner, $owners, $books));
        });
    }

    public function sheet(User $user, Company $company, K10Owner $owner): K10Sheet
    {
        $this->authorize($user, $company, $owner->income_year);
        $sheet = $this->buildSheets($company, $owner->income_year)->get($owner->id);

        return $sheet ?? abort(404);
    }

    public function html(User $user, Company $company, K10Owner $owner): string
    {
        return view('k10.worksheet', ['sheet' => $this->sheet($user, $company, $owner)])->render();
    }

    public function pdf(User $user, Company $company, K10Owner $owner): string
    {
        $html = $this->html($user, $company, $owner);
        Audit::record('k10.worksheet_exported', $owner, ['income_year' => $owner->income_year, 'format' => 'pdf'], $company, $user);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** One owner's K10 as an SRU delivery (INFO.SRU, BLANKETTER.SRU) in a ZIP. */
    public function sruZip(User $user, Company $company, K10Owner $owner): string
    {
        $sheet = $this->sheet($user, $company, $owner);
        $k10 = $sheet->k10();

        if ($k10 === null) {
            throw ValidationException::withMessages(['sru' => $sheet->blockers()[0] ?? __('K10 kan inte räknas än.')]);
        }

        if (! K10Codes::isVerifiedPeriod($sheet->period())) {
            throw ValidationException::withMessages(['sru' => __('Fältkoderna för beskattningsperiod :period är inte kontrollerade ännu. Fyll i K10 i Skatteverkets e-tjänst.', ['period' => $sheet->period()])]);
        }

        if (blank($owner->identity)) {
            throw ValidationException::withMessages(['sru' => __('Fyll i ägarens personnummer för att få en SRU-fil. Det behövs bara för filen.')]);
        }

        if (blank($company->postal_code) || blank($company->city)) {
            throw ValidationException::withMessages(['sru' => __('Fyll i postnummer och ort under Företagsuppgifter först.')]);
        }

        $digits = (string) preg_replace('/\D/', '', (string) $company->org_number);

        if (strlen($digits) !== 10) {
            throw ValidationException::withMessages(['sru' => __('Bolagets organisationsnummer behövs för K10.')]);
        }

        $companyIdentity = "16{$digits}";
        $files = SruFile::render(
            ['orgnr' => $companyIdentity, 'name' => (string) ($company->legal_name ?: $company->name), 'postal_code' => (string) $company->postal_code, 'city' => (string) $company->city, 'address' => $company->address_line1, 'email' => $company->email, 'phone' => $company->phone],
            [['form' => "K10-{$sheet->period()}", 'identity' => (string) $owner->identity, 'name' => $owner->name, 'fields' => $k10->fields($companyIdentity)]],
            now()->toImmutable(),
            'NONOX Ekonomisystem',
        );

        Audit::record('k10.sru_exported', $owner, ['income_year' => $owner->income_year, 'period' => $sheet->period()], $company, $user);

        return SruZip::of($files);
    }

    public function authorize(User $user, Company $company, int $incomeYear): void
    {
        $this->authorizePermission($user, $company);

        if ($company->legal_form !== LegalForm::Ab) {
            throw ValidationException::withMessages(['form' => __('K10 lämnas av delägare i aktiebolag. NONOX tar bara fram K10-underlag för aktiebolag.')]);
        }

        if (K10Parameters::for($incomeYear) === null) {
            throw ValidationException::withMessages(['year' => __('Beloppen och procentsatserna för inkomstår :year finns inte i NONOX, så K10 kan inte räknas. Använd Skatteverkets e-tjänst.', ['year' => $incomeYear])]);
        }
    }

    /** A payroll employee of the company; only someone who may see payroll can link a new one, as the link shows that person's pay. */
    public function authorizePermission(User $user, Company $company): void
    {
        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }
    }

    private function employee(User $user, Company $company, mixed $value, ?K10Owner $owner): ?int
    {
        $id = (int) $value;

        if ($id === 0) {
            return null;
        }

        if ($id !== $owner?->employee_id && ! $user->hasTeamPermission($company, 'payroll:view')) {
            throw ValidationException::withMessages(['owner.employee_id' => __('Du behöver behörighet till lönerna för att koppla ägaren till lönesystemet.')]);
        }

        // Within the current company: another company's employee is not found.
        if (Employee::query()->whereKey($id)->doesntExist()) {
            throw ValidationException::withMessages(['owner.employee_id' => __('Välj en anställd i företaget.')]);
        }

        return $id;
    }

    private function kronor(mixed $value, string $field): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $ore = Money::parse($value)->ore;
        } catch (InvalidArgumentException|MathException) {
            throw ValidationException::withMessages([$field => __('Beloppet går inte att läsa. Skriv till exempel 12 500.')]);
        }

        if ($ore < 0) {
            throw ValidationException::withMessages([$field => __('Skriv beloppet utan minustecken.')]);
        }

        $kronor = intdiv($ore, 100);

        if ($kronor > self::MAX_KRONOR) {
            throw ValidationException::withMessages([$field => __('Beloppet är för stort.')]);
        }

        return $kronor;
    }

    private function shares(mixed $value, string $field): ?int
    {
        $value = str_replace([' ', "\u{a0}"], '', trim((string) $value));

        if ($value === '') {
            return null;
        }

        if (! ctype_digit($value) || (int) $value < 1 || (int) $value > self::MAX_SHARES) {
            throw ValidationException::withMessages([$field => __('Skriv antalet aktier som ett heltal, till exempel 500.')]);
        }

        return (int) $value;
    }

    /** A percentage with at most two decimals, as basis points; may exceed 100 (holdings in several companies summed). */
    private function percent(mixed $value, string $field): ?int
    {
        $value = str_replace([' ', '%'], '', trim((string) $value));

        if ($value === '') {
            return null;
        }

        if (! preg_match('/^(\d{1,5})(?:[.,](\d{1,2}))?$/', $value, $parts)) {
            throw ValidationException::withMessages([$field => __('Skriv andelen i procent, till exempel 25 eller 33,33.')]);
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    private function date(mixed $value, string $field): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw ValidationException::withMessages([$field => __('Skriv datumet som ÅÅÅÅ-MM-DD.')]);
        }

        return CarbonImmutable::parse($value);
    }

    /** Twelve digits with the century, Luhn-checked. */
    private static function personnummer(string $value): string
    {
        $digits = (string) preg_replace('/\D/', '', $value);

        if (strlen($digits) !== 12 || ! in_array(substr($digits, 0, 2), ['19', '20'], true) || ! Luhn::isValid(substr($digits, 2))) {
            throw ValidationException::withMessages(['owner.identity' => __('Personnumret stämmer inte. Skriv tolv siffror med århundradet, till exempel 19121212-1212.')]);
        }

        return $digits;
    }
}
