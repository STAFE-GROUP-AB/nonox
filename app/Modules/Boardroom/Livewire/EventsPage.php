<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\User;
use App\Modules\Boardroom\Actions\RecordShareEvent;
use App\Modules\Boardroom\Domain\HolderKind;
use App\Modules\Boardroom\Domain\RegisterException;
use App\Modules\Boardroom\Domain\RegisterFold;
use App\Modules\Boardroom\Domain\ShareEventKind;
use App\Modules\Boardroom\Livewire\Concerns\InCurrentCompany;
use App\Modules\Boardroom\Models\ShareClass;
use App\Modules\Boardroom\Models\Shareholder;
use App\Modules\Boardroom\Support\ShareRegister;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Aktiebok → Händelser: every entry in the register, newest first, and the
 * form that records a new one. An entry is never changed; a wrong one is
 * corrected with a Rättelse that names it. The form asks for what the kind
 * of entry needs and nothing else.
 */
class EventsPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;
    use WithPagination;

    public const int PER_PAGE = 50;

    #[Url(as: 'ny', except: '')]
    public string $start = '';

    public bool $open = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->allowed('share-register:view'), 403);

        if ($this->start !== '' && ShareEventKind::tryFrom($this->start) !== null && $this->mayChange('share-register:manage')) {
            $this->create($this->start);
        }
    }

    public function create(string $kind = 'TRANSFER'): void
    {
        $this->open = true;
        $this->form = [
            'kind' => ShareEventKind::tryFrom($kind)->value ?? ShareEventKind::Transfer->value,
            'effective_date' => CarbonImmutable::today()->toDateString(),
            'decided_on' => '', 'registered_on' => '', 'share_from' => '', 'share_to' => '', 'share_class_id' => '',
            'from_holder_id' => '', 'to_holder_id' => '', 'capital' => '', 'consideration' => '', 'certificate_issued' => false,
            'ratio' => '', 'refers_to_event_id' => '', 'note' => '', 'document_reference' => '', 'reason' => '',
        ];
        $this->resetErrorBag();
    }

    public function correct(int $eventId): void
    {
        $this->create(ShareEventKind::Correction->value);
        $this->form['refers_to_event_id'] = (string) $eventId;
    }

    public function cancel(): void
    {
        $this->reset('open', 'form', 'start');
    }

    public function save(RecordShareEvent $record): void
    {
        $input = $this->form;

        try {
            $input['capital_change_ore'] = self::ore($input['capital'] ?? '', 'capital');
            $input['consideration_ore'] = self::ore($input['consideration'] ?? '', 'consideration');
        } catch (ValidationException $exception) {
            $this->attempt(fn () => throw $exception);

            return;
        }

        unset($input['capital'], $input['consideration']);
        $input = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $input);
        $event = null;

        if (! $this->attempt(function () use ($record, $input, &$event): void {
            $event = $record($this->user(), $this->company(), $input);
        })) {
            return;
        }

        $this->toast(__('Händelse :number är införd i aktieboken.', ['number' => $event?->sequence]));
        $this->cancel();
    }

    public function render(): View
    {
        $events = ShareRegister::events();
        $kind = ShareEventKind::tryFrom((string) ($this->form['kind'] ?? ''));

        try {
            $voided = RegisterFold::at($events)->voided;
        } catch (RegisterException) {
            $voided = [];
        }

        // The whole history is read once for the fold; the list shows it a page at a time, newest first.
        $newestFirst = array_reverse($events);
        $page = new LengthAwarePaginator(array_slice($newestFirst, ($this->getPage() - 1) * self::PER_PAGE, self::PER_PAGE), count($newestFirst), self::PER_PAGE, $this->getPage());
        $users = User::query()->whereIn('id', array_values(array_unique(array_filter(array_map(fn ($event): ?int => $event->user_id, $page->items())))))->pluck('name', 'id');

        return view('boardroom::livewire.events', [
            'events' => $newestFirst,
            'page' => $page,
            'bySequence' => collect($events)->keyBy('id'),
            'voided' => $voided,
            'holders' => Shareholder::query()->orderBy('name')->get(['id', 'name', 'kind'])->keyBy('id'),
            'classes' => ShareClass::query()->orderBy('code')->get(),
            'users' => $users,
            'kinds' => array_values(array_filter(ShareEventKind::cases(), fn (ShareEventKind $each): bool => $each !== ShareEventKind::AddressChange)),
            'kind' => $kind,
            'canManage' => $this->mayChange('share-register:manage'),
            'companyKind' => HolderKind::CompanyItself,
        ]);
    }

    private static function ore(mixed $value, string $field): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Money::parse($value)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Skriv beloppet i kronor, till exempel 25 000.')]);
        }
    }
}
