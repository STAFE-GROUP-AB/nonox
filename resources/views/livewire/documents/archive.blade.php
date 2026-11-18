@php
    use App\Domain\Documents\DocumentCategory;
    use App\Domain\Documents\RetentionClass;
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp
<div class="space-y-6">
    @if($canManage)
        <div>
            @if(! $showForm)
                <x-button type="button" wire:click="$set('showForm', true)">{{ __('Ladda upp dokument') }}</x-button>
            @else
                <x-ui.card :title="__('Ladda upp dokument')" :description="__('PDF, bild, textfil eller Word-, Excel- eller OpenDocument-dokument, högst 10 MB. Filen lagras krypterad med en kontrollsumma (SHA-256).')">
                    <form wire:submit="save" class="grid gap-4 sm:grid-cols-2" aria-label="{{ __('Ladda upp dokument') }}">
                        <x-ui.field for="document_file" :label="__('Fil')" error="file" class="sm:col-span-2">
                            <input id="document_file" type="file" class="mt-1 block w-full text-sm" wire:model="upload">
                            <p class="mt-1 text-xs text-gray-500" wire:loading wire:target="upload">{{ __('Laddar upp…') }}</p>
                        </x-ui.field>
                        <x-ui.field for="document_title" :label="__('Rubrik')" error="title" :hint="($form['retention_class'] ?? '') === RetentionClass::Personnel->value ? __('Skriv inte hälsouppgifter i rubriken. Rubriken på ett personaldokument lagras krypterad.') : null">
                            <input id="document_title" type="text" maxlength="200" class="{{ $input }} mt-1" wire:model="form.title">
                        </x-ui.field>
                        <x-ui.field for="document_category" :label="__('Kategori')" error="category" :hint="__('Välj i listan eller skriv en egen kategori.')">
                            <input id="document_category" type="text" maxlength="60" list="document_categories" class="{{ $input }} mt-1" wire:model="form.category">
                            <datalist id="document_categories">
                                @foreach($categories as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </datalist>
                        </x-ui.field>
                        <x-ui.field for="document_retention" :label="__('Sparas som')" error="retention_class">
                            <select id="document_retention" class="{{ $input }} mt-1" wire:model.live="form.retention_class">
                                @foreach($manageClasses as $class)
                                    <option value="{{ $class->value }}">{{ $class->label() }}</option>
                                @endforeach
                            </select>
                            @php($chosen = RetentionClass::tryFrom((string) ($form['retention_class'] ?? '')))
                            @if($chosen)
                                <p class="mt-1 text-sm text-gray-500">{{ $chosen->description() }}</p>
                            @endif
                        </x-ui.field>
                        @if(($form['retention_class'] ?? '') === RetentionClass::Accounting->value)
                            <x-ui.field for="document_fiscal_year" :label="__('Räkenskapsår')" error="fiscal_year_id" :hint="__('Hör dokumentet till ett år före NONOX: lägg upp räkenskapsåret under Räkenskapsår, eller spara det som Övrigt med Spara minst till och med.')">
                                <select id="document_fiscal_year" class="{{ $input }} mt-1" wire:model="form.fiscal_year_id">
                                    <option value="">{{ __('Välj räkenskapsår') }}</option>
                                    @foreach($fiscalYears as $year)
                                        <option value="{{ $year->id }}">{{ $year->start_date->toDateString() }} – {{ $year->end_date->toDateString() }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        @elseif(($form['retention_class'] ?? '') === RetentionClass::Contract->value)
                            <x-ui.field for="document_contract_ends" :label="__('Avtalet upphör')" error="contract_ends_on" :hint="__('Lämna tomt om avtalet löper tills vidare.')">
                                <input id="document_contract_ends" type="date" class="{{ $input }} mt-1" wire:model="form.contract_ends_on">
                            </x-ui.field>
                            <x-ui.field for="document_contract_years" :label="__('Spara antal år efter att avtalet upphört')" error="contract_retention_years">
                                <input id="document_contract_years" type="number" min="0" max="30" class="{{ $input }} mt-1" wire:model="form.contract_retention_years">
                            </x-ui.field>
                            <div class="sm:col-span-2">
                                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model.live="form.is_accounting_record"> {{ __('Avtalet är räkenskapsinformation') }}</label>
                                <p class="mt-1 text-sm text-gray-500">{{ __('Kryssa i om avtalet visar en affärshändelse som ska bokföras, till exempel ett leasing- eller hyresavtal (bokföringslagen 1 kap. 2 §). Då sparas det i minst sju år efter räkenskapsåret och kommer med i årets arkivpaket.') }}</p>
                            </div>
                            @if(! empty($form['is_accounting_record']))
                                <x-ui.field for="document_contract_year" :label="__('Räkenskapsår')" error="fiscal_year_id">
                                    <select id="document_contract_year" class="{{ $input }} mt-1" wire:model="form.fiscal_year_id">
                                        <option value="">{{ __('Välj räkenskapsår') }}</option>
                                        @foreach($fiscalYears as $year)
                                            <option value="{{ $year->id }}">{{ $year->start_date->toDateString() }} – {{ $year->end_date->toDateString() }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                            @endif
                        @endif
                        <x-ui.field for="document_keep_until" :label="__('Spara minst till och med')" error="keep_until" :hint="__('Frivilligt. Kan bara förlänga tiden, aldrig korta den under regeln.')">
                            <input id="document_keep_until" type="date" class="{{ $input }} mt-1" wire:model="form.keep_until">
                        </x-ui.field>
                        <x-ui.field for="document_tags" :label="__('Taggar')" error="tags" :hint="__('Skilj taggarna åt med kommatecken, högst tio.')">
                            <input id="document_tags" type="text" class="{{ $input }} mt-1" wire:model="form.tags">
                        </x-ui.field>
                        <fieldset class="sm:col-span-2">
                            <legend class="block text-sm font-medium text-gray-700">{{ __('Innehåller dokumentet personuppgifter?') }}</legend>
                            <div class="mt-2 flex gap-6 text-sm">
                                <label class="flex items-center gap-2"><input type="radio" value="1" wire:model="form.contains_personal_data"> {{ __('Ja') }}</label>
                                <label class="flex items-center gap-2"><input type="radio" value="0" wire:model="form.contains_personal_data"> {{ __('Nej') }}</label>
                            </div>
                            <x-input-error for="contains_personal_data" class="mt-2" />
                        </fieldset>
                        <div class="flex items-center gap-3 sm:col-span-2">
                            <x-button wire:loading.attr="disabled" wire:target="save,upload">{{ __('Spara dokumentet') }}</x-button>
                            <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('showForm', false)">{{ __('Avbryt') }}</button>
                        </div>
                    </form>
                </x-ui.card>
            @endif
        </div>
    @endif

    <div class="flex flex-wrap items-end gap-4">
        <div class="min-w-0 flex-1 basis-64">
            <label for="document_search" class="block text-sm font-medium text-gray-700">{{ __('Sök i rubrik, taggar och text') }}</label>
            <input id="document_search" type="search" class="{{ $input }} mt-1" wire:model.live.debounce.400ms="search">
        </div>
        <div>
            <label for="document_filter_category" class="block text-sm font-medium text-gray-700">{{ __('Kategori') }}</label>
            <select id="document_filter_category" class="{{ $input }} mt-1" wire:model.live="category">
                <option value="">{{ __('Alla kategorier') }}</option>
                @foreach($categories as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="document_filter_class" class="block text-sm font-medium text-gray-700">{{ __('Sparas som') }}</label>
            <select id="document_filter_class" class="{{ $input }} mt-1" wire:model.live="retention">
                <option value="">{{ __('Alla') }}</option>
                @foreach($classes as $class)
                    <option value="{{ $class->value }}">{{ $class->label() }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if($documents->isEmpty())
        <x-ui.empty-state :title="$filtered ? __('Inga dokument matchar') : __('Inga dokument ännu')">
            {{ $filtered ? __('Prova ett annat ord eller ta bort ett filter.') : __('Ladda upp företagets avtal, försäkringsbrev, tillstånd och policyer här, så finns de på ett ställe med rätt lagringstid.') }}
        </x-ui.empty-state>
    @else
        <div class="overflow-x-auto rounded-3xl bg-white shadow-xs ring-1 ring-stone-200">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">{{ __('Rubrik') }}</th>
                        <th scope="col" class="px-4 py-3 font-medium">{{ __('Kategori') }}</th>
                        <th scope="col" class="px-4 py-3 font-medium">{{ __('Sparas som') }}</th>
                        <th scope="col" class="px-4 py-3 font-medium">{{ __('Sparas till') }}</th>
                        <th scope="col" class="px-4 py-3 font-medium">{{ __('Version') }}</th>
                        <th scope="col" class="px-4 py-3 font-medium">{{ __('Ändrad') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($documents as $document)
                        <tr wire:key="document-{{ $document->id }}">
                            <td class="px-4 py-3">
                                <a href="{{ route('documents.show', $document) }}" class="font-medium text-accent-700 underline" wire:navigate>{{ $document->title }}</a>
                                @if($document->tags->isNotEmpty())
                                    <span class="mt-1 flex flex-wrap gap-1">
                                        @foreach($document->tags as $tag)
                                            <x-ui.badge>{{ $tag->tag }}</x-ui.badge>
                                        @endforeach
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-700">{{ DocumentCategory::labelOf($document->category) }}</td>
                            <td class="px-4 py-3 text-gray-700">
                                {{ $document->retention_class->label() }}
                                @if($document->legal_hold)
                                    <x-ui.badge tone="warning">{{ __('Bevarandespärr') }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 tabular-nums text-gray-700">
                                @if($document->retain_until)
                                    {{ $document->retain_until->toDateString() }}
                                @elseif($document->retention_class === RetentionClass::Contract && ! $document->contract_ends_on)
                                    {{ __('Medan avtalet löper') }}
                                @else
                                    {{ __('Ingen tid satt') }}
                                @endif
                            </td>
                            <td class="px-4 py-3 tabular-nums text-gray-700">{{ $document->currentVersion?->version ?? '–' }}@if($document->versions_count > 1) <span class="text-gray-500">({{ __(':count versioner', ['count' => $document->versions_count]) }})</span>@endif</td>
                            <td class="px-4 py-3 tabular-nums text-gray-700">{{ $document->updated_at->setTimezone('Europe/Stockholm')->toDateString() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $documents->links() }}
    @endif
</div>
