@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $select = $input.' min-h-11 sm:min-h-0';
@endphp

<div class="space-y-6">
    @if($showForm)
        <x-ui.card :title="__('Ny ringlista av dina kunder och prospekt')" :description="__('Välj vilka som ska med. Vill du ringa företag som inte finns bland dina kunder än skapar du listan under Prospektera.')">
            <form wire:submit="create" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.field for="list-name" :label="__('Namn')" error="name">
                    <input id="list-name" type="text" class="{{ $input }} mt-1" wire:model="form.name" required>
                </x-ui.field>
                <x-ui.field for="list-assignee" :label="__('Säljare som ringer')" error="assigned_to">
                    <select id="list-assignee" class="{{ $select }} mt-1" wire:model="form.assigned_to">
                        @foreach($members as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="list-status" :label="__('Status')">
                    <select id="list-status" class="{{ $select }} mt-1" wire:model.live="form.status">
                        <option value="">{{ __('Alla') }}</option>
                        @foreach($statuses as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="list-city" :label="__('Ort')">
                    <select id="list-city" class="{{ $select }} mt-1" wire:model.live="form.city">
                        <option value="">{{ __('Alla') }}</option>
                        @foreach($cities as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="list-tag" :label="__('Tagg')">
                    <select id="list-tag" class="{{ $select }} mt-1" wire:model.live="form.tag">
                        <option value="">{{ __('Alla') }}</option>
                        @foreach($tags as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <label class="inline-flex min-h-11 items-center gap-2 self-end text-sm text-gray-700 sm:col-span-2"><input type="checkbox" class="size-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="includePrivate"> {{ __('Ta med privatpersoner och enskilda firmor (kontrollera numret mot NIX-Telefon innan du ringer i säljsyfte)') }}</label>
                <p class="self-end text-sm text-gray-700" aria-live="polite">{{ trans_choice('{0} Inget företag matchar.|{1} 1 företag kommer med.|[2,*] :count företag kommer med.', min($matching, App\Modules\Crm\Models\CallList::MAX_ITEMS), ['count' => min($matching, App\Modules\Crm\Models\CallList::MAX_ITEMS)]) }}</p>
                <div class="flex flex-wrap items-center gap-4 sm:col-span-2 lg:col-span-3">
                    <x-button wire:loading.attr="disabled" :disabled="$matching === 0">{{ __('Skapa ringlista') }}</x-button>
                    <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                </div>
                <x-input-error for="selection" class="sm:col-span-2 lg:col-span-3" />
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-3">
            @if($canManage && ! $showForm)
                <x-button type="button" wire:click="start">{{ __('Ny ringlista') }}</x-button>
                <a href="{{ route('crm.prospecting') }}" class="inline-flex min-h-11 items-center text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Hitta nya företag under Prospektera') }}</a>
            @endif
            <label class="ml-auto block text-sm text-gray-700">
                <span class="sr-only">{{ __('Visa') }}</span>
                <select class="{{ $select }}" wire:model.live="show">
                    <option value="alla">{{ __('Alla listor') }}</option>
                    <option value="mina">{{ __('Mina listor') }}</option>
                </select>
            </label>
            <label class="inline-flex min-h-11 items-center gap-2 text-sm text-gray-700"><input type="checkbox" class="size-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model.live="archived"> {{ __('Arkiverade') }}</label>
        </div>

        @if($lists->isEmpty())
            <x-ui.empty-state class="mt-6" :title="$archived ? __('Inga arkiverade ringlistor') : __('Inga ringlistor ännu')">
                {{ __('Skapa en lista av dina prospekt här, eller sök fram nya företag under Prospektera och gör en ringlista av träffarna.') }}
            </x-ui.empty-state>
        @else
            <ul class="mt-4 divide-y divide-gray-100" data-testid="call-lists">
                @foreach($lists as $list)
                    @php($share = $list->items_count > 0 ? (int) floor($list->done_count * 100 / $list->items_count) : 0)
                    <li wire:key="list-{{ $list->id }}" class="relative flex flex-wrap items-center gap-x-4 gap-y-2 py-4">
                        <span class="min-w-0 flex-1 basis-60">
                            <a href="{{ route('crm.call-lists.show', $list) }}" class="block font-medium text-ink after:absolute after:inset-0 hover:text-accent-700">{{ $list->name }}</a>
                            <span class="mt-1 block text-sm text-gray-600">{{ collect([$list->assignee?->name, $list->created_at?->translatedFormat('j M Y')])->filter()->implode(' · ') }}</span>
                        </span>
                        <span class="w-full text-sm sm:w-56">
                            <span class="block text-gray-700">{{ __(':done av :total klara', ['done' => $list->done_count, 'total' => $list->items_count]) }}</span>
                            <span class="mt-1 block h-2 overflow-hidden rounded-full bg-stone-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $share }}" aria-label="{{ __('Hur långt listan har kommit') }}"><span class="block h-2 rounded-full bg-accent-600" style="width: {{ $share }}%"></span></span>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
