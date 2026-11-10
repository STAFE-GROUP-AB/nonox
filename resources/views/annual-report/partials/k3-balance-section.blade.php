<tr class="head"><th scope="rowgroup" colspan="4">{{ $section['label'] }}</th></tr>
@foreach($section['lines'] as $line)
    <tr>
        <th scope="row">{{ $line['label'] }}</th>
        <td class="note">{{ $noteNumbers[$line['key']] ?? '' }}</td>
        <td class="num">{{ $kr($line['amount']) }}</td>
        <td class="num">{{ $kr($line['previous']) }}</td>
    </tr>
    @if($line['key'] === 'tax_provisions' && $taxSpecification !== null)
        <tr class="spec">
            <th scope="row">{{ $taxSpecification['label'] }}</th>
            <td class="note"></td>
            <td class="num">{{ $kr($taxSpecification['amount']) }}</td>
            <td class="num">{{ $kr($taxSpecification['previous']) }}</td>
        </tr>
    @endif
@endforeach
<tr class="sum"><th scope="row">{{ __('Summa :section', ['section' => mb_strtolower($section['label'])]) }}</th><td class="note">{{ $noteNumbers['section.'.$section['key']] ?? '' }}</td><td class="num">{{ $kr($section['amount']) }}</td><td class="num">{{ $kr($section['previous']) }}</td></tr>
