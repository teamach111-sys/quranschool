<!doctype html>
<html lang="{{ $language }}" dir="{{ $language === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        body {
            color: #000;
            font-family: dejavusans, sans-serif;
            font-size: 11pt;
            margin: 0;
        }

        .receipt {
            box-sizing: border-box;
            padding: 9px;
        }

        .school {
            color: #000;
            font-size: 13pt;
            font-weight: bold;
            margin-bottom: 4mm;
        }

        h1 {
            color: #000;
            font-size: 24pt;
            margin: 0 0 3mm;
        }

        .confirmation {
            color: #444;
            font-size: 10pt;
            margin: 0 0 5mm;
        }

        .amount {
            border: 1px solid #999;
            color: #000;
            margin-bottom: 4mm;
            padding: 4mm;
        }

        .amount-label {
            color: #444;
            font-size: 9pt;
            margin-bottom: 2mm;
        }

        .amount-value {
            font-size: 23pt;
            font-weight: bold;
        }

        .amount-value-template {
            font-size: 11pt;
            font-weight: normal;
            white-space: nowrap;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        td {
            border-bottom: 1px solid #ccc;
            padding: 2mm 3mm;
            vertical-align: top;
        }

        .label {
            color: #444;
            width: 38%;
        }

        .generated {
            color: #444;
            font-size: 8pt;
            margin-top: 4mm;
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
            <div class="amount-value{{ !empty($isTemplate) ? ' amount-value-template' : '' }}">{{ $amount }}{{ $currency !== '' ? ' '.$currency : '' }}</div>
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
