@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $button = 'rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-hidden focus:ring-2 focus:ring-accent-500 focus:ring-offset-2';
    $quiet = 'text-sm font-medium text-accent-600 hover:text-accent-700 focus:outline-hidden focus:underline';
    $danger = 'text-sm font-medium text-red-700 hover:underline focus:outline-hidden focus:underline';
    $pct = fn (?int $bp): string => $bp === null ? '–' : rtrim(rtrim(number_format($bp / 100, 2, ',', "\u{a0}"), '0'), ',').' %';
    $th = 'py-2 pr-4 text-left font-medium';
@endphp

<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700" role="note">
        <p>{{ __('Strukturen är underlag för koncernens interna rapporter. Den ändrar ingenting i något företags bokföring.') }}</p>
        {{-- TODO(legal-verify): ADR-060 H5, wording of the group labels and what the screen may imply about a koncern under ABL/ARL; the jurist decides before launch. --}}
        <p class="mt-1">{{ __('NONOX prövar inte om företagen utgör en koncern enligt aktiebolagslagen eller årsredovisningslagen. Strukturen bygger på de innehav ni själva har registrerat.') }}</p>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        @if($groups->isNotEmpty())
            <x-ui.field for="corporate_group_select" :label="__('Koncern')" class="min-w-56">
                <select id="corporate_group_select" class="{{ $input }} mt-1" wire:change="select($event.target.value)">
                    @foreach($groups as $option)
                        <option value="{{ $option->id }}" @selected($current && $option->id === $current->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        @endif
        @if($canCreate->isNotEmpty())
            <form wire:submit="create" class="flex flex-wrap items-end gap-2">
                <x-ui.field for="corporate_new_name" :label="__('Ny koncern')">
                    <input id="corporate_new_name" type="text" maxlength="100" class="{{ $input }} mt-1" wire:model="newName" placeholder="{{ __('Till exempel Holding och dotterföretag') }}">
                </x-ui.field>
                <x-ui.field for="corporate_new_parent" :label="__('Moderföretag')">
                    <select id="corporate_new_parent" class="{{ $input }} mt-1" wire:model="newParent">
                        <option value="">{{ __('Välj företag') }}</option>
                        @foreach($canCreate as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <button type="submit" class="{{ $button }}">{{ __('Skapa koncern') }}</button>
            </form>
        @endif
    </div>

    @if($lists->isNotEmpty() && $canCreate->isNotEmpty())
        <x-ui.card>
            <h3 class="font-semibold text-gray-900">{{ __('Gör om en tidigare lista till koncern') }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ __('Dina tidigare listor under Koncernöversikt är bara dina egna. Välj en lista och ett moderföretag, så skapas en koncern som alla i den kan se. Listan finns kvar och inget i bokföringen ändras.') }}</p>
            <form wire:submit="convert" class="mt-3 flex flex-wrap items-end gap-2">
                <x-ui.field for="corporate_convert_list" :label="__('Lista')">
                    <select id="corporate_convert_list" class="{{ $input }} mt-1" wire:model.live="convertList">
                        <option value="">{{ __('Välj lista') }}</option>
                        @foreach($lists as $list)
                            <option value="{{ $list->id }}">{{ $list->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="corporate_convert_parent" :label="__('Moderföretag')">
                    <select id="corporate_convert_parent" class="{{ $input }} mt-1" wire:model="convertParent">
                        <option value="">{{ __('Välj företag') }}</option>
                        @foreach($lists->firstWhere('id', (int) $convertList)?->members ?? [] as $member)
                            @if($canCreate->contains('id', $member->company_id))
                                <option value="{{ $member->company_id }}">{{ $companyNames[$member->company_id] }}</option>
                            @endif
                        @endforeach
                    </select>
                </x-ui.field>
                <button type="submit" class="{{ $button }}">{{ __('Gör om till koncern') }}</button>
            </form>
        </x-ui.card>
    @endif

    @if(! $current)
        <x-ui.card>
            <x-ui.empty-state :title="__('Du ser ingen koncern ännu')">
                {{ __('En koncern syns här när du är ägare eller administratör och skapar den, eller när du har rätt att se rapporter i alla dess företag, eller har fått rollen att läsa dess sammanställning.') }}
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <x-ui.card>
            <h3 class="font-semibold text-gray-900">{{ $current->name }}</h3>
            @if(! $full)
                <p class="mt-1 text-sm text-gray-600" role="status">{{ __('Du har rollen att läsa koncernens sammanställning. Du ser företagens namn, vem som äger vad och ägd andel av kapitalet, men inga uppgifter om enskilda företag.') }}</p>
            @endif
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">{{ __('Koncernens företag och ägande') }}</caption>
                    <thead class="text-gray-600"><tr class="border-b border-gray-200">
                        <th scope="col" class="{{ $th }}">{{ __('Företag') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Ägs av') }}</th>
                        <th scope="col" class="{{ $th }} text-right">{{ __('Kapital') }}</th>
                        @if($full)
                            <th scope="col" class="{{ $th }} text-right">{{ __('Röster') }}</th>
                            <th scope="col" class="{{ $th }}">{{ __('Bestämmande inflytande') }}</th>
                            <th scope="col" class="{{ $th }}">{{ __('Förvärvat') }}</th>
                            <th scope="col" class="{{ $th }}">{{ __('Avyttrat') }}</th>
                            <th scope="col" class="{{ $th }}">{{ __('Räkenskapsår') }}</th>
                            <th scope="col" class="{{ $th }}">{{ __('Låst till') }}</th>
                            @if($report['administers'])
                                <th scope="col" class="py-2 text-right font-medium"><span class="sr-only">{{ __('Åtgärd') }}</span></th>
                            @endif
                        @endif
                    </tr></thead>
                    <tbody>
                        @foreach($report['nodes'] as $node)
                            <tr class="border-b border-gray-100" wire:key="node-{{ $node['company_id'] }}">
                                <th scope="row" class="py-2 pr-4 text-left font-medium text-gray-900" style="padding-left: {{ $node['depth'] * 1.5 }}rem">
                                    {{ $node['name'] }}
                                    @if($node['is_parent'])<span class="ms-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-normal text-gray-700">{{ __('Moderföretag') }}</span>@endif
                                    @if($node['no_control'])<span class="block text-xs font-normal text-amber-800">{{ __('Inte dotterföretag genom detta innehav') }}</span>@endif
                                    @if($full)<span class="block text-xs font-normal text-gray-500 tabular-nums">{{ $node['org_number'] }}</span>@endif
                                </th>
                                <td class="py-2 pr-4">{{ $node['holder_name'] ?? '–' }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $pct($node['capital_bp']) }}</td>
                                @if($full)
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $pct($node['voting_bp']) }}</td>
                                    <td class="py-2 pr-4">{{ $node['control_basis']?->label() ?? '–' }}</td>
                                    <td class="py-2 pr-4 tabular-nums">{{ $node['acquired_on']?->toDateString() ?? '–' }}</td>
                                    <td class="py-2 pr-4 tabular-nums">{{ $node['disposed_on']?->toDateString() ?? '–' }}</td>
                                    <td class="py-2 pr-4">
                                        @if($node['year'])
                                            {{ $node['year'] }} <span class="text-gray-600">({{ $node['year_status']->label() }})</span>
                                        @else
                                            {{ __('Inget räkenskapsår') }}
                                        @endif
                                        @if($node['archived'])<span class="block text-xs text-gray-500">{{ __('Arkiverat') }}</span>@endif
                                    </td>
                                    <td class="py-2 pr-4 tabular-nums">{{ $node['locked_until']?->toDateString() ?? __('Ingen låsning') }}</td>
                                    @if($report['administers'])
                                        <td class="py-2 text-right">
                                            @unless($node['is_parent'])
                                                <button type="button" class="{{ $danger }}" wire:click="removeMember({{ $node['company_id'] }})" wire:confirm="{{ __('Ta bort :company ur koncernen? Koncernens sammanställning räknas utan företaget.', ['company' => $node['name']]) }}" aria-label="{{ __('Ta bort :company ur koncernen', ['company' => $node['name']]) }}">{{ __('Ta bort') }}</button>
                                            @endunless
                                        </td>
                                    @endif
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($full)
                <p class="mt-3 text-xs text-gray-500">{{ __('Det bestämmande inflytandet anges av en person och räknas inte fram av rösterna. Ett företag med ett avyttrat innehav räknas inte med i perioder efter avyttringen.') }}</p>
            @endif
        </x-ui.card>

        @if($report['administers'])
            <div class="grid gap-6 lg:grid-cols-2">
                <x-ui.card>
                    <h3 class="font-semibold text-gray-900">{{ __('Registrera innehav') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Innehavet skrivs i det ägande företagets egna register. Finns redan ett innehav i samma företag ändras det. Ett företag måste ha ett registrerat innehav innan det kan läggas till i koncernen.') }}</p>
                    <form wire:submit="saveHolding" class="mt-3 grid gap-3 sm:grid-cols-2">
                        <x-ui.field for="hold_holder" :label="__('Ägare')">
                            <select id="hold_holder" class="{{ $input }} mt-1" wire:model="holdHolder">
                                <option value="">{{ __('Välj företag') }}</option>
                                @foreach($holders as $company)
                                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="hold_held" :label="__('Ägt företag')">
                            <select id="hold_held" class="{{ $input }} mt-1" wire:model="holdHeld">
                                <option value="">{{ __('Välj företag') }}</option>
                                @foreach($held as $company)
                                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="hold_capital" :label="__('Andel av kapitalet, procent')">
                            <input id="hold_capital" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="holdCapital" placeholder="100">
                        </x-ui.field>
                        <x-ui.field for="hold_voting" :label="__('Andel av rösterna, procent')">
                            <input id="hold_voting" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="holdVoting" placeholder="100">
                        </x-ui.field>
                        <x-ui.field for="hold_basis" :label="__('Bestämmande inflytande')" class="sm:col-span-2">
                            <select id="hold_basis" class="{{ $input }} mt-1" wire:model="holdBasis">
                                <option value="">{{ __('Välj') }}</option>
                                @foreach($bases as $basis)
                                    <option value="{{ $basis->value }}">{{ $basis->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="hold_acquired" :label="__('Förvärvsdag')">
                            <input id="hold_acquired" type="date" class="{{ $input }} mt-1" wire:model="holdAcquired">
                        </x-ui.field>
                        <x-ui.field for="hold_disposed" :label="__('Avyttringsdag (frivilligt)')">
                            <input id="hold_disposed" type="date" class="{{ $input }} mt-1" wire:model="holdDisposed">
                        </x-ui.field>
                        <div class="sm:col-span-2"><button type="submit" class="{{ $button }}">{{ __('Spara innehav') }}</button></div>
                    </form>
                </x-ui.card>

                <div class="space-y-6">
                    <x-ui.card>
                        <h3 class="font-semibold text-gray-900">{{ __('Lägg till företag') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ __('Du måste vara ägare eller administratör i företaget och i alla företag som redan ingår.') }}</p>
                        <form wire:submit="addMember" class="mt-3 flex flex-wrap items-end gap-2">
                            <x-ui.field for="corporate_add_company" :label="__('Företag')">
                                <select id="corporate_add_company" class="{{ $input }} mt-1" wire:model="addCompany">
                                    <option value="">{{ __('Välj företag') }}</option>
                                    @foreach($addable as $company)
                                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                            <button type="submit" class="{{ $button }}">{{ __('Lägg till') }}</button>
                        </form>
                    </x-ui.card>

                    <x-ui.card>
                        <h3 class="font-semibold text-gray-900">{{ __('Vem får läsa sammanställningen') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ __('Rollen ger bara koncernens sammanställning, aldrig tillgång till något enskilt företag. Den som läser sammanställningen och även ser moderföretagets egna rapporter kan räkna ut dotterföretagens summor.') }}</p>
                        @if($report['roles'] !== [])
                            <ul class="mt-3 divide-y divide-gray-100">
                                @foreach($report['roles'] as $role)
                                    <li class="flex items-center justify-between gap-2 py-2" wire:key="role-{{ $role['user_id'] }}">
                                        <span class="text-sm text-gray-900">{{ $role['name'] }} <span class="text-gray-500">{{ $role['email'] }}</span></span>
                                        <button type="button" class="{{ $danger }}" wire:click="revokeRole({{ $role['user_id'] }})" wire:confirm="{{ __('Ta bort rollen för :name? Personen ser inte längre koncernen.', ['name' => $role['name']]) }}" aria-label="{{ __('Ta bort rollen för :name', ['name' => $role['name']]) }}">{{ __('Ta bort') }}</button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if($report['candidates'] !== [])
                            <form wire:submit="grantRole" class="mt-3 flex flex-wrap items-end gap-2">
                                <x-ui.field for="corporate_role_user" :label="__('Person i moderföretaget')">
                                    <select id="corporate_role_user" class="{{ $input }} mt-1" wire:model="roleUser">
                                        <option value="">{{ __('Välj person') }}</option>
                                        @foreach($report['candidates'] as $candidate)
                                            <option value="{{ $candidate['id'] }}">{{ $candidate['name'] }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                                <button type="submit" class="{{ $button }}">{{ __('Ge rollen') }}</button>
                            </form>
                        @endif
                    </x-ui.card>
                </div>
            </div>
        @endif
    @endif
</div>
