<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Services\Access\RoleService;
use Spatie\Permission\Models\Role;

function transactionViewer(): User
{
    $user = User::factory()->create();
    $user->assignRole(User::ADMIN);

    return $user;
}

it('lists transactions newest first for a permitted admin', function (): void {
    Payment::factory()->successful()->create(['created_at' => now()->subDay()]);
    $newest = Payment::factory()->create(['created_at' => now()]);

    $this->actingAs(transactionViewer())
        ->getJson(route('api.admin.transactions.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.publicId', $newest->public_id);
});

it('includes pending and failed payments, not just settled ones', function (): void {
    Payment::factory()->successful()->create();
    Payment::factory()->failed()->create();
    Payment::factory()->create(); // pending

    $this->actingAs(transactionViewer())
        ->getJson(route('api.admin.transactions.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('returns the payer and gateway reference', function (): void {
    $payment = Payment::factory()->successful()->create(['gateway_ref' => 'FAKE-ABCD1234']);

    $this->actingAs(transactionViewer())
        ->getJson(route('api.admin.transactions.index'))
        ->assertOk()
        ->assertJsonPath('data.0.gatewayRef', 'FAKE-ABCD1234')
        ->assertJsonPath('data.0.payer.email', $payment->user->email)
        ->assertJsonPath('data.0.what', 'Subscription');
});

it('never exposes the raw gateway callback', function (): void {
    // It is the verbatim provider payload and can carry payer PII.
    Payment::factory()->successful()->create([
        'raw_callback' => ['secret' => 'provider-internal-value'],
    ]);

    $response = $this->actingAs(transactionViewer())
        ->getJson(route('api.admin.transactions.index'))
        ->assertOk();

    expect($response->getContent())->not->toContain('provider-internal-value');
    $response->assertJsonMissingPath('data.0.rawCallback')
        ->assertJsonMissingPath('data.0.raw_callback');
});

it('shows one transaction by public id', function (): void {
    $payment = Payment::factory()->successful()->create();

    $this->actingAs(transactionViewer())
        ->getJson(route('api.admin.transactions.show', $payment))
        ->assertOk()
        ->assertJsonPath('data.publicId', $payment->public_id);
});

it('filters by status', function (): void {
    Payment::factory()->successful()->create();
    Payment::factory()->failed()->create();

    $this->actingAs(transactionViewer())
        ->getJson(route('api.admin.transactions.index', ['status' => PaymentStatus::Failed->value]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'failed');
});

it('filters by method and gateway', function (): void {
    Payment::factory()->create(['method' => PaymentMethod::Card, 'gateway' => 'pgw']);
    Payment::factory()->create(['method' => PaymentMethod::Mpesa, 'gateway' => 'fake']);

    $admin = transactionViewer();

    $this->actingAs($admin)
        ->getJson(route('api.admin.transactions.index', ['method' => 'card']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.method', 'card');

    $this->actingAs($admin)
        ->getJson(route('api.admin.transactions.index', ['gateway' => 'pgw']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.gateway', 'pgw');
});

it('searches by gateway reference', function (): void {
    Payment::factory()->create(['gateway_ref' => 'FAKE-NEEDLE01']);
    Payment::factory()->create(['gateway_ref' => 'FAKE-OTHER99']);

    $this->actingAs(transactionViewer())
        ->getJson(route('api.admin.transactions.index', ['search' => 'NEEDLE']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.gatewayRef', 'FAKE-NEEDLE01');
});

it('searches by payer email', function (): void {
    $payer = User::factory()->create(['email' => 'traceable@example.com']);
    Payment::factory()->create(['user_id' => $payer->id]);
    Payment::factory()->create();

    $this->actingAs(transactionViewer())
        ->getJson(route('api.admin.transactions.index', ['search' => 'traceable@']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.payer.email', 'traceable@example.com');
});

it('filters to one subscriber', function (): void {
    $payer = User::factory()->create();
    Payment::factory()->count(2)->create(['user_id' => $payer->id]);
    Payment::factory()->create();

    $this->actingAs(transactionViewer())
        ->getJson(route('api.admin.transactions.index', ['subscriber' => $payer->public_id]))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters by date range on created_at so unsettled payments still appear', function (): void {
    // A pending payment has no paid_at — bounding on that would hide exactly the
    // rows a date-limited audit is looking for.
    Payment::factory()->create(['created_at' => now()->subDays(10)]);
    Payment::factory()->create(['created_at' => now()->subDay()]);

    $this->actingAs(transactionViewer())
        ->getJson(route('api.admin.transactions.index', ['from' => now()->subDays(3)->toDateString()]))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('forbids staff without the permission', function (): void {
    $role = Role::create(['name' => 'Proofreader', 'guard_name' => RoleService::GUARD]);
    $role->syncPermissions(['access admin portal', 'proofread products']);

    $staff = User::factory()->create();
    $staff->assignRole($role);

    $this->actingAs($staff)
        ->getJson(route('api.admin.transactions.index'))
        ->assertForbidden();
});

it('forbids subscribers', function (): void {
    $subscriber = User::factory()->create();
    $subscriber->assignRole(User::SUBSCRIBER);

    $this->actingAs($subscriber)
        ->getJson(route('api.admin.transactions.index'))
        ->assertForbidden();
});

it('rejects unauthenticated access', function (): void {
    $this->getJson(route('api.admin.transactions.index'))->assertUnauthorized();
});

it('lets a System Admin through without holding the permission row', function (): void {
    $systemAdmin = User::factory()->create();
    $systemAdmin->assignRole(User::SYSTEM_ADMIN);

    $this->actingAs($systemAdmin)
        ->getJson(route('api.admin.transactions.index'))
        ->assertOk();
});

it('seeds the permission and grants it to admin', function (): void {
    expect(Role::findByName(User::ADMIN, RoleService::GUARD)->hasPermissionTo('view transaction history'))
        ->toBeTrue();
});
