@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-8">
    @if($canManage && $editing === null)
        <div><x-button type="button" wire:click="create">{{ __('Lägg till aktieägare') }}</x-button></div>
    @endif

    @if($editing !== null)
        <x-ui.card :title="$editing === 0 ? __('Ny aktieägare') : __('Ändra aktieägare')" :description="$editingInRegister ? __('Ägaren finns i aktieboken. De nya uppgifterna förs in som en händelse, och de gamla finns kvar i historiken.') : null">
            <form wire:submit="save" class="mt-4 grid gap-5 sm:grid-cols-2">
                <x-ui.field for="holder-kind" :label="__('Typ')" error="form.kind">
                    <select id="holder-kind" wire:model.live="form.kind" class="{{ $input }}">
                        <option value="PERSON">{{ __('Fysisk person') }}</option>
                        <option value="ENTITY">{{ __('Juridisk person') }}</option>
                    </select>
                </x-ui.field>
                <x-ui.field for="holder-name" :label="__('Namn')" error="form.name">
                    <input id="holder-name" wire:model="form.name" class="{{ $input }}" required>
                </x-ui.field>
                @if(($form['kind'] ?? 'PERSON') === 'PERSON')
                    <x-ui.field for="holder-pnr" :label="__('Personnummer')" error="form.personal_number" :hint="__('Tolv siffror, ÅÅÅÅMMDD-NNNN. Saknar personen svenskt personnummer anger du ett annat id-nummer nedan.')">
                        <input id="holder-pnr" wire:model="form.personal_number" class="{{ $input }}" inputmode="numeric" autocomplete="off">
                    </x-ui.field>
                @else
                    <x-ui.field for="holder-org" :label="__('Organisationsnummer')" error="form.org_number">
                        <input id="holder-org" wire:model="form.org_number" class="{{ $input }}" inputmode="numeric">
                    </x-ui.field>
                @endif
                <x-ui.field for="holder-other" :label="__('Annat id-nummer')" error="form.other_id" :hint="__('Till exempel ett utländskt id-nummer, med landet som utfärdat det.')">
                    <div class="grid grid-cols-3 gap-2">
                        <input id="holder-other" wire:model="form.other_id" class="{{ $input }} col-span-2" autocomplete="off">
                        <input id="holder-other-country" wire:model="form.other_id_country" class="{{ $input }}" maxlength="2" placeholder="{{ __('Land, t.ex. NO') }}" aria-label="{{ __('Land som utfärdat id-numret') }}">
                    </div>
                </x-ui.field>
                <x-ui.field for="holder-address" :label="__('Adress')" error="form.address_line1" class="sm:col-span-2">
                    <input id="holder-address" wire:model="form.address_line1" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="holder-postal" :label="__('Postnummer')" error="form.postal_code">
                    <input id="holder-postal" wire:model="form.postal_code" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="holder-city" :label="__('Ort')" error="form.city">
                    <input id="holder-city" wire:model="form.city" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="holder-country" :label="__('Land')" error="form.country">
                    <input id="holder-country" wire:model="form.country" class="{{ $input }}" maxlength="2">
                </x-ui.field>
                @if($editingInRegister)
                    <x-ui.field for="holder-effective" :label="__('Gäller från')" error="form.effective_date">
                        <input id="holder-effective" type="date" wire:model="form.effective_date" class="{{ $input }}" required>
                    </x-ui.field>
                @endif
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <x-button type="submit">{{ __('Spara') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('editing', null)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($holders->isEmpty())
        @if($editing === null)
            <x-ui.empty-state :title="__('Inga aktieägare ännu')">{{ __('Lägg till de personer och företag som äger aktier i bolaget. Sedan för du in bildandet eller aktieboken som den ser ut i dag.') }}</x-ui.empty-state>
        @endif
    @else
        <x-ui.card :title="__('Aktieägare')">
            <ul class="mt-4 divide-y divide-stone-200">
                @foreach($holders as $holder)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                        <div>
                            <p class="font-semibold text-ink">{{ $holder->name }} <span class="font-normal text-gray-600">· {{ $holder->kind->label() }}</span></p>
                            <p class="text-gray-600">{{ \App\Modules\Boardroom\Models\Shareholder::identity($holder->snapshot(), true) }} · {{ \App\Modules\Boardroom\Models\Shareholder::address($holder->snapshot()) }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-4">
                            <span class="tabular-nums text-gray-700">{{ __(':count aktier', ['count' => number_format($shares[$holder->id] ?? 0, 0, ',', ' ')]) }}</span>
                            <a href="{{ route('privacy.export.shareholder', $holder) }}" class="font-semibold text-accent-700 underline">{{ __('Registerutdrag') }}</a>
                            @if($canManage && $holder->kind !== \App\Modules\Boardroom\Domain\HolderKind::CompanyItself)
                                <button type="button" wire:click="edit({{ $holder->id }})" class="font-semibold text-accent-700 underline">{{ __('Ändra') }}</button>
                                @unless(in_array($holder->id, $referenced, true))
                                    <button type="button" wire:click="delete({{ $holder->id }})" wire:confirm="{{ __('Ta bort aktieägaren? Det går bara när ägaren inte finns i aktieboken.') }}" class="font-semibold text-red-800 underline">{{ __('Ta bort') }}</button>
                                @endunless
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Byrå som för aktieboken')" :description="__('Styrelsen kan bemyndiga någon att föra aktieboken (aktiebolagslagen 5 kap. 9 §). En redovisningskonsult från byrån kan föra in händelser först när bemyndigandet är registrerat här. Ansvaret stannar hos styrelsen.')">
        @if($register?->firm_authorised_on)
            <p class="mt-4 text-sm text-gray-700">{{ __('Styrelsen bemyndigade byrån :date. Underlag: :reference.', ['date' => $register->firm_authorised_on->toDateString(), 'reference' => $register->firm_authorisation_reference]) }}</p>
            @if($mayAuthorise)
                <button type="button" wire:click="withdraw" wire:confirm="{{ __('Återkalla bemyndigandet? Byråns konsulter kan då inte längre föra in händelser.') }}" class="mt-3 text-sm font-semibold text-red-800 underline">{{ __('Återkalla bemyndigandet') }}</button>
            @endif
        @else
            <p class="mt-4 text-sm text-gray-700">{{ __('Inget bemyndigande är registrerat.') }}</p>
            @if($mayAuthorise)
                <form wire:submit="authorise" class="mt-4 grid gap-5 sm:grid-cols-2">
                    <x-ui.field for="auth-date" :label="__('Styrelsens beslut')" error="authorisation.decided_on">
                        <input id="auth-date" type="date" wire:model="authorisation.decided_on" class="{{ $input }}" required>
                    </x-ui.field>
                    <x-ui.field for="auth-reference" :label="__('Var beslutet finns')" error="authorisation.reference" :hint="__('Till exempel Styrelseprotokoll 2026-11-01 § 4.')">
                        <input id="auth-reference" wire:model="authorisation.reference" class="{{ $input }}" required>
                    </x-ui.field>
                    <div class="sm:col-span-2"><x-button type="submit">{{ __('Registrera bemyndigandet') }}</x-button></div>
                </form>
            @endif
        @endif
    </x-ui.card>
</div>
