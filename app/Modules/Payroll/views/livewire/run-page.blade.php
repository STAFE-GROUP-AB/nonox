@php
    use App\Modules\Payroll\Livewire\PayrollRunPage;
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $absenceTypes = ['SICK' => __('Sjuk'), 'VACATION' => __('Semester'), 'VAB' => __('Vård av barn'), 'PARENTAL' => __('Föräldraledig'), 'LEAVE_UNPAID' => __('Tjänstledig utan lön'), 'LEAVE_PAID' => __('Ledig med lön'), 'OTHER' => __('Annan frånvaro')];
@endphp

<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <p class="text-sm text-gray-700">
                {{ __('Status') }}: <span class="font-semibold">{{ $run->statusLabel() }}</span>
                · {{ __('utbetalning :date', ['date' => $run->payment_date->toDateString()]) }}
                · {{ __('period :from – :to', ['from' => $run->periodStart()->toDateString(), 'to' => $run->periodEnd()->toDateString()]) }}
            </p>
            <div class="flex flex-wrap items-center gap-3" x-data="{ confirming: false }">
                @if($canEdit)
                    <x-button type="button" wire:click="calculate" wire:loading.attr="disabled">{{ $run->status === 'CALCULATED' ? __('Beräkna igen') : __('Beräkna') }}</x-button>
                @endif
                @if($canEdit && $run->status === 'CALCULATED')
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="reopen">{{ __('Öppna igen') }}</button>
                @endif
                @if($canApprove)
                    <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" x-show="! confirming" x-on:click="confirming = true">{{ __('Attestera') }}</button>
                    <span x-show="confirming" x-cloak class="flex items-center gap-2 text-sm">
                        <span class="text-gray-700">{{ __('Lönekörningen låses och kan inte ändras. Fel rättas i nästa lönekörning.') }}</span>
                        <x-button type="button" wire:click="approve">{{ __('Ja, attestera') }}</x-button>
                        <button type="button" class="text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                    </span>
                @elseif($canPay)
                    <form method="GET" action="{{ route('payroll.runs.payment-file', $run) }}" x-data="{ reviewed: false }" class="flex flex-wrap items-center gap-3">
                        <x-ui.review-confirmation x-model="reviewed" />
                        <input type="hidden" name="figures_reviewed" x-bind:value="reviewed ? 1 : 0">
                        <button type="submit" class="text-sm font-medium text-accent-600 hover:text-accent-700 disabled:cursor-not-allowed disabled:opacity-50" x-bind:disabled="! reviewed">{{ __('Hämta lönefil till banken') }}</button>
                    </form>
                    <x-button type="button" wire:click="markPaid" wire:loading.attr="disabled">{{ __('Markera som utbetald') }}</x-button>
                @elseif($canPost)
                    <x-button type="button" wire:click="post" wire:loading.attr="disabled">{{ __('Bokför lönen') }}</x-button>
                @elseif($run->voucher)
                    <a href="{{ route('accounting.vouchers.show', $run->voucher) }}" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Verifikation :voucher', ['voucher' => $run->voucher->reference()]) }}</a>
                @elseif($blockedBySegregation)
                    <span class="text-sm text-gray-600">{{ __('Någon annan än du behöver attestera, eftersom du beräknade lönekörningen.') }}</span>
                @endif
            </div>
        </div>
        <x-input-error for="run" class="mt-2" />
        @error('payment_file') <p class="mt-2 text-sm text-accent-700">{{ $message }}</p> @enderror
        @if($run->status === 'POSTED' && $canPostTax)
            <div class="mt-4 flex flex-wrap items-end gap-3 rounded-lg bg-gray-50 p-4 text-sm">
                @if($run->taxVoucher)
                    <span class="text-gray-700">{{ __('Skatt och avgifter är bokförda mot skattekontot:') }}</span>
                    <a href="{{ route('accounting.vouchers.show', $run->taxVoucher) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Verifikation :voucher', ['voucher' => $run->taxVoucher->reference()]) }}</a>
                @else
                    <span class="min-w-0 flex-1 text-gray-700">{{ __('När Skatteverket drar skatt och arbetsgivaravgifter från skattekontot: bokför det här.') }}</span>
                    <label class="text-gray-600">{{ __('Dag') }}
                        <input type="date" class="mt-1 block rounded-lg border-gray-300 shadow-xs focus:border-accent-500 focus:ring-accent-500" wire:model="taxDate">
                    </label>
                    <x-button type="button" wire:click="postTax" wire:loading.attr="disabled">{{ __('Bokför mot skattekontot') }}</x-button>
                @endif
            </div>
        @endif
        @if($missingAccounts !== [] && in_array($run->status, ['APPROVED', 'PAID'], true))
            <div class="mt-4 rounded-lg bg-gray-50 p-4 text-sm text-gray-700">
                {{ __('Kontoplanen saknar :accounts, som lönen bokförs på.', ['accounts' => implode(', ', $missingAccounts)]) }}
                <button type="button" class="ml-2 font-medium text-accent-600 hover:text-accent-700" wire:click="addAccounts">{{ __('Lägg till kontona') }}</button>
            </div>
        @endif
        @if($pendingHours !== null)
            <p class="mt-4 rounded-lg bg-gray-50 p-4 text-sm text-gray-700" role="status">
                {{ __(':hours timmar i perioden väntar på godkännande och kommer inte med i lönen förrän de är godkända. Godkänn tidrapporterna och beräkna lönekörningen igen.', ['hours' => $pendingHours]) }}
            </p>
        @endif
        @if($lateWaiting !== null)
            <div class="mt-4 rounded-lg border border-accent-200 bg-accent-50 p-4 text-sm text-gray-800" role="alert">
                <p class="font-medium">{{ __(':hours godkända timmar från tidigare månader har inte betalats ut.', ['hours' => $lateWaiting['hours']]) }}</p>
                <ul class="mt-2 space-y-1">
                    @foreach($lateWaiting['employees'] as $row)
                        <li>{{ __(':name: :hours tim från :months', ['name' => $row['name'], 'hours' => $row['hours'], 'months' => implode(', ', $row['months'])]) }}</li>
                    @endforeach
                </ul>
                <p class="mt-2 text-gray-700">{{ __('Ska de betalas ut i den här lönekörningen? De visas som egna rader på lönebeskedet med den månad timmarna hör till. Väljer du Inte nu kommer frågan tillbaka i nästa lönekörning.') }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <x-button type="button" wire:click="includeLateTimeReports" wire:loading.attr="disabled">{{ __('Ta med i den här lönekörningen') }}</x-button>
                    <button type="button" class="text-sm font-medium text-gray-700 underline" wire:click="skipLateTimeReports" wire:loading.attr="disabled">{{ __('Inte nu') }}</button>
                </div>
                <x-input-error for="late_time_reports" class="mt-2" />
            </div>
        @endif
        @if($lateIncluded !== null || $lateSkipped !== null)
            <p class="mt-4 rounded-lg bg-gray-50 p-4 text-sm text-gray-700" role="status">
                @if($lateIncluded !== null){{ __('Timmar från tidigare månader som tas med i den här lönekörningen: :hours tim.', ['hours' => $lateIncluded['hours']]) }}@endif
                @if($lateSkipped !== null){{ __('Timmar från tidigare månader som inte tas med nu: :hours tim.', ['hours' => $lateSkipped['hours']]) }}@endif
                <button type="button" class="ml-1 font-medium text-accent-600 underline hover:text-accent-700" wire:click="resetLateTimeReports">{{ __('Ändra valet') }}</button>
            </p>
        @endif
        @if($blockers !== [])
            <div class="mt-4 rounded-lg border border-accent-200 bg-accent-50 p-4 text-sm text-accent-800" role="alert">
                <p class="font-medium">{{ __('Det här måste lösas innan lönekörningen kan beräknas:') }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach
                </ul>
                @if($rulesDate)
                    <a href="{{ route('payroll.rules', ['datum' => $rulesDate]) }}" class="mt-3 inline-block font-medium text-accent-700 underline hover:text-accent-900">{{ __('Kontrollera reglerna') }}</a>
                @endif
            </div>
        @endif
    </x-ui.card>

    @if($canEdit)
        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card :title="__('Timmar och tillägg')" :description="__('Timlön, övertid, OB, förskott och annat för perioden.')">
                <form wire:submit="addInput" class="grid gap-3 sm:grid-cols-2">
                    <x-ui.field for="input_employee" :label="__('Anställd')" error="employee_id" class="sm:col-span-2">
                        <select id="input_employee" class="{{ $input }} mt-1" wire:model="input.employee_id">
                            <option value="">{{ __('Välj…') }}</option>
                            @foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->employee_number }} {{ $employee->first_name }} {{ $employee->last_name }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="input_code" :label="__('Löneart')" error="pay_code_id" class="sm:col-span-2">
                        <select id="input_code" class="{{ $input }} mt-1" wire:model="input.pay_code_id">
                            <option value="">{{ __('Välj…') }}</option>
                            @foreach($payCodes as $payCode)<option value="{{ $payCode->id }}">{{ $payCode->name_sv }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="input_quantity" :label="__('Antal timmar, mil eller dagar')" error="quantity"><input id="input_quantity" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="input.quantity"></x-ui.field>
                    <x-ui.field for="input_amount" :label="__('eller belopp')" error="amount" :hint="__('Milersättning och traktamente: skriv alltid antal mil eller dagar, och här hela ersättningen om den är högre än den skattefria.')"><input id="input_amount" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="input.amount"></x-ui.field>
                    <x-ui.field for="input_rate" :label="__('Ersättning per mil eller dag (frivilligt)')" error="rate" class="sm:col-span-2" :hint="__('Bara för milersättning och traktamente. Det som överstiger det skattefria blir skattepliktig lön.')"><input id="input_rate" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="input.rate"></x-ui.field>
                    <div class="sm:col-span-2"><x-button wire:loading.attr="disabled">{{ __('Lägg till') }}</x-button></div>
                </form>
                @if($inputs->isNotEmpty())
                    <ul class="mt-4 divide-y divide-gray-100 text-sm">
                        @foreach($inputs as $row)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <span>{{ $row->employee?->first_name }} {{ $row->employee?->last_name }} · {{ $row->payCode?->name_sv }}</span>
                                <span class="tabular-nums">{{ $row->quantity !== null ? __(':hours tim', ['hours' => rtrim(rtrim((string) $row->quantity, '0'), '.')]) : $row->amount_ore?->format() }}</span>
                                <button type="button" class="text-gray-400 hover:text-accent-600" wire:click="removeInput({{ $row->id }})" aria-label="{{ __('Ta bort') }}">×</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('Frånvaro')" :description="__('Sjukdagar och ledighet, med timmar per dag enligt schemat.')">
                <form wire:submit="recordAbsence" class="grid gap-3 sm:grid-cols-2">
                    <x-ui.field for="absence_employee" :label="__('Anställd')" error="employee_id">
                        <select id="absence_employee" class="{{ $input }} mt-1" wire:model="absence.employee_id">
                            <option value="">{{ __('Välj…') }}</option>
                            @foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->first_name }} {{ $employee->last_name }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="absence_type" :label="__('Orsak')" error="type">
                        <select id="absence_type" class="{{ $input }} mt-1" wire:model="absence.type">
                            @foreach($absenceTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="absence_from" :label="__('Från')" error="date_from"><input id="absence_from" type="date" min="{{ $run->periodStart()->toDateString() }}" max="{{ $run->periodEnd()->toDateString() }}" class="{{ $input }} mt-1" wire:model="absence.date_from"></x-ui.field>
                    <x-ui.field for="absence_to" :label="__('Till och med')" error="date_to"><input id="absence_to" type="date" min="{{ $run->periodStart()->toDateString() }}" max="{{ $run->periodEnd()->toDateString() }}" class="{{ $input }} mt-1" wire:model="absence.date_to"></x-ui.field>
                    <x-ui.field for="absence_hours" :label="__('Timmar per dag')" error="hours"><input id="absence_hours" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="absence.hours"></x-ui.field>
                    <div class="self-end"><x-button wire:loading.attr="disabled">{{ __('Registrera') }}</x-button></div>
                </form>
                @if($absences->isNotEmpty())
                    <ul class="mt-4 divide-y divide-gray-100 text-sm">
                        @foreach($absences as $row)
                            <li class="flex justify-between gap-3 py-2">
                                <span>{{ $row->employee?->first_name }} {{ $row->employee?->last_name }} · {{ $canSeeSensitive ? ($absenceTypes[$row->type] ?? $row->type) : __('Frånvaro') }}</span>
                                <span class="tabular-nums text-gray-600">{{ $row->date_from->toDateString() }} – {{ $row->date_to->toDateString() }}, {{ __(':hours tim/dag', ['hours' => rtrim(rtrim((string) $row->hours, '0'), '.')]) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    @endif

    @if($canImport && ($canEdit || $fileImports->isNotEmpty()))
        <x-ui.card :title="__('Löneunderlag från schemasystem')" :description="__('Läs in timmar, OB och tillägg från till exempel ett schema- eller tidsystem, som PAXml eller CSV. Raderna blir förslag som du kontrollerar och godkänner innan de kommer med i lönen.')">
            @if($canEdit)
                <form wire:submit="importPayrollFile" class="grid gap-3 sm:grid-cols-3">
                    <x-ui.field for="import_source" :label="__('Källsystem')" error="importSource">
                        <select id="import_source" class="{{ $input }} mt-1" wire:model.live="importSource">
                            <option value="">{{ __('Välj…') }}</option>
                            @foreach($importSources as $source)<option value="{{ $source->id }}">{{ $source->name }}</option>@endforeach
                            <option value="new">{{ __('Nytt system…') }}</option>
                        </select>
                    </x-ui.field>
                    @if($importSource === 'new')
                        <x-ui.field for="import_source_name" :label="__('Systemets namn')" error="source_name">
                            <input id="import_source_name" type="text" maxlength="80" class="{{ $input }} mt-1" wire:model="newSourceName" placeholder="{{ __('Till exempel Personalkollen') }}">
                        </x-ui.field>
                    @endif
                    <x-ui.field for="import_file" :label="__('Fil (PAXml eller CSV, högst 10 MB)')" error="payrollFile">
                        <input id="import_file" type="file" accept=".xml,.csv,.txt" class="mt-1 block w-full text-sm" wire:model="payrollFile">
                    </x-ui.field>
                    <div class="sm:col-span-3"><x-button wire:loading.attr="disabled" wire:target="payrollFile,importPayrollFile">{{ __('Läs in filen') }}</x-button></div>
                </form>
            @endif
            @if($fileImports->isNotEmpty())
                <ul class="mt-4 divide-y divide-gray-100 text-sm">
                    @foreach($fileImports as $fileImport)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-2" wire:key="file-import-{{ $fileImport->id }}">
                            <a href="{{ route('payroll.runs.imports.show', [$run, $fileImport]) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $fileImport->original_name }}</a>
                            <span class="text-gray-600">{{ $fileImport->source?->name }} · {{ __(':proposed att ta ställning till, :approved godkända', ['proposed' => $fileImport->proposed_count, 'approved' => $fileImport->approved_count]) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif

    @if($payslips->isNotEmpty())
        <x-ui.card :title="__('Lönebesked')">
            <div class="divide-y divide-gray-100">
                @foreach($payslips as $payslip)
                    <details class="py-3" wire:key="payslip-{{ $payslip->id }}">
                        <summary class="flex cursor-pointer flex-wrap items-center gap-4 text-sm">
                            <span class="min-w-0 flex-1 font-medium text-gray-900">{{ $payslip->employee?->employee_number }} {{ $payslip->employee?->first_name }} {{ $payslip->employee?->last_name }}</span>
                            <span class="tabular-nums">{{ __('Brutto') }} <x-ui.amount :money="$payslip->gross_ore" /></span>
                            <span class="tabular-nums">{{ __('Skatt') }} <x-ui.amount :money="$payslip->tax_ore" /></span>
                            <span class="tabular-nums font-semibold">{{ __('Netto') }} <x-ui.amount :money="$payslip->net_ore" /></span>
                            <span class="tabular-nums text-gray-500">{{ __('Arbetsgivaravgift') }} <x-ui.amount :money="$payslip->employer_contribution_ore" /></span>
                            <a href="{{ route('payroll.payslips.pdf', $payslip) }}" target="_blank" class="font-medium text-accent-600 hover:text-accent-700" onclick="event.stopPropagation()">{{ $run->isOpen() ? __('Förhandsgranska') : __('Lönebesked som PDF') }}</a>
                        </summary>
                        <table class="mt-3 w-full text-sm">
                            <tbody class="divide-y divide-gray-50">
                                @foreach($payslip->lines as $line)
                                    <tr>
                                        <td class="py-1 pr-4">{{ $line->description }}
                                            @if($line->quantity !== null && $line->rate_ore !== null && ($units[$line->pay_code_id] ?? null) === 'HOURS')
                                                <span class="block text-xs text-gray-500 tabular-nums">{{ __(':hours tim × :rate kr', ['hours' => PayrollRunPage::hours((string) $line->quantity), 'rate' => $line->rate_ore->format(withCurrency: false)]) }}</span>
                                            @endif
                                            @if(is_array($line->explanation['agreement'] ?? null))
                                                @if(isset($line->explanation['agreement']['rate_from'], $line->explanation['agreement']['version_valid_from']))
                                                    <span class="block text-xs text-gray-500">{{ __('Sats enligt :agreement, version från :version, steg från :step. Källa: :source', ['agreement' => $line->explanation['agreement']['name'] ?? '', 'version' => $line->explanation['agreement']['version_valid_from'] ?? '', 'step' => $line->explanation['agreement']['rate_from'] ?? '', 'source' => trim(($line->explanation['agreement']['source_title'] ?? '').' '.($line->explanation['agreement']['source'] ?? ''))]) }}</span>
                                                @else
                                                    <span class="block text-xs text-gray-500">{{ __('Enligt :agreement. Källa: :source', ['agreement' => $line->explanation['agreement']['name'] ?? '', 'source' => trim(($line->explanation['agreement']['source_title'] ?? '').' '.($line->explanation['agreement']['source'] ?? '').(isset($line->explanation['agreement']['rule_key']) ? ', '.__('regel :rule', ['rule' => $line->explanation['agreement']['rule_key']]) : ''))]) }}</span>
                                                @endif
                                                @if(is_array($line->explanation['agreement']['commission'] ?? null))
                                                    @php($commission = $line->explanation['agreement']['commission'])
                                                    <span class="block text-xs text-gray-500 tabular-nums">{{ __('Provision :commission på omsättning :turnover, minus garanterad lön :guarantee', ['commission' => App\Support\Money\Money::ofOre((int) $commission['commission_ore'])->format(), 'turnover' => App\Support\Money\Money::ofOre((int) $commission['turnover_ore'])->format(), 'guarantee' => App\Support\Money\Money::ofOre((int) $commission['guaranteed_ore'])->format()]) }}</span>
                                                @endif
                                                @if(! empty($line->explanation['agreement']['local_override']) || str_starts_with((string) ($line->explanation['agreement']['source'] ?? ''), 'LOKALT'))
                                                    <span class="block text-xs font-medium text-accent-700">{{ __('Värdet kommer från företagets lokala avtal.') }}</span>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="py-1 text-right"><x-ui.amount :money="$line->amount_ore" /></td>
                                    </tr>
                                @endforeach
                                <tr><td class="py-1 pr-4 text-gray-600">{{ __('Skatt') }}
                                    @if(($payslip->tax_explanation['method'] ?? null) === 'table')
                                        <span class="block text-xs text-gray-500">{{ __('Tabell :table, kolumn :column, inkomst :income kr', ['table' => $payslip->tax_explanation['table'], 'column' => $payslip->tax_explanation['column'], 'income' => number_format((int) $payslip->tax_explanation['income_kronor'], 0, ',', ' ')]) }}@if($payslip->tax_explanation['adjustment'] ?? false) {{ __('enligt jämkningsbeslut') }}@endif</span>
                                    @elseif(($payslip->tax_explanation['method'] ?? null) === 'adjustment_percent')
                                        <span class="block text-xs text-gray-500">{{ __('Jämkning :percent', ['percent' => rtrim(rtrim(number_format(((int) $payslip->tax_explanation['rate_bp']) / 100, 2, ',', ''), '0'), ',').' %']) }}</span>
                                    @elseif(($payslip->tax_explanation['method'] ?? null) === 'adjustment_amount')
                                        <span class="block text-xs text-gray-500">{{ __('Jämkning med fast belopp') }}</span>
                                    @endif
                                </td><td class="py-1 text-right"><x-ui.amount :money="$payslip->tax_ore->negated()" /></td></tr>
                            </tbody>
                        </table>
                        @foreach($payslip->warnings as $warning)
                            <p class="mt-2 text-sm text-accent-700">{{ $warning }}</p>
                        @endforeach
                    </details>
                @endforeach
            </div>
        </x-ui.card>
    @endif
</div>
