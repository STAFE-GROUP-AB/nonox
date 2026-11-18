@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $starts = [
        App\Actions\Budget\CreateBudgetVersion::EMPTY => [__('Tom budget'), __('Börja från noll och fyll i raderna själv.')],
        App\Actions\Budget\CreateBudgetVersion::LAST_YEAR => [__('Som i fjol'), __('Förra årets utfall per konto och månad, avrundat till hela kronor.')],
        App\Actions\Budget\CreateBudgetVersion::COPY => [__('Kopia av en budget'), __('Alla rader från en annan budget, också en låst.')],
    ];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap gap-3">
        <a href="{{ route('accounting.budgets.report') }}" wire:navigate class="inline-flex items-center rounded-full bg-white px-4 py-2 text-sm font-semibold text-ink ring-1 ring-stone-200 hover:ring-stone-300">{{ __('Budget mot utfall') }}</a>
        <a href="{{ route('accounting.budgets.rolling') }}" wire:navigate class="inline-flex items-center rounded-full bg-white px-4 py-2 text-sm font-semibold text-ink ring-1 ring-stone-200 hover:ring-stone-300">{{ __('Rullande 12 månader') }}</a>
    </div>

    <x-ui.card :title="__('Budgetar')">
        <x-slot:cta>
            @if($canManage && ! $creating && ! $creatingForecast)
                <span class="flex flex-wrap gap-2">
                    <x-button type="button" wire:click="create">{{ __('Ny budget') }}</x-button>
                    <x-secondary-button type="button" wire:click="createForecast">{{ __('Ny prognos') }}</x-secondary-button>
                </span>
            @endif
        </x-slot:cta>

        @if($creating)
            <form wire:submit="save" class="mb-6 space-y-4 rounded-2xl bg-paper p-5 ring-1 ring-stone-200">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field for="budget_name" :label="__('Namn')" error="name">
                        <input id="budget_name" type="text" maxlength="60" class="{{ $input }} mt-1" wire:model="form.name">
                    </x-ui.field>
                    <x-ui.field for="budget_year" :label="__('Räkenskapsår')" error="fiscal_year_id">
                        <select id="budget_year" class="{{ $input }} mt-1" wire:model="form.fiscal_year_id">
                            @foreach($fiscalYears as $year)
                                <option value="{{ $year->id }}">{{ $year->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                </div>
                <fieldset>
                    <legend class="text-sm font-medium text-gray-700">{{ __('Börja med') }}</legend>
                    <div class="mt-2 grid gap-3 sm:grid-cols-3">
                        @foreach($starts as $value => [$label, $description])
                            <label wire:key="start-{{ $value }}" @class([
                                'flex cursor-pointer flex-col gap-1 rounded-2xl bg-white p-4 ring-1 transition',
                                'ring-2 ring-ink' => $form['start'] === $value,
                                'ring-stone-200 hover:ring-stone-300' => $form['start'] !== $value,
                            ])>
                                <span class="flex items-center gap-2 font-semibold text-ink"><input type="radio" class="text-ink focus:ring-accent-500" value="{{ $value }}" wire:model.live="form.start">{{ $label }}</span>
                                <span class="text-xs text-gray-600">{{ $description }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('start')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
                </fieldset>
                @if($form['start'] !== App\Actions\Budget\CreateBudgetVersion::EMPTY)
                    <div class="grid gap-4 sm:grid-cols-2">
                        @if($form['start'] === App\Actions\Budget\CreateBudgetVersion::COPY)
                            <x-ui.field for="budget_source" :label="__('Budget att kopiera')" error="source_version_id">
                                <select id="budget_source" class="{{ $input }} mt-1" wire:model="form.source_version_id">
                                    <option value="">{{ __('Välj budget') }}</option>
                                    @foreach($versions as $version)
                                        <option value="{{ $version->id }}">{{ $version->name }} · {{ $version->fiscalYear->label() }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        @endif
                        <x-ui.field for="budget_percent" :label="__('Förändring i procent')" error="percent">
                            <input id="budget_percent" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.percent" placeholder="{{ __('Till exempel 5 eller -2,5') }}">
                        </x-ui.field>
                    </div>
                @endif
                <div class="flex flex-wrap gap-3">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Skapa budget') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        @endif

        @if($creatingForecast)
            <form wire:submit="saveForecast" class="mb-6 space-y-4 rounded-2xl bg-paper p-5 ring-1 ring-stone-200" data-forecast-form>
                <p class="text-sm text-gray-700">{{ __('En prognos tar utfallet ur bokföringen till och med brytmånaden och räknar fram resten av året. Den bokförs aldrig.') }}</p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.field for="forecast_name" :label="__('Namn')" error="name">
                        <input id="forecast_name" type="text" maxlength="60" class="{{ $input }} mt-1" wire:model="forecastForm.name">
                    </x-ui.field>
                    <x-ui.field for="forecast_year" :label="__('Räkenskapsår')" error="fiscal_year_id">
                        <select id="forecast_year" class="{{ $input }} mt-1" wire:model.live="forecastForm.fiscal_year_id">
                            @foreach($fiscalYears as $year)
                                <option value="{{ $year->id }}">{{ $year->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="forecast_cutoff" :label="__('Utfall till och med')" error="cutoff">
                        <select id="forecast_cutoff" class="{{ $input }} mt-1" wire:model.live="forecastForm.cutoff">
                            @forelse($cutoffs as $month)
                                <option value="{{ $month }}">{{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->locale(app()->getLocale())->isoFormat('MMMM YYYY') }}</option>
                            @empty
                                <option value="">{{ __('Ingen månad har tagit slut ännu') }}</option>
                            @endforelse
                        </select>
                    </x-ui.field>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-ui.field for="forecast_revenue" :label="__('Metod för intäkter')" error="revenue_method">
                        <select id="forecast_revenue" class="{{ $input }} mt-1" wire:model="forecastForm.revenue_method">
                            @foreach($methods as $method)
                                <option value="{{ $method->value }}">{{ $method->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="forecast_costs" :label="__('Metod för kostnader')" error="cost_method">
                        <select id="forecast_costs" class="{{ $input }} mt-1" wire:model="forecastForm.cost_method">
                            @foreach($methods as $method)
                                <option value="{{ $method->value }}">{{ $method->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="forecast_average" :label="__('Månader i snittet')" error="average_months">
                        <input id="forecast_average" type="number" min="1" max="12" class="{{ $input }} mt-1" wire:model.live.blur="forecastForm.average_months">
                    </x-ui.field>
                    <x-ui.field for="forecast_growth" :label="__('Förändring mot i fjol i procent')" error="growth">
                        <input id="forecast_growth" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="forecastForm.growth" placeholder="{{ __('Till exempel 5 eller -2,5') }}">
                    </x-ui.field>
                </div>
                <ul class="space-y-1 text-sm text-gray-700" data-forecast-notes>
                    <li>{{ App\Domain\Budget\ForecastEngine::averageNote($forecastAverageWindow) }}</li>
                    @unless($forecastHasPreviousYear)
                        <li>{{ __('Det finns inget föregående räkenskapsår, så Samma månad i fjol ger 0.') }}</li>
                    @endunless
                    @unless($forecastHasBudget)
                        <li>{{ __('Räkenskapsåret har ingen budget som används i rapporterna, så intäkterna räknas med snittet. Gör en budget först om intäkterna ska följa den.') }}</li>
                    @endunless
                    @if($forecastCutoffUnlocked)
                        <li class="font-medium text-amber-800">{{ __('Brytmånaden är inte låst, så den kan sakna verifikationer som bokförs i efterhand.') }}</li>
                    @endif
                </ul>
                @if($forecasts->isNotEmpty())
                    <x-ui.field for="forecast_source" :label="__('Utgå från en tidigare prognos')" error="source_version_id">
                        <select id="forecast_source" class="{{ $input }} mt-1 sm:max-w-md" wire:model="forecastForm.source_version_id">
                            <option value="">{{ __('Nej, börja om') }}</option>
                            @foreach($forecasts as $forecast)
                                <option value="{{ $forecast->id }}">{{ $forecast->name }} · {{ $forecast->fiscalYear->label() }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500">{{ __('Radernas egna metoder och de månader du har ändrat för hand efter den nya brytmånaden följer med.') }}</p>
                    </x-ui.field>
                @endif
                <div class="flex flex-wrap gap-3">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Skapa prognos') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        @endif

        @if($versions->isEmpty())
            <x-ui.empty-state :title="__('Ingen budget ännu')">{{ __('Skapa en budget för räkenskapsåret. Börja tomt, med förra årets utfall eller med en kopia av en annan budget.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="py-2 pe-4 font-medium">{{ __('Namn') }}</th>
                            <th class="py-2 pe-4 font-medium">{{ __('Räkenskapsår') }}</th>
                            <th class="py-2 pe-4 font-medium">{{ __('Status') }}</th>
                            <th class="py-2 pe-4 text-right font-medium">{{ __('Rader') }}</th>
                            <th class="py-2 font-medium"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($versions as $version)
                            <tr wire:key="budget-{{ $version->id }}">
                                <td class="py-3 pe-4">
                                    <a href="{{ route('accounting.budgets.show', ['budgetVersion' => $version->id]) }}" wire:navigate class="font-semibold text-ink underline-offset-2 hover:underline">{{ $version->name }}</a>
                                    @if($version->kind === App\Domain\Budget\BudgetKind::Forecast)
                                        <x-ui.badge tone="neutral" class="ms-2">{{ __('Prognos, utfall t.o.m. :month', ['month' => $version->forecast_cutoff?->locale(app()->getLocale())->isoFormat('MMM YYYY')]) }}</x-ui.badge>
                                    @endif
                                    @if($version->is_active)
                                        <x-ui.badge tone="accent" class="ms-2">{{ $version->kind === App\Domain\Budget\BudgetKind::Forecast ? __('Används i Rullande 12 månader') : __('Används i rapporterna') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="py-3 pe-4 text-gray-700">{{ $version->fiscalYear->label() }}</td>
                                <td class="py-3 pe-4"><x-ui.badge :tone="$version->isLocked() ? 'neutral' : 'warning'">{{ $version->status->label() }}</x-ui.badge></td>
                                <td class="py-3 pe-4 text-right tabular-nums">{{ $version->lines_count }}</td>
                                <td class="py-3 text-right whitespace-nowrap">
                                    @if($canManage)
                                        @unless($version->is_active)
                                            <button type="button" wire:click="activate({{ $version->id }})" class="text-sm font-medium text-gray-700 underline">{{ $version->kind === App\Domain\Budget\BudgetKind::Forecast ? __('Använd i Rullande 12 månader') : __('Använd i rapporterna') }}</button>
                                        @endunless
                                        <button type="button" wire:click="delete({{ $version->id }})" wire:confirm="{{ $version->kind === App\Domain\Budget\BudgetKind::Forecast ? __('Ta bort prognosen :name med alla rader? Bokföringen påverkas inte, men prognosen går inte att få tillbaka.', ['name' => $version->name]) : __('Ta bort budgeten :name med alla rader? Bokföringen påverkas inte, men budgeten går inte att få tillbaka.', ['name' => $version->name]) }}" class="ms-3 text-sm font-medium text-gray-600 underline">{{ __('Ta bort') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
