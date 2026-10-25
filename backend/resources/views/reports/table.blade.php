<!doctype html>
<html lang="uz">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #0f172a; }
    h1 { font-size: 15px; margin: 0 0 4px; }
    .meta { color: #475569; margin-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #e2e8f0; text-align: left; padding: 4px 5px; border: 1px solid #cbd5e1; }
    td { padding: 3px 5px; border: 1px solid #e2e8f0; }
    .num { text-align: right; white-space: nowrap; }
    tr.total td { font-weight: bold; background: #f1f5f9; }
</style>
</head>
<body>
<h1>{{ $report['title'] }}</h1>
<div class="meta">
    @if($report['period']) Davr: {{ $report['period']['date_from'] }} — {{ $report['period']['date_to'] }}<br>@endif
    @foreach($filters as $label => $value) {{ $label }}: {{ $value }}<br>@endforeach
    Tayyorlangan: {{ now()->timezone('Asia/Tashkent')->format('d.m.Y H:i') }}
</div>
<table>
    <thead><tr>
        @foreach($report['columns'] as $c)<th class="{{ $c['type'] === 'text' ? '' : 'num' }}">{{ $c['label'] }}</th>@endforeach
    </tr></thead>
    <tbody>
    @forelse($report['rows'] as $row)
        <tr>
            @foreach($report['columns'] as $c)
                <td class="{{ $c['type'] === 'text' ? '' : 'num' }}">
                    @if($c['type'] === 'money' || $c['type'] === 'qty' || $c['type'] === 'percent'){{ number_format((float) $row[$c['key']], $c['type'] === 'qty' ? 3 : 2, '.', ' ') }}@else{{ $row[$c['key']] }}@endif
                </td>
            @endforeach
        </tr>
    @empty
        <tr><td colspan="{{ count($report['columns']) }}">Ma’lumot topilmadi.</td></tr>
    @endforelse
    @if($report['totals'])
        <tr class="total">
            @foreach($report['columns'] as $i => $c)
                <td class="{{ $c['type'] === 'text' ? '' : 'num' }}">
                    @if($i === 0)JAMI
                    @elseif(($report['totals'][$c['key']] ?? null) === null)
                    @elseif($c['type'] === 'int'){{ $report['totals'][$c['key']] }}
                    @else{{ number_format((float) $report['totals'][$c['key']], 2, '.', ' ') }}@endif
                </td>
            @endforeach
        </tr>
    @endif
    </tbody>
</table>
</body>
</html>
