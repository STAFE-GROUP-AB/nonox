@php
    use App\Domain\Documents\DocumentCategory;
    use App\Domain\Documents\RetentionClass;
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $current = $document->versions->firstWhere('id', $document->current_version_id);
@endphp
<div class="space-y-6">
    <x-ui.page-heading :title="$document->title" :lead="DocumentCategory::labelOf($document->category).' · '.$document->retention_class->label()">
        <x-slot:action>
            <a href="{{ route('documents.index') }}" class="text-sm font-medium text-accent-700 underline" wire:navigate>{{ __('Alla dokument') }}</a>
        </x-slot:action>
    </x-ui.page-heading>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card :title="__('Aktuell version')">
                @if($current)
                    @php($url = route('documents.file', [$document, $current->id]))
                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                        @if($current->isImage())
                            <img src="{{ $url }}" alt="{{ $current->original_name }}" class="w-full object-contain">
                        @elseif($current->isPdf())
                            <iframe src="{{ $url }}" title="{{ $current->original_name }}" class="h-128 w-full"></iframe>
                        @else
                            <p class="p-4 text-sm text-gray-700">{{ __('Filen kan inte visas här. Ladda ner den för att öppna den.') }} <a href="{{ $url }}" class="font-medium text-accent-700 underline">{{ __('Ladda ner') }}</a></p>
                        @endif
                    </div>
                    <p class="mt-2 text-sm text-gray-600">{{ __('Version :version', ['version' => $current->version]) }} · {{ $current->original_name }} · {{ $current->humanSize() }} · <a href="{{ $url }}" target="_blank" class="text-accent-700 underline">{{ __('Öppna i nytt fönster') }}</a></p>
                    <p class="mt-1 break-all text-xs text-gray-500">{{ __('Kontrollsumma') }}: <span class="font-mono">{{ $current->sha256 }}</span></p>
                @endif

                @if($canManage)
                    <div class="mt-4 border-t border-gray-100 pt-4">
                        <label for="document_new_version" class="block text-sm font-medium text-gray-700">{{ __('Ladda upp en ny version') }}</label>
                        <input id="document_new_version" type="file" class="mt-1 block w-full text-sm" wire:model="newVersion">
                        <p class="mt-1 text-xs text-gray-500">{{ __('Den nya versionen blir aktuell. De tidigare versionerna finns kvar och kan inte ändras.') }}</p>
                        <p class="mt-1 text-xs text-gray-500" wire:loading wire:target="newVersion">{{ __('Laddar upp…') }}</p>
                        <x-input-error for="newVersion" class="mt-2" />
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('Versioner')">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                                <th scope="col" class="py-1 pr-3 font-medium">{{ __('Version') }}</th>
                                <th scope="col" class="py-1 pr-3 font-medium">{{ __('Fil') }}</th>
                                <th scope="col" class="py-1 pr-3 font-medium">{{ __('Uppladdad') }}</th>
                                <th scope="col" class="py-1 pr-3 font-medium">{{ __('Av') }}</th>
                                <th scope="col" class="py-1 font-medium">{{ __('Kontrollsumma') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($document->versions as $version)
                                <tr wire:key="version-{{ $version->id }}">
                                    <td class="py-2 pr-3 tabular-nums">{{ $version->version }}@if($version->id === $document->current_version_id) <x-ui.badge tone="accent">{{ __('Aktuell') }}</x-ui.badge>@endif</td>
                                    <td class="py-2 pr-3"><a href="{{ route('documents.file', [$document, $version->id]) }}" class="text-accent-700 underline" target="_blank">{{ $version->original_name }}</a> <span class="text-gray-500">{{ $version->humanSize() }}</span></td>
                                    <td class="py-2 pr-3 tabular-nums">{{ $version->created_at->setTimezone('Europe/Stockholm')->format('Y-m-d H:i') }}</td>
                                    <td class="py-2 pr-3">{{ $version->uploader?->name ?? '–' }}</td>
                                    <td class="py-2 font-mono text-xs text-gray-500" title="{{ $version->sha256 }}">{{ substr($version->sha256, 0, 12) }}…</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Kopplingar')" :description="__('Koppla dokumentet till en kund, leverantör, ett projekt eller en annan post i företaget.')">
                @if($linked === [])
                    <p class="text-sm text-gray-600">{{ __('Dokumentet är inte kopplat till något.') }}</p>
                @else
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach($linked as $link)
                            <li class="flex items-center justify-between gap-3 py-2" wire:key="link-{{ $link['id'] }}">
                                <span><span class="text-gray-500">{{ $link['type']->label() }}:</span> {{ $link['name'] }}</span>
                                @if($canManage)
                                    <button type="button" class="text-gray-600 underline" wire:click="removeLink({{ $link['id'] }})">{{ __('Ta bort kopplingen') }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($canManage && $linkTypes !== [])
                    <form wire:submit="addLink" class="mt-4 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4" aria-label="{{ __('Lägg till koppling') }}">
                        <x-ui.field for="document_link_type" :label="__('Koppla till')" error="link_type">
                            <select id="document_link_type" class="{{ $input }} mt-1" wire:model.live="linkType">
                                <option value="">{{ __('Välj') }}</option>
                                @foreach($linkTypes as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        @if($linkType !== '')
                            <x-ui.field for="document_link_id" :label="__('Post')" error="link_id" class="min-w-0 flex-1 basis-56">
                                <select id="document_link_id" class="{{ $input }} mt-1" wire:model="linkId">
                                    <option value="">{{ __('Välj') }}</option>
                                    @foreach($linkOptions as $option)
                                        <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                            <x-secondary-button type="submit">{{ __('Lägg till koppling') }}</x-secondary-button>
                        @endif
                    </form>
                @endif
            </x-ui.card>

            @if($document->retention_class === RetentionClass::Accounting)
                <x-ui.card :title="__('Underlag till verifikationer')" :description="__('Är dokumentet underlag till en verifikation läggs samma fil till som underlag, utan kopia. Verifikationen ändras inte.')">
                    @if($underlag->isEmpty())
                        <p class="text-sm text-gray-600">{{ __('Dokumentet är inte underlag till någon verifikation.') }}</p>
                    @else
                        <ul class="flex flex-wrap gap-3 text-sm">
                            @foreach($underlag as $voucher)
                                <li wire:key="underlag-{{ $voucher->id }}"><a href="{{ route('accounting.vouchers.show', $voucher->id) }}" class="font-medium text-accent-700 underline" wire:navigate>{{ $voucher->reference() }}</a></li>
                            @endforeach
                        </ul>
                    @endif

                    @if($canAttach)
                        <form wire:submit="attachToVoucher" class="mt-4 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4" aria-label="{{ __('Lägg som underlag') }}">
                            <x-ui.field for="document_voucher" :label="__('Verifikation i räkenskapsåret')" error="voucher" :hint="__('Serie och nummer, till exempel A12.')">
                                <input id="document_voucher" type="text" maxlength="20" class="{{ $input }} mt-1 w-40" wire:model="voucherReference">
                            </x-ui.field>
                            <x-secondary-button type="submit">{{ __('Lägg som underlag') }}</x-secondary-button>
                        </form>
                    @endif
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-6">
            <x-ui.card :title="__('Uppgifter')">
                @if(! $editing)
                    <dl class="space-y-3 text-sm">
                        <div><dt class="text-gray-500">{{ __('Kategori') }}</dt><dd class="text-gray-900">{{ DocumentCategory::labelOf($document->category) }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Sparas som') }}</dt><dd class="text-gray-900">{{ $document->retention_class->label() }}</dd></div>
                        @if($document->fiscalYear)
                            <div><dt class="text-gray-500">{{ __('Räkenskapsår') }}</dt><dd class="tabular-nums text-gray-900">{{ $document->fiscalYear->start_date->toDateString() }} – {{ $document->fiscalYear->end_date->toDateString() }}</dd></div>
                        @endif
                        @if($document->is_accounting_record)
                            <div><dt class="text-gray-500">{{ __('Räkenskapsinformation') }}</dt><dd class="text-gray-900">{{ __('Ja') }}</dd></div>
                        @endif
                        @if($document->retention_class === RetentionClass::Contract)
                            <div><dt class="text-gray-500">{{ __('Avtalet upphör') }}</dt><dd class="tabular-nums text-gray-900">{{ $document->contract_ends_on?->toDateString() ?? __('Löper tills vidare') }}</dd></div>
                        @endif
                        <div><dt class="text-gray-500">{{ __('Sparas till och med') }}</dt><dd class="tabular-nums text-gray-900">{{ $document->retain_until?->toDateString() ?? __('Ingen tid satt') }}</dd></div>
                        <div><dt class="text-gray-500">{{ __('Personuppgifter') }}</dt><dd class="text-gray-900">{{ $document->contains_personal_data ? __('Ja') : __('Nej') }}</dd></div>
                        <div>
                            <dt class="text-gray-500">{{ __('Taggar') }}</dt>
                            <dd class="mt-1 flex flex-wrap gap-1">
                                @forelse($document->tags as $tag)
                                    <x-ui.badge>{{ $tag->tag }}</x-ui.badge>
                                @empty
                                    <span class="text-gray-900">–</span>
                                @endforelse
                            </dd>
                        </div>
                    </dl>
                    @if($canManage)
                        <x-secondary-button type="button" class="mt-4" wire:click="edit">{{ __('Ändra uppgifter') }}</x-secondary-button>
                    @endif
                @else
                    <form wire:submit="save" class="space-y-4" aria-label="{{ __('Ändra uppgifter') }}">
                        <x-ui.field for="edit_title" :label="__('Rubrik')" error="title" :hint="$document->retention_class === RetentionClass::Personnel ? __('Skriv inte hälsouppgifter i rubriken. Rubriken på ett personaldokument lagras krypterad.') : null">
                            <input id="edit_title" type="text" maxlength="200" class="{{ $input }} mt-1" wire:model="form.title">
                        </x-ui.field>
                        <x-ui.field for="edit_category" :label="__('Kategori')" error="category">
                            <input id="edit_category" type="text" maxlength="60" list="edit_categories" class="{{ $input }} mt-1" wire:model="form.category">
                            <datalist id="edit_categories">
                                @foreach($categories as $category)
                                    <option value="{{ $category->value }}">{{ $category->label() }}</option>
                                @endforeach
                            </datalist>
                        </x-ui.field>
                        <x-ui.field for="edit_retention" :label="__('Sparas som')" error="retention_class">
                            <select id="edit_retention" class="{{ $input }} mt-1" wire:model.live="form.retention_class">
                                @foreach($classes as $class)
                                    <option value="{{ $class->value }}">{{ $class->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        @if(($form['retention_class'] ?? '') === RetentionClass::Accounting->value)
                            <x-ui.field for="edit_fiscal_year" :label="__('Räkenskapsår')" error="fiscal_year_id">
                                <select id="edit_fiscal_year" class="{{ $input }} mt-1" wire:model="form.fiscal_year_id">
                                    <option value="">{{ __('Välj räkenskapsår') }}</option>
                                    @foreach($fiscalYears as $year)
                                        <option value="{{ $year->id }}">{{ $year->start_date->toDateString() }} – {{ $year->end_date->toDateString() }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        @elseif(($form['retention_class'] ?? '') === RetentionClass::Contract->value)
                            <x-ui.field for="edit_contract_ends" :label="__('Avtalet upphör')" error="contract_ends_on" :hint="__('Lämna tomt om avtalet löper tills vidare.')">
                                <input id="edit_contract_ends" type="date" class="{{ $input }} mt-1" wire:model="form.contract_ends_on">
                            </x-ui.field>
                            <x-ui.field for="edit_contract_years" :label="__('Spara antal år efter att avtalet upphört')" error="contract_retention_years">
                                <input id="edit_contract_years" type="number" min="0" max="30" class="{{ $input }} mt-1" wire:model="form.contract_retention_years">
                            </x-ui.field>
                            <div class="sm:col-span-2">
                                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model.live="form.is_accounting_record"> {{ __('Avtalet är räkenskapsinformation') }}</label>
                                <p class="mt-1 text-sm text-gray-500">{{ __('Kryssa i om avtalet visar en affärshändelse som ska bokföras, till exempel ett leasing- eller hyresavtal (bokföringslagen 1 kap. 2 §). Då sparas det i minst sju år efter räkenskapsåret och kommer med i årets arkivpaket.') }}</p>
                            </div>
                            @if(! empty($form['is_accounting_record']))
                                <x-ui.field for="edit_contract_year" :label="__('Räkenskapsår')" error="fiscal_year_id">
                                    <select id="edit_contract_year" class="{{ $input }} mt-1" wire:model="form.fiscal_year_id">
                                        <option value="">{{ __('Välj räkenskapsår') }}</option>
                                        @foreach($fiscalYears as $year)
                                            <option value="{{ $year->id }}">{{ $year->start_date->toDateString() }} – {{ $year->end_date->toDateString() }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                            @endif
                        @endif
                        <x-ui.field for="edit_keep_until" :label="__('Spara minst till och med')" error="keep_until">
                            <input id="edit_keep_until" type="date" class="{{ $input }} mt-1" wire:model="form.keep_until">
                        </x-ui.field>
                        <x-ui.field for="edit_tags" :label="__('Taggar')" error="tags">
                            <input id="edit_tags" type="text" class="{{ $input }} mt-1" wire:model="form.tags">
                        </x-ui.field>
                        <fieldset>
                            <legend class="block text-sm font-medium text-gray-700">{{ __('Innehåller dokumentet personuppgifter?') }}</legend>
                            <div class="mt-2 flex gap-6 text-sm">
                                <label class="flex items-center gap-2"><input type="radio" value="1" wire:model="form.contains_personal_data"> {{ __('Ja') }}</label>
                                <label class="flex items-center gap-2"><input type="radio" value="0" wire:model="form.contains_personal_data"> {{ __('Nej') }}</label>
                            </div>
                            <x-input-error for="contains_personal_data" class="mt-2" />
                        </fieldset>
                        <div class="flex items-center gap-3">
                            <x-button>{{ __('Spara') }}</x-button>
                            <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                        </div>
                    </form>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('Bevarande')">
                @if($document->legal_hold)
                    <p class="text-sm text-gray-700"><x-ui.badge tone="warning">{{ __('Bevarandespärr') }}</x-ui.badge> {{ __('Satt :date. Dokumentet kan inte raderas förrän spärren hävs.', ['date' => $document->legal_hold_at?->setTimezone('Europe/Stockholm')->toDateString()]) }}</p>
                    @if($document->legal_hold_reason)
                        <p class="mt-1 text-sm text-gray-600">{{ __('Skäl') }}: {{ $document->legal_hold_reason->label() }}</p>
                    @endif
                @else
                    <p class="text-sm text-gray-600">{{ __('En bevarandespärr hindrar att dokumentet raderas, till exempel under en skatterevision eller en tvist.') }}</p>
                @endif

                @if($canHold)
                    <div class="mt-3">
                        @if($document->legal_hold)
                            <x-secondary-button type="button" wire:click="setHold(false)" wire:confirm="{{ __('Häva bevarandespärren? Dokumentet kan sedan raderas när lagringstiden tillåter det.') }}">{{ __('Häv bevarandespärren') }}</x-secondary-button>
                        @else
                            <div class="flex flex-wrap items-end gap-3">
                                <x-ui.field for="document_hold_reason" :label="__('Skäl')" error="hold_reason">
                                    <select id="document_hold_reason" class="{{ $input }} mt-1" wire:model="holdReason">
                                        <option value="">{{ __('Välj') }}</option>
                                        @foreach($holdReasons as $reason)
                                            <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                                <x-secondary-button type="button" wire:click="setHold(true)">{{ __('Sätt bevarandespärr') }}</x-secondary-button>
                            </div>
                        @endif
                    </div>
                @endif

                @if($canManage)
                    <div class="mt-5 border-t border-gray-100 pt-4">
                        @if($blocker)
                            <p class="text-sm text-gray-600">{{ $blocker }}</p>
                        @else
                            <x-danger-button type="button" wire:click="deleteDocument" wire:confirm="{{ __('Radera dokumentet med alla versioner? Det går inte att ångra.') }}">{{ __('Radera dokumentet') }}</x-danger-button>
                        @endif
                        <x-input-error for="document" class="mt-2" />
                    </div>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
