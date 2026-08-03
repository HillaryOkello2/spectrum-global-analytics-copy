<?php

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\Public;
use App\Http\Controllers\Api\V1\Subscriber;
use App\Http\Controllers\Api\V1\Webhooks;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->as('api.')->group(function (): void {

    // Public — no auth, throttled (FR-01..09, FR-30)
    Route::middleware('throttle:60,1')->group(function (): void {
        Route::apiResource('catalog/components', Public\Catalog\ComponentController::class)
            ->only('index')->names('catalog.components');
        Route::apiResource('catalog/components.products', Public\Catalog\ComponentProductController::class)
            ->only('index')->names('catalog.components.products');
        Route::apiResource('tiers', Public\TierController::class)->only('index');
        Route::get('products/{product}/preview', Public\ProductPreviewController::class)->name('products.preview');
    });

    Route::middleware('throttle:10,1')->group(function (): void {
        Route::post('auth/register', [Public\Auth\RegisteredUserController::class, 'store'])->name('auth.register');
        Route::post('auth/login', [Public\Auth\AuthenticatedSessionController::class, 'store'])->name('auth.login');
        Route::post('auth/forgot-password', [Public\Auth\PasswordResetLinkController::class, 'store'])->name('auth.forgot-password');
        Route::post('auth/reset-password', [Public\Auth\NewPasswordController::class, 'store'])->name('auth.reset-password');
    });

    // Gateway webhooks — signature verified inside the gateway driver (§13.2)
    Route::post('webhooks/payments/{gateway}', Webhooks\PaymentCallbackController::class)
        ->name('webhooks.payments');

    // Authenticated (both portals)
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('auth/me', [Public\Auth\AuthenticatedSessionController::class, 'show'])->name('auth.me');
        Route::post('auth/logout', [Public\Auth\AuthenticatedSessionController::class, 'destroy'])->name('auth.logout');

        // Subscriber portal (FR-31..37)
        Route::middleware('role:subscriber')->group(function (): void {
            Route::get('dashboard', Subscriber\DashboardController::class)->name('dashboard');
            Route::apiSingleton('me', Subscriber\ProfileController::class);
            Route::put('me/password', [Subscriber\PasswordController::class, 'update'])->name('me.password');
            Route::get('me/subscription', [Subscriber\SubscriptionController::class, 'show'])->name('me.subscription');
            Route::post('me/subscription/renew', Subscriber\RenewSubscriptionController::class)->name('me.subscription.renew');
            Route::post('me/subscription/upgrade', Subscriber\UpgradeSubscriptionController::class)->name('me.subscription.upgrade');
            Route::apiResource('me/invoices', Subscriber\InvoiceController::class)
                ->only(['index', 'show'])->names('me.invoices');
            Route::apiResource('products', Subscriber\ProductController::class)->only('show');
            Route::get('payments/{payment}/status', Subscriber\PaymentStatusController::class)->name('payments.status');
        });

        // Admin portal (FR-38..46). Entry is permission-based, not role-based, so
        // custom staff roles work: each section then requires its own permission.
        Route::prefix('admin')->as('admin.')->middleware('permission:access admin portal')->group(function (): void {

            Route::middleware('permission:manage users')->group(function (): void {
                Route::apiResource('users', Admin\UserController::class)->only(['index', 'store', 'show', 'update']);
                Route::apiSingleton('users.roles', Admin\Users\UserRoleController::class)->only(['show', 'update']);
                Route::apiSingleton('users.permissions', Admin\Users\UserPermissionController::class)->only(['show', 'update']);

                Route::apiResource('roles', Admin\RoleController::class);
                Route::apiSingleton('roles.permissions', Admin\Roles\RolePermissionController::class)->only(['show', 'update']);
                Route::apiResource('permissions', Admin\PermissionController::class)->only('index');
            });

            Route::middleware('permission:manage subscribers')->group(function (): void {
                Route::apiResource('subscribers', Admin\SubscriberController::class)
                    ->only(['index', 'show', 'update'])
                    ->parameters(['subscribers' => 'subscriber']);
            });

            Route::apiResource('audit-logs', Admin\AuditLogController::class)
                ->only('index')->middleware('permission:view audit logs');

            Route::middleware('permission:view analytics')->group(function (): void {
                Route::get('analytics/summary', Admin\Analytics\AnalyticsSummaryController::class)->name('analytics.summary');
                Route::get('analytics/subscriptions-by-tier', Admin\Analytics\SubscriptionsByTierController::class)->name('analytics.subscriptions-by-tier');
                Route::get('analytics/products-by-component', Admin\Analytics\ProductsByComponentController::class)->name('analytics.products-by-component');
            });

            Route::middleware('permission:manage vault')->group(function (): void {
                Route::apiResource('vault/components', Admin\Vault\VaultComponentController::class)
                    ->only('index')->names('vault.components');
                Route::apiResource('vault/components.products', Admin\Vault\VaultComponentProductController::class)
                    ->only('index')->names('vault.components.products');
                Route::post('products/{product}/hide', Admin\Products\HideProductController::class)->name('products.hide');
                Route::post('products/{product}/unhide', Admin\Products\UnhideProductController::class)->name('products.unhide');
            });

            Route::middleware('permission:proofread products')->group(function (): void {
                Route::apiResource('tasks', Admin\GenerationTaskController::class)->only(['index', 'show']);
                Route::post('tasks/{task}/open', Admin\Tasks\OpenTaskController::class)->name('tasks.open');
                Route::post('tasks/{task}/proofread', Admin\Tasks\SubmitProofreadController::class)->name('tasks.proofread');
                Route::post('tasks/{task}/redact', Admin\Tasks\SubmitRedactionController::class)->name('tasks.redact');
                Route::post('tasks/{task}/approve', Admin\Tasks\ApproveTaskController::class)->name('tasks.approve');
                Route::post('tasks/{task}/reject', Admin\Tasks\RejectTaskController::class)->name('tasks.reject');
                Route::get('generation-queue', [Admin\GenerationTaskController::class, 'index'])->name('generation-queue');
            });

            Route::middleware('permission:manage topics')->group(function (): void {
                Route::apiResource('topics', Admin\TopicController::class)->only(['index', 'store']);
                Route::post('topics/{topic}/queue', Admin\Topics\QueueTopicGenerationController::class)->name('topics.queue');
                Route::apiResource('llm-providers', Admin\LlmProviderController::class)->only('index');
            });
        });
    });
});
