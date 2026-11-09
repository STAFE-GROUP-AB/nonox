@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-8">
    @if($canManage && ! $adopting)
        <div><x-button type="button" wire:click="adopt">{{ $versions->isEmpty() ? __('För in bolagsordningen') : __('Ny version av bolagsordningen') }}</x-button></div>
    @endif

    @if($adopting)
        <x-ui.card :title="__('Ny version av bolagsordningen')" :description="__('En version ändras aldrig. Ändras bolagsordningen för du in en ny version, och den gäller från registreringen (aktiebolagslagen 3 kap. 5 §).')">
            <form wire:submit="save" class="mt-4 grid gap-5 sm:grid-cols-2">
                <x-ui.field for="art-adopted" :label="__('Antagen')" error="form.adopted_on">
                    <input id="art-adopted" type="date" wire:model="form.adopted_on" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="art-registered" :label="__('Registrerad')" error="form.registered_on" :hint="__('Lämna tomt tills Bolagsverket har registrerat den.')">
                    <input id="art-registered" type="date" wire:model="form.registered_on" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="art-seat" :label="__('Styrelsens säte')" error="form.seat">
                    <input id="art-seat" wire:model="form.seat" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="art-notice" :label="__('Så kallas aktieägarna')" error="form.notice_method" class="sm:col-span-2" :hint="__('Skriv av bolagsordningens bestämmelse, till exempel: Kallelse sker genom brev med post eller e-post till aktieägarna.')">
                    <textarea id="art-notice" wire:model="form.notice_method" rows="2" class="{{ $input }}" required></textarea>
                </x-ui.field>
                <label class="flex items-center gap-3 sm:col-span-2"><x-checkbox wire:model="form.short_notice_clause" /> <span>{{ __('Bolagsordningen tillåter kallelse senast två veckor före stämman') }}</span></label>
                <x-ui.field for="art-cap-min" :label="__('Lägsta aktiekapital (kr)')" error="form.share_capital_min">
                    <input id="art-cap-min" wire:model="form.share_capital_min" inputmode="decimal" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="art-cap-max" :label="__('Högsta aktiekapital (kr)')" error="form.share_capital_max">
                    <input id="art-cap-max" wire:model="form.share_capital_max" inputmode="decimal" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="art-shares-min" :label="__('Lägsta antal aktier')" error="form.shares_min">
                    <input id="art-shares-min" type="number" min="1" wire:model="form.shares_min" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="art-shares-max" :label="__('Högsta antal aktier')" error="form.shares_max">
                    <input id="art-shares-max" type="number" min="1" wire:model="form.shares_max" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="art-board-min" :label="__('Lägsta antal styrelseledamöter')" error="form.board_min">
                    <input id="art-board-min" type="number" min="1" wire:model="form.board_min" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="art-board-max" :label="__('Högsta antal styrelseledamöter')" error="form.board_max">
                    <input id="art-board-max" type="number" min="1" wire:model="form.board_max" class="{{ $input }}">
                </x-ui.field>
                <fieldset class="sm:col-span-2">
                    <legend class="text-sm font-medium text-gray-700">{{ __('Bestämmelser') }}</legend>
                    <div class="mt-2 space-y-2">
                        <label class="flex items-center gap-3"><x-checkbox wire:model="form.has_auditor" /> <span>{{ __('Bolaget ska ha revisor') }}</span></label>
                        <label class="flex items-center gap-3"><x-checkbox wire:model="form.pre_emption_clause" /> <span>{{ __('Förköpsförbehåll') }}</span></label>
                        <label class="flex items-center gap-3"><x-checkbox wire:model="form.consent_clause" /> <span>{{ __('Samtyckesförbehåll') }}</span></label>
                        <label class="flex items-center gap-3"><x-checkbox wire:model="form.redemption_clause" /> <span>{{ __('Hembudsförbehåll') }}</span></label>
                        <label class="flex items-center gap-3"><x-checkbox wire:model="form.conversion_clause" /> <span>{{ __('Omvandlingsförbehåll') }}</span></label>
                    </div>
                </fieldset>
                <x-ui.field for="art-other" :label="__('Övriga bestämmelser')" error="form.other_provisions" class="sm:col-span-2">
                    <textarea id="art-other" wire:model="form.other_provisions" rows="3" class="{{ $input }}"></textarea>
                </x-ui.field>
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <x-button type="submit" wire:confirm="{{ __('Spara bolagsordningen som en ny version? En version kan inte ändras efteråt.') }}">{{ __('Spara versionen') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('adopting', false)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @forelse($versions as $version)
        <x-ui.card :title="__('Version :version', ['version' => $version->version]).($applicable?->id === $version->id ? ' – '.__('gäller i dag') : '')" :description="__('Antagen :adopted', ['adopted' => $version->adopted_on->toDateString()]).' · '.($version->registered_on ? __('registrerad :date', ['date' => $version->registered_on->toDateString()]) : __('inte registrerad ännu'))">
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-600">{{ __('Så kallas aktieägarna') }}</dt><dd class="text-ink">{{ $version->notice_method }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Kallelse senast två veckor före') }}</dt><dd class="text-ink">{{ $version->short_notice_clause ? __('Ja') : __('Nej') }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Revisor') }}</dt><dd class="text-ink">{{ $version->has_auditor ? __('Ja') : __('Nej') }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Förbehåll') }}</dt><dd class="text-ink">{{ collect([$version->pre_emption_clause ? __('förköp') : null, $version->consent_clause ? __('samtycke') : null, $version->redemption_clause ? __('hembud') : null, $version->conversion_clause ? __('omvandling') : null])->filter()->join(', ') ?: __('Inga') }}</dd></div>
            </dl>
            @if($canManage && $version->registered_on === null)
                <form wire:submit="register({{ $version->id }})" class="mt-4 flex flex-wrap items-end gap-3">
                    <x-ui.field for="reg-{{ $version->id }}" :label="__('Registrerad hos Bolagsverket')" error="registering.registered_on">
                        <input id="reg-{{ $version->id }}" type="date" wire:model="registering.{{ $version->id }}" class="{{ $input }}" required>
                    </x-ui.field>
                    <x-button type="submit">{{ __('Spara registreringsdagen') }}</x-button>
                </form>
            @endif
        </x-ui.card>
    @empty
        @unless($adopting)
            <x-ui.empty-state :title="__('Ingen bolagsordning ännu')">{{ __('För in bolagsordningen, så kan NONOX föreslå datum för kallelser och visa hur aktieägarna ska kallas.') }}</x-ui.empty-state>
        @endunless
    @endforelse

    <x-ui.card :title="__('Aktieslag')" :description="__('Har bolaget bara ett aktieslag räcker det med ett. Med flera aktieslag ska varje aktie i aktieboken ha sitt slag.')">
        @if($classes->isEmpty())
            <p class="mt-4 text-sm text-gray-700">{{ __('Inga aktieslag ännu. Det första skapas när du för in de första aktierna.') }}</p>
        @else
            <ul class="mt-4 divide-y divide-stone-200 text-sm">
                @foreach($classes as $class)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                        <span><span class="font-semibold text-ink">{{ $class->code }}</span> {{ $class->name }} · {{ __(':votes röst per aktie', ['votes' => $class->votesLabel()]) }}</span>
                        @if($canManage)
                            <button type="button" wire:click="editClass({{ $class->id }})" class="font-semibold text-accent-700 underline">{{ __('Ändra') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        @if($canManage && $editingClass === null)
            <button type="button" wire:click="editClass" class="mt-4 text-sm font-semibold text-accent-700 underline">{{ __('Lägg till aktieslag') }}</button>
        @endif
        @if($editingClass !== null)
            <form wire:submit="saveClass" class="mt-4 grid gap-5 sm:grid-cols-3">
                <x-ui.field for="class-code" :label="__('Beteckning')" error="class.code">
                    <input id="class-code" wire:model="class.code" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="class-name" :label="__('Namn')" error="class.name">
                    <input id="class-name" wire:model="class.name" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="class-votes" :label="__('Röster per aktie')" error="class.votes">
                    <input id="class-votes" wire:model="class.votes" inputmode="decimal" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="class-restriction" :label="__('Förbehåll eller villkor')" error="class.restriction_text" class="sm:col-span-3">
                    <input id="class-restriction" wire:model="class.restriction_text" class="{{ $input }}">
                </x-ui.field>
                <div class="flex flex-wrap gap-3 sm:col-span-3">
                    <x-button type="submit">{{ __('Spara') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('editingClass', null)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        @endif
    </x-ui.card>
</div>
