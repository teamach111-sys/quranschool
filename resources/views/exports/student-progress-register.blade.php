<!doctype html>
<html lang="{{ $language }}" dir="{{ $language === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body {
            color: #111;
            font-family: dejavusans, sans-serif;
            font-size: 9pt;
        }

        .school {
            color: #444;
            font-size: 10pt;
            margin-bottom: 2mm;
        }

        h1 {
            font-size: 19pt;
            margin: 0 0 5mm;
        }

        .metadata {
            border-collapse: collapse;
            margin-bottom: 5mm;
            width: 100%;
        }

        .metadata td {
            padding: 2mm;
            width: 50%;
        }

        .metadata strong {
            color: #444;
        }

        .summary {
            border: 1px solid #999;
            margin-bottom: 4mm;
            padding: 2.5mm;
        }

        table.sessions {
            border-collapse: collapse;
            width: 100%;
        }

        .sessions th,
        .sessions td {
            border: 1px solid #999;
            padding: 2mm;
            text-align: start;
            vertical-align: top;
        }

        .sessions th {
            background: #eee;
            font-weight: bold;
        }

        .sessions thead {
            display: table-header-group;
        }

        .observations {
            page-break-inside: avoid;
            margin-top: 6mm;
        }

        .observations-title {
            font-weight: bold;
            margin-bottom: 2mm;
        }

        .observation-line {
            border-bottom: 1px solid #999;
            height: 8mm;
        }

        .signature {
            margin-top: 8mm;
            text-align: end;
        }
    </style>
</head>
<body>
    <div class="school">{{ $schoolName }}</div>
    <h1>{{ $labels['title'] }}</h1>
    <table class="metadata">
        <tbody>
            <tr>
                <td><strong>{{ $labels['student'] }} :</strong> {{ $student->full_name }}</td>
                <td><strong>{{ $labels['year'] }} :</strong> {{ $yearLabel }}</td>
            </tr>
            <tr>
                @if ($studentNumber)
                    <td><strong>{{ $labels['studentNumber'] }} :</strong> {{ $studentNumber }}</td>
                @else
                    <td></td>
                @endif
                <td><strong>{{ $labels['group'] }} :</strong> {{ $student->room_name ?: '—' }}</td>
            </tr>
            <tr>
                <td><strong>{{ $labels['generated'] }} :</strong> {{ $generatedAt }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
    <div class="summary"><strong>{{ $labels['sessionCount'] }} :</strong> {{ $sessions->count() }}</div>
    <table class="sessions">
        <thead>
            <tr>
                <th style="width: 15%">{{ $labels['date'] }}</th>
                <th style="width: 32%">{{ $labels['portion'] }}</th>
                <th style="width: 18%">{{ $labels['evaluation'] }}</th>
                <th style="width: 35%">{{ $labels['notes'] }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($sessions as $session)
                <tr>
                    <td>{{ $session['session_date'] }}</td>
                    <td>{{ $session['portion_label'] }}</td>
                    <td>{{ $session['evaluation'] }}</td>
                    <td>{{ $session['notes'] ?: '' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">{{ $labels['noSessions'] }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    <div class="observations">
        <div class="observations-title">{{ $labels['observations'] }}</div>
        <div class="observation-line"></div>
        <div class="observation-line"></div>
        <div class="observation-line"></div>
    </div>
    <div class="signature">{{ $labels['teacherSignature'] }} : ____________________</div>
</body>
</html>
