@php
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $chip = 'inline-flex min-h-11 items-center rounded-xl px-4 text-sm font-medium';
    $money = fn (int $ore) => App\Support\Money\Money::ofOre($ore);
    $zone = 'Europe/Stockholm';
    $today = $now->setTimezone($zone)->toDateString();
    $activeStage = $stages->first()?->id;
@endphp

<div class="space-y-6">
    {{-- Filters: pipeline, owner and the deals without a next step. --}}
    <div class="flex flex-wrap items-end gap-3">
        @if($pipelines->count() > 1)
            <x-ui.field for="board_pipeline" :label="__('Säljtratt')">
                <select id="board_pipeline" class="{{ $input }} mt-1" wire:model.live="pipelineId">
                    @foreach($pipelines as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        @endif
        <x-ui.field for="board_owner" :label="__('Ansvarig')">
            <select id="board_owner" class="{{ $input }} mt-1" wire:model.live="owner">
                <option value="">{{ __('Alla') }}</option>
                <option value="mina">{{ __('Mina affärer') }}</option>
                @foreach($members as $member)
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endforeach
            </select>
        </x-ui.field>
        <button type="button" wire:click="$set('show', '{{ $show === 'utan-steg' ? '' : 'utan-steg' }}')" @class([$chip, 'bg-ink text-white' => $show === 'utan-steg', 'bg-white text-ink ring-1 ring-stone-200' => $show !== 'utan-steg']) aria-pressed="{{ $show === 'utan-steg' ? 'true' : 'false' }}">{{ __('Utan nästa steg') }}</button>
        @if($canManage && $pipeline)
            <x-button type="button" class="ms-auto min-h-11" wire:click="startCreate">{{ __('Ny affär') }}</x-button>
        @endif
    </div>

    {{-- New deal, with the next step it must have. --}}
    @if($creating)
        <x-ui.card :title="__('Ny affär')" :description="__('Varje affär får ett nästa steg direkt, så att den inte glöms bort.')">
            <form wire:submit="create" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="deal_account" :label="__('Kund eller prospekt')" error="account_id" class="sm:col-span-2">
                    @if(($form['account_id'] ?? '') !== '')
                        <span class="mt-1 flex min-h-11 flex-wrap items-center gap-3">
                            <span class="font-medium text-ink">{{ $accountSearch }}</span>
                            <button type="button" class="text-sm text-accent-600 underline" wire:click="$set('form.account_id', '')">{{ __('Byt') }}</button>
                        </span>
                    @else
                        <input id="deal_account" type="search" class="{{ $input }} mt-1" wire:model.live.debounce.300ms="accountSearch" placeholder="{{ __('Sök på namn') }}" autocomplete="off">
                        @if($matches->isNotEmpty())
                            <ul class="mt-2 divide-y divide-gray-100 rounded-lg ring-1 ring-stone-200">
                                @foreach($matches as $match)
                                    <li wire:key="match-{{ $match->id }}">
                                        <button type="button" class="block min-h-11 w-full px-3 py-2 text-left text-sm hover:bg-stone-50" wire:click="pickAccount({{ $match->id }})">{{ $match->name }}@if($match->city) <span class="text-gray-500">· {{ $match->city }}</span>@endif</button>
                                    </li>
                                @endforeach
                            </ul>
                        @elseif(mb_strlen(trim($accountSearch)) >= 2)
                            <p class="mt-2 text-sm text-gray-600">{{ __('Ingen träff. Lägg till prospektet under Kunder först.') }}</p>
                        @endif
                    @endif
                </x-ui.field>
                <x-ui.field for="deal_title" :label="__('Rubrik')" error="title" class="sm:col-span-2">
                    <input id="deal_title" type="text" class="{{ $input }} mt-1" wire:model="form.title" placeholder="{{ __('Till exempel: ramavtal 2027') }}">
                </x-ui.field>
                <x-ui.field for="deal_value" :label="__('Värde i kronor, utan moms')" :error="$errors->has('value') ? 'value' : 'value_ore'">
                    <input id="deal_value" type="text" inputmode="decimal" class="{{ $input }} mt-1 tabular-nums" wire:model="form.value" placeholder="0">
                </x-ui.field>
                <x-ui.field for="deal_close" :label="__('Väntat avslut')" error="expected_close_date">
                    <input id="deal_close" type="date" class="{{ $input }} mt-1" wire:model="form.expected_close_date">
                </x-ui.field>
                <x-ui.field for="deal_stage" :label="__('Steg')" error="stage_id">
                    <select id="deal_stage" class="{{ $input }} mt-1" wire:model="form.stage_id">
                        <option value="">{{ $stages->first()?->name }}</option>
                        @foreach($stages->skip(1) as $stage)
                            <option value="{{ $stage->id }}">{{ $stage->name }} ({{ $stage->probability }} %)</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="deal_owner" :label="__('Ansvarig')" error="owner_id">
                    <select id="deal_owner" class="{{ $input }} mt-1" wire:model="form.owner_id">
                        @foreach($members as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                @if($people->isNotEmpty())
                    <x-ui.field for="deal_person" :label="__('Kontaktperson')" error="person_id">
                        <select id="deal_person" class="{{ $input }} mt-1" wire:model="form.person_id">
                            <option value="">{{ __('Ingen särskild') }}</option>
                            @foreach($people as $person)
                                <option value="{{ $person->id }}">{{ $person->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <fieldset class="grid gap-4 rounded-2xl bg-stone-50 p-4 sm:col-span-2 sm:grid-cols-3">
                    <legend class="px-1 text-sm font-semibold text-ink">{{ __('Nästa steg') }}</legend>
                    <x-ui.field for="deal_next_type" :label="__('Typ')" error="next_step.type">
                        <select id="deal_next_type" class="{{ $input }} mt-1" wire:model="form.next_type">
                            @foreach($types as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="deal_next_subject" :label="__('Vad')" error="subject">
                        <input id="deal_next_subject" type="text" class="{{ $input }} mt-1" wire:model="form.next_subject" placeholder="{{ __('Till exempel: boka ett möte') }}">
                    </x-ui.field>
                    <x-ui.field for="deal_next_due" :label="__('När')" :error="$errors->has('due_at') ? 'due_at' : 'next_step.due_at'">
                        <input id="deal_next_due" type="datetime-local" class="{{ $input }} mt-1" wire:model="form.next_due_at">
                    </x-ui.field>
                    @error('person_id')<p class="text-sm text-accent-700 sm:col-span-3">{{ $message }}</p>@enderror
                </fieldset>
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Skapa affären') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closeForm">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if(! $pipeline)
        <x-ui.card>
            <x-ui.empty-state :title="__('Ingen säljtratt ännu')">{{ $canSettings ? __('Lägg till en säljtratt under CRM → Inställningar.') : __('Be ägaren eller en administratör att lägga till en säljtratt.') }}</x-ui.empty-state>
        </x-ui.card>
    @else
        @if($truncated)
            <p class="text-sm text-gray-600">{{ __('Tavlan visar de :count affärer som ska avslutas först. Välj en ansvarig för att se färre.', ['count' => App\Modules\Crm\Livewire\DealsBoard::LIMIT]) }}</p>
        @endif

        {{-- The board. On the phone one stage at a time, chosen with the switcher. --}}
        <div x-data="{ active: {{ (int) $activeStage }}, over: null }" class="space-y-4">
            <div class="flex gap-2 overflow-x-auto pb-1 sm:hidden" role="group" aria-label="{{ __('Visa steg') }}">
                @foreach($stages as $stage)
                    <button type="button" class="{{ $chip }} flex-none" x-on:click="active = {{ $stage->id }}" :class="active === {{ $stage->id }} ? 'bg-ink text-white' : 'bg-white text-ink ring-1 ring-stone-200'" :aria-pressed="(active === {{ $stage->id }}).toString()">
                        {{ $stage->name }} ({{ $figures[$stage->id]['count'] ?? 0 }})
                    </button>
                @endforeach
            </div>

            <div class="flex gap-4 overflow-x-auto pb-2" data-testid="crm-board">
                @foreach($stages as $stage)
                    @php $sum = $figures[$stage->id] ?? ['count' => 0, 'value' => 0, 'weighted' => 0]; @endphp
                    <section wire:key="stage-{{ $stage->id }}"
                        class="w-full flex-none flex-col rounded-3xl bg-stone-100 p-3 sm:flex sm:w-52 2xl:w-72"
                        :class="{ 'hidden': active !== {{ $stage->id }}, 'flex': active === {{ $stage->id }}, 'ring-2 ring-accent-500': over === {{ $stage->id }} }"
                        @if($canManage)
                            x-on:dragover.prevent="over = {{ $stage->id }}"
                            x-on:dragleave="over = null"
                            x-on:drop.prevent="over = null; $wire.move(parseInt($event.dataTransfer.getData('text/plain')), {{ $stage->id }})"
                        @endif
                        aria-label="{{ $stage->name }}">
                        <header class="px-2 pb-3">
                            <h2 class="flex items-baseline justify-between gap-2 font-semibold text-ink">
                                <span>{{ $stage->name }}</span>
                                <span class="text-xs font-normal text-gray-500">{{ $stage->probability }} %</span>
                            </h2>
                            <p class="mt-1 text-xs text-gray-600">
                                {{ trans_choice(':count affär|:count affärer', $sum['count'], ['count' => $sum['count']]) }} ·
                                <x-ui.amount :money="$money($sum['value'])" /> ·
                                {{ __('viktat') }} <x-ui.amount :money="$money($sum['weighted'])" />
                            </p>
                        </header>
                        <ul class="space-y-3">
                            @forelse($byStage->get($stage->id, collect()) as $deal)
                                @php
                                    $nextDue = $deal->next_due_at === null ? null : \Carbon\CarbonImmutable::parse($deal->next_due_at);
                                    $missing = (int) $deal->open_steps === 0;
                                    $late = $nextDue !== null && $nextDue->lessThan($now);
                                    $closeLate = $deal->expected_close_date !== null && $deal->expected_close_date->toDateString() < $today;
                                @endphp
                                <li wire:key="deal-{{ $deal->id }}" class="rounded-2xl bg-white p-3 shadow-xs ring-1 ring-stone-200"
                                    @if($canManage) draggable="true" x-on:dragstart="$event.dataTransfer.setData('text/plain', '{{ $deal->id }}'); $event.dataTransfer.effectAllowed = 'move'" @endif
                                    data-testid="crm-deal-card">
                                    <a href="{{ route('crm.deals.show', $deal) }}" class="block font-medium text-gray-900 hover:text-accent-700">{{ $deal->title }}</a>
                                    <span class="block text-sm text-gray-600">{{ $deal->account?->name }}</span>
                                    <span class="mt-2 flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
                                        <x-ui.amount :money="$money($deal->value_ore)" class="font-semibold text-ink" />
                                        @if($deal->expected_close_date)
                                            <span @class(['text-xs', 'font-medium text-accent-700' => $closeLate, 'text-gray-500' => ! $closeLate])>{{ __('Avslut') }} {{ $deal->expected_close_date->translatedFormat('j M') }}</span>
                                        @endif
                                    </span>
                                    <span @class(['mt-1 block text-xs', 'font-medium text-accent-700' => $missing || $late, 'text-gray-500' => ! $missing && ! $late])>
                                        @if($missing)
                                            {{ __('Nästa steg saknas') }}
                                        @elseif($nextDue)
                                            {{ __('Nästa steg') }} {{ $nextDue->setTimezone($zone)->translatedFormat('j M') }}@if($late) · {{ __('försenat') }}@endif
                                        @else
                                            {{ __('Nästa steg utan datum') }}
                                        @endif
                                        @if($deal->owner) · {{ $deal->owner->name }}@endif
                                    </span>
                                    @if($canManage && $stages->count() > 1)
                                        {{-- Choosing a stage moves nothing; the button does (WCAG 3.2.2: arrow keys in a select must not act). --}}
                                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-600" x-data="{ to: '{{ $deal->stage_id }}' }">
                                            <select class="min-h-11 w-full min-w-0 flex-1 basis-full rounded-lg border-gray-300 py-1 text-xs focus:border-accent-500 focus:ring-accent-500 sm:min-h-0" aria-label="{{ __('Steg att flytta :title till', ['title' => $deal->title]) }}" x-model="to">
                                                @foreach($stages as $option)
                                                    <option value="{{ $option->id }}" @selected($option->id === $deal->stage_id)>{{ $option->name }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" class="inline-flex min-h-11 items-center rounded-lg bg-stone-100 px-3 font-medium text-ink hover:bg-stone-200 disabled:opacity-40 sm:min-h-8" x-bind:disabled="to === '{{ $deal->stage_id }}'" x-on:click="$wire.move({{ $deal->id }}, parseInt(to))" aria-label="{{ __('Flytta :title', ['title' => $deal->title]) }}">{{ __('Flytta') }}</button>
                                        </div>
                                    @endif
                                </li>
                            @empty
                                <li class="px-2 py-4 text-sm text-gray-500">{{ __('Inga affärer i det här steget.') }}</li>
                            @endforelse
                        </ul>
                    </section>
                @endforeach
            </div>
        </div>
    @endif
</div>
