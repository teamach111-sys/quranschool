<!doctype html>
<html lang="{{ $language }}" dir="{{ $language === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body {
            color: #173d32;
            font-family: dejavusans, sans-serif;
            font-size: 9pt;
        }

        h1 {
            color: #174e3c;
            font-size: 18pt;
            margin: 0 0 12px;
        }

        .metadata {
            border: 1px solid #c8d8ce;
            margin-bottom: 16px;
            padding: 10px;
        }

        .metadata p {
            margin: 3px 0;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th,
        td {
            border: 1px solid #c8d8ce;
            padding: 6px;
            vertical-align: top;
        }

        th {
            background: #e7f0e9;
            color: #174e3c;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <h1>{{ $labels['title'] }}</h1>
    <section class="metadata">
        <p><strong>{{ $labels['school'] }}:</strong> {{ $schoolName }}</p>
        <p><strong>{{ $labels['year'] }}:</strong> {{ $yearLabel }}</p>
        <p><strong>{{ $labels['room'] }}:</strong> {{ $roomName }}</p>
        <p><strong>{{ $labels['count'] }}:</strong> {{ $records->count() }}</p>
        <p><strong>{{ $labels['generated'] }}:</strong> {{ $generatedAt }}</p>
    </section>
    <table>
        <thead>
            <tr>
                <th>{{ $labels['day'] }}</th>
                <th>{{ $labels['time'] }}</th>
                <th>{{ $labels['room'] }}</th>
                <th>{{ $labels['teacher'] }}</th>
                <th>{{ $labels['course'] }}</th>
                <th>{{ $labels['validity'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($records as $record)
                <tr>
                    <td>{{ $dayLabel((int) $record->day_of_week) }}</td>
                    <td>{{ substr($record->starts_at, 0, 5) }}–{{ substr($record->ends_at, 0, 5) }}</td>
                    <td>{{ $record->room_name }}</td>
                    <td>{{ $record->teacher_name }}</td>
                    <td>{{ $record->label }}</td>
                    <td>{{ $record->valid_from }} – {{ $record->valid_until ?: $labels['noEndDate'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
