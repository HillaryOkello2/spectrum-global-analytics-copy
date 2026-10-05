@php
    $payment = $invoice->payment;
    $payer = $payment?->user;
    $money = fn ($currency, $amount) => $currency.' '.number_format((float) $amount, 2);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1a1a1a; }
        .masthead { border-bottom: 2px solid #1a1a1a; padding-bottom: 12px; margin-bottom: 24px; }
        .masthead h1 { font-size: 16px; margin: 0 0 4px; letter-spacing: 0.08em; }
        .masthead p { margin: 0; font-size: 10px; color: #555; }
        .meta { width: 100%; margin-bottom: 24px; }
        .meta td { vertical-align: top; padding: 0 0 4px; }
        .label { color: #555; width: 120px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.items th { text-align: left; border-bottom: 1px solid #1a1a1a; padding: 6px 0; font-size: 10px; letter-spacing: 0.06em; }
        table.items td { padding: 8px 0; border-bottom: 1px solid #e5e5e5; }
        td.amount, th.amount { text-align: right; }
        .total td { font-weight: bold; border-bottom: none; padding-top: 10px; }
        .paid { margin-top: 20px; padding: 8px 10px; background: #f3f6f3; border-left: 3px solid #2f6f3e; }
        .footnote { margin-top: 28px; font-size: 9px; color: #777; }
    </style>
</head>
<body>
    <div class="masthead">
        <h1>{{ config('app.name') }}</h1>
        <p>A New Security Architecture for a Fractured World</p>
    </div>

    <table class="meta">
        <tr>
            <td class="label">Invoice</td>
            <td><strong>{{ $invoice->number }}</strong></td>
            <td class="label">Issued</td>
            <td>{{ $invoice->issue_date?->format('j F Y') }}</td>
        </tr>
        <tr>
            <td class="label">Billed to</td>
            <td>
                {{ $payer?->full_name }}<br>
                {{ $payer?->email }}
            </td>
            <td class="label">Status</td>
            <td>Paid{{ $payment?->paid_at ? ' on '.$payment->paid_at->format('j F Y') : '' }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->line_items ?? [] as $item)
                <tr>
                    <td>{{ $item['description'] ?? '' }}</td>
                    <td class="amount">{{ $money($invoice->currency, $item['amount'] ?? 0) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Total paid</td>
                <td class="amount">{{ $money($invoice->currency, $invoice->amount) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($payment)
        <div class="paid">
            Paid by {{ ucfirst($payment->method->value) }}@if ($payment->transaction_code), reference {{ $payment->transaction_code }}@endif.
            @if ($payment->exchange_rate)
                Charged in {{ $payment->currency }} against a listed price of
                {{ $money($payment->list_currency, $payment->list_amount) }}.
            @endif
        </div>
    @endif

    <p class="footnote">
        This invoice is issued for a subscription already settled; no payment is due.
        Questions about it should quote the invoice number above.
    </p>
</body>
</html>
