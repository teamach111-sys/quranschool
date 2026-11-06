<!doctype html>
<html lang="{{ $language }}" dir="{{ $language === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body {
            color: #26342b;
            font-family: dejavusans, sans-serif;
            font-size: 11pt;
            margin: 0;
        }

        .receipt {
            box-sizing: border-box;
            padding: 9px;
        }

        .school {
            color: #315b45;
            font-size: 13pt;
            font-weight: bold;
            margin-bottom: 8mm;
        }

        h1 {
            color: #26342b;
            font-size: 24pt;
            margin: 0 0 3mm;
        }

        .confirmation {
            color: #617066;
            font-size: 10pt;
            margin: 0 0 10mm;
        }

        .amount {
            background: #edf5ef;
            border: 1px solid #c8d8ce;
            color: #174e3c;
            margin-bottom: 8mm;
            padding: 7mm;
        }

        .amount-label {
            color: #52665d;
            font-size: 9pt;
            margin-bottom: 2mm;
        }

        .amount-value {
            font-size: 23pt;
            font-weight: bold;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        td {
            border-bottom: 1px solid #dce5df;
            padding: 4mm 3mm;
            vertical-align: top;
        }

        .label {
            color: #52665d;
            width: 38%;
        }

        .generated {
            color: #617066;
            font-size: 8pt;
            margin-top: 7mm;
        }

    </style>
</head>
<body>
    <div class="receipt">
        <div class="school">{{ $schoolName }}</div>
        <h1>{{ $labels['title'] }}</h1>
        <p class="confirmation">{{ $labels['paymentConfirmation'] }}</p>
        <div class="amount">
            <div class="amount-label">{{ $labels['amountReceived'] }}</div>
            <div class="amount-value">{{ $amount }} {{ $currency }}</div>
        </div>
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
