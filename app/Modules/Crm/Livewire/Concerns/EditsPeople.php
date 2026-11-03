<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire\Concerns;

use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Domain\LegalBasis;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Models\Person;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The person form, on the account card and on Personer: add or change a
 * contact person with the legal basis GDPR requires, and erase one on
 * request. The legal basis starts as legitimate interest, the normal case
 * for a contact at a business, and the form says so.
 *
 * @mixin Component
 */
trait EditsPeople
{
    /** @var array<string, mixed> */
    public array $personForm = [];

    #[Locked]
    public ?int $editingPersonId = null;

    public function startPerson(?int $personId = null): void
    {
        $this->authorizeManage();
        $person = $personId === null ? null : Person::query()->findOrFail($personId);

        $this->editingPersonId = $person?->id;
        $this->personForm = [
            'name' => (string) $person?->name,
            'title' => (string) $person?->title,
            'email' => (string) $person?->email,
            'phone' => (string) $person?->phone,
            'mobile' => (string) $person?->mobile,
            'legal_basis' => $person->legal_basis->value ?? LegalBasis::LegitimateInterest->value,
            'legal_basis_note' => (string) $person?->legal_basis_note,
            'consent_at' => (string) $person?->consent_at?->toDateString(),
            'is_active' => $person->is_active ?? true,
            'informed_at' => (string) $person?->informed_at?->toDateString(),
            'do_not_contact' => $person?->doNotContact() ?? false,
            'do_not_contact_reason' => (string) $person?->do_not_contact_reason?->value,
            'role' => PersonRole::Other->value,
        ];
        $this->panel = 'person';
        $this->resetErrorBag();
    }

    public function savePerson(SavePerson $save): void
    {
        $person = $this->editingPersonId === null ? null : Person::query()->findOrFail($this->editingPersonId);
        $input = $this->personForm;

        if ($person === null && $this->personAccountId() !== null) {
            $input['account_id'] = $this->personAccountId();
        } else {
            unset($input['role']);
        }

        $saved = $save($this->user(), $this->company(), $input, $person);

        $this->personSaved($saved, $person === null);
    }

    /** Erasure on request (GDPR art. 17): the person goes, their activities are anonymised. */
    public function erasePerson(EraseCrmData $erase): void
    {
        if ($this->editingPersonId === null) {
            return;
        }

        $erase->person($this->user(), $this->company(), Person::query()->findOrFail($this->editingPersonId));

        $this->closePanel();
        $this->toast(__('Personen är raderad. Aktiviteterna om personen finns kvar utan namn och text.'));
    }

    abstract protected function personSaved(Person $person, bool $created): void;

    /** The account a new person is placed at, or null. */
    abstract protected function personAccountId(): ?int;
}
