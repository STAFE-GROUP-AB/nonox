<?php

declare(strict_types=1);

namespace App\Actions\Vat;

use App\Domain\Accounting\AccountType;
use App\Domain\Vat\ImportVat;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Proposes the voucher for the import VAT on a tullräkning (ADR-057): the
 * date of the tullräkning, a description and the rows. Nothing is booked:
 * the rows are filled into the voucher editor, where the person checks
 * them, attaches the tullräkning and posts with SaveDraftVoucher and
 * PostVoucher like any voucher. The BAS accounts the rows need are added to
 * the chart when missing, as for purchases from abroad (ADR-016).
 */
class ProposeImportVat
{
    /**
     * @param  array<string, mixed>  $input  date, reference, kind, reduce, lines: list of {rate, customs_value, duty, incidental}
     * @return array{date: string, description: string, rows: list<array{account: int, name: string, debit_ore: int, credit_ore: int}>}
     */
    public function __invoke(User $user, Company $company, array $input): array
    {
        Gate::forUser($user)->authorize('create', [Voucher::class, $company]);

        $validated = Validator::make($input, [
            'date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:60'],
            'kind' => ['required', Rule::in(ImportVat::KINDS)],
            'reduce' => ['boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:'.count(ImportVat::RATES)],
        ], [
            'date.required' => __('Skriv tullräkningens datum.'),
            'lines.required' => __('Fyll i minst en rad från tullräkningen.'),
            'lines.min' => __('Fyll i minst en rad från tullräkningen.'),
            'lines.max' => __('Fyll i högst en rad per momssats, alltså högst tre rader.'),
        ], [
            'date' => __('datum'),
        ])->validate();

        $kind = (string) $validated['kind'];
        $reduce = (bool) ($validated['reduce'] ?? false);
        $lines = $this->lines(is_array($validated['lines']) ? $validated['lines'] : [], $reduce);
        $rows = ImportVat::rows($kind, $lines, $reduce);

        if ($rows === []) {
            throw ValidationException::withMessages(['lines' => __('Beskattningsunderlaget blir noll. Skriv tullvärdet från tullräkningen.')]);
        }

        $names = CurrentCompany::run($company, fn (): array => DB::transaction(
            fn (): array => $this->ensureAccounts($user, $company, ImportVat::accountsFor($kind, array_column($lines, 'rate'))),
        ));

        $reference = trim((string) ($validated['reference'] ?? ''));

        return [
            'date' => CarbonImmutable::parse((string) $validated['date'])->toDateString(),
            'description' => mb_substr($reduce
                ? ($reference === '' ? __('Importmoms, ändrat beslut från Tullverket') : __('Importmoms, ändrat beslut från Tullverket :reference', ['reference' => $reference]))
                : ($reference === '' ? __('Importmoms, tullräkning') : __('Importmoms, tullräkning :reference', ['reference' => $reference])), 0, 255),
            'rows' => array_map(fn (array $row): array => [...$row, 'name' => $names[$row['account']] ?? ''], $rows),
        ];
    }

    /**
     * The goods lines, by rate, with the amounts read as kronor. Empty lines
     * are skipped.
     *
     * @param  array<int|string, mixed>  $input
     * @return list<array{rate: int, customs_value: int, duty: int, incidental: int}>
     */
    private function lines(array $input, bool $reduce): array
    {
        $errors = [];
        $lines = [];

        foreach (array_values($input) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $values = [
                'customs_value' => $this->amount($line, 'customs_value', $index, $errors),
                'duty' => $this->amount($line, 'duty', $index, $errors),
                'incidental' => $this->amount($line, 'incidental', $index, $errors),
            ];

            if (array_sum($values) === 0) {
                continue;
            }

            $rate = is_scalar($line['rate'] ?? null) ? (int) $line['rate'] : 0;

            if (! in_array($rate, ImportVat::RATES, true)) {
                $errors["lines.{$index}.rate"] = __('Rad :line: välj momssats 25, 12 eller 6 %.', ['line' => $index + 1]);

                continue;
            }

            if (! $reduce && $values['customs_value'] === 0) {
                $errors["lines.{$index}.customs_value"] = __('Rad :line: skriv tullvärdet från tullräkningen.', ['line' => $index + 1]);
            }

            $lines[] = ['rate' => $rate, ...$values];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Fyll i minst en rad från tullräkningen.')]);
        }

        return $lines;
    }

    /**
     * One amount of a line in öre; 0 when empty. An unreadable or negative
     * amount adds an error.
     *
     * @param  array<mixed>  $line
     * @param  array<string, mixed>  $errors
     */
    private function amount(array $line, string $field, int $index, array &$errors): int
    {
        $raw = $line[$field] ?? '';

        if (! is_scalar($raw)) {
            $errors["lines.{$index}.{$field}"] = __('Rad :line: beloppet går inte att läsa. Skriv till exempel 105 000,00.', ['line' => $index + 1]);

            return 0;
        }

        $value = trim((string) $raw);

        try {
            $ore = Money::parse($value === '' ? '0' : $value)->ore;
        } catch (InvalidArgumentException) {
            $errors["lines.{$index}.{$field}"] = __('Rad :line: beloppet går inte att läsa. Skriv till exempel 105 000,00.', ['line' => $index + 1]);

            return 0;
        }

        if ($ore < 0) {
            $errors["lines.{$index}.{$field}"] = __('Rad :line: skriv beloppen utan minustecken. Kryssa i att det är ett ändrat beslut om underlaget ska minska.', ['line' => $index + 1]);
        }

        return $ore;
    }

    /**
     * Adds the BAS accounts the rows need when the chart lacks them, and
     * returns the names of all of them.
     *
     * @param  list<int>  $numbers
     * @return array<int, string>
     */
    private function ensureAccounts(User $user, Company $company, array $numbers): array
    {
        $existing = Account::query()->whereIn('number', $numbers)->pluck('name', 'number')->all();

        foreach (array_diff(array_unique($numbers), array_keys($existing)) as $number) {
            $account = Account::create([
                'number' => $number,
                'name' => ImportVat::ACCOUNT_NAMES[$number],
                'type' => AccountType::forNumber($number),
            ]);

            Audit::record('account.created', $account, ['number' => $number, 'reason' => 'import_vat'], $company, $user);
            $existing[$number] = $account->name;
        }

        return $existing;
    }
}
