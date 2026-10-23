<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->displayNumber() }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { margin: 0; }
    </style>
</head>
<body>
    @include('invoices.document')
</body>
</html>
