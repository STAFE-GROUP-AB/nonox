@use('App\Modules\Planning\Models\Availability')
@use('App\Modules\Planning\Models\ShiftRequest')
@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $locale = app()->getLocale();
    $weekdays = [1 => __('Måndag'), 2 => __('Tisdag'), 3 => __('Onsdag'), 4 => __('Torsdag'), 5 => __('Fredag'), 6 => __('Lördag'), 7 => __('Söndag')];
    $statusTone = [ShiftRequest::AWAITING_COUNTERPART => 'bg-gray-100 text-gray-700', ShiftRequest::DECLINED => 'bg-accent-50 text-accent-800', ShiftRequest::PENDING => 'bg-gray-100 text-gray-700', ShiftRequest::APPROVED => 'bg-green-50 text-green-800', ShiftRequest::REJECTED => 'bg-accent-50 text-accent-800', ShiftRequest::WITHDRAWN => 'bg-gray-100 text-gray-500'];
    $day = fn ($moment) => ucfirst($moment->locale($locale)->isoFormat('dddd D MMMM'));
    $time = fn ($start, $end) => $start->format('H:i').'–'.$end->format('H:i');
@endphp
<div>
    @if($employee)
        <div class="space-y-6">
            <x-ui.card>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Nästa pass') }}</p>
                @if($next)
                    <div class="mt-2" data-next-shift>
                        <p class="text-2xl font-bold tracking-tight text-ink">{{ $day($next->start) }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900">{{ $time($next->start, $next->end) }}
                            @if($next->areaId && $areas->get($next->areaId))<span class="font-normal text-gray-600"> · {{ $areas->get($next->areaId)->name }}</span>@endif
                        </p>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ $next->start->lessThanOrEqualTo($now) ? __('Pågår nu') : ucfirst($next->start->locale($locale)->diffForHumans()) }}
                            @if($next->breakMinutes > 0) · {{ __('Rast :minutes min', ['minutes' => $next->breakMinutes]) }}@endif
                        </p>
                        @if($next->note)<p class="mt-2 text-sm text-gray-700">{{ $next->note }}</p>@endif
                    </div>
                @else
                    <p class="mt-2 text-sm text-gray-600">{{ __('Du har inga publicerade pass de närmaste två månaderna.') }}</p>
                @endif
            </x-ui.card>

            <x-ui.card>
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Mitt schema') }}</p>
                    <div class="flex items-center gap-1">
                        <button type="button" wire:click="previousWeek" @disabled($week === 0) class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 disabled:opacity-40" aria-label="{{ __('Föregående vecka') }}">‹</button>
                        <span class="text-sm font-semibold tabular-nums text-gray-900">{{ __('Vecka :number', ['number' => (int) $monday->format('W')]) }}</span>
                        <button type="button" wire:click="nextWeek" @disabled($week >= App\Modules\Planning\Livewire\MySchedule::MAX_WEEKS_AHEAD) class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 disabled:opacity-40" aria-label="{{ __('Nästa vecka') }}">›</button>
                    </div>
                </div>

                <ul class="mt-3 divide-y divide-gray-100" data-week-list>
                    @forelse($weekShifts as $shift)
                        @php($pending = in_array($shift->id, $pendingShiftIds, true))
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-2 py-3" wire:key="my-shift-{{ $shift->id }}">
                            <span @class(['min-w-0 flex-1 basis-48', 'line-through opacity-60' => $shift->cancelled])>
                                <span class="block font-medium text-gray-900">{{ $day($shift->start) }}</span>
                                <span class="block text-sm tabular-nums text-gray-700">{{ $time($shift->start, $shift->end) }}@if($shift->areaId && $areas->get($shift->areaId)) · {{ $areas->get($shift->areaId)->name }}@endif</span>
                                @if($shift->note && ! $shift->cancelled)<span class="block text-xs text-gray-500">{{ $shift->note }}</span>@endif
                            </span>
                            @if($shift->cancelled)
                                <span class="rounded-full bg-accent-50 px-3 py-1 text-xs font-medium text-accent-800">{{ __('Inställt') }}</span>
                            @elseif($shift->recent)
                                <span class="rounded-full bg-green-50 px-3 py-1 text-xs font-medium text-green-800" data-changed>{{ __('Nytt eller ändrat') }}</span>
                            @endif
                            @if(! $shift->cancelled && $shift->isCurrent() && $shift->start->greaterThan($now) && $swaps)
                                @if($pending)
                                    <span class="text-xs text-gray-500">{{ __('Förfrågan skickad') }}</span>
                                @else
                                    <span class="flex gap-3 text-sm">
                                        <button type="button" class="font-semibold text-accent-700 underline" wire:click="askSwap('{{ $shift->id }}')">{{ __('Byt') }}</button>
                                        <button type="button" class="font-semibold text-gray-700 underline" wire:click="askDrop('{{ $shift->id }}')">{{ __('Lämna') }}</button>
                                    </span>
                                @endif
                            @endif
                        </li>
                    @empty
                        <li class="py-3 text-sm text-gray-600">{{ __('Inga pass den här veckan.') }}</li>
                    @endforelse
                </ul>
                <p class="mt-2 text-xs text-gray-500">{{ __('Här syns bara publicerade pass. Ändringar som inte är publicerade ser du när din chef publicerar dem.') }}</p>
            </x-ui.card>

            @if($askedOfMe->isNotEmpty())
                <x-ui.card>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Vill byta med dig') }}</p>
                    <ul class="mt-3 divide-y divide-gray-100" data-asked-of-me>
                        @foreach($askedOfMe as $asked)
                            <li class="space-y-2 py-3 text-sm" wire:key="asked-{{ $asked->id }}">
                                <p class="font-medium text-gray-900">{{ __(':name vill byta pass med dig', ['name' => trim(($asked->requester?->first_name ?? '').' '.mb_substr((string) $asked->requester?->last_name, 0, 1))]) }}</p>
                                @if($asked->counterpartShift)
                                    <p class="tabular-nums text-gray-700">{{ __('Ditt pass: :shift', ['shift' => $day($asked->counterpartShift->starts_at).' '.$time($asked->counterpartShift->starts_at, $asked->counterpartShift->ends_at)]) }}</p>
                                @endif
                                <p class="tabular-nums text-gray-700">{{ __('Du får i stället: :shift', ['shift' => $day($asked->shift->starts_at).' '.$time($asked->shift->starts_at, $asked->shift->ends_at)]) }}</p>
                                @if($asked->note)<p class="text-gray-600">“{{ $asked->note }}”</p>@endif
                                <div class="flex flex-wrap items-center gap-3 pt-1">
                                    <x-button type="button" wire:click="acceptSwap({{ $asked->id }})" wire:confirm="{{ __('Säga ja till bytet? Er chef godkänner det sedan, först då ändras schemat.') }}">{{ __('Ja, byt') }}</x-button>
                                    <x-secondary-button type="button" wire:click="declineSwap({{ $asked->id }})">{{ __('Nej tack') }}</x-secondary-button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            @if($panel === 'request')
                <x-ui.card>
                    <form wire:submit="sendRequest" class="space-y-4">
                        <p class="text-lg font-semibold text-gray-900">{{ ShiftRequest::kindLabel($request['kind']) }}</p>
                        @if($requestShift)
                            <p class="text-sm text-gray-700">{{ $day($requestShift->starts_at) }} · <span class="tabular-nums">{{ $time($requestShift->starts_at, $requestShift->ends_at) }}</span>@if($requestShift->area_id && $areas->get($requestShift->area_id)) · {{ $areas->get($requestShift->area_id)->name }}@endif</p>
                        @endif
                        @if($request['kind'] === ShiftRequest::SWAP)
                            <x-ui.field for="swap_with" :label="__('Byt mot')" error="request.counterpart_shift_id">
                                <select id="swap_with" class="{{ $input }} mt-1" wire:model="request.counterpart_shift_id">
                                    <option value="">{{ __('Välj en kollegas pass') }}</option>
                                    @foreach($swapCandidates as $candidate)
                                        <option value="{{ $candidate->id }}">{{ $day($candidate->starts_at) }} {{ $time($candidate->starts_at, $candidate->ends_at) }} · {{ $candidate->employee?->first_name }} {{ mb_substr((string) $candidate->employee?->last_name, 0, 1) }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        @endif
                        <x-ui.field for="request_note" :label="__('Meddelande till din chef (frivilligt)')" error="request.note">
                            <textarea id="request_note" rows="2" maxlength="500" class="{{ $input }} mt-1" wire:model="request.note"></textarea>
                        </x-ui.field>
                        <p class="text-sm text-gray-600">{{ $request['kind'] === ShiftRequest::SWAP ? __('Din kollega får frågan först. Säger hen ja går bytet vidare till din chef, och inget ändras i schemat förrän chefen har godkänt.') : __('Inget ändras i schemat förrän din chef har godkänt. Du får besked här och via e-post.') }}</p>
                        <div class="flex items-center gap-4">
                            <x-button wire:loading.attr="disabled">{{ __('Skicka förfrågan') }}</x-button>
                            <button type="button" class="text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                        </div>
                    </form>
                </x-ui.card>
            @endif

            @if($claims)
                <x-ui.card>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Öppna pass') }}</p>
                    <ul class="mt-3 divide-y divide-gray-100" data-open-shifts>
                        @forelse($openShifts as $open)
                            <li class="flex flex-wrap items-center gap-3 py-3" wire:key="open-{{ $open->id }}">
                                <span class="min-w-0 flex-1 basis-48">
                                    <span class="block font-medium text-gray-900">{{ $day($open->starts_at) }}</span>
                                    <span class="block text-sm tabular-nums text-gray-700">{{ $time($open->starts_at, $open->ends_at) }}@if($open->area_id && $areas->get($open->area_id)) · {{ $areas->get($open->area_id)->name }}@endif</span>
                                </span>
                                @if(in_array($open->id, $pendingShiftIds, true))
                                    <span class="text-xs text-gray-500">{{ __('Förfrågan skickad') }}</span>
                                @else
                                    <x-secondary-button type="button" wire:click="claim('{{ $open->id }}')">{{ __('Jag tar passet') }}</x-secondary-button>
                                @endif
                            </li>
                        @empty
                            <li class="py-3 text-sm text-gray-600">{{ __('Inga öppna pass just nu.') }}</li>
                        @endforelse
                    </ul>
                </x-ui.card>
            @endif

            @if($requests->isNotEmpty())
                <x-ui.card>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Mina förfrågningar') }}</p>
                    <ul class="mt-3 divide-y divide-gray-100">
                        @foreach($requests as $item)
                            <li class="flex flex-wrap items-center gap-3 py-3 text-sm" wire:key="my-request-{{ $item->id }}">
                                <span class="min-w-0 flex-1 basis-48">
                                    <span class="block font-medium text-gray-900">{{ ShiftRequest::kindLabel($item->kind) }}</span>
                                    <span class="block tabular-nums text-gray-600">{{ $day($item->shift->starts_at) }} {{ $time($item->shift->starts_at, $item->shift->ends_at) }}@if($item->counterpartShift) ↔ {{ $day($item->counterpartShift->starts_at) }} {{ $time($item->counterpartShift->starts_at, $item->counterpartShift->ends_at) }}@endif</span>
                                    @if($item->decision_note)<span class="block text-gray-700">{{ $item->decision_note }}</span>@endif
                                </span>
                                <span class="rounded-full px-3 py-1 text-xs font-medium {{ $statusTone[$item->status] ?? '' }}">{{ ShiftRequest::statusLabel($item->status) }}</span>
                                @if($item->isOpen())
                                    <button type="button" class="text-xs text-gray-600 underline" wire:click="withdraw({{ $item->id }})">{{ __('Återkalla') }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            @if($availabilityRows !== null)
                <x-ui.card>
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Min tillgänglighet') }}</p>
                        @if($panel !== 'availability')
                            <button type="button" class="text-sm font-semibold text-accent-700 underline" wire:click="newAvailability">{{ __('Lägg till') }}</button>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Berätta när du kan, inte kan eller gärna jobbar. Din chef ser det när schemat läggs.') }}</p>

                    @if($panel === 'availability')
                        <form wire:submit="saveAvailability" class="mt-4 space-y-4 rounded-2xl bg-stone-50 p-4">
                            <x-ui.field for="availability_kind" :label="__('Jag')" error="availability.kind">
                                <select id="availability_kind" class="{{ $input }} mt-1" wire:model="availability.kind">
                                    @foreach(Availability::KINDS as $kind)
                                        <option value="{{ $kind }}">{{ Availability::kindLabel($kind) }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                            <fieldset class="flex flex-wrap gap-4 text-sm">
                                <legend class="sr-only">{{ __('När') }}</legend>
                                <label class="flex items-center gap-2"><input type="radio" value="weekly" wire:model.live="availability.repeat"> {{ __('Varje vecka') }}</label>
                                <label class="flex items-center gap-2"><input type="radio" value="date" wire:model.live="availability.repeat"> {{ __('En viss dag') }}</label>
                            </fieldset>
                            @if($availability['repeat'] === 'weekly')
                                <x-ui.field for="availability_weekday" :label="__('Veckodag')" error="availability.weekday">
                                    <select id="availability_weekday" class="{{ $input }} mt-1" wire:model="availability.weekday">
                                        @foreach($weekdays as $number => $name)
                                            <option value="{{ $number }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                            @else
                                <x-ui.field for="availability_date" :label="__('Datum')" error="availability.date">
                                    <input id="availability_date" type="date" class="{{ $input }} mt-1" wire:model="availability.date">
                                </x-ui.field>
                            @endif
                            <label class="flex items-center gap-2 text-sm"><x-checkbox wire:model.live="availability.whole_day" /> {{ __('Hela dagen') }}</label>
                            @if(! $availability['whole_day'])
                                <div class="grid grid-cols-2 gap-4">
                                    <x-ui.field for="availability_from" :label="__('Från')" error="availability.from">
                                        <input id="availability_from" type="time" step="900" class="{{ $input }} mt-1" wire:model="availability.from">
                                    </x-ui.field>
                                    <x-ui.field for="availability_to" :label="__('Till')" error="availability.to">
                                        <input id="availability_to" type="time" step="900" class="{{ $input }} mt-1" wire:model="availability.to">
                                    </x-ui.field>
                                </div>
                            @endif
                            <x-ui.field for="availability_note" :label="__('Anteckning (frivilligt)')" error="availability.note">
                                <input id="availability_note" type="text" maxlength="255" class="{{ $input }} mt-1" wire:model="availability.note">
                            </x-ui.field>
                            <div class="flex flex-wrap items-center gap-4">
                                <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                                <button type="button" class="text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
                                @if($editingAvailability)
                                    <button type="button" class="text-sm font-semibold text-red-800 underline" wire:click="deleteAvailability({{ $editingAvailability }})" wire:confirm="{{ __('Ta bort raden?') }}">{{ __('Ta bort') }}</button>
                                @endif
                            </div>
                        </form>
                    @endif

                    @if($availabilityRows->isNotEmpty())
                        <ul class="mt-3 divide-y divide-gray-100">
                            @foreach($availabilityRows as $row)
                                <li class="flex flex-wrap items-center gap-3 py-3 text-sm" wire:key="availability-{{ $row->id }}">
                                    <span class="min-w-0 flex-1 basis-48">
                                        <span class="block font-medium text-gray-900">{{ $row->isRecurring() ? __('Varje :weekday', ['weekday' => mb_strtolower($weekdays[$row->weekday] ?? '')]) : $day($row->date) }}</span>
                                        <span class="block text-gray-600">{{ Availability::kindLabel($row->kind) }} · {{ $row->timeLabel() }}@if($row->note) · {{ $row->note }}@endif</span>
                                    </span>
                                    <button type="button" class="text-xs font-semibold text-accent-700 underline" wire:click="editAvailability({{ $row->id }})">{{ __('Ändra') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            @endif

            <x-ui.card>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Schemat i din kalender') }}</p>
                <p class="mt-1 text-sm text-gray-600">{{ __('Prenumerera på dina publicerade pass i telefonens kalender. Länken är hemlig: den som har den ser dina pass.') }}</p>
                @if($icalUrl)
                    <div class="mt-3 space-y-2" x-data="{ copied: false }">
                        <code class="block break-all rounded-lg bg-gray-50 px-3 py-2 font-mono text-xs text-gray-900" data-ical-url>{{ $icalUrl }}</code>
                        <div class="flex flex-wrap gap-3">
                            <button type="button" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                x-on:click="navigator.clipboard.writeText(@js($icalUrl)); copied = true; setTimeout(() => copied = false, 2000)">
                                <span x-show="! copied">{{ __('Kopiera länken') }}</span><span x-show="copied" x-cloak>{{ __('Kopierad') }}</span>
                            </button>
                            <a href="{{ preg_replace('#^https?://#', 'webcal://', $icalUrl) }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Öppna i kalendern') }}</a>
                        </div>
                        <p class="text-xs text-gray-500">{{ __('Länken visas bara nu. Behöver du den igen skapar du en ny; den gamla slutar då att fungera.') }}</p>
                    </div>
                @elseif($ical)
                    <p class="mt-3 text-sm text-gray-700">{{ __('Du har en kalenderlänk sedan :date.', ['date' => $ical->created_at->setTimezone(App\Modules\Planning\Support\LocalTimestamp::ZONE)->toDateString()]) }}</p>
                @endif
                <div class="mt-3 flex flex-wrap gap-3">
                    <x-secondary-button type="button" wire:click="issueIcal" :wire:confirm="$ical ? __('Skapa en ny länk? Den gamla slutar fungera.') : null">{{ $ical ? __('Skapa ny länk') : __('Skapa kalenderlänk') }}</x-secondary-button>
                    @if($ical)
                        <button type="button" class="text-sm font-semibold text-red-800 underline" wire:click="revokeIcal" wire:confirm="{{ __('Stänga av kalenderlänken? Kalendrar som använder den slutar uppdateras.') }}">{{ __('Stäng av länken') }}</button>
                    @endif
                </div>
            </x-ui.card>
        </div>
    @endif
</div>
