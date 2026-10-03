@php($money = fn (int $paise) => \App\Domain\Billing\Support\InvoicePdf::money($paise))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $invoice->number }}</title>
    <style>
        @page { margin: 36px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 20px; margin: 0; }
        .muted { color: #64748b; }
        .small { font-size: 9px; }
        .label { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; }
        .parties td { vertical-align: top; width: 50%; padding-top: 18px; }
        .lines { margin-top: 24px; }
        .lines th { text-align: left; font-weight: normal; color: #64748b; border-bottom: 1px solid #cbd5e1; padding: 6px 0; }
        .lines td { border-bottom: 1px solid #f1f5f9; padding: 6px 0; }
        .right { text-align: right; }
        .totals td { padding: 3px 0; }
        .grand td { border-top: 1px solid #cbd5e1; padding-top: 8px; font-size: 13px; font-weight: bold; }
        .rule { border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; }
    </style>
</head>
<body>
    <table class="header rule">
        <tr>
            <td>
                <h1>{{ $title }}</h1>
                <div class="muted">No. {{ $invoice->number }}</div>
            </td>
            <td class="right muted">
                <div>Date: {{ $invoice->issued_at->timezone('Asia/Kolkata')->format('j M Y') }}</div>
                @if ($method)
                    <div>Paid by {{ $method }}@if ($reference) · {{ $reference }}@endif</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            @foreach (['From' => $invoice->seller, 'Billed to' => $invoice->buyer] as $heading => $party)
                <td>
                    <div class="label">{{ $heading }}</div>
                    <div><strong>{{ $party['name'] ?? '' }}</strong></div>
                    @if (! empty($party['address']))<div>{!! nl2br(e($party['address'])) !!}</div>@endif
                    @if (! empty($party['email']))<div>{{ $party['email'] }}</div>@endif
                    @if (! empty($party['phone']))<div>{{ $party['phone'] }}</div>@endif
                    @if (! empty($party['gstin']))<div>GSTIN: {{ $party['gstin'] }}</div>@endif
                    @if (! empty($party['reference']))<div class="muted">Customer ID: {{ $party['reference'] }}</div>@endif
                </td>
            @endforeach
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line['description'] }}</td>
                    <td class="right">{{ $money((int) $line['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top: 10px;">
        @if (! empty($invoice->tax))
            <tr>
                <td class="right muted">Taxable value</td>
                <td class="right" style="width: 120px;">{{ $money($invoice->subtotal) }}</td>
            </tr>
            @foreach ($invoice->tax as $tax)
                <tr>
                    <td class="right muted">{{ $tax['label'] }} @ {{ rtrim(rtrim(number_format((float) $tax['rate'], 2), '0'), '.') }}%</td>
                    <td class="right">{{ $money((int) $tax['amount']) }}</td>
                </tr>
            @endforeach
        @endif
        <tr class="grand">
            <td class="right">Total</td>
            <td class="right" style="width: 120px;">{{ $money($invoice->total) }}</td>
        </tr>
    </table>

    <p class="small muted" style="margin-top: 36px;">
        Amount received in full. This is a computer-generated invoice and needs no signature.
    </p>
</body>
</html>
