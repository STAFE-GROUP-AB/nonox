{{-- K10-underlag for the owners of an aktiebolag (App\Livewire\Accounting\K10Page, ADR-062). --}}
@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $kr = fn (?int $kronor): string => $kronor === null ? '–' : number_format($kronor, 0, ',', "\u{a0}")."\u{a0}kr";
    $previous = $incomeYear - 1;
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
        <x-ui.badge tone="accent">{{ __('Beta') }}</x-ui.badge>
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('Välj inkomstår') }}">
            @foreach($years as $option)
                <button type="button" wire:key="k10-year-{{ $option }}" wire:click="$set('incomeYear', {{ $option }})" @class([
                    'rounded-full px-4 py-2 text-sm font-semibold transition',
                    'bg-ink text-white' => $option === $incomeYear,
                    'bg-stone-200/60 text-gray-800 hover:bg-stone-200' => $option !== $incomeYear,
                ]) @if($option === $incomeYear) aria-current="true" @endif>{{ __('Inkomstår :year', ['year' => $option]) }}</button>
            @endforeach
        </nav>
    </div>

    <p class="rounded-2xl bg-stone-100 px-4 py-3 text-sm text-gray-700" data-k10-beta>{{ __('K10-underlaget är ett förslag och en arbetsyta, inte en deklaration. Reglerna är de som gäller från inkomstår 2026 och är inte granskade av en jurist. Ägaren lämnar K10 själv med sin Inkomstdeklaration 1 och ansvarar för uppgifterna. Ingenting bokförs.') }}</p>

    @if(! $isAb)
        <x-ui.empty-state :title="__('Inte för den här företagsformen')">{{ __('K10 lämnas av delägare i aktiebolag. NONOX tar bara fram K10-underlag för aktiebolag.') }}</x-ui.empty-state>
    @elseif(! $applies)
        <x-ui.empty-state :title="__('Inget inkomstår att räkna på')">{{ __('Beloppen och procentsatserna för inkomstår :year finns inte i NONOX, så K10 kan inte räknas. Använd Skatteverkets e-tjänst.', ['year' => $incomeYear]) }}</x-ui.empty-state>
    @else
        <div class="grid gap-6 xl:grid-cols-[minmax(0,30rem)_1fr]">
            <x-ui.card :title="__('Företaget :year', ['year' => $incomeYear])" :description="__('Det som gäller alla ägare. Aktierna räknas vid årets ingång.')" data-k10-year>
                <form wire:submit="saveYear" class="space-y-4">
                    <x-ui.field for="k10_shares_total" :label="__('Antal aktier i företaget vid årets ingång')" error="year.shares_total">
                        <input id="k10_shares_total" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="yearForm.shares_total">
                    </x-ui.field>
                    <x-ui.field for="k10_dividend_total" :label="__('Utdelning från företaget under :year', ['year' => $incomeYear])" error="year.dividend_total" :hint="$books !== null ? __('Förslag från bokföringen: :amount beslutad utdelning på konto 2898 under :year (utbetalt :paid). Lämna tomt för att använda förslaget.', ['amount' => $kr($books->dividendDecidedKronor()), 'paid' => $kr($books->dividendPaidKronor()), 'year' => $incomeYear]) : __('Lämna tomt för att använda beslutad utdelning på konto 2898 under året.')">
                        <input id="k10_dividend_total" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="yearForm.dividend_total">
                    </x-ui.field>
                    <x-ui.field for="k10_wage_subsidy" :label="__('Lön som täcks av statliga bidrag under :year', ['year' => $previous])" error="year.wage_subsidy" :hint="__('Dras från löneunderlaget (IL 57 kap. 18 §).')">
                        <input id="k10_wage_subsidy" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="yearForm.wage_subsidy">
                    </x-ui.field>
                    <label class="flex items-start gap-3 text-sm text-gray-700"><x-checkbox wire:model="yearForm.qualified_confirmed" class="mt-0.5" /><span>{{ __('Företaget är ett fåmansföretag och ägarnas aktier är kvalificerade. Det bedömer du själv (IL 56 kap. 2 § och 57 kap. 4 §).') }}</span></label>
                    <x-button>{{ __('Spara året') }}</x-button>
                </form>
            </x-ui.card>

            <x-ui.card :title="__('Ägare')" :description="__('Ett K10-underlag per ägare med kvalificerade aktier.')" data-k10-owners>
                <x-slot:cta>
                    <button type="button" wire:click="newOwner" class="inline-flex min-h-10 items-center rounded-full bg-ink px-4 text-sm font-semibold text-white hover:bg-gray-800">{{ __('Lägg till ägare') }}</button>
                    @if($canCopy)
                        <button type="button" wire:click="copyOwners" class="inline-flex min-h-10 items-center rounded-full px-4 text-sm font-semibold text-ink ring-1 ring-stone-300 hover:bg-stone-100">{{ __('Hämta ägarna från :year', ['year' => $previous]) }}</button>
                    @endif
                </x-slot:cta>

                @if($sheets->isEmpty())
                    <p class="text-sm text-gray-600">{{ __('Inga ägare än. Lägg till de ägare som ska lämna K10.') }}</p>
                @endif

                <div class="space-y-4">
                    @foreach($sheets as $sheet)
                        @php($k10 = $sheet->k10())
                        <article class="space-y-3 rounded-2xl p-4 ring-1 ring-stone-200" wire:key="k10-owner-{{ $sheet->owner->id }}" data-k10-owner="{{ $sheet->owner->id }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <h3 class="font-bold text-ink">{{ $sheet->owner->name }}</h3>
                                <p class="text-sm text-gray-600">{{ __(':owned av :total aktier', ['owned' => number_format($sheet->owner->shares_owned, 0, ',', "\u{a0}"), 'total' => $sheet->input()?->sharesTotal === null ? '–' : number_format($sheet->input()->sharesTotal, 0, ',', "\u{a0}")]) }}</p>
                            </div>

                            @foreach($sheet->blockers() as $blocker)
                                <p class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-950 ring-1 ring-amber-200" role="status" data-k10-blocker>{{ $blocker }}</p>
                            @endforeach

                            @if($sheet->owner->saved_space_carried)
                                <p class="text-xs text-gray-500" data-k10-carried>{{ __('Sparat utdelningsutrymme förs över från förra årets underlag.') }}</p>
                            @endif

                            @if($k10 !== null)
                                <dl class="grid gap-2 sm:grid-cols-3">
                                    <div class="rounded-xl bg-stone-50 px-3 py-2"><dt class="text-xs text-gray-500">{{ __('Gränsbelopp (1.6)') }}</dt><dd class="font-bold tabular-nums text-ink" data-k10-limit>{{ $kr($k10->limitKronor()) }}</dd></div>
                                    <div class="rounded-xl bg-stone-50 px-3 py-2"><dt class="text-xs text-gray-500">{{ __('Utdelning (2.1)') }}</dt><dd class="font-bold tabular-nums text-ink">{{ $kr($sheet->input()->dividendKronor) }}</dd></div>
                                    <div class="rounded-xl bg-stone-50 px-3 py-2"><dt class="text-xs text-gray-500">{{ __('Sparat utdelningsutrymme till nästa år') }}</dt><dd class="font-bold tabular-nums text-ink" data-k10-saved>{{ $kr($k10->savedForNextYearKronor()) }}</dd></div>
                                </dl>
                                @foreach($sheet->warnings() as $warning)
                                    <p class="rounded-xl bg-white px-3 py-2 text-sm text-accent-800 ring-1 ring-accent-200" role="status">{{ $warning }}</p>
                                @endforeach
                            @endif

                            <div class="flex flex-wrap items-center gap-4 text-sm">
                                <button type="button" wire:click="editOwner({{ $sheet->owner->id }})" class="font-medium text-ink underline" aria-label="{{ __('Ändra :name', ['name' => $sheet->owner->name]) }}">{{ __('Ändra') }}</button>
                                @if($k10 !== null)
                                    <a href="{{ route('accounting.k10.show', $sheet->owner) }}" target="_blank" rel="noopener" class="font-medium text-accent-700 underline">{{ __('Visa underlaget') }}</a>
                                    <a href="{{ route('accounting.k10.pdf', $sheet->owner) }}" class="font-medium text-accent-700 underline">{{ __('Ladda ner PDF') }}</a>
                                    @if($sheet->canExportSru())
                                        <a href="{{ route('accounting.k10.sru', $sheet->owner) }}" class="font-medium text-accent-700 underline" data-k10-sru>{{ __('Ladda ner SRU-fil') }}</a>
                                    @endif
                                @endif
                                <a href="{{ route('privacy.export.k10Owner', $sheet->owner) }}" class="font-medium text-accent-700 underline" aria-label="{{ __('Registerutdrag för :name', ['name' => $sheet->owner->name]) }}">{{ __('Registerutdrag (JSON)') }}</a>
                                <button type="button" wire:click="deleteOwner({{ $sheet->owner->id }})" wire:confirm="{{ __('Ta bort :name och K10-underlaget för :year? Uppgifterna raderas och kan inte återställas.', ['name' => $sheet->owner->name, 'year' => $incomeYear]) }}" class="font-medium text-gray-700 underline">{{ __('Ta bort') }}</button>
                            </div>
                        </article>
                    @endforeach
                </div>
            </x-ui.card>
        </div>

        @if($editing !== null)
            <x-ui.card :title="$editing === 0 ? __('Ny ägare') : __('Ändra ägare')" :description="__('Belopp i hela kronor. Lämna ett belopp tomt för att använda förslaget från bokföringen.')" data-k10-owner-form>
                <form wire:submit="saveOwner" class="space-y-6">
                    <fieldset class="grid gap-4 sm:grid-cols-2">
                        <legend class="mb-2 text-sm font-bold text-ink">{{ __('Ägaren') }}</legend>
                        <x-ui.field for="k10_owner_name" :label="__('Namn')" error="owner.name">
                            <input id="k10_owner_name" type="text" class="{{ $input }} mt-1" wire:model="ownerForm.name" autocomplete="off">
                        </x-ui.field>
                        <x-ui.field for="k10_owner_identity" :label="__('Personnummer med århundrade')" error="owner.identity" :hint="($ownerForm['masked'] ?? '') !== '' ? __('Sparat: :number. Lämna tomt för att behålla det, eller skriv ett nytt.', ['number' => $ownerForm['masked']]) : __('Frivilligt. Behövs bara för SRU-filen och sparas krypterat.')">
                            <input id="k10_owner_identity" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="ownerForm.identity" autocomplete="off" placeholder="{{ ($ownerForm['masked'] ?? '') !== '' ? $ownerForm['masked'] : '19121212-1212' }}">
                        </x-ui.field>
                        @if(($ownerForm['masked'] ?? '') !== '')
                            <label class="flex items-center gap-3 text-sm text-gray-700 sm:col-span-2"><x-checkbox wire:model="ownerForm.remove_identity" />{{ __('Ta bort det sparade personnumret') }}</label>
                        @endif
                        <x-ui.field for="k10_owner_shares" :label="__('Antal aktier vid årets ingång')" error="owner.shares_owned">
                            <input id="k10_owner_shares" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="ownerForm.shares_owned">
                        </x-ui.field>
                        <x-ui.field for="k10_owner_acquired" :label="__('Köpte aktierna under :year, datum', ['year' => $previous])" error="owner.acquired_on" :hint="__('Bara om aktierna köptes under året före inkomståret. Då räknas lönerna från det datumet (IL 57 kap. 16 a §).')">
                            <input id="k10_owner_acquired" type="date" class="{{ $input }} mt-1" wire:model="ownerForm.acquired_on">
                        </x-ui.field>
                        <x-ui.field for="k10_owner_spouse" :label="__('Make eller maka som också äger aktier')" error="owner.spouse_owner_id" :hint="__('Makar vid årets ingång räknar det lönebaserade utrymmet tillsammans.')">
                            <select id="k10_owner_spouse" class="{{ $input }} mt-1" wire:model="ownerForm.spouse_owner_id">
                                <option value="">{{ __('Ingen') }}</option>
                                @foreach($owners as $option)
                                    @if($option->id !== $editing)
                                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </x-ui.field>
                        @if($employees->isNotEmpty())
                            <x-ui.field for="k10_owner_employee" :label="__('Ägaren i lönesystemet')" error="owner.employee_id" :hint="__('Då hämtas ägarens kontanta ersättning för :year från lönekörningarna.', ['year' => $previous])">
                                <select id="k10_owner_employee" class="{{ $input }} mt-1" wire:model="ownerForm.employee_id">
                                    <option value="">{{ __('Inte anställd') }}</option>
                                    @foreach($employees as $employee)
                                        <option value="{{ $employee->id }}">{{ $employee->first_name }} {{ $employee->last_name }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        @endif
                        <x-ui.field for="k10_owner_other" :label="__('Ägarandelar i andra fåmansföretag, %')" error="owner.other_holdings" :hint="__('Summan av dina ägarandelar i andra fåmansföretag med kvalificerade aktier, till exempel 25 + 75 = 100. Skriv 0 om du inte har några.')">
                            <input id="k10_owner_other" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="ownerForm.other_holdings">
                        </x-ui.field>
                        <label class="flex items-start gap-3 text-sm text-gray-700 sm:col-span-2"><x-checkbox wire:model="ownerForm.topped_up_previous_year" class="mt-0.5" /><span>{{ __('Ägaren hade aktier hela :year och köpte fler under året', ['year' => $previous]) }}</span></label>
                        <label class="flex items-start gap-3 text-sm text-gray-700 sm:col-span-2"><x-checkbox wire:model="ownerForm.transferred_shares" class="mt-0.5" /><span>{{ __('Ägaren har sålt eller gett bort aktier under :year', ['year' => $incomeYear]) }}</span></label>
                    </fieldset>

                    <fieldset class="grid gap-4 sm:grid-cols-2">
                        <legend class="mb-2 text-sm font-bold text-ink">{{ __('Det bara ägaren vet') }}</legend>
                        <x-ui.field for="k10_owner_cost_basis" :label="__('Omkostnadsbelopp för aktierna')" error="owner.cost_basis" :hint="__('Vad aktierna kostade, med villkorade aktieägartillskott och liknande. Ränta räknas på det som överstiger 100 000 kr.')">
                            <input id="k10_owner_cost_basis" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="ownerForm.cost_basis">
                        </x-ui.field>
                        <x-ui.field for="k10_owner_saved" :label="__('Sparat utdelningsutrymme från :year', ['year' => $previous])" error="owner.saved_space" :hint="($ownerForm['saved_space_carried'] ?? false) ? __('Förs över från förra årets underlag i NONOX. Kontrollera beloppet mot förra årets K10, punkt 2.6 eller 1.6.') : __('Från förra årets K10, punkt 2.6 eller 1.6. Räknas inte upp med ränta från inkomstår 2026.')">
                            <input id="k10_owner_saved" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="ownerForm.saved_space">
                        </x-ui.field>
                        <x-ui.field for="k10_owner_own_pay" :label="__('Din kontanta ersättning från företaget under :year', ['year' => $previous])" error="owner.own_pay" :hint="__('Lön och annan kontant ersättning, inte förmåner. Lämna tomt för att hämta den från lönekörningarna.')">
                            <input id="k10_owner_own_pay" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="ownerForm.own_pay">
                        </x-ui.field>
                        <x-ui.field for="k10_owner_related_pay" :label="__('Högsta kontanta ersättning till en närstående under :year', ['year' => $previous])" error="owner.related_pay" :hint="__('Från företaget och dess dotterföretag. Det lönebaserade utrymmet får vara högst 50 gånger den högsta ersättningen till dig eller en närstående.')">
                            <input id="k10_owner_related_pay" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="ownerForm.related_pay">
                        </x-ui.field>
                    </fieldset>

                    <details class="rounded-2xl ring-1 ring-stone-200">
                        <summary class="min-h-11 cursor-pointer px-4 py-2 text-sm font-bold text-ink">{{ __('Ändra förslagen från bokföringen') }}</summary>
                        <div class="grid gap-4 border-t border-stone-100 px-4 py-3 sm:grid-cols-3">
                            <x-ui.field for="k10_owner_company_pay" :label="__('Löner i företaget under :year', ['year' => $previous])" error="owner.company_pay" :hint="__('Punkt 4.1 eller 4.5: kontant ersättning till alla anställda.')">
                                <input id="k10_owner_company_pay" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="ownerForm.company_pay">
                            </x-ui.field>
                            <x-ui.field for="k10_owner_subsidiary_pay" :label="__('Löner i dotterföretag under :year', ['year' => $previous])" error="owner.subsidiary_pay" :hint="__('Punkt 4.2 eller 4.6, efter moderföretagets andel och innehavstid.')">
                                <input id="k10_owner_subsidiary_pay" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="ownerForm.subsidiary_pay">
                            </x-ui.field>
                            <x-ui.field for="k10_owner_dividend" :label="__('Din utdelning under :year', ['year' => $incomeYear])" error="owner.dividend" :hint="__('Punkt 2.1. Tomt: företagets utdelning fördelad efter ditt antal aktier.')">
                                <input id="k10_owner_dividend" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="ownerForm.dividend">
                            </x-ui.field>
                        </div>
                    </details>

                    <div class="flex flex-wrap items-center gap-4">
                        <x-button>{{ __('Spara ägaren') }}</x-button>
                        <button type="button" wire:click="cancelOwner" class="text-sm font-medium text-gray-700 underline">{{ __('Avbryt') }}</button>
                    </div>
                    <p class="text-xs text-gray-500">{{ __('Personnumret sparas krypterat, visas aldrig i sin helhet och skrivs bara i SRU-filen.') }}</p>
                </form>
            </x-ui.card>
        @endif

        <div class="space-y-1 rounded-2xl bg-stone-50 px-4 py-3 text-sm text-gray-700" data-k10-instructions>
            <p class="font-semibold text-ink">{{ __('Så lämnar ägaren K10') }}</p>
            <p>{{ __('K10 är en bilaga till ägarens egen Inkomstdeklaration 1. Fyll i bilagan i Skatteverkets e-tjänst med siffrorna i underlaget, eller lämna SRU-filen i e-tjänsten Filöverföring. Ägaren skriver sedan under sin deklaration.') }}</p>
        </div>
    @endif
</div>
