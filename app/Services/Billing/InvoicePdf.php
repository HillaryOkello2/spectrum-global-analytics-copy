<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders an invoice as a PDF for attaching to the payment email.
 */
class InvoicePdf
{
    /**
     * @return string the PDF bytes
     */
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing('payment.user', 'subscription.tier');

        $options = new Options;
        // Nothing in the template is fetched over the network; keeping this off
        // means a crafted value could never make the renderer call out.
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml(view('invoices.pdf', ['invoice' => $invoice])->render());
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function filename(Invoice $invoice): string
    {
        return 'invoice-'.$invoice->number.'.pdf';
    }
}
