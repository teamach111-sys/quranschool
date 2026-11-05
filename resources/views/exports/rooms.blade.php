<!doctype html>
<html lang="{{ $language }}" dir="{{ $language === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans, sans-serif; font-size: 9pt; color: #1d2923; }
        h1 { font-size: 17pt; margin: 0 0 12px; }
        .meta { margin-bottom: 14px; color: #45554c; line-height: 1.7; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #bac8bf; padding: 6px; vertical-align: top; }
        th { background: #e8f0eb; font-weight: bold; }
        tr:nth-child(even) td { background: #f7faf8; }
    </style>
</head>
<body>
    <h1>{{ $labels['title'] }}</h1>
    <div class="meta">
        {{ $labels['school'] }}: {{ $schoolName }}<br>
        {{ $labels['year'] }}: {{ $yearLabel }}<br>
        {{ $labels['count'] }}: {{ $count }}<br>
        {{ $labels['generated'] }}: {{ $generatedAt }}
    </div>
    <table>
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $value)
                        <td>{{ $value }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
