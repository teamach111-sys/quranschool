<!doctype html>
<html lang="{{ $language }}" dir="{{ $language === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body {
            color: #173d32;
            font-family: dejavusans, sans-serif;
            font-size: 8pt;
        }

        h1 {
            color: #174e3c;
            font-size: 16pt;
            margin: 0 0 12px;
        }

        .school {
            color: #315f50;
            font-size: 11pt;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .metadata {
            color: #52665d;
            font-size: 7pt;
            margin-bottom: 12px;
        }

        .metadata p {
            margin: 2px 0;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th {
            background: #e8f0eb;
            color: #173d32;
            font-weight: bold;
        }

        th,
        td {
            border: 1px solid #c8d8ce;
            padding: 5px 4px;
            vertical-align: top;
        }

        tr:nth-child(even) td {
            background: #f7faf8;
        }
    </style>
</head>
<body>
    <div class="school">{{ $schoolName }}</div>
    <h1>{{ $title }}</h1>
    <div class="metadata">
        @foreach ($metadata as $item)
            <p><strong>{{ $item[0] }}:</strong> {{ $item[1] }}</p>
        @endforeach
    </div>
    <table>
        <thead>
            <tr>
                @foreach ($headings as $heading)
                    <th>{{ $heading }}</th>
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
