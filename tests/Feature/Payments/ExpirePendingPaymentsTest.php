<?php

use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Console\Scheduling\Schedule;

it('fails only the pending payments past the timeout', function (): void {
    config(['payments.pending_timeout' => 30]);

    $stale = Payment::factory()->create(['created_at' => now()->subMinutes(31)]);
    $fresh = Payment::factory()->create(['created_at' => now()->subMinutes(5)]);
    $settled = Payment::factory()->successful()->create(['created_at' => now()->subHour()]);

    $this->artisan('payments:expire-pending')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($stale->failure_reason)->toBe(PaymentFailureReason::Expired)
        ->and($fresh->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($settled->refresh()->status)->toBe(PaymentStatus::Successful);
});

it('runs every five minutes', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'payments:expire-pending'));

    expect($event?->expression)->toBe('*/5 * * * *');
});
