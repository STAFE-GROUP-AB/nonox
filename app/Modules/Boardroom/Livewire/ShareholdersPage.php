<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Boardroom\Actions\RecordFirmAuthorisation;
use App\Modules\Boardroom\Actions\RecordShareEvent;
use App\Modules\Boardroom\Actions\SaveShareholder;
use App\Modules\Boardroom\Domain\HolderKind;
use App\Modules\Boardroom\Domain\RegisterException;
use App\Modules\Boardroom\Livewire\Concerns\InCurrentCompany;
use App\Modules\Boardroom\Models\Register;
use App\Modules\Boardroom\Models\ShareEvent;
use App\Modules\Boardroom\Models\Shareholder;
use App\Modules\Boardroom\Support\BoardroomAccess;
use App\Modules\Boardroom\Support\ShareRegister;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Aktiebok → Aktieägare: the people and companies in the register with
 * their identity and postal address (ABL 5 kap. 5 § 2), the shares each
 * holds today, a register extract per holder, and the board's authorisation
 * for an accounting firm (5 kap. 9 §). A holder already in the register gets
 * new details through an entry, so the old ones stay in the history.
 */
class ShareholdersPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    /** null: no form; 0: a new holder; otherwise the holder being changed */
    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<string, string> */
    public array $authorisation = ['decided_on' => '', 'reference' => ''];

    public function mount(): void
    {
        abort_unless($this->allowed('share-register:view'), 403);
    }

    public function create(): void
    {
        $this->editing = 0;
        $this->form = [
            'kind' => HolderKind::Person->value, 'name' => '', 'personal_number' => '', 'org_number' => '', 'other_id' => '', 'other_id_country' => '',
            'address_line1' => '', 'address_line2' => '', 'postal_code' => '', 'city' => '', 'country' => 'SE', 'effective_date' => CarbonImmutable::today()->toDateString(),
        ];
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $holder = Shareholder::query()->findOrFail($id);
        abort_if($holder->kind === HolderKind::CompanyItself, 404);
        $this->editing = $holder->id;
        $this->form = [
            ...$holder->only(['name', 'org_number', 'other_id', 'other_id_country', 'address_line1', 'address_line2', 'postal_code', 'city', 'country']),
            'kind' => $holder->kind->value,
            'personal_number' => (string) $holder->personal_number,
            'effective_date' => CarbonImmutable::today()->toDateString(),
        ];
        $this->resetErrorBag();
    }

    public function save(SaveShareholder $save, RecordShareEvent $record): void
    {
        $holder = $this->editing ? Shareholder::query()->findOrFail($this->editing) : null;
        $inRegister = $holder !== null && SaveShareholder::inRegister($holder);

        $done = $this->attempt(function () use ($save, $record, $holder, $inRegister): void {
            if ($inRegister) {
                $record->changeDetails($this->user(), $this->company(), $holder, $this->form, (string) ($this->form['effective_date'] ?? ''));

                return;
            }

            $save($this->user(), $this->company(), $this->form, $holder);
        });

        if (! $done) {
            return;
        }

        $this->toast(match (true) {
            $holder === null => __('Aktieägaren är tillagd.'),
            $inRegister => __('De nya uppgifterna är införda i aktieboken. De gamla finns kvar i historiken.'),
            default => __('Aktieägaren är sparad.'),
        });
        $this->reset('editing', 'form');
    }

    public function delete(int $id, SaveShareholder $save): void
    {
        $holder = Shareholder::query()->findOrFail($id);

        if ($this->attempt(fn () => $save->delete($this->user(), $this->company(), $holder))) {
            $this->toast(__('Aktieägaren är borttagen.'));
        }
    }

    public function authorise(RecordFirmAuthorisation $record): void
    {
        if ($this->attempt(fn () => $record($this->user(), $this->company(), $this->authorisation), 'authorisation')) {
            $this->toast(__('Bemyndigandet är registrerat. Byrån kan nu föra in händelser i aktieboken.'));
            $this->authorisation = ['decided_on' => '', 'reference' => ''];
        }
    }

    public function withdraw(RecordFirmAuthorisation $record): void
    {
        if ($this->attempt(fn () => $record->withdraw($this->user(), $this->company()), 'authorisation')) {
            $this->toast(__('Bemyndigandet är återkallat.'));
        }
    }

    public function render(): View
    {
        $holders = Shareholder::query()->orderBy('name')->get();
        $referenced = ShareEvent::query()->whereNotNull('to_holder_id')->distinct()->pluck('to_holder_id')
            ->merge(ShareEvent::query()->whereNotNull('from_holder_id')->distinct()->pluck('from_holder_id'))
            ->unique()->all();

        try {
            $shares = ShareRegister::at()->sharesByHolder();
        } catch (RegisterException) {
            $shares = [];
        }

        $company = $this->company();

        return view('boardroom::livewire.shareholders', [
            'holders' => $holders,
            'referenced' => $referenced,
            'shares' => $shares,
            'canManage' => $this->mayChange('share-register:manage'),
            'register' => Register::query()->first(),
            'mayAuthorise' => $this->mayChange('share-register:manage') && BoardroomAccess::mayRecordAuthorisation($this->user(), $company),
            'editingInRegister' => $this->editing ? in_array($this->editing, $referenced, true) : false,
        ]);
    }
}
