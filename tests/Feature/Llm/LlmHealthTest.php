<?php

use App\Models\LlmCallLog;
use App\Models\LlmProvider;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    // The snapshot is cached for 15s; without this a test would read the
    // previous test's providers.
    Cache::flush();
});

function healthAdmin(array $permissions = ['access admin portal', 'view analytics']): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function health(User $user): array
{
    return test()->actingAs($user)
        ->getJson(route('api.admin.analytics.llm-health'))
        ->assertOk()
        ->json('data');
}

it('returns every provider, including ones nobody has called', function (): void {
    LlmProvider::factory()->count(3)->create();

    $data = health(healthAdmin());

    expect($data['providers'])->toHaveCount(3)
        ->and($data)->toHaveKey('generatedAt');

    foreach ($data['providers'] as $provider) {
        expect($provider['status'])->toBe('idle')
            ->and($provider['requests24h'])->toBe(0)
            ->and($provider['errors24h'])->toBe(0)
            ->and($provider['avgLatencyMs'])->toBeNull()
            ->and($provider['lastCheckedAt'])->toBeNull()
            ->and($provider['recentErrors'])->toBe([]);
    }
});

it('joins to the provider directory on the same public id', function (): void {
    $provider = LlmProvider::factory()->create();

    $directory = $this->actingAs(healthAdmin(['access admin portal', 'view analytics', 'manage topics']))
        ->getJson(route('api.admin.llm-providers.index'))
        ->json('data.0.publicId');

    expect(health(healthAdmin())['providers'][0]['publicId'])
        ->toBe($directory)
        ->toBe($provider->public_id);
});

it('counts attempts and errors over the window and excludes probes', function (): void {
    $provider = LlmProvider::factory()->create();

    LlmCallLog::factory()->count(8)->for($provider, 'provider')->create(['latency_ms' => 1000]);
    LlmCallLog::factory()->count(2)->for($provider, 'provider')->failed()->create();
    // Probes, and traffic older than the window: neither is counted.
    LlmCallLog::factory()->count(5)->for($provider, 'provider')->probe()->create();
    LlmCallLog::factory()->count(4)->for($provider, 'provider')->at(now()->subDays(2))->create();

    $health = health(healthAdmin())['providers'][0];

    expect($health['requests24h'])->toBe(10)
        ->and($health['errors24h'])->toBe(2)
        ->and($health['errors24h'])->toBeLessThanOrEqual($health['requests24h'])
        // Successful calls only: a failed call has no latency to average.
        ->and($health['avgLatencyMs'])->toBe(1000);
});

it('reads as degraded once a tenth of its calls are failing', function (): void {
    $provider = LlmProvider::factory()->create();

    LlmCallLog::factory()->count(18)->for($provider, 'provider')->create();
    LlmCallLog::factory()->count(2)->for($provider, 'provider')->failed()->create();

    expect(health(healthAdmin())['providers'][0]['status'])->toBe('degraded');
});

it('reads as healthy below that', function (): void {
    $provider = LlmProvider::factory()->create();

    LlmCallLog::factory()->count(50)->for($provider, 'provider')->create();
    LlmCallLog::factory()->count(2)->for($provider, 'provider')->failed()->create();

    expect(health(healthAdmin())['providers'][0]['status'])->toBe('healthy');
});

it('reads as down when the last five real calls all failed', function (): void {
    $provider = LlmProvider::factory()->create();

    LlmCallLog::factory()->count(20)->for($provider, 'provider')
        ->at(now()->subHours(6))->create();
    LlmCallLog::factory()->count(5)->for($provider, 'provider')
        ->failed('503', 'Service unavailable')->at(now()->subMinutes(5))->create();

    expect(health(healthAdmin())['providers'][0]['status'])->toBe('down');
});

it('reads as down when a quiet provider fails its probe', function (): void {
    $provider = LlmProvider::factory()->create();

    // No real traffic at all — the rule the probe exists for. Without it this
    // provider would read as idle while its key was revoked.
    LlmCallLog::factory()->for($provider, 'provider')->probe()
        ->failed('401', 'Invalid API key provided')->create();

    expect(health(healthAdmin())['providers'][0]['status'])->toBe('down');
});

it('is idle again once the probe recovers', function (): void {
    $provider = LlmProvider::factory()->create();

    LlmCallLog::factory()->for($provider, 'provider')->probe()
        ->failed('401')->at(now()->subMinutes(10))->create();
    LlmCallLog::factory()->for($provider, 'provider')->probe()
        ->at(now()->subMinute())->create();

    expect(health(healthAdmin())['providers'][0]['status'])->toBe('idle');
});

it('does not hold an old failed probe against a switched-off provider', function (): void {
    $provider = LlmProvider::factory()->create(['is_active' => false]);

    // Deactivated providers are not probed any more, so the last thing on
    // record is a failure from before they were switched off.
    LlmCallLog::factory()->for($provider, 'provider')->probe()
        ->failed('401')->at(now()->subDays(3))->create();

    expect(health(healthAdmin())['providers'][0]['status'])->toBe('idle');
});

it('returns the newest errors first, capped at twenty', function (): void {
    $provider = LlmProvider::factory()->create();

    foreach (range(1, 25) as $minutes) {
        LlmCallLog::factory()->for($provider, 'provider')
            ->failed('429', "Failure {$minutes}")
            ->at(now()->subMinutes($minutes))
            ->create();
    }

    $errors = health(healthAdmin())['providers'][0]['recentErrors'];

    expect($errors)->toHaveCount(20)
        ->and($errors[0]['message'])->toBe('Failure 1')
        ->and($errors[19]['message'])->toBe('Failure 20')
        ->and($errors[0])->toHaveKeys(['occurredAt', 'code', 'message', 'context']);
});

it('refuses an admin without the analytics permission', function (): void {
    $this->actingAs(healthAdmin(['access admin portal']))
        ->getJson(route('api.admin.analytics.llm-health'))
        ->assertForbidden();
});

it('refuses an unauthenticated caller', function (): void {
    $this->getJson(route('api.admin.analytics.llm-health'))->assertUnauthorized();
});

it('reads a billing or server refusal on the probe as down', function (string $code): void {
    $provider = LlmProvider::factory()->create();

    LlmCallLog::factory()->for($provider, 'provider')->probe()->failed($code)->create();

    expect(health(healthAdmin())['providers'][0]['status'])->toBe('down');
})->with(['401', '402', '403', '500', '503', 'timeout', 'network']);

it('never calls a provider healthy on the strength of a failing probe alone', function (): void {
    $provider = LlmProvider::factory()->create();

    // 429 is not an outage — but it is not nothing either, and a provider with
    // no traffic and a failing probe must not read as fine.
    LlmCallLog::factory()->for($provider, 'provider')->probe()->failed('429')->create();

    expect(health(healthAdmin())['providers'][0]['status'])->toBe('degraded');
});
