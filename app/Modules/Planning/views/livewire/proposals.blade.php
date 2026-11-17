@use('App\Modules\Planning\Domain\Proposal\ProposalWeights')
@use('App\Modules\Planning\Support\ProposalReasons')
@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $locale = app()->getLocale();
    $person = fn (?int $id) => $id === null ? null : (($p = $people->get($id)) ? $p->first_name.' '.$p->last_name : __('Tidigare anställd'));
    $accepted = $proposal?->isAccepted() ?? false;
@endphp
<div class="space-y-6">
    <form wire:submit="make" class="space-y-4 rounded-3xl bg-white p-4 shadow-xs ring-1 ring-stone-200 sm:p-6">
        <div class="flex flex-wrap items-end gap-3">
            <label class="block text-sm">
                <span class="font-medium text-gray-700">{{ __('Från') }}</span>
                <input type="date" wire:model="from" class="{{ $input }} mt-1 w-40">
            </label>
            <label class="block text-sm">
                <span class="font-medium text-gray-700">{{ __('Till') }}</span>
                <input type="date" wire:model="to" class="{{ $input }} mt-1 w-40">
            </label>
            <x-button type="submit" wire:loading.attr="disabled" wire:target="make">{{ __('Gör förslag') }}</x-button>
        </div>
        <p class="text-sm text-gray-600">{{ __('Förslaget fyller bara öppna pass som redan finns i schemat och som inte har börjat, högst fyra veckor åt gången. Lägg ut en mall utan personer eller lägg till öppna pass först.') }}</p>

        <details class="rounded-2xl bg-stone-50 p-4" data-weights>
            <summary class="cursor-pointer text-sm font-semibold text-ink">{{ __('Vad väger mest') }}</summary>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($criteria as $criterion)
                    <label class="block text-sm">
                        <span class="font-medium text-gray-700">{{ ProposalWeights::criterionLabel($criterion) }}</span>
                        <select wire:model="weights.{{ $criterion }}" class="{{ $input }} mt-1">
                            @foreach($levels as $level)
                                <option value="{{ $level }}">{{ ProposalWeights::levelLabel($level) }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
                <label class="block text-sm">
                    <span class="font-medium text-gray-700">{{ __('Veckor bakåt för jämn fördelning') }}</span>
                    <input type="number" min="1" max="26" wire:model="fairnessWeeks" class="{{ $input }} mt-1">
                </label>
                <div class="block text-sm">
                    <span class="font-medium text-gray-700">{{ __('Obekväm tid vardagar') }}</span>
                    <div class="mt-1 flex items-center gap-2">
                        <input type="time" wire:model="unsocialFrom" aria-label="{{ __('Obekväm tid från') }}" class="{{ $input }}">
                        <span aria-hidden="true">–</span>
                        <input type="time" wire:model="unsocialTo" aria-label="{{ __('Obekväm tid till') }}" class="{{ $input }}">
                    </div>
                </div>
            </div>
            <p class="mt-3 text-xs text-gray-600">{{ __('Jämn fördelning jämför planerade timmar mot avtalad tid, och andelen kvällar, nätter och helger, de senaste veckorna och perioden. Lördagar, söndagar och helgdagar räknas alltid som obekväm tid. Lönen påverkar aldrig vem som föreslås.') }}</p>
        </details>
    </form>

    @if($proposal === null)
        <x-ui.card>
            <x-ui.empty-state :title="__('Inget förslag än')">{{ __('Välj en period och tryck på Gör förslag. Inget i schemat ändras förrän du godkänner förslaget.') }}</x-ui.empty-state>
        </x-ui.card>
    @else
        <x-ui.card :title="__('Förslag för :from–:to', ['from' => $proposal->period_from->locale($locale)->isoFormat('D MMM'), 'to' => $proposal->period_to->locale($locale)->isoFormat('D MMM YYYY')])"
                   :description="__(':filled av :total öppna pass har ett förslag. Gjort :made.', ['filled' => $proposal->filled_count, 'total' => $proposal->slot_count, 'made' => $proposal->created_at->setTimezone(App\Modules\Planning\Support\LocalTimestamp::ZONE)->format('Y-m-d H:i')])">
            @if($accepted)
                <p class="mt-4 rounded-2xl bg-green-50 p-3 text-sm text-green-800" data-accepted>{{ trans_choice('{1} Godkänt: 1 pass blev ett utkast i schemat.|[2,*] Godkänt: :count pass blev utkast i schemat.', $proposal->accepted_count, ['count' => $proposal->accepted_count]) }} <a href="{{ route('planning.schedule', ['vecka' => $proposal->period_from->startOfWeek()->toDateString()]) }}" class="font-semibold underline" wire:navigate>{{ __('Öppna schemat och publicera') }}</a></p>
            @elseif($stale)
                <p class="mt-4 rounded-2xl bg-amber-50 p-3 text-sm text-amber-900" data-stale>{{ App\Modules\Planning\Actions\AcceptProposal::staleMessage() }}</p>
            @endif

            @forelse($days as $date => $dayItems)
                <h3 class="mt-6 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ $date === '' ? __('Passet finns inte längre') : ucfirst(\Carbon\CarbonImmutable::parse($date)->locale($locale)->isoFormat('dddd D MMMM')) }}</h3>
                <ul class="mt-2 divide-y divide-stone-100">
                    @foreach($dayItems as $item)
                        @php($shift = $shifts->get($item->shift_id))
                        <li class="flex flex-wrap items-start gap-3 py-3" wire:key="item-{{ $item->id }}" data-item="{{ $item->id }}" data-filled="{{ $item->employee_id ? 'ja' : 'nej' }}">
                            @if(! $accepted && $item->employee_id !== null)
                                <input type="checkbox" id="item-{{ $item->id }}" value="{{ $item->id }}" wire:model="selected" class="mt-1 rounded border-gray-300 text-accent-600 focus:ring-accent-500" aria-label="{{ __('Godkänn förslaget för passet') }}">
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="text-sm tabular-nums text-gray-700">
                                    @if($shift)
                                        {{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}
                                        @if($shift->area_id && $areas->get($shift->area_id)) · {{ $areas->get($shift->area_id) }}@endif
                                        @if($shift->skill_id && $skills->get($shift->skill_id)) · {{ $skills->get($shift->skill_id) }}@endif
                                    @endif
                                </p>
                                @if($item->employee_id !== null)
                                    <p class="font-semibold text-ink">{{ $person($item->employee_id) }}
                                        @if($item->accepted_at)<x-ui.badge tone="success" class="ml-2">{{ __('Godkänt') }}</x-ui.badge>@endif
                                    </p>
                                    <ul class="mt-1 flex flex-wrap gap-2" data-reasons>
                                        @foreach($item->reasons as $reason)
                                            <li><x-ui.badge :tone="($reason['code'] ?? '') === 'extra_time' ? 'warning' : 'neutral'">{{ ProposalReasons::label($reason, $skills) }}</x-ui.badge></li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="font-semibold text-gray-600">{{ __('Inget förslag') }}</p>
                                    <p class="mt-1 text-sm text-amber-900" data-reasons>{{ collect($item->reasons)->map(fn ($reason) => ProposalReasons::label($reason, $skills))->join(' ') }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @empty
                <div class="mt-4"><x-ui.empty-state :title="__('Inga öppna pass i perioden')">{{ __('Lägg till öppna pass i schemat, eller lägg ut en mall utan personer, och gör ett nytt förslag.') }}</x-ui.empty-state></div>
            @endforelse

            @if(! $accepted && $proposal->filled_count > 0 && ! $stale)
                <div class="mt-6 flex flex-wrap gap-3">
                    <x-button type="button" wire:click="acceptSelected" wire:confirm="{{ __('Godkänna de markerade passen? De får personen som utkast i schemat. Inget publiceras och ingen får besked förrän du publicerar.') }}">{{ __('Godkänn markerade') }}</x-button>
                    <x-secondary-button type="button" wire:click="acceptAll" wire:confirm="{{ __('Godkänna alla förslag? Passen får personen som utkast i schemat. Inget publiceras och ingen får besked förrän du publicerar.') }}">{{ __('Godkänn alla') }}</x-secondary-button>
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
