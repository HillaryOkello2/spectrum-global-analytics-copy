<?php

namespace App\Services\Llm;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Turns a vendor failure into something safe to store and show an admin:
 * a short code, and a one-line message with no credentials in it.
 *
 * Vendors echo the offending key back often enough that masking has to happen
 * before the row is written, not when it is read — a leaked key in the database
 * is leaked whether or not the endpoint redacts it.
 */
class LlmErrorSanitizer
{
    /** The dashboard shows these on one line; §2.3 of the frontend contract. */
    public const MAX_LENGTH = 300;

    /**
     * Shapes that are credentials wherever they appear: OpenAI-style `sk-`
     * keys, Google `AIza` keys, Anthropic `sk-ant-`, bearer headers, and long
     * opaque tokens. Deliberately greedy — a masked message that was never
     * secret costs nothing; the reverse is a disclosed key.
     *
     * @var array<int, string>
     */
    private const SECRET_PATTERNS = [
        '/\b(sk|rk|pk)-[A-Za-z0-9_\-]{8,}/i',
        '/\bAIza[0-9A-Za-z_\-]{10,}/',
        '/\b(Bearer|Basic)\s+[A-Za-z0-9._\-\/+=]{8,}/i',
        '/\b(api[_\-]?key|x-api-key|authorization|access[_\-]?token|secret)\b\s*[:=]\s*["\']?[^\s"\',}]{6,}/i',
        '/\beyJ[A-Za-z0-9._\-]{20,}/',            // JWT
        '/\b[A-Za-z0-9_\-]{40,}\b/',              // anything else long and opaque
    ];

    /**
     * Classify an HTTP failure. The status is kept verbatim as a string so the
     * dashboard can group by it; the frontend contract asks for exactly that.
     */
    public function codeForStatus(int $status): string
    {
        return (string) $status;
    }

    /**
     * Message for a failed HTTP response. Only the vendor's own error text is
     * taken — never the whole body, which on some vendors echoes the prompt
     * (and therefore client content) straight back.
     */
    public function fromResponse(Response $response): string
    {
        $json = rescue(fn () => $response->json(), null, false);

        $message = is_array($json)
            ? (data_get($json, 'error.message')
                ?? data_get($json, 'error.status')
                ?? data_get($json, 'message')
                ?? data_get($json, 'error')
                ?? data_get($json, 'detail'))
            : null;

        if (! is_string($message) || trim($message) === '') {
            $message = 'HTTP '.$response->status().' from the provider.';
        }

        return $this->clean($message);
    }

    /**
     * Message for an exception. Laravel's RequestException appends a slice of
     * the response body to its message, so this goes through the same masking
     * as a response body would.
     */
    public function fromThrowable(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (trim($message) === '') {
            $message = class_basename($e);
        }

        return $this->clean($message);
    }

    /**
     * Mask credentials, flatten to one line, truncate.
     *
     * Configured keys are replaced by exact match first: a key that does not
     * match any pattern above (a short or unusually shaped one) would otherwise
     * survive, and we know the exact strings to look for.
     */
    public function clean(string $message): string
    {
        foreach ($this->configuredKeys() as $key) {
            $message = str_replace($key, '[redacted]', $message);
        }

        foreach (self::SECRET_PATTERNS as $pattern) {
            $message = (string) preg_replace($pattern, '[redacted]', $message);
        }

        // Stack traces and multi-line vendor payloads: admins read this on a
        // dashboard row, so it has to be one line.
        $message = (string) preg_replace('/\s+/', ' ', $message);

        return Str::limit(trim($message), self::MAX_LENGTH - 3);
    }

    /**
     * Every API key this installation holds, longest first so a key that is a
     * prefix of another cannot leave a fragment behind.
     *
     * @return array<int, string>
     */
    private function configuredKeys(): array
    {
        $keys = collect((array) config('llm.drivers', []))
            ->pluck('api_key')
            ->filter(fn ($key) => is_string($key) && strlen($key) >= 8)
            ->unique()
            ->values()
            ->all();

        usort($keys, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $keys;
    }
}
