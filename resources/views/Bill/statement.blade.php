@php
    use App\Services\StudentStatement;
    $text = fn ($value, $instead) => StudentStatement::printable($value) ? $value : $instead;
    $rupees = fn ($amount) => ($amount < 0 ? '−' : '') . '₹' . preg_replace('/(?<=\d)(?=(\d\d)*\d{3}$)/', ',', abs($amount));
    $day = fn ($date) => \Carbon\Carbon::parse($date)->format('j M Y');
@endphp
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Monthly bill</title>
    <style>
        @page { margin: 14mm 15mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #111; }
        .bill + .bill { page-break-before: always; }
        h1 { font-size: 20px; margin: 0; }
        h2 { font-size: 14px; margin: 0 0 10px; font-weight: normal; }
        table { width: 100%; border-collapse: collapse; }
        .lines { table-layout: fixed; }
        .who td { padding: 2px 0; vertical-align: top; word-wrap: break-word; }
        .who td:first-child { width: 22%; }
        .lines th { text-align: left; border-bottom: 2px solid #111; padding: 5px 4px; }
        .lines thead .head { border: 0; padding-top: 10px; font-weight: normal; }
        .lines td { border-bottom: 1px solid #bbb; padding: 5px 4px; word-wrap: break-word; }
        .lines tr { page-break-inside: avoid; }
        .lines .part { font-weight: bold; background: #eee; }
        .n { text-align: right !important; }
        .sum td { font-weight: bold; border-bottom: 1px solid #111; }
        .close td { font-size: 17px; font-weight: bold; border: 2px solid #111; padding: 8px 6px; }
        .note { margin-top: 12px; }
    </style>
</head>

<body>
    @foreach ($bills as $bill)
        @php
            $name = $text($bill['name'], 'see canteen ID');
            $month = \Carbon\Carbon::parse($bill['month'] . '-01')->format('F Y');
        @endphp
        <div class="bill">
            <h1>Canteen</h1>
            <h2>Monthly bill for {{ $month }}</h2>
            <table class="who">
                <tr><td>Name</td><td><b>{{ $name }}</b></td></tr>
                <tr><td>Canteen ID</td><td><b>{{ $bill['canteen_id'] ?? 'Not given' }}</b></td></tr>
                <tr><td>College</td><td>{{ $text($bill['college'], 'Cannot be printed') ?: 'Not given' }}</td></tr>
                <tr><td>Printed on</td><td>{{ now()->format('j M Y') }}</td></tr>
            </table>
            @if (! $bill['available'])
                <p class="note"><b>Not available.</b> This month or a later one has an old payment without a reliable amount, so the bill cannot be worked out.</p>
            @else
                <table class="lines">
                    <thead>
                        {{-- Printed again on a second page. --}}
                        <tr><th class="head" colspan="3">{{ $name }}, {{ $bill['canteen_id'] ?? 'no canteen ID' }}, {{ $month }}</th></tr>
                        <tr><th>Item</th><th class="n">Number</th><th class="n">Amount</th></tr>
                    </thead>
                    <tbody>
                        <tr class="sum"><td colspan="2">{{ $bill['opening'] < 0 ? 'Advance from before' : 'From before' }}</td><td class="n">{{ $rupees(abs($bill['opening'])) }}</td></tr>
                        @if (! $bill['meals'])
                            <tr><td colspan="3">No meals this month</td></tr>
                        @endif
                        @foreach ($bill['meals'] as $line)
                            <tr><td>{{ $text($line['name'], 'Meal') }}</td><td class="n">{{ $line['count'] }}</td><td class="n">{{ $rupees($line['amount']) }}</td></tr>
                        @endforeach
                        @foreach ($bill['extras'] as $line)
                            <tr><td>{{ $text($line['name'], 'Extra') }}</td><td class="n">{{ $line['count'] }}</td><td class="n">{{ $rupees($line['amount']) }}</td></tr>
                        @endforeach
                        <tr class="sum"><td colspan="2">Total for {{ $month }}</td><td class="n">{{ $rupees($bill['charged']) }}</td></tr>
                        @if ($bill['payments'])
                            <tr><td class="part" colspan="3">Payments</td></tr>
                        @endif
                        @foreach ($bill['payments'] as $line)
                            <tr><td>{{ $day($line['date']) }}, {{ $line['method'] }}</td><td class="n">Receipt {{ $line['number'] }}</td><td class="n">− {{ $rupees($line['amount']) }}</td></tr>
                        @endforeach
                        <tr class="close"><td colspan="2">{{ $bill['closing'] < 0 ? 'Advance' : 'To pay' }}</td><td class="n">{{ $rupees(abs($bill['closing'])) }}</td></tr>
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach
</body>

</html>
