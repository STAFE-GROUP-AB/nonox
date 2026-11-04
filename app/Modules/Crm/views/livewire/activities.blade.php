@php
    $input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $zone = 'Europe/Stockholm';
    $groups = [
        ['title' => __('Försenade'), 'items' => $overdue, 'late' => true],
        ['title' => __('I dag'), 'items' => $today, 'late' => false],
        ['title' => __('Kommande'), 'items' => $upcoming, 'late' => false],
        ['title' => __('Utan datum'), 'items' => $undated, 'late' => false],
    ];
    $empty = $overdue->isEmpty() && $today->isEmpty() && $upcoming->isEmpty() && $undated->isEmpty();
@endphp

<div class="space-y-6">
    <p class="text-sm"><a href="{{ route('crm.calendar') }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Se dina aktiviteter i din egen kalender') }}</a></p>
    @if($canManage)
        <div class="flex flex-wrap gap-2 text-sm" role="group" aria-label="{{ __('Vems aktiviteter') }}">
            <button type="button" wire:click="$set('who', '')" @class(['inline-flex min-h-11 items-center rounded-xl px-4 font-medium', 'bg-ink text-white' => $who === '', 'bg-white text-ink ring-1 ring-stone-200' => $who !== '']) aria-pressed="{{ $who === '' ? 'true' : 'false' }}">{{ __('Mina') }}</button>
            <button type="button" wire:click="$set('who', 'alla')" @class(['inline-flex min-h-11 items-center rounded-xl px-4 font-medium', 'bg-ink text-white' => $who === 'alla', 'bg-white text-ink ring-1 ring-stone-200' => $who !== 'alla']) aria-pressed="{{ $who === 'alla' ? 'true' : 'false' }}">{{ __('Allas') }}</button>
        </div>
    @endif

    @if($nextAccount)
        <x-ui.card :title="__('Nästa steg för :name', ['name' => $nextAccount->name])" :description="$nextRequired ? __('Det här är affärens enda planerade steg. Planera nästa, så klarmarkeras steget samtidigt. Eller avsluta affären som vunnen eller förlorad.') : __('Planera vad som ska hända härnäst, eller hoppa över.')">
            <form wire:submit="saveNext" class="grid gap-4 sm:grid-cols-3">
                <x-ui.field for="next_type" :label="__('Typ')" error="type">
                    <select id="next_type" class="{{ $input }} mt-1" wire:model="next.type">
                        @foreach($types as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="next_subject" :label="__('Rubrik')" error="subject">
                    <input id="next_subject" type="text" class="{{ $input }} mt-1" wire:model="next.subject" placeholder="{{ __('Till exempel: följ upp offerten') }}">
                </x-ui.field>
                <x-ui.field for="next_due" :label="__('När')" error="due_at">
                    <input id="next_due" type="datetime-local" class="{{ $input }} mt-1" wire:model="next.due_at">
                </x-ui.field>
                @error('next')<p class="text-sm text-accent-700 sm:col-span-3">{{ $message }}</p>@enderror
                <div class="flex flex-wrap items-center gap-4 sm:col-span-3">
                    <x-button wire:loading.attr="disabled">{{ $nextRequired ? __('Klar och planera nästa') : __('Planera') }}</x-button>
                    @if($nextRequired)
                        <a href="{{ route('crm.deals.show', $nextDealId) }}" class="inline-flex min-h-11 items-center text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Avsluta affären') }}</a>
                        <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="skipNext">{{ __('Avbryt') }}</button>
                    @else
                        <button type="button" class="min-h-11 text-sm font-medium text-gray-700 underline" wire:click="skipNext">{{ __('Hoppa över') }}</button>
                    @endif
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($empty)
        <x-ui.card>
            <x-ui.empty-state :title="__('Inget att göra just nu')">{{ __('Planera samtal, möten och uppgifter på kundens kort, så hamnar de här.') }}</x-ui.empty-state>
        </x-ui.card>
    @endif

    @foreach($groups as $group)
        @continue($group['items']->isEmpty())
        <x-ui.card :title="$group['title'].' ('.$group['items']->count().')'">
            <ul class="divide-y divide-gray-100" data-testid="crm-activities">
                @foreach($group['items'] as $activity)
                    @php($number = $activity->person?->callNumber() ?? $activity->account?->phone)
                    <li wire:key="activity-{{ $activity->id }}" class="flex flex-wrap items-center gap-3 py-3">
                        @if($canManage)
                            <button type="button" class="inline-flex size-11 flex-none items-center justify-center rounded-full ring-2 ring-stone-300 hover:bg-stone-100 hover:ring-ink" wire:click="done({{ $activity->id }})" aria-label="{{ __('Klarmarkera :subject', ['subject' => $activity->subject]) }}" title="{{ __('Klar') }}">
                                <svg class="size-5 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 12l5 5 9-10" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        @endif
                        <span class="min-w-0 flex-1 basis-52">
                            <a href="{{ route('crm.accounts.show', $activity->account_id) }}" class="block font-medium text-gray-900 hover:text-accent-700">{{ $activity->subject }}</a>
                            <span class="block text-sm text-gray-600">
                                {{ $activity->type->label() }} · {{ $activity->account?->name }}@if($activity->person) · {{ $activity->person->name }}@if($activity->person->doNotContact()) ({{ __("Kontakta inte") }})@endif @endif
                                @if($who === 'alla' && $activity->assignee) · {{ $activity->assignee->name }}@endif
                            </span>
                            @if($activity->deal)
                                <a href="{{ route('crm.deals.show', $activity->deal) }}" class="block text-sm text-accent-600 hover:text-accent-700">{{ __('Affär') }}: {{ $activity->deal->title }}</a>
                            @endif
                            @if($activity->due_at)
                                <span @class(['block text-sm', 'font-medium text-accent-700' => $group['late'], 'text-gray-500' => ! $group['late']])>{{ $activity->due_at->setTimezone($zone)->translatedFormat('D j M H:i') }}</span>
                            @endif
                        </span>
                        @if($number && ! ($activity->person?->doNotContact() ?? false) && in_array($activity->type->value, ['CALL', 'TASK'], true))
                            <a href="tel:{{ preg_replace('/[^\d+]/', '', $number) }}" class="inline-flex min-h-11 items-center rounded-xl bg-stone-100 px-4 text-sm font-medium text-ink hover:bg-stone-200">{{ __('Ring') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endforeach
</div>
