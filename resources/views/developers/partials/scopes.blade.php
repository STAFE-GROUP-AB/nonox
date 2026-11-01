{{-- The scopes table of the guide Behörigheter, from the spec: what each scope grants and which operations need it. --}}
@use('App\Support\Developer\DeveloperPortal', 'Dev')
<div class="dv-table" tabindex="0">
  <table>
    <thead><tr><th scope="col">{{ Dev::t('scope', $lang) }}</th><th scope="col">{{ Dev::t('gives', $lang) }}</th><th scope="col">{{ Dev::t('operations', $lang) }}</th></tr></thead>
    <tbody>
      @foreach($spec['scopes'] as $scope => $info)
        <tr id="{{ Illuminate\Support\Str::slug($scope) }}">
          <th scope="row" style="font-weight:600"><code>{{ $scope }}</code></th>
          <td>{{ $info['description'][$lang] }}</td>
          <td>
            @foreach($info['operations'] as $id)
              @php $operation = $spec['operations'][$id]; @endphp
              <a href="{{ Dev::operationUrl($id, $lang) }}" style="display:block;white-space:nowrap;text-decoration:none;margin:2px 0"><span class="dv-method dv-m-{{ $operation['method'] }}">{{ strtoupper($operation['method']) }}</span> <code>{{ $operation['path'] }}</code></a>
            @endforeach
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
