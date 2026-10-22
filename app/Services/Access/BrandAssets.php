<?php

namespace App\Services\Access;

/**
 * The logo, served the way each medium can actually use it.
 *
 * Two media, two answers. Email cannot carry a file path, so it gets a URL and
 * the recipient's client decides whether to fetch it — which is why everything
 * built on this keeps a text wordmark as the fallback rather than relying on
 * the image arriving. The invoice PDF is the opposite: InvoicePdf turns remote
 * fetching off on purpose, so a URL there would render as nothing, and it gets
 * the bytes inline instead.
 *
 * Both degrade to no logo at all rather than to a broken one: a missing file
 * means the text masthead, which is what the mail looked like before the logo
 * existed.
 */
class BrandAssets
{
    /**
     * Where the file sits on disk. Configurable so a deployment can point at a
     * client-supplied replacement without a code change.
     */
    public function logoPath(): ?string
    {
        $path = config('mail.brand.logo_path');

        if (blank($path)) {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : public_path($path);

        return is_file($path) && is_readable($path) ? $path : null;
    }

    public function hasLogo(): bool
    {
        return $this->logoPath() !== null;
    }

    /**
     * The absolute URL an email points at.
     *
     * Null when the file is not there, so the templates fall back to the text
     * wordmark rather than showing a broken image — a broken image in a
     * subscriber's inbox reads worse than no image at all.
     */
    public function logoUrl(): ?string
    {
        if (! $this->hasLogo()) {
            return null;
        }

        if (filled($explicit = config('mail.brand.logo_url'))) {
            return $explicit;
        }

        return rtrim((string) config('app.url'), '/').'/'
            .ltrim((string) config('mail.brand.logo_path'), '/');
    }

    /**
     * The width the email masthead renders the logo at.
     */
    public function logoWidth(): int
    {
        return max(1, (int) config('mail.brand.logo_width', 210));
    }

    /**
     * The matching height, worked out from the file's own proportions.
     *
     * Declared in the markup alongside the width because a mail client that
     * will not load the image reserves space from those two attributes. With
     * only a width it reserves a large empty square and drops the alt text
     * somewhere inside it; with both, it reserves exactly the strip the logo
     * occupies and the alt text reads as the wordmark.
     *
     * Falls back to the aspect ratio of the logo as supplied if the file
     * cannot be measured.
     */
    public function logoHeight(): int
    {
        $path = $this->logoPath();
        $size = $path === null ? false : @getimagesize($path);

        $ratio = $size && ! empty($size[0])
            ? $size[1] / $size[0]
            : 235 / 918;

        return max(1, (int) round($this->logoWidth() * $ratio));
    }

    /**
     * The logo as a `data:` URI, for the invoice PDF.
     *
     * Dompdf is configured with remote fetching disabled (see InvoicePdf, which
     * explains why), so a URL would render as a blank box. A data URI is read
     * from local disk and needs no network, which keeps that property intact.
     */
    public function logoDataUri(): ?string
    {
        $path = $this->logoPath();

        if ($path === null) {
            return null;
        }

        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            return null;
        }

        return 'data:'.$this->mimeOf($path).';base64,'.base64_encode($bytes);
    }

    private function mimeOf(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
    }
}
