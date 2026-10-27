<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoicing\InvoiceDocument;
use App\Support\ModuleAccess;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, InvoiceDocument $document): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        app(ModuleAccess::class)->authorize($user, 'invoicing');

        return Pdf::loadView('invoices.pdf', $document->viewData($invoice))
            ->setPaper('a4')
            ->download($document->filename($invoice));
    }
}
