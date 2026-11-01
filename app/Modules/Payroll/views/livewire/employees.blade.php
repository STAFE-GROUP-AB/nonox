@php
    use App\Modules\Payroll\Domain\PersonalNumber;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
    $types = ['TILLSVIDARE' => __('Tillsvidare'), 'ALLMAN_VISSTID' => __('Allmän visstid'), 'VIKARIAT' => __('Vikariat'), 'SASONG' => __('Säsong'), 'PROVANSTALLNING' => __('Provanställning'), 'TIMANSTALLD' => __('Timanställd')];
    $residences = ['SE' => __('Bosatt i Sverige (skattetabell)'), 'NON_RESIDENT_SINK' => __('Bosatt utomlands, SINK'), 'NON_RESIDENT_A_SINK' => __('Bosatt utomlands, A-SINK')];
@endphp

<div class="space-y-6">
    @if($showForm)
        <x-ui.card :title="$editingId ? __('Ändra anställd') : __('Ny anställd')">
            @if($editingId && auth()->user()->hasTeamPermission(auth()->user()->currentCompany(), 'payroll:view-sensitive'))
                <p class="mb-4 text-sm"><a href="{{ route('privacy.export.employee', $editingId) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Ladda ner registerutdrag (JSON)') }}</a> <span class="text-gray-500">· {{ __('när personen begär ut sina uppgifter enligt GDPR') }}</span></p>
            @endif
            <form wire:submit="save" class="space-y-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field for="employee_first_name" :label="__('Förnamn')" error="first_name"><input id="employee_first_name" type="text" class="{{ $input }} mt-1" wire:model="form.first_name"></x-ui.field>
                    <x-ui.field for="employee_last_name" :label="__('Efternamn')" error="last_name"><input id="employee_last_name" type="text" class="{{ $input }} mt-1" wire:model="form.last_name"></x-ui.field>
                    <x-ui.field for="employee_personal_number" :label="__('Personnummer')" error="personal_number" :hint="__('Tolv siffror, ÅÅÅÅMMDD-NNNN. Sparas krypterat.')"><input id="employee_personal_number" type="text" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="form.personal_number" autocomplete="off" @if($editing) placeholder="{{ PersonalNumber::masked($editing->personal_number) }} ({{ __('tomt behåller') }})" @endif></x-ui.field>
                    <x-ui.field for="employee_email" :label="__('E-post')" error="email"><input id="employee_email" type="email" class="{{ $input }} mt-1" wire:model="form.email"></x-ui.field>
                    <x-ui.field for="employee_address" :label="__('Adress')" error="address" class="sm:col-span-2"><input id="employee_address" type="text" class="{{ $input }} mt-1" wire:model="form.address"></x-ui.field>
                    <x-ui.field for="employee_postal_code" :label="__('Postnummer')" error="postal_code"><input id="employee_postal_code" type="text" class="{{ $input }} mt-1" wire:model="form.postal_code"></x-ui.field>
                    <x-ui.field for="employee_city" :label="__('Ort')" error="city"><input id="employee_city" type="text" class="{{ $input }} mt-1" wire:model="form.city"></x-ui.field>
                    <x-ui.field for="employee_bank_clearing" :label="__('Clearingnummer')" error="bank_clearing"><input id="employee_bank_clearing" type="text" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="form.bank_clearing" autocomplete="off" @if($editing?->bank_clearing) placeholder="{{ __('tomt behåller') }}" @endif></x-ui.field>
                    <x-ui.field for="employee_bank_account" :label="__('Kontonummer för lön')" error="bank_account" :hint="__('Sparas krypterat.')"><input id="employee_bank_account" type="text" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="form.bank_account" autocomplete="off" @if($editing?->bank_account) placeholder="****{{ substr($editing->bank_account, -4) }} ({{ __('tomt behåller') }})" @endif></x-ui.field>
                    @if($editing?->hasPendingBankChange())
                        <div class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900 sm:col-span-2">
                            {{ __('Nytt bankkonto ****:last väntar på bekräftelse. Ingen lönefil kan skapas förrän någon annan som hanterar personal har bekräftat det.', ['last' => substr((string) ($editing->pending_bank['bank_account'] ?? ''), -4)]) }}
                            <div class="mt-3 flex gap-4">
                                <button type="button" class="font-semibold underline" wire:click="confirmBank({{ $editing->id }})" wire:confirm="{{ __('Bekräfta det nya bankkontot för lön?') }}">{{ __('Bekräfta') }}</button>
                                <button type="button" class="underline" wire:click="withdrawBank({{ $editing->id }})">{{ __('Ta bort ändringen') }}</button>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.field for="employee_tax_residence" :label="__('Skatt')" error="tax_residence" class="sm:col-span-3">
                        <select id="employee_tax_residence" class="{{ $input }} mt-1" wire:model.live="form.tax_residence">
                            @foreach($residences as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    @if(($form['tax_residence'] ?? 'SE') === 'SE')
                        <x-ui.field for="employee_tax_table" :label="__('Skattetabell')" error="tax_table" :hint="__('Från den anställdes skattebesked.')"><input id="employee_tax_table" type="number" class="{{ $input }} mt-1" wire:model="form.tax_table"></x-ui.field>
                        <x-ui.field for="employee_tax_column" :label="__('Kolumn')" error="tax_column"><input id="employee_tax_column" type="number" class="{{ $input }} mt-1" wire:model="form.tax_column"></x-ui.field>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.field for="employee_start" :label="__('Anställd från')" error="start_date"><input id="employee_start" type="date" class="{{ $input }} mt-1" wire:model="form.start_date"></x-ui.field>
                    <x-ui.field for="employee_type" :label="__('Anställningsform')" error="employment_type">
                        <select id="employee_type" class="{{ $input }} mt-1" wire:model="form.employment_type">
                            @foreach($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="employee_position" :label="__('Befattning')" error="position_title"><input id="employee_position" type="text" class="{{ $input }} mt-1" wire:model="form.position_title" placeholder="{{ __('Till exempel Kock') }}"></x-ui.field>
                    <x-ui.field for="employee_salary_form" :label="__('Lön per')" error="salary_form">
                        <select id="employee_salary_form" class="{{ $input }} mt-1" wire:model="form.salary_form">
                            <option value="MONTHLY">{{ __('Månad') }}</option>
                            <option value="HOURLY">{{ __('Timme') }}</option>
                        </select>
                    </x-ui.field>
                    <x-ui.field for="employee_salary" :label="__('Lön, kronor')" error="salary"><input id="employee_salary" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="form.salary"></x-ui.field>
                    <x-ui.field for="employee_rate" :label="__('Sysselsättningsgrad, %')" error="employment_rate"><input id="employee_rate" type="number" class="{{ $input }} mt-1" wire:model="form.employment_rate"></x-ui.field>
                    <x-ui.field for="employee_weekly_hours" :label="__('Arbetstid, timmar per vecka')" error="weekly_hours" :hint="__('Behövs för frånvaro och sjuklön.')"><input id="employee_weekly_hours" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="form.weekly_hours"></x-ui.field>
                    <x-ui.field for="employee_cost_center" :label="__('Kostnadsställe (frivilligt)')" error="cost_center"><input id="employee_cost_center" type="text" class="{{ $input }} mt-1" wire:model="form.cost_center"></x-ui.field>
                    <x-ui.field for="employee_vacation_rule" :label="__('Semesterregel')" error="vacation_rule">
                        <select id="employee_vacation_rule" class="{{ $input }} mt-1" wire:model="form.vacation_rule">
                            <option value="">{{ __('Som i löneinställningarna') }}</option>
                            <option value="SAMMALONEREGELN">{{ __('Sammalöneregeln') }}</option>
                            <option value="PROCENTREGELN">{{ __('Procentregeln') }}</option>
                        </select>
                    </x-ui.field>
                    <x-ui.field for="employee_vacation_days" :label="__('Semesterdagar per år')" error="vacation_days_per_year" :hint="__('Minst 25 enligt semesterlagen. Fler om avtalet ger det.')"><input id="employee_vacation_days" type="number" min="25" max="40" class="{{ $input }} mt-1" wire:model="form.vacation_days_per_year"></x-ui.field>
                    <x-ui.field for="employee_pension" :label="__('Tjänstepension')" error="pension_plan_id">
                        <select id="employee_pension" class="{{ $input }} mt-1" wire:model="form.pension_plan_id">
                            <option value="">{{ __('Ingen') }}</option>
                            @foreach($pensionPlans as $pensionPlan)<option value="{{ $pensionPlan->id }}">{{ $pensionPlan->name }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <label class="flex items-start gap-2 text-sm sm:col-span-3">
                        <input type="checkbox" class="mt-0.5 rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="form.vacation_payout_with_salary">
                        <span>{{ __('Betala semesterersättning med varje lön i stället för betalda semesterdagar') }}
                            <span class="block text-xs text-gray-500">{{ __('Bara när anställningen är högst tre månader (semesterlagen 5 §) eller kollektivavtalet tillåter det, vanligt för timanställda.') }}</span>
                        </span>
                    </label>
                    <x-ui.field for="employee_user" :label="__('Inloggning (frivilligt)')" error="user_id" :hint="__('Den anställde ser sina egna lönebesked under Mina lönebesked.')" class="sm:col-span-2">
                        <select id="employee_user" class="{{ $input }} mt-1" wire:model="form.user_id">
                            <option value="">{{ __('Ingen inloggning') }}</option>
                            @foreach($members as $member)<option value="{{ $member->id }}">{{ $member->name }} ({{ $member->email }})</option>@endforeach
                        </select>
                    </x-ui.field>
                </div>

                <div class="flex items-center gap-4">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>

        @if($editingId && ($form['salary_form'] ?? '') === 'MONTHLY' && ! $endedOn)
            @include('payroll::partials.employee-salary')
        @endif

        @if($editingId)
            @include('payroll::partials.employee-ending')
        @endif
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-4">
            @if($canManage && ! $showForm)
                <x-button type="button" wire:click="create">{{ __('Ny anställd') }}</x-button>
            @endif
            <p class="text-sm text-gray-600">{{ $employees->count() === 1 ? __('1 anställd') : __(':count anställda', ['count' => $employees->count()]) }}</p>
        </div>

        @if($employees->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga anställda ännu')">{{ __('Lägg upp personalen med skattetabell och kontonummer. Uppgifterna används i lönekörningarna och arbetsgivardeklarationen.') }}</x-ui.empty-state>
        @else
            <ul class="mt-4 divide-y divide-gray-100">
                @foreach($employees as $employee)
                    @php($employment = $employee->employments->first())
                    <li wire:key="employee-{{ $employee->id }}" class="flex flex-wrap items-center gap-4 py-3">
                        <span class="w-12 flex-none font-semibold tabular-nums text-gray-900">{{ $employee->employee_number }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-gray-900">{{ $employee->first_name }} {{ $employee->last_name }}</span>
                            <span class="block text-sm text-gray-600 tabular-nums">{{ collect([PersonalNumber::masked($employee->personal_number), $employment?->position_title, $employment ? $types[$employment->employment_type] ?? null : null])->filter()->implode(' · ') }}</span>
                        </span>
                        @if($employment)
                            <span class="text-right text-sm tabular-nums">
                                <x-ui.amount :money="$employment->salary_form === 'HOURLY' ? $employment->hourly_rate_ore : $employment->monthly_salary_ore" />
                                <span class="block text-xs text-gray-500">{{ $employment->salary_form === 'HOURLY' ? __('per timme') : __('per månad') }}</span>
                            </span>
                        @endif
                        @isset($vacation[$employee->id])
                            @php($balance = $vacation[$employee->id])
                            <span class="w-32 text-right text-xs">
                                @if($balance['payout_with_salary'])
                                    <span class="text-gray-600">{{ __('Semesterersättning med lönen') }}</span>
                                @else
                                    <span class="block font-medium text-gray-900 tabular-nums">{{ __(':left av :earned semesterdagar', ['left' => $balance['left'], 'earned' => $balance['earned']]) }}</span>
                                    <span class="block text-gray-500">{{ __('kvar t.o.m. :date', ['date' => $balance['year_end']->toDateString()]) }}</span>
                                @endif
                            </span>
                        @endisset
                        <span class="w-40 text-right text-xs">
                            @if($employee->user_id)
                                <span class="text-gray-600">{{ __('Har inloggning till Min sida') }}</span>
                            @elseif($canManage)
                                <button type="button" class="font-medium text-accent-600 hover:text-accent-700" wire:click="invite({{ $employee->id }})" wire:loading.attr="disabled">
                                    {{ $employee->portal_invited_at ? __('Skicka inbjudan igen') : __('Bjud in till Min sida') }}
                                </button>
                                @if($employee->portal_invited_at)
                                    <span class="block text-gray-500">{{ __('Inbjuden :date', ['date' => $employee->portal_invited_at->setTimezone(config('app.timezone'))->format('Y-m-d')]) }}</span>
                                @endif
                            @endif
                        </span>
                        @if($canManage)
                            <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="edit({{ $employee->id }})">{{ __('Ändra') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Pensionsplaner')" :description="__('Tjänstepension som arbetsgivaren betalar: en procent av lönen upp till 7,5 inkomstbasbelopp om året och en annan över. Särskild löneskatt räknas till. Satserna är era, från kollektivavtal eller egen policy.')">
        @if($pensionPlans->isNotEmpty())
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($pensionPlans as $pensionPlan)
                    <li class="flex justify-between py-2"><span class="font-medium text-gray-900">{{ $pensionPlan->name }}</span>
                        <span class="tabular-nums text-gray-600">{{ __(':below % under taket, :above % över', ['below' => str_replace('.', ',', (string) (($pensionPlan->rules['below_bp'] ?? 0) / 100)), 'above' => str_replace('.', ',', (string) (($pensionPlan->rules['above_bp'] ?? 0) / 100))]) }}</span></li>
                @endforeach
            </ul>
        @endif
        @if($canManage)
            @if($showPlanForm)
                <form wire:submit="savePlan" class="mt-4 grid gap-4 sm:grid-cols-2">
                    <x-ui.field for="plan_name" :label="__('Namn')" error="name"><input id="plan_name" type="text" class="{{ $input }} mt-1" wire:model="plan.name" placeholder="{{ __('Till exempel Visita–HRF') }}"></x-ui.field>
                    <x-ui.field for="plan_template" :label="__('Mall')" error="template">
                        <select id="plan_template" class="{{ $input }} mt-1" wire:model.live="plan.template">
                            <option value="ITP1_LIKE">{{ __('Som ITP1 (tjänstemän)') }}</option>
                            <option value="SAF_LO_LIKE">{{ __('Som SAF-LO (arbetare)') }}</option>
                            <option value="CUSTOM">{{ __('Egen') }}</option>
                        </select>
                    </x-ui.field>
                    <x-ui.field for="plan_below" :label="__('Procent av lönen upp till taket')" error="below_percent"><input id="plan_below" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="plan.below_percent"></x-ui.field>
                    <x-ui.field for="plan_above" :label="__('Procent av lönen över taket')" error="above_percent"><input id="plan_above" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="plan.above_percent"></x-ui.field>
                    <div class="flex items-center gap-4 sm:col-span-2">
                        <x-button>{{ __('Spara planen') }}</x-button>
                        <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('showPlanForm', false)">{{ __('Avbryt') }}</button>
                    </div>
                </form>
            @else
                <x-button type="button" class="mt-4" wire:click="$set('showPlanForm', true)">{{ __('Ny pensionsplan') }}</x-button>
            @endif
        @endif
    </x-ui.card>
</div>
