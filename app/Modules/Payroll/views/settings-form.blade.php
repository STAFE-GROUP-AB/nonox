@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
    $labels = [
        'salary_period' => __('Löneperiod'),
        'default_payment_day' => __('Utbetalningsdag'),
        'proration_method' => __('Delmånad räknas på'),
        'vacation_year_start_month' => __('Semesteråret börjar månad'),
        'vacation_rule_default' => __('Semesterregel'),
        'vacation_liability_booking' => __('Semesterlöneskuld bokförs'),
        'vacation_day_value_percent' => __('Dagsvärde i semesterlöneskulden, % av månadslönen'),
        'vacation_day_method' => __('Semesterdagar för deltid'),
        'vacation_payout_tax' => __('Skatt på semesterersättning med varje lön'),
        'net_pay_rounding' => __('Avrundning av nettolön'),
        'segregation_of_duties' => __('Den som beräknar får inte attestera'),
        'use_employment_dimensions' => __('Kostnadsställe och projekt från anställningen'),
        'payment_method' => __('Betalsätt'),
        'payslip_delivery' => __('Lönebesked'),
        'employee_self_service' => __('Självservice för anställda'),
        'agi_submission_mode' => __('Arbetsgivardeklaration'),
        'agi_deadline_group' => __('Deklarationsdag för arbetsgivardeklarationen'),
        'holiday_calendar' => __('Helgdagskalender'),
    ];
    $values = [
        'MONTHLY_CURRENT' => __('Innevarande månad'), 'MONTHLY_ARREARS' => __('Efterskott'),
        'WORK_SCHEDULE' => __('Arbetsschema'), 'CALENDAR_DAYS' => __('Kalenderdagar'),
        'SAMMALONEREGELN' => __('Sammalöneregeln'), 'PROCENTREGELN' => __('Procentregeln'),
        'MONTHLY' => __('Varje månad'), 'YEAR_END' => __('Vid bokslut'),
        'ORE' => __('Till öre'), 'WHOLE_KRONOR' => __('Till hela kronor'),
        'PAIN001' => __('Betalfil till banken'), 'BANKGIROT_SALARY' => __('Bankgirots lönetjänst'), 'MANUAL' => __('Manuellt'),
        'PORTAL' => __('I portalen'), 'PORTAL_AND_EMAIL_NOTICE' => __('I portalen, med avisering via e-post'),
        'FILE' => __('Fil till Skatteverket'), 'API' => __('Direkt till Skatteverket'),
        'SE' => __('Sverige'),
        'TURNOVER_UP_TO_40_MSEK' => __('Omsättning upp till 40 miljoner kronor'),
        'TURNOVER_OVER_40_MSEK' => __('Omsättning över 40 miljoner kronor'),
        'NET' => __('Bara schemalagda arbetsdagar, färre dagar för färre arbetsdagar i veckan'),
        'GROSS' => __('Alla vardagar, 25 dagar för alla'),
        'TABLE' => __('Enligt skattetabell, som lönen'),
        'ONE_OFF' => __('Som engångsbelopp'),
    ];
@endphp

<form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
    @foreach($options as $key => $allowed)
        <x-ui.field :for="'payroll_'.$key" :label="$labels[$key]" :error="'settings.'.$key">
            @if($allowed !== null)
                <select id="payroll_{{ $key }}" class="{{ $input }} mt-1" wire:model="settings.{{ $key }}">
                    @foreach($allowed as $value)
                        <option value="{{ $value }}">{{ $values[$value] ?? $value }}</option>
                    @endforeach
                </select>
            @elseif($key === 'vacation_day_value_percent')
                <input id="payroll_{{ $key }}" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="settings.{{ $key }}">
                <p class="mt-1 text-xs text-gray-500">{{ __('För sammalöneregeln. Semesterlagen anger inget dagsvärde; 4,6 % är vanligt i kollektivavtal. Semestertillägget räknas till.') }}</p>
            @elseif(in_array($key, ['default_payment_day', 'vacation_year_start_month'], true))
                <input id="payroll_{{ $key }}" type="number" class="{{ $input }} mt-1" wire:model="settings.{{ $key }}">
            @else
                <label class="mt-2 flex items-center gap-2 text-sm"><input id="payroll_{{ $key }}" type="checkbox" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="settings.{{ $key }}"> {{ __('Ja') }}</label>
            @endif
        </x-ui.field>
    @endforeach
    <div class="sm:col-span-2"><x-button wire:loading.attr="disabled">{{ __('Spara löneinställningarna') }}</x-button></div>
</form>
