<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * `brand:check` exists because the logo can be perfectly correct everywhere we
 * can see it and still be broken in the one place that counts — an anonymous
 * fetch from the public internet, made by someone else's mail client.
 */
it('names the development default as the problem when APP_URL is localhost', function (): void {
    Http::fake(['*' => Http::response('', 404)]);
    config(['app.url' => 'http://localhost:8000']);

    $this->artisan('brand:check')
        ->expectsOutputToContain('points at this machine')
        ->expectsOutputToContain('APP_URL')
        ->assertFailed();
});

it('passes when the logo answers as an image', function (): void {
    Http::fake(['*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png'])]);
    config(['app.url' => 'https://api.example.com']);

    $this->artisan('brand:check')
        ->expectsOutputToContain('reachable')
        ->assertSuccessful();

    // Fetched the URL the email actually carries, with no credentials — the
    // way a mail client would.
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.example.com/images/spectrum-logo.png');
});

it('explains a 404 as a deployment problem rather than a code one', function (): void {
    Http::fake(['*' => Http::response('', 404)]);
    config(['app.url' => 'https://api.example.com']);

    $this->artisan('brand:check')
        ->expectsOutputToContain('document root')
        ->assertFailed();
});

it('catches a URL that answers with a page instead of an image', function (): void {
    // A login redirect, an error page or a bot-protection challenge all look
    // like this, and all render as a broken image in the inbox.
    Http::fake(['*' => Http::response('<html>One moment, please…</html>', 200, ['Content-Type' => 'text/html'])]);
    config(['app.url' => 'https://api.example.com']);

    $this->artisan('brand:check')
        ->expectsOutputToContain('not an image')
        ->assertFailed();
});

it('reports a missing file before anything else', function (): void {
    config(['mail.brand.logo_path' => 'images/not-there.png']);

    $this->artisan('brand:check')
        ->expectsOutputToContain('not on disk')
        ->assertFailed();

    // Nothing was fetched: there is no URL to check if there is no file.
    Http::assertNothingSent();
});

it('reports a connection failure as nothing answering', function (): void {
    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));
    config(['app.url' => 'https://api.example.com']);

    $this->artisan('brand:check')
        ->expectsOutputToContain('request failed')
        ->assertFailed();
});
