<div class="space-y-6">
    <x-ui.card :title="__('Lönekörningar')">
        @if($runs->isEmpty())
            <x-ui.empty-state :title="__('Inga attesterade lönekörningar')">{{ __('Här visas lönekörningar när de är attesterade.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-gray-200 text-left text-gray-500"><th class="py-2 pr-4 font-medium">{{ __('Lönekörning') }}</th><th class="py-2 pr-4 font-medium">{{ __('Utbetalning') }}</th><th class="py-2 pr-4 font-medium">{{ __('Status') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Bruttolön') }}</th><th class="py-2 text-right font-medium">{{ __('Nettolön') }}</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($runs as $run)
                            @php($sums = $totals->get($run->id, collect()))
                            <tr wire:key="archive-run-{{ $run->id }}">
                                <td class="py-2 pr-4"><button type="button" class="font-medium text-accent-600 hover:text-accent-700" wire:click="$set('runId', {{ $open?->id === $run->id ? 0 : $run->id }})">{{ $run->title() }}</button></td>
                                <td class="py-2 pr-4 tabular-nums">{{ $run->payment_date->toDateString() }}</td>
                                <td class="py-2 pr-4">{{ $run->statusLabel() }}</td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="App\Support\Money\Money::ofOre((int) $sums->sum(fn ($payslip) => $payslip->gross_ore->ore))" :currency="false" /></td>
                                <td class="py-2 text-right"><x-ui.amount :money="App\Support\Money\Money::ofOre((int) $sums->sum(fn ($payslip) => $payslip->net_ore->ore))" :currency="false" /></td>
                            </tr>
                            @if($open?->id === $run->id)
                                <tr>
                                    <td colspan="5" class="bg-gray-50 px-4 py-3">
                                        <ul class="divide-y divide-gray-100">
                                            @foreach($payslips as $payslip)
                                                <li wire:key="archive-payslip-{{ $payslip->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                                                    <span>{{ $payslip->employee?->employee_number }} {{ $payslip->employee?->first_name }} {{ $payslip->employee?->last_name }}</span>
                                                    <x-ui.link :href="route('payroll.archive.payslip', $payslip)">{{ __('Lönebesked (PDF)') }}</x-ui.link>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Arbetsgivardeklarationer')">
        @if($submissions->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Inga arbetsgivardeklarationer är skapade.') }}</p>
        @else
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($submissions as $submission)
                    <li wire:key="archive-agi-{{ $submission->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="tabular-nums">{{ $submission->period }} · {{ $submission->sequence > 1 ? __('Rättelse :number', ['number' => $submission->sequence - 1]) : __('Första filen') }} · {{ $submission->statusLabel() }}</span>
                        <x-ui.link :href="route('payroll.archive.agi', $submission)">{{ __('Hämta fil') }}</x-ui.link>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
