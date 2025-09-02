<!DOCTYPE html>
<html>

<head>
    <title>Bill Receipt</title>
    <style>
        /* Reset and base */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 40px auto;
            max-width: 600px;
            color: #333;
            background-color: #fff;
            font-size: 14px;
            line-height: 1.5;
        }

        .receipt {
            border: 1px solid #ddd;
            padding: 30px 40px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            background: #fafafa;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        .header h1 {
            margin: 0;
            font-size: 28px;
            letter-spacing: 2px;
        }

        .header p {
            margin: 5px 0 0 0;
            font-style: italic;
            color: #555;
            font-size: 14px;
        }

        .receipt-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
            font-size: 13px;
        }

        .receipt-info div {
            flex: 1 1 45%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }

        th,
        td {
            padding: 12px 15px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background-color: #f4f4f4;
            font-weight: 600;
        }

        tr:nth-child(even) td {
            background-color: #fcfcfc;
        }

        .summary-table {
            margin-bottom: 40px;
        }

        .total-row b {
            font-size: 17px;
            color: #111;
        }

        .conditional-row {
            color: #d9534f;
            /* Bootstrap danger red */
            font-weight: 600;
        }

        .footer {
            text-align: center;
            border-top: 2px solid #333;
            padding-top: 15px;
            font-size: 13px;
            color: #777;
        }

        .thank-you {
            margin-top: 10px;
            font-weight: 600;
            font-style: italic;
            color: #004085;
        }
    </style>
</head>

<body>
    <div class="receipt">
        <div class="header">
            <h1>Bill Receipt</h1>
            <p>{{ $payment_month }} Payment</p>
        </div>

        <div class="receipt-info">
            <div>
                <strong>Name:</strong> {{ $user_data->full_name }}<br>
                <strong>Email:</strong> {{ $user_data->email ?? 'N/A' }}<br>
                <strong>Phone:</strong> {{ $user_data->phone_number ?? 'N/A' }}<br>
                <strong>Collage:</strong> {{ $user_data->collage_name ?? 'N/A' }}<br>
                <strong>Canteen Id:</strong> {{ $user_data->canteen_id ?? 'N/A' }}<br>

            </div>
            <div style="text-align: right;">
                <strong>Receipt #:</strong> {{ $receipt_number ?? '000001' }}<br>
                <strong>Date of Payment:</strong> {{ $payment_date }}<br>
                <strong>Payment Mode:</strong> {{ $payment_mode }}
            </div>
        </div>

        <h3>Meal Summary</h3>
        <table class="summary-table">
            <thead>
                <tr>
                    @foreach ($attedance_counts as $attendance)
                        <th>{{ $attendance['meal_name']}}</th>
                    @endforeach
                </tr>
            </thead>
            <thead>
                <tr>
                    @foreach ($attedance_counts as $attendance)
                        <th>{{ $attendance['total']}}</th>
                    @endforeach
                </tr>
            </thead>
            
        </table>

        <table>
            <tbody>
                <tr class="total-row">
                    <th>Total Amount:</th>
                    <td><b>{{ number_format($total_amount, 2) }}</b></td>
                </tr>
                {{-- @if (!empty($pending_amount) && $pending_amount > 0)
                    <tr class="conditional-row">
                        <th>Pending Amount:</th>
                        <td>{{ number_format($pending_amount, 2) }}</td>
                    </tr>
                @endif --}}
                {{-- @if (!empty($advance_amount) && $advance_amount > 0)
                    <tr style="color: green; font-weight: 600;">
                        <th>Advance Amount:</th>
                        <td>{{ number_format($advance_amount, 2) }}</td>
                    </tr>
                @endif --}}
                <tr>
                    <th>Month of Payment:</th>
                    <td>{{ $payment_month }}</td>
                </tr>
            </tbody>
        </table>

        <div class="footer">
            <div>If you have any questions about this receipt, please contact our support team.</div>
            <div class="thank-you">Thank you for your payment!</div>
        </div>
    </div>
</body>

</html>
