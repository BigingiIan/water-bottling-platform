{{--
    The invoice document, shared by the detail page and the PDF. Plain CSS only (dompdf has no Tailwind),
    scoped under .inv-document. Expects: $invoice (with items, customer, order), $organisation, $footerText.
--}}
@php
    $settingsDate = app(\App\Support\SettingsDate::class);
    $money = fn ($value): string => \App\Services\Orders\Money::format((string) $value);
@endphp

<style>
    .inv-document { color: #0f172a; font-family: 'DejaVu Sans', Inter, Arial, sans-serif; font-size: 12px; line-height: 1.45; }
    .inv-document table { border-collapse: collapse; width: 100%; }
    .inv-head td { vertical-align: top; }
    .inv-org-name { font-size: 18px; font-weight: 800; }
    .inv-muted { color: #64748b; }
    .inv-title { font-size: 22px; font-weight: 800; letter-spacing: 0.5px; text-align: right; }
    .inv-meta td { padding: 2px 0; }
    .inv-meta td.inv-label { color: #64748b; padding-right: 12px; text-align: right; }
    .inv-meta td.inv-value { text-align: right; font-weight: 600; }
    .inv-section { margin-top: 22px; }
    .inv-caption { color: #64748b; font-size: 10px; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase; margin-bottom: 4px; }
    .inv-lines th { background: #f1f5f9; border-bottom: 1px solid #cbd5e1; color: #475569; font-size: 10px; letter-spacing: 0.4px; padding: 7px 8px; text-align: left; text-transform: uppercase; }
    .inv-lines td { border-bottom: 1px solid #e2e8f0; padding: 7px 8px; }
    .inv-num, .inv-lines th.inv-num { text-align: right; white-space: nowrap; }
    .inv-totals { margin-left: auto; width: 46%; margin-top: 10px; }
    .inv-totals td { padding: 4px 8px; }
    .inv-totals .inv-grand td { border-top: 2px solid #0f172a; font-size: 14px; font-weight: 800; padding-top: 7px; }
    .inv-stamp { border: 2px solid #b91c1c; color: #b91c1c; display: inline-block; font-size: 16px; font-weight: 800; letter-spacing: 2px; padding: 4px 12px; }
    .inv-footer { border-top: 1px solid #e2e8f0; color: #475569; margin-top: 28px; padding-top: 10px; font-size: 11px; }
    .inv-disclaimer { color: #b45309; font-weight: 700; margin-top: 6px; }
</style>

<div class="inv-document">
    <table class="inv-head">
        <tr>
            <td style="width: 55%;">
                <div class="inv-org-name">{{ $organisation['name'] }}</div>
                @if ($organisation['address'])<div class="inv-muted">{{ $organisation['address'] }}</div>@endif
                @if ($organisation['phone'])<div class="inv-muted">Tel: {{ $organisation['phone'] }}</div>@endif
                @if ($organisation['kraPin'])<div class="inv-muted">KRA PIN: {{ $organisation['kraPin'] }}</div>@endif
            </td>
            <td>
                <div class="inv-title">INVOICE</div>
                <table class="inv-meta" style="width: auto; margin-left: auto; margin-top: 6px;">
                    <tr><td class="inv-label">Number</td><td class="inv-value">{{ $invoice->invoice_number ?? 'Draft (not yet issued)' }}</td></tr>
                    <tr><td class="inv-label">Issue date</td><td class="inv-value">{{ $invoice->issue_date ? $settingsDate->formatDate($invoice->issue_date) : '—' }}</td></tr>
                    <tr><td class="inv-label">Due date</td><td class="inv-value">{{ $invoice->due_date ? $settingsDate->formatDate($invoice->due_date) : '—' }}</td></tr>
                    <tr><td class="inv-label">Order</td><td class="inv-value">{{ $invoice->order->order_number }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="inv-section">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                <div class="inv-caption">Bill to</div>
                <div style="font-weight: 700;">{{ $invoice->customer->name }}</div>
                @if ($invoice->customer->address)<div class="inv-muted">{{ $invoice->customer->address }}</div>@endif
                @if ($invoice->customer->contact_number)<div class="inv-muted">{{ $invoice->customer->contact_number }}</div>@endif
                <div class="inv-muted">Payment terms: {{ \App\Support\Plural::count($invoice->customer->payment_terms_days, 'day') }}</div>
            </td>
            <td style="text-align: right; vertical-align: top;">
                @if ($invoice->status === \App\Services\Invoicing\InvoiceStatus::Void)
                    <span class="inv-stamp">VOID</span>
                    <div class="inv-muted" style="margin-top: 6px;">{{ $invoice->void_reason }}</div>
                @elseif ($invoice->status === \App\Services\Invoicing\InvoiceStatus::Paid)
                    <span class="inv-stamp" style="border-color: #15803d; color: #15803d;">PAID</span>
                @elseif ($invoice->status === \App\Services\Invoicing\InvoiceStatus::Draft)
                    <span class="inv-stamp" style="border-color: #64748b; color: #64748b;">DRAFT</span>
                @endif
            </td>
        </tr>
    </table>

    <table class="inv-lines inv-section">
        <thead>
            <tr>
                <th>Description</th>
                <th class="inv-num">Qty</th>
                <th class="inv-num">Unit price</th>
                <th class="inv-num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="inv-num">{{ number_format($item->quantity) }}</td>
                    <td class="inv-num">{{ $money($item->unit_price) }}</td>
                    <td class="inv-num">{{ $money($item->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="inv-totals">
        <tr><td>Subtotal{{ $invoice->prices_include_vat ? ' (excl. VAT)' : '' }}</td><td class="inv-num">{{ $money($invoice->subtotal) }}</td></tr>
        <tr><td>VAT {{ rtrim(rtrim(number_format((float) $invoice->vat_rate, 2), '0'), '.') }}%{{ $invoice->prices_include_vat ? ' (included in prices)' : '' }}</td><td class="inv-num">{{ $money($invoice->vat_amount) }}</td></tr>
        <tr class="inv-grand"><td>Total</td><td class="inv-num">{{ $money($invoice->total) }}</td></tr>
        @if (bccomp((string) $invoice->amount_paid, '0', 2) > 0)
            <tr><td class="inv-muted">Paid</td><td class="inv-num inv-muted">{{ $money($invoice->amount_paid) }}</td></tr>
            <tr><td style="font-weight: 700;">Balance due</td><td class="inv-num" style="font-weight: 700;">{{ $money($invoice->balance()) }}</td></tr>
        @endif
    </table>

    <div class="inv-footer">
        @if ($footerText)<div>{{ $footerText }}</div>@endif
        <div class="inv-disclaimer">Internal invoice, not an eTIMS tax invoice.</div>
    </div>
</div>
