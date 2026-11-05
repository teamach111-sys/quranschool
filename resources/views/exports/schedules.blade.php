<!doctype html>
<html lang="{{ $language }}" dir="{{ $language === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        * {
            text-decoration: none !important;
        }

        body {
            color: #173d32;
            font-family: dejavusans, sans-serif;
            font-size: 7pt;
            text-decoration: none;
        }

        .metadata {
            color: #52635a;
            font-size: 7pt;
            margin-bottom: 5mm;
            text-align: center;
        }

        table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }

        th,
        td {
            border: 0.6px solid #bac8bf;
            padding: 1.2mm;
            vertical-align: top;
        }

        thead th {
            background: #e8f0eb;
            color: #263b31;
            font-size: 7pt;
            font-weight: bold;
            height: 7mm;
            text-align: center;
            vertical-align: middle;
        }

        .time-column {
            background: #f5f8f6;
            color: #63736a;
            font-family: dejavusans, sans-serif;
            font-size: 6.5pt;
            text-align: center;
            vertical-align: middle;
            width: 14mm;
        }

        .hour-cell {
            height: 9mm;
        }

        .lesson {
            border: 0 !important;
            background: transparent !important;
            outline: 0 !important;
            box-shadow: none !important;
            font-size: 6pt;
            line-height: 1.3;
            padding: 0;
            text-align: start;
            text-decoration: none !important;
        }

        .lesson * {
            border: 0 !important;
            outline: 0 !important;
            box-shadow: none !important;
            text-decoration: none !important;
        }

        .lesson-label {
            font-size: 6.5pt;
            font-weight: bold;
        }

        .lesson-details {
            font-size: 5.5pt;
        }
    </style>
</head>
<body>
    <div class="metadata">
        {{ $labels['school'] }}: {{ $schoolName }}
        &nbsp;|&nbsp; {{ $labels['year'] }}: {{ $yearLabel }}
        &nbsp;|&nbsp; {{ $labels['room'] }}: {{ $roomName }}
        &nbsp;|&nbsp; {{ $labels['generated'] }}: {{ $generatedAt }}
    </div>
    <table>
        <thead>
            <tr>
                <th class="time-column">{{ $labels['time'] }}</th>
                @foreach ($days as $day)
                    <th colspan="{{ $calendar['laneCount'] }}">{{ $day['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($calendar['hours'] as $hourRow)
                <tr>
                    <th class="time-column">{{ sprintf('%02d:00', $hourRow['hour']) }}</th>
                    @foreach ($hourRow['days'] as $lanes)
                        @foreach ($lanes as $cell)
                            @if (!$cell['continuation'])
                                <td
                                    class="hour-cell"
                                    @if ($cell['rowspan'] > 1) rowspan="{{ $cell['rowspan'] }}" @endif
                                    @if ($cell['record'])
                                        style="background-color: {{ $cell['background'] }}; border-color: {{ $cell['border'] }};"
                                    @endif
                                >
                                    @if ($cell['record'])
                                        <div class="lesson" style="color: {{ $cell['text'] }};">
                                            <span class="lesson-label">{{ $cell['record']->label }}</span><br>
                                            <span class="lesson-details">
                                                {{ substr($cell['record']->starts_at, 0, 5) }}–{{ substr($cell['record']->ends_at, 0, 5) }}<br>
                                                {{ $cell['record']->room_name }} · {{ $cell['record']->teacher_name }}
                                            </span>
                                        </div>
                                    @endif
                                </td>
                            @endif
                        @endforeach
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
