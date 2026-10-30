{{-- Logo, or the name when there is none, then the address. --}}
@php($logo = $brand->logoDataUri())
@if($logo)
    <img class="logo" src="{{ $logo }}" alt="{{ $company->legal_name ?: $company->name }}"><br>
    <strong>{{ $company->legal_name ?: $company->name }}</strong><br>
@else
    <strong style="font-size: 12pt">{{ $company->legal_name ?: $company->name }}</strong><br>
@endif
<span class="muted">{{ $company->address_line1 }}@if($company->address_line2)<br>{{ $company->address_line2 }}@endif<br>{{ trim($company->postal_code.' '.$company->city) }}</span>
