<!doctype html>
<html lang="{{ $language }}" dir="{{ $language === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body {
            color: #242a27;
            font-family: dejavusans, sans-serif;
            font-size: 9pt;
        }

        h1 {
            color: #343a37;
            font-size: 21pt;
            font-style: italic;
            margin: 0 0 4px;
            text-align: center;
        }

        .subtitle {
            font-size: 13pt;
            font-weight: bold;
            margin: 0 0 15px;
            text-align: center;
            text-decoration: underline;
        }

        .metadata {
            color: #46504b;
            font-size: 8pt;
            margin-bottom: 10px;
            text-align: center;
        }

        table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }

        th,
        td {
            border: 1.5px solid #57605b;
            padding: 8px 5px;
            vertical-align: middle;
        }

        th {
            background: #e6e9e7;
            font-size: 9pt;
            font-weight: bold;
            text-align: center;
        }

        .day-column {
            background: #e6e9e7;
            font-size: 10pt;
            font-style: italic;
            text-align: start;
            width: 24mm;
        }

        .period-column {
            width: auto;
        }

        .lesson {
            font-size: 8.5pt;
            line-height: 1.45;
            text-align: center;
        }

        .lesson + .lesson {
            border-top: 1px solid #c0c6c2;
            margin-top: 5px;
            padding-top: 5px;
        }

        .teacher {
            color: #58615c;
            font-size: 7.5pt;
        }

        .empty {
            color: #a4aaa6;
            text-align: center;
        }

        .footer {
            color: #718078;
            font-size: 7pt;
            margin-top: 10px;
            text-align: end;
        }
    </style>
</head>
<body>
    <h1>{{ $labels['studentTitle'] }}</h1>
    <p class="subtitle">{{ $roomName }}</p>
    <p class="metadata">
        {{ $labels['school'] }}: {{ $schoolName }}
        &nbsp;&nbsp;|&nbsp;&nbsp;
        {{ $labels['year'] }}: {{ $yearLabel }}
    </p>
    <table>
        <thead>
            <tr>
                <th class="day-column"></th>
                @foreach ($periods as $period)
                    <th class="period-column">{{ $period['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($days as $day)
                <tr>
                    <th class="day-column">{{ $day['label'] }}</th>
                    @foreach ($periods as $period)
                        @php
                            $matchingRecords = $records
                                ->where('day_of_week', $day['number'])
                                ->filter(fn ($record) => substr($record->starts_at, 0, 5).'|'.substr($record->ends_at, 0, 5) === $period['key']);
                        @endphp
                        <td>
                            @forelse ($matchingRecords as $record)
                                <div class="lesson">
                                    <div><strong>{{ $record->label }}</strong></div>
                                    <div class="teacher">{{ $record->teacher_name }}</div>
                                </div>
                            @empty
                                <div class="empty">—</div>
                            @endforelse
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="footer">{{ $labels['generated'] }}: {{ $generatedAt }}</p>
</body>
</html>
