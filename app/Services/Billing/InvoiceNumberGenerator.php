<?php

namespace App\Services\Billing;

use App\Models\Invoice;

class InvoiceNumberGenerator
{
    /**
     * Sequential per-year invoice numbers: INV-2026-000001.
     */
    public function next(): string
    {
        $year = now()->year;
        $prefix = "INV-{$year}-";

        $lastNumber = Invoice::query()
            ->where('number', 'like', "{$prefix}%")
            ->orderByDesc('number')
            ->value('number');

        $sequence = $lastNumber ? (int) substr($lastNumber, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
