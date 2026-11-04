@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $select = $input.' min-h-11 sm:min-h-0';
    $tab = 'inline-flex min-h-11 items-center rounded-full px-4 text-sm font-semibold ring-1';
    $sourceDate = collect($state['dates'])->max();
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Sätt att prospektera') }}">
        <button type="button" wire:click="$set('mode', 'sok')" aria-pressed="{{ $mode === 'sok' ? 'true' : 'false' }}" @class([$tab, 'bg-ink text-white ring-ink' => $mode === 'sok', 'bg-white text-ink ring-stone-200 hover:bg-stone-50' => $mode !== 'sok'])>{{ __('Sök i registret') }}</button>
        <button type="button" wire:click="$set('mode', 'liknande')" aria-pressed="{{ $mode === 'liknande' ? 'true' : 'false' }}" @class([$tab, 'bg-ink text-white ring-ink' => $mode === 'liknande', 'bg-white text-ink ring-stone-200 hover:bg-stone-50' => $mode !== 'liknande'])>{{ __('Liknar mina bästa kunder') }}</button>
    </div>

    @unless($state['loaded'])
        <x-ui.card :title="__('Företagsregistret är inte inläst')">
            <p class="text-sm text-gray-700">{{ __('Den här installationen har inte läst in företagsregistret än. Den som driftar NONOX läser in det med kommandot nedan. Det är gratis öppna data från SCB och Bolagsverket och tar några minuter.') }}</p>
            <pre class="mt-3 overflow-x-auto rounded-xl bg-stone-100 p-3 text-xs text-ink">php artisan registry:import scb-bulk
php artisan registry:import bolagsverket-bulk</pre>
        </x-ui.card>
    @endunless

    @if($mode === 'sok')
        <x-ui.card :title="__('Filter')" :description="__('Sök på namn eller organisationsnummer, eller välj minst ett filter. Sökningen och filtren gäller tillsammans. Bara företag i drift visas om du inte väljer annat.')">
            <div class="max-w-xl">
                <x-ui.field for="prospect-search" :label="__('Sök på namn eller organisationsnummer')" :hint="__('Minst två tecken, till exempel en del av namnet eller organisationsnumrets början, med eller utan bindestreck.')">
                    <input id="prospect-search" type="search" class="{{ $input }} mt-1" wire:model.live.debounce.400ms="search" autocomplete="off" maxlength="100">
                </x-ui.field>
                @if($shortSearch)
                    <p class="mt-1 text-sm text-gray-600" role="status">{{ __('Skriv minst två tecken för att söka.') }}</p>
                @endif
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.field for="prospect-division" :label="__('Bransch (SNI)')">
                    <select id="prospect-division" class="{{ $select }} mt-1" wire:model.live="division">
                        <option value="">{{ __('Alla branscher') }}</option>
                        @foreach($sections as $sectionCode => $sectionName)
                            <optgroup label="{{ $sectionCode }} {{ $sectionName }}">
                                @foreach($divisions as $divisionCode => $division)
                                    @if($division['section'] === (string) $sectionCode)
                                        <option value="{{ $divisionCode }}">{{ $divisionCode }} {{ $division['name'] }}</option>
                                    @endif
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="prospect-sni" :label="__('Detaljerad bransch')">
                    <select id="prospect-sni" class="{{ $select }} mt-1" wire:model.live="sni" @disabled($division === '')>
                        <option value="">{{ $division === '' ? __('Välj bransch först') : __('Hela huvudgruppen') }}</option>
                        @foreach($codes as $code => $name)
                            <option value="{{ $code }}">{{ $code }} {{ $name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="prospect-town" :label="__('Ort')" :hint="__('Postorten, till exempel Uppsala.')">
                    <input id="prospect-town" type="text" class="{{ $input }} mt-1" wire:model.live.debounce.500ms="town" autocomplete="off">
                </x-ui.field>
                <x-ui.field for="prospect-postal" :label="__('Postnummer börjar med')">
                    <input id="prospect-postal" type="text" inputmode="numeric" maxlength="6" class="{{ $input }} mt-1" wire:model.live.debounce.500ms="postal">
                </x-ui.field>
                @if($state['api'])
                    <x-ui.field for="prospect-county" :label="__('Län')">
                        <select id="prospect-county" class="{{ $select }} mt-1" wire:model.live="county">
                            <option value="">{{ __('Alla län') }}</option>
                            @foreach($counties as $code => $name)
                                <option value="{{ $code }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="prospect-municipality" :label="__('Kommun')">
                        <select id="prospect-municipality" class="{{ $select }} mt-1" wire:model.live="municipality">
                            <option value="">{{ __('Alla kommuner') }}</option>
                            @foreach($municipalities as $code => $name)
                                <option value="{{ $code }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <x-ui.field for="prospect-form" :label="__('Bolagsform')">
                    <select id="prospect-form" class="{{ $select }} mt-1" wire:model.live="form">
                        <option value="">{{ __('Alla bolagsformer') }}</option>
                        @foreach($forms as $code => $name)
                            <option value="{{ $code }}">{{ __($name) }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="prospect-from" :label="__('Registrerat från')">
                    <input id="prospect-from" type="date" class="{{ $input }} mt-1" wire:model.live="from">
                </x-ui.field>
                <x-ui.field for="prospect-to" :label="__('Registrerat till')">
                    <input id="prospect-to" type="date" class="{{ $input }} mt-1" wire:model.live="to">
                </x-ui.field>
                @if($state['api'])
                    <x-ui.field for="prospect-employees-min" :label="__('Anställda, minst')">
                        <select id="prospect-employees-min" class="{{ $select }} mt-1" wire:model.live="employeesMin">
                            <option value="">{{ __('Ingen gräns') }}</option>
                            @foreach($employeeClasses as $class => $label)
                                <option value="{{ $class }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="prospect-employees-max" :label="__('Anställda, högst')">
                        <select id="prospect-employees-max" class="{{ $select }} mt-1" wire:model.live="employeesMax">
                            <option value="">{{ __('Ingen gräns') }}</option>
                            @foreach($employeeClasses as $class => $label)
                                <option value="{{ $class }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="prospect-turnover-min" :label="__('Omsättning, minst')">
                        <select id="prospect-turnover-min" class="{{ $select }} mt-1" wire:model.live="turnoverMin">
                            <option value="">{{ __('Ingen gräns') }}</option>
                            @foreach($turnoverClasses as $class => $label)
                                <option value="{{ $class }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="prospect-turnover-max" :label="__('Omsättning, högst')">
                        <select id="prospect-turnover-max" class="{{ $select }} mt-1" wire:model.live="turnoverMax">
                            <option value="">{{ __('Ingen gräns') }}</option>
                            @foreach($turnoverClasses as $class => $label)
                                <option value="{{ $class }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
            </div>
            @unless($state['api'])
                <p class="mt-4 text-sm text-gray-600">{{ __('Antal anställda, omsättning, län och kommun kommer från SCB:s API och är inte inlästa på den här installationen.') }}</p>
            @endunless
            <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-gray-700">
                <label class="inline-flex min-h-11 items-center gap-2"><input type="checkbox" class="size-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model.live="all"> {{ __('Visa även företag som inte är i drift') }}</label>
                <label class="inline-flex min-h-11 items-center gap-2"><input type="checkbox" class="size-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model.live="blocked"> {{ __('Visa företag med reklamspärr') }}</label>
                @if($filtered)
                    <button type="button" class="min-h-11 font-medium text-accent-600 hover:text-accent-700" wire:click="clearFilters">{{ __('Rensa filter') }}</button>
                @endif
            </div>
        </x-ui.card>
    @else
        <x-ui.card :title="__('Dina bästa kunder')" :description="__('Kunderna som har fakturerats mest de senaste 12 månaderna. Välj vilka du vill hitta fler av. NONOX letar efter företag med samma bransch, ungefär samma storlek och i samma region, utan AI: bara uppgifterna i registret.')">
            @if($best->isEmpty())
                <p class="text-sm text-gray-600">{{ __('Inga kunder med organisationsnummer har fakturerats de senaste 12 månaderna. Sök i registret med filter i stället.') }}</p>
            @else
                <fieldset>
                    <legend class="sr-only">{{ __('Kunder att jämföra med') }}</legend>
                    <ul class="grid gap-2 sm:grid-cols-2">
                        @foreach($best as $customer)
                            @php($inRegister = $profiles->has((string) App\Models\RegistryCompany::digits($customer->org_number)))
                            <li wire:key="best-{{ $customer->account_id }}">
                                <label class="flex min-h-11 items-center gap-3 rounded-xl px-3 py-2 ring-1 ring-stone-200">
                                    <input type="checkbox" class="size-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" value="{{ $customer->account_id }}" wire:model.live="chosen">
                                    <span class="min-w-0 flex-1 text-sm">
                                        <span class="block truncate font-medium text-ink">{{ $customer->name }}</span>
                                        <span class="block text-gray-600"><x-ui.amount :money="App\Support\Money\Money::ofOre($customer->invoiced)" /> {{ __('senaste 12 mån') }}@if(in_array($customer->account_id, $chosenIds, true) && ! $inRegister) · {{ __('finns inte i registret') }}@endif</span>
                                    </span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                </fieldset>
                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <x-ui.field for="like-sni" :label="__('Bransch')">
                        <select id="like-sni" class="{{ $select }} mt-1" wire:model.live="likeSni">
                            <option value="code">{{ __('Samma SNI-kod (fem siffror)') }}</option>
                            <option value="division">{{ __('Samma huvudgrupp (två siffror)') }}</option>
                            <option value="">{{ __('Bransch spelar ingen roll') }}</option>
                        </select>
                    </x-ui.field>
                    <div class="text-sm text-gray-700">
                        <label class="inline-flex min-h-11 items-center gap-2"><input type="checkbox" class="size-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model.live="likeSize" @disabled(! $state['api']) aria-describedby="like-size-why"> {{ __('Ungefär lika många anställda') }}</label>
                        <p id="like-size-why" class="text-gray-600">{{ $state['api'] ? __('Samma storleksklass, en klass upp eller ned.') : __('Storleksklass kräver SCB:s API-nyckel och ingår inte här.') }}</p>
                    </div>
                    <div class="text-sm text-gray-700">
                        <label class="inline-flex min-h-11 items-center gap-2"><input type="checkbox" class="size-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model.live="likeRegion"> {{ $state['api'] ? __('Samma län') : __('Samma ort') }}</label>
                        <p class="text-gray-600">{{ $state['api'] ? __('Länet där företaget har sitt säte.') : __('Postorten. Län kräver SCB:s API-nyckel.') }}</p>
                    </div>
                </div>
                <label class="mt-2 inline-flex min-h-11 items-center gap-2 text-sm text-gray-700"><input type="checkbox" class="size-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model.live="blocked"> {{ __('Visa företag med reklamspärr') }}</label>
                <div class="mt-4 max-w-xl">
                    <x-ui.field for="like-search" :label="__('Sök på namn eller organisationsnummer')" :hint="__('Minst två tecken, till exempel en del av namnet eller organisationsnumrets början, med eller utan bindestreck.')">
                        <input id="like-search" type="search" class="{{ $input }} mt-1" wire:model.live.debounce.400ms="search" autocomplete="off" maxlength="100">
                    </x-ui.field>
                    @if($shortSearch)
                        <p class="mt-1 text-sm text-gray-600" role="status">{{ __('Skriv minst två tecken för att söka.') }}</p>
                    @endif
                </div>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card data-testid="prospect-hits">
        <p class="text-sm text-gray-600" data-testid="register-source">
            {{ __('Källa: Bolagsverket och SCB, värdefulla datamängder, bearbetat av NONOX, hämtat :date.', ['date' => $sourceDate ? $sourceDate->setTimezone('Europe/Stockholm')->translatedFormat('j M Y') : '–']) }}
            {{ __('Bara juridiska personer: enskilda firmor finns inte med. Företag som redan finns bland dina kunder och prospekt visas inte.') }}
        </p>

        @if(! $searching)
            <x-ui.empty-state class="mt-6" :title="$mode === 'sok' ? __('Sök eller välj ett filter') : __('Välj minst en kund som finns i registret')">
                {{ $mode === 'sok' ? __('Sök på ett namn eller ett organisationsnummer, eller börja med en bransch eller en ort. Träffarna visas här.') : __('NONOX jämför med kundernas uppgifter i företagsregistret, så kunden måste ha ett organisationsnummer som finns där.') }}
            </x-ui.empty-state>
        @elseif($results->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga företag matchar')">
                {{ trim($search) !== '' ? __('Inget företag matchar sökningen. Kontrollera stavningen, sök på en kortare del av namnet eller rensa filtren.') : __('Ändra filtren eller välj fler kunder att jämföra med.') }}
            </x-ui.empty-state>
        @else
            <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold text-ink" aria-live="polite">{{ $capped ? __('Fler än :count företag', ['count' => number_format(App\Modules\Crm\Livewire\ProspectingPage::COUNT_CAP, 0, ',', ' ')]) : trans_choice('{1} 1 företag|[2,*] :count företag', $total, ['count' => number_format($total, 0, ',', ' ')]) }}</p>
                <label for="prospect-per-page" class="inline-flex items-center gap-2 text-sm text-gray-700">
                    {{ __('Visa per sida') }}
                    <select id="prospect-per-page" class="block min-h-11 rounded-lg border-gray-300 shadow-xs focus:border-accent-500 focus:ring-accent-500 sm:min-h-0" wire:model.live="perPage">
                        @foreach($pageSizes as $size)
                            <option value="{{ $size }}">{{ $size }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            @if($canManage)
                <div class="mt-4 rounded-2xl bg-stone-50 p-4 ring-1 ring-stone-200">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <x-ui.field for="prospect-owner" :label="__('Ansvarig för nya prospekt')">
                            <select id="prospect-owner" class="{{ $select }} mt-1" wire:model="ownerId">
                                @foreach($members as $member)
                                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <div class="flex items-end">
                            <label class="inline-flex min-h-11 items-center gap-2 text-sm text-gray-700"><input type="checkbox" class="size-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model.live="asCallList"> {{ __('Gör också en ringlista') }}</label>
                        </div>
                        @if($asCallList)
                            <x-ui.field for="prospect-list-name" :label="__('Ringlistans namn')">
                                <input id="prospect-list-name" type="text" class="{{ $input }} mt-1" wire:model="listName" placeholder="{{ __('Ringlista :date', ['date' => now()->translatedFormat('j M Y')]) }}">
                            </x-ui.field>
                            <x-ui.field for="prospect-list-assignee" :label="__('Säljare som ringer')">
                                <select id="prospect-list-assignee" class="{{ $select }} mt-1" wire:model="listAssignee">
                                    @foreach($members as $member)
                                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        @endif
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <x-button type="button" wire:click="addSelected" wire:loading.attr="disabled">{{ __('Lägg till markerade') }}</x-button>
                        <x-secondary-button type="button" wire:click="addAll" wire:loading.attr="disabled" wire:confirm="{{ __('Lägga till :count företag som prospekt?', ['count' => min($total, App\Modules\Crm\Domain\ProspectSearch::MAX_ADD)]) }}">{{ $total > App\Modules\Crm\Domain\ProspectSearch::MAX_ADD ? __('Lägg till de första :max', ['max' => App\Modules\Crm\Domain\ProspectSearch::MAX_ADD]) : __('Lägg till alla träffar') }}</x-secondary-button>
                    </div>
                    <x-input-error for="selection" class="mt-2" />
                </div>
            @endif

            <ul class="mt-4 divide-y divide-gray-100" data-testid="prospect-results">
                @foreach($results as $hit)
                    @php($why = $explain($hit))
                    <li wire:key="hit-{{ $hit->org_number }}" class="flex flex-wrap items-start gap-x-4 gap-y-2 py-4">
                        @if($canManage)
                            <input type="checkbox" class="mt-1 size-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" value="{{ $hit->org_number }}" wire:model="selected" aria-label="{{ __('Markera :name', ['name' => $hit->name]) }}">
                        @endif
                        <span class="min-w-0 flex-1 basis-60">
                            <span class="block font-medium text-ink">{{ $hit->name }}</span>
                            <span class="mt-1 block text-sm text-gray-600">
                                <span class="tabular-nums">{{ $hit->formattedOrgNumber() }}</span>
                                · {{ App\Support\CompanyRegistry\RegistryCodes::legalForm($hit->legal_form) }}
                                @if($hit->town()) · {{ $hit->town() }}@endif
                                @if($hit->registered_on) · {{ __('registrerat :date', ['date' => $hit->registered_on->translatedFormat('j M Y')]) }}@endif
                            </span>
                            @if($hit->sni_code)
                                <span class="block text-sm text-gray-600">{{ App\Support\CompanyRegistry\Sni::label($hit->sni_code) }}</span>
                            @endif
                            @if($hit->employee_class !== null || $hit->turnover_class !== null)
                                <span class="block text-sm text-gray-600">{{ collect([App\Support\CompanyRegistry\RegistryCodes::employees($hit->employee_class), $hit->turnover_class === null ? null : __('omsättning :class', ['class' => App\Support\CompanyRegistry\RegistryCodes::turnover($hit->turnover_class)])])->filter()->implode(' · ') }}</span>
                            @endif
                            @if($why)
                                <span class="mt-1 block text-sm text-accent-800">{{ __('Liknar :customer: :reasons', ['customer' => $why['customer'], 'reasons' => implode(', ', $why['reasons'])]) }}</span>
                            @endif
                            <span class="mt-1 flex flex-wrap gap-2">
                                @unless($hit->isInBusiness())<x-ui.badge>{{ $hit->proceedings ? App\Support\CompanyRegistry\RegistryCodes::proceedings($hit->proceedings) : ($hit->deregistered_on ? __('Avregistrerat') : __('Inte i drift')) }}</x-ui.badge>@endunless
                                @if($hit->advertising_block)<x-ui.badge>{{ __('Reklamspärr') }}</x-ui.badge>@endif
                                @if($hit->hasPhoneBlock())<x-ui.badge>{{ __('Spärr mot telefonförsäljning') }}</x-ui.badge>@endif
                            </span>
                        </span>
                        @if($canManage)
                            <button type="button" class="min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="addOne('{{ $hit->org_number }}')" wire:loading.attr="disabled">{{ __('Lägg till som prospekt') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $results->links() }}</div>
        @endif
    </x-ui.card>
</div>
