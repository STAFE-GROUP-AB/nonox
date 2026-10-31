@php
    use App\Domain\Accounting\SieImport\SieImportStatus;
    use App\Support\Money\Money;

    $year = $report['fiscal_year'] ?? [];
    $blockers = $report['blockers'] ?? [];
    $warnings = $report['warnings'] ?? [];
@endphp

<div class="space-y-6" @if($import->status->isInProgress()) wire:poll.2s @endif>
    {{-- What happened, or what happens next --}}
    @if($import->status === SieImportStatus::Completed)
        <x-ui.card :title="__('Filen är inläst')">
            <p class="text-gray-700">{{ __(':count verifikationer är bokförda i räkenskapsåret :year, och varje konto stämmer med filen.', ['count' => $result['vouchers_imported'] ?? 0, 'year' => $import->fiscalYear?->label()]) }}</p>
            <ul class="mt-4 space-y-1 text-sm text-gray-600">
                <li>✓ {{ __('Antal verifikationer: :here här, :file i filen.', ['here' => $result['vouchers_imported'] ?? 0, 'file' => $result['vouchers_in_file'] ?? 0]) }}</li>
                @if($result['checked_closing'] ?? false)
                    <li>✓ {{ __('Utgående balanser: samma som i filen för alla konton.') }}</li>
                @endif
                @if($result['checked_results'] ?? false)
                    <li>✓ {{ __('Resultat: samma som i filen för alla konton.') }}</li>
                @endif
                @unless(($result['checked_closing'] ?? false) || ($result['checked_results'] ?? false))
                    <li>{{ __('Filen hade inga utgående balanser eller resultat att jämföra med, så bara antalet verifikationer är kontrollerat.') }}</li>
                @endunless
            </ul>
            <div class="mt-5 flex flex-wrap gap-4">
                <a href="{{ route('accounting.vouchers.index') }}" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Visa verifikationerna') }}</a>
                <a href="{{ route('accounting.fiscalYears') }}" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Visa räkenskapsåren') }}</a>
            </div>
        </x-ui.card>
    @elseif($import->status->isInProgress())
        <x-ui.card :title="__('Filen läses in')">
            <p class="text-gray-700">{{ __('Det här kan ta en stund för en stor fil. Du kan lämna sidan; inläsningen fortsätter ändå.') }}</p>
            @if($progress)
                <p class="mt-3 text-sm text-gray-600 tabular-nums">{{ __(':done av :total verifikationer', ['done' => $progress['done'], 'total' => $progress['total']]) }}</p>
            @endif
        </x-ui.card>
    @elseif($import->status === SieImportStatus::Failed)
        <x-ui.card :title="__('Filen kunde inte läsas in')">
            <p class="text-gray-700">{{ __('Ingenting har ändrats i bokföringen.') }}</p>
            <ul class="mt-3 list-disc space-y-1 ps-5 text-sm text-accent-700">
                @foreach(explode("\n", (string) $import->error) as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
            @if(! empty($result['differences']))
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium">{{ __('Konto') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Vad') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('I filen') }}</th>
                                <th class="py-2 text-right font-medium">{{ __('Här') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($result['differences'] as $difference)
                                <tr>
                                    <td class="py-2 pr-4 tabular-nums">{{ $difference['account'] }}</td>
                                    <td class="py-2 pr-4">{{ $difference['kind'] === 'UB' ? __('Utgående balans') : __('Resultat') }}</td>
                                    <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($difference['file'])" :currency="false" /></td>
                                    <td class="py-2 text-right"><x-ui.amount :money="Money::ofOre($difference['here'])" :currency="false" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p class="mt-4 text-sm text-gray-600">
                <a href="{{ route('accounting.sieImports.index') }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Ladda upp filen igen') }}</a>
                {{ __('när det som står ovan är rättat.') }}
            </p>
        </x-ui.card>
    @elseif($blockers !== [])
        <x-ui.card :title="__('Filen kan inte läsas in som den är')">
            <p class="text-gray-700">{{ __('Ingenting har ändrats. Det här behöver rättas först:') }}</p>
            <ul class="mt-3 list-disc space-y-1 ps-5 text-sm text-accent-700">
                @foreach($blockers as $blocker)
                    <li>{{ $blocker }}</li>
                @endforeach
            </ul>
            @if($wrongCompany)
                <div class="mt-5 rounded-2xl bg-gray-50 p-4">
                    @forelse($otherCompanies as $other)
                        <p class="text-sm text-gray-800">{{ __('Filen gäller :company, som du också har tillgång till.', ['company' => $other->name]) }}</p>
                        <x-button type="button" class="mt-3" wire:click="moveTo({{ $other->id }})" wire:loading.attr="disabled">{{ __('Byt till :company och granska filen där', ['company' => $other->name]) }}</x-button>
                    @empty
                        <p class="text-sm text-gray-800">{{ __('Filen gäller :company (:number), som inte finns bland dina företag ännu.', ['company' => $report['company_name'] ?? '?', 'number' => $report['organisation_number'] ?? '?']) }}</p>
                        <x-button type="button" class="mt-3" wire:click="createCompanyFromFile">{{ __('Skapa företaget med uppgifterna från filen') }}</x-button>
                        <p class="mt-2 text-xs text-gray-500">{{ __('När företaget är skapat läser du in filen där.') }}</p>
                    @endforelse
                </div>
            @endif
        </x-ui.card>
    @endif

    {{-- The report --}}
    <x-ui.card :title="__('Det här finns i filen')">
        <dl class="grid gap-x-8 gap-y-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-gray-500">{{ __('Företag') }}</dt>
                <dd class="text-gray-900">{{ $report['company_name'] ?? '–' }} @if($report['organisation_number'] ?? null)<span class="text-gray-600">({{ $report['organisation_number'] }})</span>@endif</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500">{{ __('Räkenskapsår') }}</dt>
                <dd class="text-gray-900 tabular-nums">
                    @if($year['label'] ?? null)
                        {{ $year['start'] }} – {{ $year['end'] }}
                        @if($year['create'] ?? false)
                            <span class="text-sm text-gray-600">· {{ __('skapas här') }}</span>
                        @endif
                    @else
                        –
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500">{{ __('Verifikationer') }}</dt>
                <dd class="text-gray-900 tabular-nums">{{ __(':vouchers verifikationer med :rows rader', ['vouchers' => $report['vouchers'] ?? 0, 'rows' => $report['rows'] ?? 0]) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500">{{ __('Ingående balanser') }}</dt>
                <dd class="text-gray-900">
                    @if(($report['opening_balances'] ?? 0) === 0)
                        {{ __('Inga') }}
                    @elseif($report['write_opening_balances'] ?? false)
                        {{ __(':count konton', ['count' => $report['opening_balances']]) }}
                    @else
                        {{ __(':count konton, finns redan här och stämmer', ['count' => $report['opening_balances']]) }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500">{{ __('Kontrollsumma') }}</dt>
                <dd class="text-gray-900">{{ match ($report['checksum'] ?? 'absent') {
                    'valid' => __('Stämmer, filen är oförändrad'),
                    'invalid' => __('Stämmer inte'),
                    'unterminated' => __('Filen är avbruten'),
                    default => __('Saknas i filen'),
                } }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500">{{ __('Skapad av') }}</dt>
                <dd class="text-gray-900">{{ $report['program'] ?: '–' }}</dd>
            </div>
        </dl>

        @if(! empty($report['series']))
            <h3 class="mt-8 font-semibold text-gray-900">{{ __('Serier och nummer') }}</h3>
            <ul class="mt-2 space-y-1 text-sm text-gray-700">
                @foreach($report['series'] as $series)
                    <li>
                        <span class="font-semibold">{{ $series['code'] }}</span>:
                        {{ __(':count verifikationer', ['count' => $series['vouchers']]) }},
                        @if($series['keep'])
                            {{ __('behåller sina nummer') }}
                        @else
                            {{ __('får nya nummer från :first (filens nummer sparas)', ['first' => $series['first']]) }}
                        @endif
                        @if($series['create'])
                            · {{ __('serien skapas') }}
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if(! empty($report['accounts_to_create']))
            <h3 class="mt-8 font-semibold text-gray-900">{{ __('Konton som läggs till i kontoplanen') }}</h3>
            <ul class="mt-2 grid gap-1 text-sm text-gray-700 sm:grid-cols-2">
                @foreach($report['accounts_to_create'] as $account)
                    <li><span class="tabular-nums">{{ $account['number'] }}</span> {{ $account['name'] }}</li>
                @endforeach
            </ul>
        @endif

        @foreach(['cost_centers_to_create' => __('Kostnadsställen som läggs till'), 'projects_to_create' => __('Projekt som läggs till')] as $key => $heading)
            @if(! empty($report[$key]))
                <h3 class="mt-8 font-semibold text-gray-900">{{ $heading }}</h3>
                <ul class="mt-2 grid gap-1 text-sm text-gray-700 sm:grid-cols-2">
                    @foreach($report[$key] as $object)
                        <li><span class="tabular-nums">{{ $object['code'] }}</span> {{ $object['name'] }}</li>
                    @endforeach
                </ul>
            @endif
        @endforeach

        @if(($report['added_rows'] ?? 0) > 0 || ($report['removed_rows'] ?? 0) > 0)
            <p class="mt-6 text-sm text-gray-600">{{ __('Filen visar :added rader som lagts till och :removed som tagits bort i efterhand. Den historiken sparas med verifikationerna.', ['added' => $report['added_rows'] ?? 0, 'removed' => $report['removed_rows'] ?? 0]) }}</p>
        @endif

        @if(($report['unused_accounts'] ?? 0) > 0)
            <p class="mt-2 text-sm text-gray-600">{{ __(':count konton i filen används inte och läggs inte till.', ['count' => $report['unused_accounts']]) }}</p>
        @endif
    </x-ui.card>

    @if($warnings !== [])
        <x-ui.card :title="__('Bra att veta')">
            <ul class="list-disc space-y-1 ps-5 text-sm text-gray-700">
                @foreach($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @if($canConfirm)
        <x-ui.card :title="__('Läs in filen')">
            <p class="text-gray-700">{{ __('Verifikationerna blir bokförda och kan inte ändras eller tas bort efteråt, bara rättas, precis som allt annat som är bokfört. Efteråt jämförs varje konto med filen. Stämmer något inte läses ingenting in.') }}</p>
            <div class="mt-5" x-data="{ open: false }">
                <x-button type="button" x-on:click="open = true" x-show="! open">{{ __('Läs in filen') }}</x-button>
                <div x-show="open" x-cloak class="flex flex-wrap items-center gap-4">
                    <x-button type="button" wire:click="confirm" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="confirm">{{ __('Ja, bokför :count verifikationer', ['count' => $report['vouchers'] ?? 0]) }}</span>
                        <span wire:loading wire:target="confirm">{{ __('Läser in…') }}</span>
                    </x-button>
                    <button type="button" class="text-sm text-gray-600 underline" x-on:click="open = false">{{ __('Avbryt') }}</button>
                </div>
            </div>
        </x-ui.card>
    @endif
</div>
