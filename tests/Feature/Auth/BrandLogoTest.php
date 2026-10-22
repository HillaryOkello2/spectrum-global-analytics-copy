<?php

use App\Http\Middleware\RestrictToApi;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Notifications\AccountCreated;
use App\Notifications\SubscriptionEndingSoon;
use App\Notifications\WelcomeSubscriber;
use App\Services\Access\BrandAssets;
use App\Services\Billing\InvoicePdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The logo, in the two places it has to survive: an inbox that may refuse to
 * load images, and a PDF renderer that refuses to fetch them.
 */
function brandHtml(object $notification, User $user): string
{
    return (string) $notification->toMail($user)->render();
}

it('ships the logo where the config points', function (): void {
    // Everything below is worthless if the file is not actually deployed.
    expect(app(BrandAssets::class)->hasLogo())->toBeTrue()
        ->and(public_path(config('mail.brand.logo_path')))->toBeFile();
});

it('puts the logo in the email masthead, with the brand name as its alt text', function (): void {
    $html = brandHtml(new AccountCreated('Str0ngPassword!'), User::factory()->create());

    expect($html)
        ->toContain(app(BrandAssets::class)->logoUrl())
        // Plenty of clients block remote images until asked. Blocked, the
        // masthead must still read as the brand rather than as an empty box —
        // which is what alt="logo" would give.
        ->toContain('alt="'.config('app.name').'"');
});

it('carries the logo on the welcome, payment and subscription emails alike', function (): void {
    $user = User::factory()->create(['first_name' => 'Amina']);
    $tier = SubscriptionTier::factory()->free()->create(['name' => 'Freemium']);
    $subscription = Subscription::factory()->for($user)->create(['tier_id' => $tier->id]);

    $logo = app(BrandAssets::class)->logoUrl();

    // One theme serves every notification, so this is really asserting that
    // none of them opts out of it.
    expect(brandHtml(new WelcomeSubscriber($subscription), $user))->toContain($logo)
        ->and(brandHtml(new SubscriptionEndingSoon($subscription, 7), $user))->toContain($logo);
});

it('falls back to the wordmark when the logo is not deployed', function (): void {
    config(['mail.brand.logo_path' => 'images/not-there.png']);

    $html = brandHtml(new AccountCreated('Str0ngPassword!'), User::factory()->create());

    // A missing file must give the text masthead, not a broken image.
    expect(app(BrandAssets::class)->logoUrl())->toBeNull()
        ->and($html)->not->toContain('<img')
        ->and($html)->toContain(config('app.name'))
        ->and($html)->toContain(config('mail.brand.tagline'));
});

it('lets a deployment point the email at another host for the logo', function (): void {
    config(['mail.brand.logo_url' => 'https://cdn.example.com/sga.png']);

    expect(app(BrandAssets::class)->logoUrl())->toBe('https://cdn.example.com/sga.png')
        ->and(brandHtml(new AccountCreated('x'), User::factory()->create()))
        ->toContain('https://cdn.example.com/sga.png');
});

it('keeps the logo reachable when the API-only lockdown is on', function (): void {
    // The mail client fetches it anonymously, long after the mail was sent. A
    // lockdown that 404s it turns every masthead into a broken image.
    //
    // Asserted against the middleware rather than with $this->get(): the web
    // server serves files out of public/ before PHP is reached, so a request
    // for the logo never touches the router in a test and a 404 here would
    // prove nothing either way. What the application actually controls is
    // whether RestrictToApi lets the path through.
    config(['security.api_only' => true]);

    $blocked = app(RestrictToApi::class)->handle(
        Request::create('/'.config('mail.brand.logo_path')),
        fn () => new Response('served', 200),
    );

    expect(config('security.allowed_paths'))->toContain('images/*')
        ->and($blocked->getStatusCode())->toBe(200)
        ->and($blocked->getContent())->toBe('served');
});

it('still blocks everything else while allowing the logo', function (): void {
    config(['security.api_only' => true]);

    $middleware = app(RestrictToApi::class);
    $passthrough = fn () => new Response('served', 200);

    expect($middleware->handle(Request::create('/horizon'), $passthrough)->getStatusCode())->toBe(404)
        ->and($middleware->handle(Request::create('/storage/secret.txt'), $passthrough)->getStatusCode())->toBe(404);
});

// ------------------------------------------------------------------ PDF

it('embeds the logo in the invoice PDF rather than linking it', function (): void {
    // InvoicePdf disables Dompdf's remote fetching on purpose, so a URL would
    // render as a blank box. The bytes have to be inline.
    $dataUri = app(BrandAssets::class)->logoDataUri();

    expect($dataUri)->toStartWith('data:image/png;base64,');

    $html = view('invoices.pdf', ['invoice' => invoiceForPdf()])->render();

    expect($html)->toContain($dataUri)
        ->and($html)->not->toContain(config('app.url').'/images/');
});

it('still renders an invoice PDF when the logo is missing', function (): void {
    config(['mail.brand.logo_path' => 'images/not-there.png']);

    $pdf = app(InvoicePdf::class)->render(invoiceForPdf());

    // An absent logo must not cost the subscriber their invoice.
    expect($pdf)->toStartWith('%PDF')
        ->and(strlen($pdf))->toBeGreaterThan(1000);
});

it('produces a readable PDF with the logo in it', function (): void {
    $pdf = app(InvoicePdf::class)->render(invoiceForPdf());

    expect($pdf)->toStartWith('%PDF')
        // The embedded image makes it materially bigger than the text-only one.
        ->and(strlen($pdf))->toBeGreaterThan(20000);
});

function invoiceForPdf(): Invoice
{
    $user = User::factory()->create(['first_name' => 'Amina', 'last_name' => 'Otieno']);
    $tier = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99]);
    $subscription = Subscription::factory()->for($user)->create(['tier_id' => $tier->id]);

    $payment = Payment::factory()->for($user)->create([
        'subscription_id' => $subscription->id,
        'payable_type' => Subscription::class,
        'payable_id' => $subscription->id,
        'amount' => '6499.00',
        'currency' => 'KES',
    ]);

    $invoice = Invoice::create([
        'payment_id' => $payment->id,
        'subscription_id' => $subscription->id,
        'number' => 'SGA-INV-000123',
        'amount' => '6499.00',
        'currency' => 'KES',
        'issue_date' => today(),
        'line_items' => [['description' => 'Premium subscription (monthly)', 'amount' => '6499.00']],
    ]);

    return $invoice->load('payment.user', 'subscription.tier');
}
