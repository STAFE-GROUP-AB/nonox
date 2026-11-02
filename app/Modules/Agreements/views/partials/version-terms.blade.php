@php
    $vacation = $rules->vacation();
    $pension = $rules->pension();
    $insurances = $rules->insurances();
    $percent = fn (int $bp): string => number_format($bp / 100, 2, ',', ' ');
@endphp
<h3 class="mt-6 text-sm font-semibold text-gray-900">{{ __('Semester, pension och försäkringar') }}</h3>
@if($vacation === [] && $pension === [] && $insurances === [])
    <p class="mt-1 text-sm text-gray-600">{{ __('Versionen har inga egna regler för semester, pension eller försäkringar. Lagens semesterregler gäller.') }}</p>
@else
    <ul class="mt-2 space-y-2 text-sm text-gray-700">
        @if($vacation !== [])
            <li>
                <span class="font-medium text-gray-900">{{ __('Semester') }}</span>
                <span class="block">
                    {{ collect([
                        isset($vacation['rule_monthly']) ? ($vacation['rule_monthly'] === 'SAMMALONEREGELN' ? __('sammalöneregeln för månadsavlönade') : __('procentregeln för alla')) : null,
                        isset($vacation['percent_bp']) ? __('semesterlön :percent %', ['percent' => $percent((int) $vacation['percent_bp'])]) : null,
                        isset($vacation['supplement_bp_per_day']) ? __('semestertillägg :percent % av månadslönen per dag', ['percent' => $percent((int) $vacation['supplement_bp_per_day'])]) : null,
                        isset($vacation['variable_pay_percent_bp']) ? __(':percent % av rörlig lön (räknas inte ännu)', ['percent' => $percent((int) $vacation['variable_pay_percent_bp'])]) : null,
                    ])->filter()->implode(', ') }}
                    @if($vacation['unverified'] ?? false)<span class="text-accent-700">{{ __('(saknar primär källa)') }}</span>@endif
                </span>
                @foreach((array) ($vacation['minimum_daily']['steps'] ?? []) as $step)
                    <span class="block tabular-nums">
                        {{ __('Lägsta semesterlön per dag från :date:', ['date' => $step['valid_from']]) }}
                        {{ collect($step['rows'])->map(fn ($row) => App\Support\Money\Money::ofOre((int) $row['ore'])->format().collect([
                            isset($row['age_min']) ? ' '.__('från :age år', ['age' => $row['age_min']]) : null,
                            isset($row['age_max_exclusive']) ? ' '.__('under :age år', ['age' => $row['age_max_exclusive']]) : null,
                            isset($row['experience_months_min']) ? ' '.__(':months mån branschvana', ['months' => $row['experience_months_min']]) : null,
                        ])->filter()->implode(''))->implode(' · ') }}
                    </span>
                @endforeach
                @foreach((array) ($vacation['extra_days'] ?? []) as $extra)
                    <span class="block">{{ __(':days extra semesterdagar när övertid ingår i lönen', ['days' => $extra['days']]) }}</span>
                @endforeach
                <span class="block text-xs text-gray-500">{{ __('Källa: :source', ['source' => $vacation['source'] ?? '']) }}</span>
            </li>
        @endif
        @if($pension !== [])
            <li>
                <span class="font-medium text-gray-900">{{ __('Tjänstepension') }}</span>
                <span class="block">{{ __('Premierna läses från lönens regel :rule (Lön → Regler), som kontrolleras för sig.', ['rule' => $pension['rule_key'] ?? '']) }}</span>
                <span class="block text-xs text-gray-500">{{ __('Källa: :source', ['source' => $pension['source'] ?? '']) }}</span>
            </li>
        @endif
        @if($insurances !== [])
            <li>
                <span class="font-medium text-gray-900">{{ __('Avtalsförsäkringar') }}</span>
                <span class="block">{{ __('Premierna läses från lönens regel :rule (Lön → Regler), som kontrolleras för sig.', ['rule' => $insurances['rule_key'] ?? '']) }}</span>
                @isset($insurances['admin_fee_bp'])
                    <span class="block">{{ __('Administrationsavgift :percent % av premieunderlaget', ['percent' => $percent((int) $insurances['admin_fee_bp'])]) }}</span>
                @endisset
                <span class="block text-xs text-gray-500">{{ __('Källa: :source', ['source' => $insurances['source'] ?? '']) }}</span>
            </li>
        @endif
    </ul>
@endif
