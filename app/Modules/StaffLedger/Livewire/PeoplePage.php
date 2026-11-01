<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use App\Modules\StaffLedger\Actions\PersonCredentials;
use App\Modules\StaffLedger\Actions\SavePerson;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Modules\StaffLedger\Livewire\Concerns\RevealsPersonalNumbers;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\Support\KioskStrings;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Personalliggare → Personal: the people, their kiosk PIN, QR card and
 * optional photo. Personal numbers are masked until a user with
 * staff-ledger:view-sensitive shows them, and are never sent to the browser
 * when a person is edited.
 */
class PeoplePage extends Component
{
    use InCurrentCompany;
    use RevealsPersonalNumbers;
    use ShowsToasts;
    use WithFileUploads;

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public ?int $settingPin = null;

    public string $pin = '';

    public ?int $settingPhoto = null;

    /** @var UploadedFile|null */
    public $photo = null;

    public bool $consent = false;

    public bool $showArchived = false;

    public function mount(): void
    {
        abort_unless($this->allowed('staff-ledger:view'), 403);
    }

    public function create(): void
    {
        $this->editing = 0;
        $this->form = ['first_name' => '', 'last_name' => '', 'personal_number' => '', 'email' => '', 'language' => 'sv', 'hired' => false, 'employer_name' => '', 'employer_org_number' => '', 'employee_id' => '', 'user_id' => '', 'is_manager' => false];
    }

    public function edit(int $id): void
    {
        $person = Person::query()->findOrFail($id);
        $this->editing = $person->id;
        $this->form = [
            ...$person->only(['first_name', 'last_name', 'email', 'language', 'employer_name', 'employer_org_number', 'is_manager']),
            'personal_number' => '',
            'hired' => $person->isHired(),
            'employee_id' => (string) $person->employee_id,
            'user_id' => (string) $person->user_id,
        ];
    }

    public function save(SavePerson $save): void
    {
        $person = $this->editing ? Person::query()->findOrFail($this->editing) : null;
        $input = [...$this->form, 'employee_id' => ($this->form['employee_id'] ?? '') === '' ? null : (int) $this->form['employee_id'], 'user_id' => ($this->form['user_id'] ?? '') === '' ? null : (int) $this->form['user_id'], 'email' => ($this->form['email'] ?? '') ?: null];

        try {
            $saved = $save($this->user(), $this->company(), $input, $person);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["form.{$key}" => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->reset('editing', 'form');
        $this->toast($person === null ? __(':name är tillagd. Välj en kod för kiosken.', ['name' => $saved->name()]) : __('Uppgifterna för :name är sparade.', ['name' => $saved->name()]));

        if ($person === null) {
            $this->settingPin = $saved->id;
        }
    }

    public function savePin(PersonCredentials $credentials): void
    {
        $person = Person::query()->findOrFail($this->settingPin);

        try {
            $credentials->setPin($this->user(), $this->company(), $person, $this->pin);
        } catch (ValidationException $exception) {
            $this->addError('pin', $this->firstError($exception));
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->reset('settingPin', 'pin');
        $this->toast(__('Koden för :name är sparad. Kiosken får den vid nästa uppdatering.', ['name' => $person->name()]));
    }

    public function savePhoto(PersonCredentials $credentials): void
    {
        $person = Person::query()->findOrFail($this->settingPhoto);

        if (! $this->photo instanceof UploadedFile) {
            $this->toast(__('Välj en bild.'), 'error');

            return;
        }

        try {
            $credentials->setPhoto($this->user(), $this->company(), $person, $this->photo, $this->consent);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->reset('settingPhoto', 'photo', 'consent');
        $this->toast(__('Fotot är sparat.'));
    }

    public function removePhoto(int $id, PersonCredentials $credentials): void
    {
        $credentials->removePhoto($this->user(), $this->company(), Person::query()->findOrFail($id));
        $this->toast(__('Fotot är borttaget.'));
    }

    public function renewQr(int $id, PersonCredentials $credentials): void
    {
        $credentials->renewQr($this->user(), $this->company(), Person::query()->findOrFail($id));
        $this->toast(__('En ny QR-kod är skapad. Den gamla fungerar inte längre.'));
    }

    public function toggleArchive(int $id, PersonCredentials $credentials): void
    {
        $person = Person::query()->findOrFail($id);
        $credentials->archive($this->user(), $this->company(), $person, $person->archived_at === null);
        $this->toast($person->archived_at === null ? __(':name är aktiv igen.', ['name' => $person->name()]) : __(':name är arkiverad och visas inte längre på kiosken. Liggaren finns kvar.', ['name' => $person->name()]));
    }

    public function render(ModuleManager $modules): View
    {
        $sensitive = $this->showsFullNumbers();
        $people = Person::query()->when(! $this->showArchived, fn ($query) => $query->whereNull('archived_at'))->orderBy('first_name')->orderBy('last_name')->get();

        $payroll = $modules->isEnabled(PayrollModule::KEY, $this->company());

        return view('staff-ledger::livewire.people', [
            'people' => $people,
            'sensitive' => $sensitive,
            'canReveal' => $this->allowed('staff-ledger:view-sensitive'),
            'canManage' => $this->allowed('staff-ledger:manage'),
            'languages' => KioskStrings::LANGUAGES,
            'employees' => $payroll ? Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'employee_number']) : collect(),
            'members' => $this->company()->allUsers()->sortBy('name')->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values(),
        ]);
    }
}
