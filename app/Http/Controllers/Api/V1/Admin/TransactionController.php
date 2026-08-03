<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Admin Portal
 *
 * Transaction history (FR-42): every payment raised against the platform —
 * subscription signups, renewals and upgrades — with the payer and gateway
 * reference, so finance and support can trace a payment end to end.
 *
 * Includes pending and failed payments, not just successful ones: a payment that
 * never settled is exactly what someone is looking for when they come asking.
 */
class TransactionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $transactions = Payment::query()
            ->with(['user', 'invoice'])
            ->filter($request->only(['search', 'status', 'method', 'gateway', 'subscriber', 'from', 'to']))
            ->latest()
            ->paginate(30);

        return TransactionResource::collection($transactions);
    }

    public function show(Payment $transaction): TransactionResource
    {
        return new TransactionResource($transaction->load(['user', 'invoice']));
    }
}
