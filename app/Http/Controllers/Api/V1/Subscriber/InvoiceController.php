<?php

namespace App\Http\Controllers\Api\V1\Subscriber;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Subscriber Portal
 *
 * Subscription invoices (FR-34).
 */
class InvoiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $invoices = Invoice::query()
            ->whereHas('payment', fn ($q) => $q->where('user_id', $request->user()->id))
            ->latest('issue_date')
            ->paginate(20);

        return InvoiceResource::collection($invoices);
    }

    public function show(Request $request, Invoice $invoice): InvoiceResource
    {
        abort_unless($invoice->payment->user_id === $request->user()->id, 404);

        return new InvoiceResource($invoice);
    }
}
