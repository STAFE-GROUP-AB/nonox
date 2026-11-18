<div data-budget-sie-import>
    @if($canManage)
        <x-ui.card :title="__('Läs in budget från SIE-fil')" :description="__('Budgeten (#PBUDGET) i en SIE-fil av typ 2, 3 eller 4 blir en ny budget för räkenskapsåret i filen. Bokföringen i filen läses inte in; den läser du in under Läs in SIE-fil.')">
            @if($report === null)
                <form wire:submit="analyse" class="flex flex-wrap items-end gap-4">
                    <x-ui.field for="budget_sie_file" :label="__('SIE-fil')" error="file">
                        <input id="budget_sie_file" type="file" accept=".se,.si,.sie,text/plain" wire:model="file" class="mt-1 block text-sm">
                    </x-ui.field>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="file,analyse">{{ __('Granska filen') }}</x-button>
                </form>
            @else
                <div class="space-y-5">
                    @if($report['blockers'] !== [])
                        <div>
                            <p class="font-semibold text-ink">{{ __('Budgeten kan inte läsas in som den är') }}</p>
                            <ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-accent-700">
                                @foreach($report['blockers'] as $blocker)
                                    <li>{{ $blocker }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        <dl class="grid gap-x-8 gap-y-4 sm:grid-cols-3">
                            <div>
                                <dt class="text-sm text-gray-500">{{ __('Räkenskapsår') }}</dt>
                                <dd class="text-gray-900 tabular-nums">{{ $report['fiscal_year'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">{{ __('Budgetrader') }}</dt>
                                <dd class="text-gray-900 tabular-nums">{{ __(':lines rader på :accounts konton', ['lines' => $report['lines'], 'accounts' => $report['accounts']]) }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-gray-500">{{ __('Budgeterat resultat') }}</dt>
                                <dd class="text-gray-900"><x-ui.amount :money="App\Support\Money\Money::ofOre($report['result_ore'])" /></dd>
                            </div>
                            @if($report['cost_centers'] > 0 || $report['projects'] > 0)
                                <div class="sm:col-span-3">
                                    <dt class="text-sm text-gray-500">{{ __('Kostnadsställen och projekt') }}</dt>
                                    <dd class="text-gray-900">{{ __(':cost_centers kostnadsställen och :projects projekt', ['cost_centers' => $report['cost_centers'], 'projects' => $report['projects']]) }}</dd>
                                </div>
                            @endif
                        </dl>
                    @endif

                    <p class="text-sm text-gray-600">
                        {{ __('Filen gäller :company', ['company' => $report['company_name'] ?: '–']) }}@if($report['organisation_number']) ({{ $report['organisation_number'] }})@endif · {{ __('skapad av :program', ['program' => $report['program'] ?: '–']) }}
                    </p>

                    @if($report['warnings'] !== [])
                        <div>
                            <p class="font-semibold text-ink">{{ __('Bra att veta') }}</p>
                            <ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-gray-700">
                                @foreach($report['warnings'] as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @error('file')
                        <p class="text-sm text-accent-700">{{ $message }}</p>
                    @enderror

                    <div class="flex flex-wrap items-center gap-4">
                        @if($report['blockers'] === [])
                            <x-button type="button" wire:click="import" wire:loading.attr="disabled" wire:target="import">{{ __('Läs in budgeten') }}</x-button>
                        @endif
                        <x-secondary-button type="button" wire:click="cancel">{{ $report['blockers'] === [] ? __('Avbryt') : __('Välj en annan fil') }}</x-secondary-button>
                    </div>
                    @if($report['blockers'] === [])
                        <p class="text-xs text-gray-500">{{ __('Budgeten blir ett utkast som du kan ändra. Den används i rapporterna bara om året inte redan har en budget som gör det.') }}</p>
                    @endif
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
