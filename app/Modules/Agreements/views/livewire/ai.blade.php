@php($select = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm')
<div class="space-y-6">
    <x-ui.card :title="__('Så fungerar det')">
        <ul class="list-disc space-y-1 pl-5 text-sm text-gray-700">
            <li>{{ __('NONOX AI läser bara texten i dokumentet. Personnummer, bankuppgifter, adresser, e-postadresser och telefonnummer döljs innan texten skickas till er AI-leverantör.') }}</li>
            <li>{{ __('AI:n föreslår fakta om företaget och varje anställning, med meningen och sidan den läste det från. Avtalets belopp och satser kommer aldrig från AI:n utan från den kontrollerade avtalsdatan.') }}</li>
            <li>{{ __('Inget förs in förrän du godkänner det. Ålder och minimilön räknas av NONOX från födelsedatumet, inte av AI:n. AI:n bedömer aldrig en person.') }}</li>
        </ul>
    </x-ui.card>

    <x-ui.card :title="__('Ladda upp dokument')" :description="__('För företaget: hängavtal, medlemsbekräftelse från arbetsgivarorganisationen eller ett lönebesked. För en anställd: anställningsavtalet eller ett lönebesked. PDF med text eller textfil, högst 5 MB.')">
        <form wire:submit="upload" class="grid gap-3 sm:grid-cols-3">
            <x-ui.field for="ai_employee" :label="__('Gäller')">
                <select id="ai_employee" wire:model.live="employeeId" class="{{ $select }}">
                    <option value="">{{ __('Företaget') }}</option>
                    @foreach($employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->first_name }} {{ $employee->last_name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="ai_type" :label="__('Dokument')" error="document_type">
                <select id="ai_type" wire:model="documentType" class="{{ $select }}">
                    @foreach($employeeId === '' ? App\Modules\Agreements\Models\AgreementDocument::COMPANY_TYPES : App\Modules\Agreements\Models\AgreementDocument::EMPLOYEE_TYPES as $type)
                        <option value="{{ $type }}">{{ App\Modules\Agreements\Models\AgreementDocument::typeLabel($type) }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="ai_file" :label="__('Fil')" error="document">
                <input id="ai_file" type="file" wire:model="document" accept="application/pdf,text/plain,.txt,.pdf" class="mt-1 block w-full text-sm">
            </x-ui.field>
            <div class="sm:col-span-3">
                <x-button type="submit" wire:loading.attr="disabled">{{ __('Ladda upp') }}</x-button>
            </div>
        </form>
    </x-ui.card>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Företagets dokument')" :description="__('Vilket avtal och vilken variant företaget är bundet av.')">
            @forelse($companyDocuments as $document)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-2 text-sm last:border-0">
                    <span>{{ App\Modules\Agreements\Models\AgreementDocument::typeLabel($document->document_type) }} <span class="text-gray-500">{{ $document->original_name }}</span>
                        @if($document->analyzed_at)<span class="block text-xs text-gray-500">{{ __('Läst :date', ['date' => $document->analyzed_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i')]) }}</span>@endif
                    </span>
                    <span class="flex gap-3">
                        <button type="button" class="font-medium text-accent-600 hover:text-accent-700" wire:click="identify({{ $document->id }})" wire:loading.attr="disabled">{{ __('Låt NONOX AI läsa') }}</button>
                        <button type="button" class="text-gray-600 underline" wire:click="deleteDocument({{ $document->id }})" wire:confirm="{{ __('Ta bort dokumentet och förslagen som inte är hanterade?') }}">{{ __('Ta bort') }}</button>
                    </span>
                </div>
            @empty
                <p class="text-sm text-gray-600">{{ __('Inga dokument ännu.') }}</p>
            @endforelse
        </x-ui.card>

        <x-ui.card :title="__('Anställdas dokument')" :description="__('Avtal, lönegrupp, befattning, anställningsform, veckoarbetstid och branschvana per anställd.')">
            @forelse($employeeDocuments as $document)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-2 text-sm last:border-0">
                    <span>{{ $document->employee?->first_name }} {{ $document->employee?->last_name }} <span class="text-gray-500">{{ App\Modules\Agreements\Models\AgreementDocument::typeLabel($document->document_type) }}</span>
                        <span class="block text-xs text-gray-500">{{ $document->analyzed_at ? __('Läst :date', ['date' => $document->analyzed_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i')]) : __('Inte läst ännu') }}</span>
                    </span>
                    <button type="button" class="text-gray-600 underline" wire:click="deleteDocument({{ $document->id }})" wire:confirm="{{ __('Ta bort dokumentet och förslagen som inte är hanterade?') }}">{{ __('Ta bort') }}</button>
                </div>
            @empty
                <p class="text-sm text-gray-600">{{ __('Inga anställningsavtal ännu.') }}</p>
            @endforelse
            <x-button type="button" class="mt-4" wire:click="analyzeAll" wire:loading.attr="disabled">{{ __('Analysera all personal') }}</x-button>
            <x-input-error for="analysis" class="mt-2" />
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Förslag att granska')" :description="__('Jämför varje förslag med citatet och det som är registrerat i dag. Godkänn, ändra eller avvisa. Förslag vars citat inte hittades ordagrant i dokumentet kan bara godkännas ett och ett.')">
        <x-input-error for="proposal" class="mb-3" />
        @if($groups->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Inga förslag väntar.') }}</p>
        @else
            <x-button type="button" wire:click="acceptMatching" wire:confirm="{{ __('Godkänn och för in alla förslag vars citat finns i dokumentet?') }}">{{ __('Godkänn alla som stämmer med dokumentet') }}</x-button>
            @foreach($groups as $employeeId => $proposals)
                @php($first = $proposals->first())
                <div class="mt-6">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900">{{ $employeeId === '' ? __('Företaget') : trim($first->employee?->first_name.' '.$first->employee?->last_name) }}</h3>
                        @if($employeeId !== '')
                            <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="acceptMatching({{ (int) $employeeId }})">{{ __('Godkänn alla för den här personen som stämmer med dokumentet') }}</button>
                        @endif
                    </div>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-gray-500">
                                    <th class="py-1 pr-4 font-medium">{{ __('Uppgift') }}</th>
                                    <th class="py-1 pr-4 font-medium">{{ __('Förslag') }}</th>
                                    <th class="py-1 pr-4 font-medium">{{ __('Registrerat i dag') }}</th>
                                    <th class="py-1 pr-4 font-medium">{{ __('Ur dokumentet') }}</th>
                                    <th class="py-1 font-medium"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($proposals as $proposal)
                                    <tr>
                                        <td class="py-2 pr-4 align-top">{{ App\Modules\Agreements\Actions\AgreementAi::fieldLabel($proposal->field) }}
                                            @if(in_array($proposal->field, App\Modules\Agreements\Actions\AgreementAi::INFORMATION_FIELDS, true))
                                                <span class="block text-xs text-gray-500">{{ __('Jämförs bara; ändras under Lön → Anställda.') }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 align-top font-medium text-gray-900">
                                            @if($proposal->field === 'binding')
                                                {{ $agreements->get($proposal->value['agreement_key'] ?? '')?->agreementRules()->name() ?? ($proposal->value['agreement_key'] ?? '') }}
                                                <span class="block text-xs font-normal text-gray-600">{{ filled($proposal->value['variant'] ?? null) ? App\Modules\Agreements\Models\AgreementRuleVersion::variantLabel($proposal->value['variant']) : __('Variant okänd') }}{{ filled($proposal->value['bound_from'] ?? null) ? ', '.__('från :date', ['date' => $proposal->value['bound_from']]) : '' }}</span>
                                            @elseif($proposal->field === 'override')
                                                {{ App\Modules\Agreements\Domain\LocalOverrides::label((string) ($proposal->value['path'] ?? '')) }}: {{ App\Modules\Agreements\Domain\LocalOverrides::describe((string) ($proposal->value['path'] ?? ''), $proposal->value['value'] ?? null) }}
                                                <span class="block text-xs font-normal text-gray-600">{{ $proposal->value['description'] ?? '' }}</span>
                                                <span class="block text-xs font-normal text-gray-500">{{ __('Används i lönen först när en behörig person har kontrollerat den under Lön → Avtal → Lokala avtal.') }}</span>
                                            @else
                                                {{ $proposal->value }}
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 align-top text-gray-600">{{ App\Modules\Agreements\Actions\AgreementAi::registered($proposal, $proposal->employment) }}</td>
                                        <td class="py-2 pr-4 align-top">
                                            @if($proposal->quote)<q class="text-gray-700">{{ $proposal->quote }}</q>@endif
                                            <span class="block text-xs text-gray-500">{{ $proposal->page ? __('Sida :page', ['page' => $proposal->page]) : '' }}
                                                @if(! $proposal->quote_found)<span class="text-accent-700">{{ __('Citatet hittades inte i dokumentet.') }}</span>@endif
                                            </span>
                                        </td>
                                        <td class="py-2 align-top text-right whitespace-nowrap">
                                            <button type="button" class="font-medium text-accent-600 hover:text-accent-700" wire:click="accept({{ $proposal->id }})">{{ __('Godkänn') }}</button>
                                            <button type="button" class="ml-2 text-gray-700 underline" wire:click="edit({{ $proposal->id }})">{{ __('Ändra') }}</button>
                                            <button type="button" class="ml-2 text-gray-600 underline" wire:click="reject({{ $proposal->id }})">{{ __('Avvisa') }}</button>
                                        </td>
                                    </tr>
                                    @if($editing === $proposal->id)
                                        <tr>
                                            <td colspan="5" class="bg-gray-50 px-4 py-3">
                                                <form wire:submit="saveEdit" class="flex flex-wrap items-end gap-3">
                                                    @if($proposal->field === 'binding')
                                                        <x-ui.field for="edit_variant" :label="__('Bundet som')">
                                                            <select id="edit_variant" wire:model="editBinding.variant" class="{{ $select }}">
                                                                <option value="">{{ __('Välj') }}</option>
                                                                @foreach(App\Modules\Agreements\Models\AgreementRuleVersion::VARIANTS as $variant)
                                                                    <option value="{{ $variant }}">{{ App\Modules\Agreements\Models\AgreementRuleVersion::variantLabel($variant) }}</option>
                                                                @endforeach
                                                            </select>
                                                        </x-ui.field>
                                                        <x-ui.field for="edit_from" :label="__('Bundet från')">
                                                            <input id="edit_from" type="date" wire:model="editBinding.bound_from" class="{{ $select }}">
                                                        </x-ui.field>
                                                    @else
                                                        <x-ui.field for="edit_value" :label="App\Modules\Agreements\Actions\AgreementAi::fieldLabel($proposal->field)" :hint="$proposal->field === 'override' ? __('Kronor (30), procent (35 %) eller delare (/600), som det lokala avtalet säger.') : null">
                                                            <input id="edit_value" type="text" wire:model="editValue" class="{{ $select }}">
                                                        </x-ui.field>
                                                    @endif
                                                    <x-button type="submit">{{ __('Spara och för in') }}</x-button>
                                                    <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('editing', null)">{{ __('Avbryt') }}</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @endif
    </x-ui.card>
</div>
