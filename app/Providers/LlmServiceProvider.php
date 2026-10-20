<?php

namespace App\Providers;

use App\Services\Llm\LlmCallRecorder;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Hooks LLM call recording onto the HTTP client's own events.
 *
 * The drivers retry internally, and a retry is a second request to the vendor
 * — these events are the only place that sees each one. The recorder ignores
 * everything sent outside an open recording scope, so the payment gateway and
 * any other HTTP traffic are not touched.
 */
class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmCallRecorder::class);
    }

    public function boot(): void
    {
        Event::listen(RequestSending::class, function (): void {
            $recorder = $this->app->make(LlmCallRecorder::class);

            if ($recorder->isRecording()) {
                $recorder->attemptStarted();
            }
        });

        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            $this->app->make(LlmCallRecorder::class)->attemptFinished($event->response);
        });

        Event::listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            $this->app->make(LlmCallRecorder::class)->attemptFailed($event->exception);
        });
    }
}
