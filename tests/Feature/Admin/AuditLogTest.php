<?php

use App\Models\Product;
use App\Models\SubscriptionTier;
use App\Models\User;
use Database\Seeders\SubscriptionTierSeeder;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

function auditor(): User
{
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user;
}

function subscriber(): User
{
    $user = User::factory()->create(['country' => 'KE', 'status' => 'pending']);
    $user->assignRole('subscriber');

    return $user;
}

it('records which fields changed, from what, to what', function (): void {
    $admin = auditor();
    $subscriber = subscriber();

    $this->actingAs($admin)
        ->putJson(route('api.admin.subscribers.update', $subscriber), [
            'country' => 'UG',
            'status' => 'active',
        ])
        ->assertOk();

    $entry = Activity::where('description', 'subscriber updated')->sole();
    $changes = collect($entry->properties['changes'])->keyBy('field');

    expect($changes)->toHaveCount(2)
        ->and($changes['country']['from'])->toBe('KE')
        ->and($changes['country']['to'])->toBe('UG')
        ->and($changes['country']['label'])->toBe('Country')
        ->and($changes['status']['from'])->toBe('pending')
        ->and($changes['status']['to'])->toBe('active')
        ->and($entry->causer_id)->toBe($admin->id);
});

it('leaves out fields that were submitted unchanged', function (): void {
    $subscriber = subscriber();

    $this->actingAs(auditor())
        ->putJson(route('api.admin.subscribers.update', $subscriber), [
            'country' => 'KE',
            'status' => 'active',
        ])
        ->assertOk();

    $changes = Activity::where('description', 'subscriber updated')->sole()->properties['changes'];

    expect($changes)->toHaveCount(1)
        ->and($changes[0]['field'])->toBe('status');
});

it('names the record a change was made to, so the entry survives its deletion', function (): void {
    $subscriber = subscriber();

    $this->actingAs(auditor())
        ->putJson(route('api.admin.subscribers.update', $subscriber), ['status' => 'active'])
        ->assertOk();

    $subject = Activity::where('description', 'subscriber updated')->sole()->properties['subject'];

    expect($subject['label'])->toBe($subscriber->full_name)
        ->and($subject['publicId'])->toBe($subscriber->public_id);
});

it('records a list change as a list, not as a row count', function (): void {
    Role::findOrCreate('editor');

    $staff = User::factory()->create();
    $staff->assignRole('admin');

    $this->actingAs(auditor())
        ->putJson(route('api.admin.users.roles.update', $staff), ['roles' => ['editor']])
        ->assertOk();

    $change = Activity::where('description', 'roles updated')->sole()->properties['changes'][0];

    expect($change['field'])->toBe('roles')
        ->and($change['from'])->toBe(['admin'])
        ->and($change['to'])->toBe(['editor']);
});

it('never writes a credential into the log', function (): void {
    $staff = User::factory()->create();
    $staff->assignRole('admin');

    $this->actingAs(auditor())
        ->putJson(route('api.admin.users.update', $staff), [
            'first_name' => 'Renamed',
            'password' => 'a-very-secret-password',
        ])
        ->assertOk();

    $entry = Activity::where('description', 'admin user updated')->sole();
    $serialised = json_encode($entry->properties);

    expect($serialised)->not->toContain('a-very-secret-password');

    foreach ($entry->properties['changes'] as $change) {
        if ($change['field'] === 'password') {
            expect($change['to'])->toBe('[redacted]');
        }
    }
});

it('records where the change came from', function (): void {
    $this->actingAs(auditor())
        ->putJson(route('api.admin.subscribers.update', subscriber()), ['status' => 'active'])
        ->assertOk();

    expect(Activity::where('description', 'subscriber updated')->sole()->properties['context']['via'])
        ->toBe('api');
});

it('serves the log as changes, a summary and the person who made them', function (): void {
    $admin = auditor();
    $subscriber = subscriber();

    $this->actingAs($admin)
        ->putJson(route('api.admin.subscribers.update', $subscriber), ['status' => 'active'])
        ->assertOk();

    $entry = collect($this->actingAs($admin)
        ->getJson(route('api.admin.audit-logs.index'))
        ->assertOk()
        ->json('data'))
        ->firstWhere('event', 'subscriber updated');

    expect($entry['causer']['name'])->toBe($admin->full_name)
        ->and($entry['causer']['publicId'])->toBe($admin->public_id)
        ->and($entry['subject']['type'])->toBe('User')
        ->and($entry['subject']['label'])->toBe($subscriber->full_name)
        ->and($entry['changes'][0]['field'])->toBe('status')
        ->and($entry['summary'])
        ->toContain($admin->full_name)
        ->toContain('subscriber updated')
        ->toContain('pending → active');
});

it('attributes an unattended change to the system rather than leaving it blank', function (): void {
    $this->seed(SubscriptionTierSeeder::class);
    $tier = SubscriptionTier::firstOrFail();

    // The scheduler releases products; nobody clicked anything.
    Product::factory()->approved()->create();
    $this->artisan('products:release')->assertSuccessful();

    $entry = collect($this->actingAs(auditor())
        ->getJson(route('api.admin.audit-logs.index'))
        ->json('data'))
        ->firstWhere('event', 'product released');

    expect($entry['causer']['name'])->toBe('System')
        ->and($entry['context']['via'])->toBe('system')
        ->and($entry['changes'][0]['to'])->toBe('published');
});

it('filters by who made the change and what it was made to', function (): void {
    $admin = auditor();
    $other = auditor();
    $subscriber = subscriber();

    $this->actingAs($admin)
        ->putJson(route('api.admin.subscribers.update', $subscriber), ['status' => 'active'])
        ->assertOk();
    $this->actingAs($other)
        ->putJson(route('api.admin.subscribers.update', subscriber()), ['status' => 'active'])
        ->assertOk();

    $byAdmin = $this->actingAs($admin)
        ->getJson(route('api.admin.audit-logs.index').'?causer='.$admin->public_id)
        ->assertOk()
        ->json('data');

    expect($byAdmin)->toHaveCount(1)
        ->and($byAdmin[0]['subject']['publicId'])->toBe($subscriber->public_id);

    $onSubscriber = $this->actingAs($admin)
        ->getJson(route('api.admin.audit-logs.index').'?subject='.$subscriber->public_id)
        ->assertOk()
        ->json('data');

    expect($onSubscriber)->toHaveCount(1);

    $byType = $this->actingAs($admin)
        ->getJson(route('api.admin.audit-logs.index').'?subjectType=User')
        ->assertOk()
        ->json('data');

    expect($byType)->toHaveCount(2);
});

it('keeps the pagination keys it has always returned', function (): void {
    $admin = auditor();

    $this->actingAs($admin)
        ->putJson(route('api.admin.subscribers.update', subscriber()), ['status' => 'active'])
        ->assertOk();

    $this->actingAs($admin)
        ->getJson(route('api.admin.audit-logs.index'))
        ->assertOk()
        ->assertJsonPath('meta.currentPage', 1)
        ->assertJsonPath('meta.lastPage', 1)
        ->assertJsonStructure(['data' => [['id', 'event', 'summary', 'subject', 'causer', 'changes', 'properties', 'createdAt']]]);
});

it('refuses a caller without the audit log permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('access admin portal');

    $this->actingAs($user)->getJson(route('api.admin.audit-logs.index'))->assertForbidden();
});
