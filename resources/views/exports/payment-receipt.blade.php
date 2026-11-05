<!doctype html>
<html lang="{{ $language }}" dir="{{ $language === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body {
            color: #173d32;
            font-family: dejavusans, sans-serif;
            font-size: 11pt;
        }

        .receipt {
            border: 1px solid #c8d8ce;
            padding: 28px;
        }

        .school {
            color: #315f50;
            font-size: 13pt;
            font-weight: bold;
            margin-bottom: 10px;
        }

        h1 {
            font-size: 20pt;
            margin: 0 0 8px;
        }

        .confirmation {
            color: #52665d;
            font-size: 9pt;
            margin: 0 0 20px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        td {
            border-bottom: 1px solid #e1e9e3;
            padding: 10px 6px;
            vertical-align: top;
        }

        .label {
            color: #52665d;
            width: 38%;
        }

        .amount {
            color: #174e3c;
            font-size: 15pt;
            font-weight: bold;
        }

        .generated {
            color: #52665d;
            font-size: 8pt;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="school">{{ $schoolName }}</div>
        <h1>{{ $labels['title'] }}</h1>
        <p class="confirmation">{{ $labels['paymentConfirmation'] }}</p>
        <table>
            <tbody>
                <tr>
                    <td class="label">{{ $labels['student'] }}</td>
                    <td>{{ $studentName }}</td>
                </tr>
                <tr>
                    <td class="label">{{ $labels['academicYear'] }}</td>
                    <td>{{ $yearLabel }}</td>
                </tr>
                <tr>
                    <td class="label">{{ $labels['billingPeriod'] }}</td>
                    <td>{{ $periodStart }} – {{ $periodEnd }}</td>
                </tr>
                <tr>
                    <td class="label">{{ $labels['amountReceived'] }}</td>
                    <td class="amount">{{ $amount }} {{ $currency }}</td>
                </tr>
                <tr>
                    <td class="label">{{ $labels['paidAt'] }}</td>
                    <td>{{ $paidAt }}</td>
                </tr>
                @if ($paymentMethod)
                    <tr>
                        <td class="label">{{ $labels['paymentMethod'] }}</td>
                        <td>{{ $paymentMethod }}</td>
                    </tr>
                @endif
                @if ($reference)
                    <tr>
                        <td class="label">{{ $labels['reference'] }}</td>
                        <td>{{ $reference }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
        <p class="generated">{{ $labels['generatedAt'] }}: {{ $generatedAt }}</p>
    </div>
</body>
</html>
