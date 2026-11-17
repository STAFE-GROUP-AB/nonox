@use('App\Modules\Planning\Models\Message')
@php
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $locale = app()->getLocale();
    $date = fn ($day) => $day->locale($locale)->isoFormat('D MMM YYYY');
    $range = fn ($message) => $message->date_from->equalTo($message->date_to) ? $date($message->date_from) : $date($message->date_from).' – '.$date($message->date_to);
    $scope = $form['scope'];
@endphp
<div class="grid gap-8 lg:grid-cols-3">
    <div class="space-y-4">
        <x-ui.card :title="__('Nytt meddelande')">
            <form wire:submit="send" class="space-y-4" data-message-form>
                <x-ui.field for="message-scope" :label="__('Till')" error="form.scope">
                    <select id="message-scope" wire:model.live="form.scope" class="{{ $input }}">
                        <option value="{{ Message::SHIFT }}">{{ __('Personen på ett pass') }}</option>
                        <option value="{{ Message::DAY }}">{{ __('Alla som jobbar en dag') }}</option>
                        <option value="{{ Message::PERIOD }}">{{ __('Alla som jobbar under en period') }}</option>
                        <option value="{{ Message::AREA }}">{{ __('Alla som jobbar på en avdelning') }}</option>
                        <option value="{{ Message::ALL }}">{{ __('Alla anställda') }}</option>
                    </select>
                </x-ui.field>

                @if($scope === Message::SHIFT)
                    <x-ui.field for="message-shift-date" :label="__('Dag')" error="form.shift_date">
                        <input id="message-shift-date" type="date" wire:model.live="form.shift_date" class="{{ $input }}">
                    </x-ui.field>
                    <x-ui.field for="message-shift" :label="__('Pass')" error="form.shift_id">
                        <select id="message-shift" wire:model.live="form.shift_id" class="{{ $input }}">
                            <option value="">{{ $dayShifts->isEmpty() ? __('Inga publicerade pass den dagen') : __('Välj ett pass') }}</option>
                            @foreach($dayShifts as $shift)
                                <option value="{{ $shift->id }}">{{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }} · {{ trim(($shift->employee?->first_name ?? '').' '.($shift->employee?->last_name ?? '')) }}@if($shift->area_id && $areaNames->get($shift->area_id)) · {{ $areaNames->get($shift->area_id) }}@endif</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @elseif($scope === Message::DAY)
                    <x-ui.field for="message-day" :label="__('Dag')" error="form.date_from">
                        <input id="message-day" type="date" wire:model.live="form.date_from" class="{{ $input }}">
                    </x-ui.field>
                @else
                    @if($scope === Message::AREA)
                        <x-ui.field for="message-area" :label="__('Avdelning')" error="form.area_id">
                            <select id="message-area" wire:model.live="form.area_id" class="{{ $input }}">
                                <option value="">{{ __('Välj en avdelning') }}</option>
                                @foreach($areas->whereNull('archived_at') as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    @endif
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field for="message-from" :label="__('Från')" error="form.date_from">
                            <input id="message-from" type="date" wire:model.live="form.date_from" class="{{ $input }}">
                        </x-ui.field>
                        <x-ui.field for="message-to" :label="__('Till och med')" error="form.date_to">
                            <input id="message-to" type="date" wire:model.live="form.date_to" class="{{ $input }}">
                        </x-ui.field>
                    </div>
                @endif

                <p class="text-sm text-gray-700" data-audience>
                    @if($audience === null)
                        {{ $scope === Message::ALL ? __('Välj perioden meddelandet gäller.') : __('Välj vad meddelandet gäller, så ser du hur många det når.') }}
                    @elseif($audience === 0)
                        <span class="text-red-800">{{ $scope === Message::ALL ? __('Ingen är anställd under perioden.') : __('Ingen har ett publicerat pass i det du valt.') }}</span>
                    @else
                        {{ trans_choice('{1} Meddelandet når 1 person.|[2,*] Meddelandet når :count personer.', $audience, ['count' => $audience]) }}
                    @endif
                </p>

                <x-ui.field for="message-body" :label="__('Meddelande')" :hint="__('Skriv inte om hälsa, sjukdom eller andra känsliga uppgifter här.')" error="form.body">
                    <textarea id="message-body" wire:model="form.body" rows="6" maxlength="{{ Message::MAX_BODY }}" class="{{ $input }}" required></textarea>
                </x-ui.field>
                <label class="inline-flex items-start gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="form.requires_ack" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500">
                    <span>{{ __('Be om bekräftelse') }}<span class="block text-xs text-gray-500">{{ __('Personalen trycker "Jag har läst" och du ser vilka som har gjort det.') }}</span></span>
                </label>
                <p class="text-xs text-gray-500">{{ __('Mottagarna bestäms när du skickar. Ändras schemat efteråt får ingen ny person meddelandet. De som har inloggning får en notis och ett e-postmeddelande om att det finns ett meddelande, utan texten.') }}</p>
                <x-button type="submit" wire:loading.attr="disabled">{{ __('Skicka') }}</x-button>
            </form>
        </x-ui.card>
    </div>

    <div class="space-y-6 lg:col-span-2">
        <x-ui.card :title="__('Skickade meddelanden')" :description="__('Meddelandena tas bort tolv månader efter den sista dagen de gäller. De har ingen svarstråd; behöver ni prata, starta en chatt med knappen Chatt.')">
            <ul class="divide-y divide-stone-100" data-messages>
                @forelse($messages as $message)
                    <li wire:key="message-{{ $message->id }}" class="space-y-2 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1 basis-60">
                                <p class="text-xs text-gray-500">
                                    <span class="font-semibold text-gray-700">{{ Message::scopeLabel($message->scope) }}</span>
                                    @if($message->area_id) · {{ $areaNames->get($message->area_id) }}@endif
                                    · {{ $range($message) }}
                                    · {{ __('skickat :time', ['time' => $message->created_at?->setTimezone('Europe/Stockholm')->format('Y-m-d H:i')]) }}@if($message->author) {{ __('av :name', ['name' => $message->author->name]) }}@endif
                                </p>
                                <p class="mt-1 whitespace-pre-line text-sm text-ink">{{ $message->body }}</p>
                            </div>
                            <span class="flex gap-3 text-sm font-semibold">
                                <button type="button" wire:click="show({{ $message->id }})" class="text-accent-700 underline" aria-expanded="{{ $showing === $message->id ? 'true' : 'false' }}">{{ $showing === $message->id ? __('Dölj mottagare') : __('Visa mottagare') }}</button>
                                <button type="button" wire:click="delete({{ $message->id }})" wire:confirm="{{ __('Ta bort meddelandet? Personalen ser det inte längre, och du ser inte vilka som har läst det.') }}" class="text-red-800 underline">{{ __('Ta bort') }}</button>
                            </span>
                        </div>
                        <p class="text-xs tabular-nums text-gray-600" data-receipts="{{ $message->id }}">
                            {{ __('Läst av :read av :total', ['read' => $message->read_count, 'total' => $message->recipients_count]) }}
                            @if($message->requires_ack) · {{ __('Bekräftat av :count', ['count' => $message->acknowledged_count]) }}@endif
                            @if($message->unreachable_count > 0) · <span class="text-accent-800">{{ trans_choice('{1} 1 nås inte|[2,*] :count nås inte', $message->unreachable_count, ['count' => $message->unreachable_count]) }}</span>@endif
                        </p>
                        @if($showing === $message->id)
                            <ul class="mt-2 divide-y divide-gray-100 rounded-lg bg-gray-50 px-3 text-sm" data-recipients>
                                @foreach($recipients as $recipient)
                                    <li wire:key="recipient-{{ $recipient->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                                        <span class="font-medium text-gray-900">{{ trim(($recipient->employee?->first_name ?? '').' '.($recipient->employee?->last_name ?? '')) }}</span>
                                        <span class="text-xs tabular-nums text-gray-600">
                                            @if($recipient->read_at === null && $recipient->notified_at === null)
                                                {{ __('Nås inte (ingen inloggning)') }}
                                            @elseif($recipient->read_at === null)
                                                {{ __('Inte läst') }}
                                            @else
                                                {{ __('Läst :time', ['time' => $recipient->read_at->format('Y-m-d H:i')]) }}
                                                @if($message->requires_ack)
                                                    · {{ $recipient->acknowledged_at ? __('Bekräftat :time', ['time' => $recipient->acknowledged_at->format('Y-m-d H:i')]) : __('Inte bekräftat') }}
                                                @endif
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @empty
                    <li class="py-2"><x-ui.empty-state :title="__('Inga meddelanden ännu')">{{ __('Skriv till exempel till alla som jobbar på lördag att leveransen kommer en timme tidigare.') }}</x-ui.empty-state></li>
                @endforelse
            </ul>
        </x-ui.card>
        <p class="text-xs text-gray-500">{{ __('Läst och bekräftat visas bara per meddelande, för att du ska veta att beskedet har kommit fram. Det räknas inte ihop per person.') }}</p>
    </div>
</div>
