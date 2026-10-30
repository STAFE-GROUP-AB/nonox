<div class="space-y-6">
    @if($vacation)
        <x-ui.card>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Semester') }}</p>
            @if($vacation['payout_with_salary'])
                <p class="mt-1 text-sm text-gray-700">{{ __('Du får semesterersättning med varje lön i stället för betalda semesterdagar.') }}</p>
            @else
                <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-900">{{ __(':count dagar', ['count' => $vacation['left']]) }}</p>
                <p class="text-sm text-gray-600">{{ __('betald semester kvar till och med :date. Du har tagit ut :taken av :earned dagar.', ['date' => $vacation['year_end']->toDateString(), 'taken' => $vacation['taken'], 'earned' => $vacation['earned']]) }}</p>
                @if($vacation['saved'] > 0)
                    <p class="text-sm text-gray-600">{{ __('Av dem är :count sparade från tidigare år.', ['count' => $vacation['saved']]) }}</p>
                @endif
            @endif
        </x-ui.card>
    @endif

<x-ui.card>
    @if($employee === null)
        <x-ui.empty-state :title="__('Ditt konto är inte kopplat till någon anställd')">{{ __('Be din arbetsgivare koppla din inloggning till dig under Lön → Anställda. Sedan visas dina lönebesked här.') }}</x-ui.empty-state>
    @elseif($payslips->isEmpty())
        <x-ui.empty-state :title="__('Inga lönebesked ännu')">{{ __('När din lön är attesterad visas lönebeskedet här.') }}</x-ui.empty-state>
    @else
        <ul class="divide-y divide-gray-100">
            @foreach($payslips as $payslip)
                <li class="flex flex-wrap items-center gap-4 py-4">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium text-gray-900">{{ __('Lön :period', ['period' => \Carbon\CarbonImmutable::parse($payslip->getAttribute('period').'-01')->translatedFormat('F Y')]) }}</span>
                        <span class="block text-sm text-gray-600">{{ __('Utbetalning :date', ['date' => \Carbon\CarbonImmutable::parse($payslip->getAttribute('payment_date'))->toDateString()]) }}</span>
                    </span>
                    <span class="text-right">
                        <span class="block text-xs text-gray-500">{{ __('Netto') }}</span>
                        <x-ui.amount :money="$payslip->net_ore" class="text-lg font-semibold" />
                    </span>
                    <a href="{{ route('payroll.portal.payslip', $payslip) }}" target="_blank" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Öppna PDF') }}</a>
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>
</div>
