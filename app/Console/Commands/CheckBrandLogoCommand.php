<?php

namespace App\Console\Commands;

use App\Services\Access\BrandAssets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Why the logo is not showing in a delivered email.
 *
 * A mail client fetches that image itself, from the public internet, minutes
 * or days after the mail was sent. So it does not matter that the file is on
 * the server or that the markup is right — what matters is whether the exact
 * URL in the message answers to an anonymous request. This checks the whole
 * chain in the order it breaks.
 *
 * Read-only apart from one outbound HTTP request to our own logo.
 */
class CheckBrandLogoCommand extends Command
{
    protected $signature = 'brand:check {--url= : Check this URL instead of the configured one}';

    protected $description = 'Check that the logo an email points at is actually reachable';

    public function handle(BrandAssets $brand): int
    {
        $problems = 0;

        // 1. On disk, where this application looks for it.
        $path = $brand->logoPath();

        if ($path === null) {
            $this->components->error('The logo file is not on disk.');
            $this->line('  Looked for: '.public_path((string) config('mail.brand.logo_path')));
            $this->line('  Emails are falling back to the text wordmark. Deploy the file, or set');
            $this->line('  MAIL_BRAND_LOGO_PATH / MAIL_BRAND_LOGO_URL to where it really is.');

            return self::FAILURE;
        }

        $this->components->info('Logo found on disk');
        $this->line('  '.$path.'  ('.number_format((int) filesize($path)).' bytes, '
            .$brand->logoWidth().'x'.$brand->logoHeight().' as rendered)');
        $this->newLine();

        // 2. The URL the message actually carries.
        $url = (string) ($this->option('url') ?: $brand->logoUrl());

        $this->components->info('URL written into every email');
        $this->line('  '.$url);

        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (in_array($host, ['localhost', '127.0.0.1', '::1', 'host.docker.internal'], true)) {
            $this->newLine();
            $this->components->error('That URL points at this machine.');
            $this->line('  A mail client fetches the image from wherever the reader is, so `localhost`');
            $this->line('  resolves to THEIR machine and the image is always broken.');
            $this->line('  Cause: APP_URL is still the development default.');
            $this->line('  Fix:   set APP_URL to the public host in .env, then `php artisan config:clear`');
            $this->line('         (or set MAIL_BRAND_LOGO_URL to an absolute public URL).');
            $problems++;
        } elseif ($scheme !== 'https') {
            $this->newLine();
            $this->components->warn('That URL is not https.');
            $this->line('  Some clients refuse to load insecure images inside a message. Prefer https.');
            $problems++;
        }

        $this->newLine();

        // 3. Does it actually answer, anonymously, from outside?
        $problems += $this->fetch($url);

        if ($problems === 0) {
            $this->components->info('The logo is reachable. A client that loads images will show it.');
            $this->line('  Note that many clients block remote images by default; the alt text');
            $this->line('  "'.config('app.name').'" stands in when they do.');
        }

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Fetch it the way a mail client would: no cookies, no session, no auth.
     */
    private function fetch(string $url): int
    {
        $this->components->info('Fetching it as a mail client would');

        try {
            $response = Http::withoutVerifying()->timeout(15)->get($url);
        } catch (Throwable $e) {
            $this->components->error('The request failed: '.$e->getMessage());
            $this->line('  Nothing answered at that URL. Check DNS, the host and the web server.');

            return 1;
        }

        $type = $response->header('Content-Type');

        if ($response->successful() && str_starts_with((string) $type, 'image/')) {
            $this->line("  <info>{$response->status()}</info>  {$type}, "
                .number_format(strlen($response->body())).' bytes');
            $this->newLine();

            return 0;
        }

        $this->line("  <fg=red>{$response->status()}</>  ".($type ?: 'no content type'));
        $this->newLine();

        if ($response->status() === 404) {
            $this->components->error('404 — the web server does not serve that path.');
            $this->line('  Most likely the file was not deployed into the document root. On a host');
            $this->line('  whose docroot is not this app\'s public/ directory, copying the repo is not');
            $this->line('  enough — the file has to land under the directory the web server serves.');
            $this->line('  It can also be the API-only lockdown: `images/*` must be in');
            $this->line('  API_ONLY_ALLOWED_PATHS, and it is in the default list.');

            return 1;
        }

        if (! str_starts_with((string) $type, 'image/')) {
            $this->components->error('That URL answered with something that is not an image.');
            $this->line('  A redirect to a login page, an error page or a bot-protection challenge');
            $this->line('  all look like this. A mail client will show a broken image either way.');

            return 1;
        }

        $this->components->error('The logo did not load.');

        return 1;
    }
}
