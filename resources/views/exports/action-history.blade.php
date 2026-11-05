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
        <p><strong>{{ $labels['generatedAt'] }}:</strong> {{ $generatedAt }}</p>
        <p><strong>{{ $labels['recordCount'] }}:</strong> {{ $events->count() }}</p>
        <p><strong>{{ $labels['filters'] }}:</strong> {{ implode(' | ', $filters) }}</p>
    </section>
    <table>
        <thead>
            <tr>
                <th>{{ $labels['summary'] }}</th>
                <th>{{ $labels['action'] }}</th>
                <th>{{ $labels['entity'] }}</th>
                <th>{{ $labels['record'] }}</th>
                <th>{{ $labels['actor'] }}</th>
                <th>{{ $labels['date'] }}</th>
                <th>{{ $labels['reason'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($events as $event)
                <tr>
                    <td>{{ $localizedSummary($event->summary_key) }}</td>
                    <td>{{ $localizedAction($event->action_type) }}</td>
                    <td>{{ $localizedEntity($event->entity_type) }}</td>
                    <td>{{ $event->entity_id ?? '—' }}</td>
                    <td>{{ $event->actor_name }}</td>
                    <td>{{ $event->created_at }}</td>
                    <td>{{ $event->reason ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
