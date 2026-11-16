<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Company\LookUpCompany;
use App\Domain\Company\LegalForm;
use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyRegistry\CompanyRegistries;
use App\Support\CompanyRegistry\RegisteredCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * "Hämta uppgifter" for a form with an organisation number (ADR-035).
 * The register's answer fills the fields that are empty, or that still
 * hold what an earlier lookup put there. A field the user has filled in
 * differently is never changed without asking: its proposed value is
 * listed, and the user chooses to use it or keep their own. Nothing is
 * saved until the user saves the form.
 *
 * @mixin Component
 */
trait LooksUpCompanies
{
    /** @var array<string, mixed>|null what the register said, shown above the proposal */
    public ?array $registryResult = null;

    /** @var array<string, string> proposed values for fields the user had filled differently */
    public array $registryConflicts = [];

    /** @var array<string, string> values a lookup filled in, which a later lookup may replace */
    public array $registryFilled = [];

    public string $registryNotice = '';

    /** @var list<string> non-blocking hints from a lookup, e.g. that a company may be too large for K2 */
    public array $registryHints = [];

    /**
     * Which of the register's values this form has, and where they go:
     * name, address_line1, address_line2, postal_code, city, legal_form.
     *
     * @return array<string, string> proposal key => property path ("form.name")
     */
    abstract protected function registryFields(): array;

    /** The organisation number as typed in the form. */
    abstract protected function registryNumber(): string;

    /** The company whose quota the lookup counts against; null before a company exists. */
    abstract protected function registryQuotaCompany(): ?Company;

    /**
     * Values that count as not filled in by the user, such as a select's
     * preset (proposal key => value).
     *
     * @return array<string, string>
     */
    protected function registryPresets(): array
    {
        return [];
    }

    public function lookUpCompany(): void
    {
        $this->runCompanyLookup(false);
    }

    /**
     * After the organisation number field loses focus: looks up on its own
     * only when the number is valid and the name is still empty, and stays
     * quiet about a number that is not complete yet.
     */
    protected function lookUpCompanyAutomatically(): void
    {
        $namePath = $this->registryFields()['name'] ?? null;

        if ($namePath !== null && trim((string) data_get($this, $namePath)) !== '') {
            return;
        }

        $number = OrganisationNumber::tryFrom($this->registryNumber());

        if ($number === null) {
            return;
        }

        // A sole trader's personal number is only answered by an API driver: without one there is nothing to ask.
        if ((int) $number->digits[2] < 2 && CompanyRegistries::make() === null) {
            return;
        }

        $this->runCompanyLookup(true);
    }

    public function useRegistryValues(): void
    {
        $fields = $this->registryFields();

        foreach ($this->registryConflicts as $key => $value) {
            if (isset($fields[$key])) {
                data_set($this, $fields[$key], $value);
                $this->registryFilled[$key] = $value;
            }
        }

        $this->registryConflicts = [];
        $this->dispatch('toast', message: __('Uppgifterna från registret är ifyllda. Kontrollera dem och spara.'), type: 'success');
    }

    public function keepOwnValues(): void
    {
        $this->registryConflicts = [];
    }

    public function companyLookupAvailable(): bool
    {
        return CompanyRegistries::isConfigured();
    }

    public function registrySourceName(): string
    {
        return (CompanyRegistries::local() ?? CompanyRegistries::make())?->sourceName() ?? '';
    }

    /** Takes back what an earlier lookup suggested, before a new lookup or after a failed one. */
    protected function clearRegistrySuggestions(): void {}

    /**
     * Suggestions beyond the shared fields (industry, employer, hints),
     * for a form that wants them: only onboarding does. Returns the hints to show.
     *
     * @return list<string>
     */
    protected function applyRegistrySuggestions(RegisteredCompany $found): array
    {
        return [];
    }

    private function runCompanyLookup(bool $automatic): void
    {
        $this->registryNotice = '';
        $this->registryConflicts = [];
        $this->registryHints = [];

        if (! CompanyRegistries::isConfigured()) {
            if (! $automatic) {
                $this->registryNotice = __('Hämtning av företagsuppgifter är inte påslagen i den här installationen.');
            }

            return;
        }

        /** @var User $user */
        $user = Auth::user();

        try {
            $found = app(LookUpCompany::class)($user, $this->registryQuotaCompany(), $this->registryNumber());
        } catch (ValidationException $exception) {
            $this->registryResult = null;
            $this->registryNotice = (string) collect($exception->errors())->flatten()->first();
            $this->clearRegistrySuggestions();

            return;
        }

        $this->clearRegistrySuggestions();

        $source = (string) $found->sourceName;
        $this->registryResult = [
            'source' => $source,
            'source_date' => self::swedishDate($found->sourceDate),
            'name' => $found->nameExcluded ? (string) __('Namnet visas inte') : $found->name,
            'legal_form' => $found->legalFormText,
            'registered_on' => self::swedishDate($found->registeredOn),
            'deregistered_on' => self::swedishDate($found->deregisteredOn),
            'proceedings' => $found->proceedings,
        ];

        $fields = $this->registryFields();
        $presets = $this->registryPresets();
        $filled = 0;

        $this->registryHints = $this->applyRegistrySuggestions($found);

        foreach ($this->proposal($found) as $key => $value) {
            if (! isset($fields[$key])) {
                continue;
            }

            $current = trim((string) data_get($this, $fields[$key]));
            $untouched = $current === ''
                || $current === $value
                || ($presets[$key] ?? null) === $current
                || ($this->registryFilled[$key] ?? null) === $current;

            if (! $untouched) {
                $this->registryConflicts[$key] = $value;

                continue;
            }

            data_set($this, $fields[$key], $value);
            $this->registryFilled[$key] = $value;
            $filled++;
        }

        $message = $this->registryConflicts === []
            ? __('Uppgifterna är hämtade från :source. Kontrollera dem innan du sparar.', ['source' => $source])
            : __('Uppgifterna är hämtade från :source. Några skiljer sig från det du har skrivit – välj vad som ska gälla.', ['source' => $source]);

        if ($filled > 0 || $this->registryConflicts !== []) {
            $this->dispatch('toast', message: $message, type: 'success');
        }
    }

    /** @return array<string, string> */
    private function proposal(RegisteredCompany $found): array
    {
        return array_filter([
            // A name left out after an objection is not proposed: the placeholder would end up on the invoices.
            'name' => $found->nameExcluded ? '' : $found->name,
            'address_line1' => (string) $found->formattedStreet(),
            'address_line2' => (string) $found->formattedCoAddress(),
            'postal_code' => (string) $found->formattedPostalCode(),
            'city' => (string) $found->formattedCity(),
            'legal_form' => (string) $found->legalForm()?->value,
        ], fn (string $value): bool => $value !== '');
    }

    /** "4 nov 2026" for a date as Y-m-d, or null. */
    public static function swedishDate(?string $date): ?string
    {
        if ($date === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m) !== 1) {
            return null;
        }

        $months = ['jan', 'feb', 'mars', 'apr', 'maj', 'juni', 'juli', 'aug', 'sep', 'okt', 'nov', 'dec'];

        return (int) $m[3].' '.($months[(int) $m[2] - 1] ?? '').' '.$m[1];
    }

    /** The label for a proposal key and how its value reads, for the view. */
    public function registryLabel(string $key): string
    {
        return match ($key) {
            'name' => __('Namn'),
            'address_line1' => __('Adress'),
            'address_line2' => __('Adress, rad 2'),
            'postal_code' => __('Postnummer'),
            'city' => __('Ort'),
            'legal_form' => __('Företagsform'),
            default => $key,
        };
    }

    public function registryValueLabel(string $key, string $value): string
    {
        if ($key === 'legal_form') {
            return LegalForm::tryFrom($value)?->label() ?? $value;
        }

        return $value;
    }
}
