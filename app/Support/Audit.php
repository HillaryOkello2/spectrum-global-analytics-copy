<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Builds audit-log entries that say what changed, not just that something did.
 *
 * The log used to record `subscriber updated` and nothing else, which answers
 * neither of the two questions anyone opens an audit log to ask: what was
 * changed, and what was it before. Every entry written through this class
 * carries a field-level diff, the subject in a form that survives the row
 * being deleted, and where the change came from.
 *
 * Usage mirrors the Spatie helper it wraps:
 *
 *   Audit::of($subscriber)->by($actor)->changes($before, $subscriber)
 *       ->log('subscriber updated');
 */
class Audit
{
    /**
     * Never written to the log, whatever a caller passes. Credentials and
     * gateway payloads have no business in a table admins can read, and an
     * audit trail is exactly the place a secret gets copied to and forgotten.
     *
     * @var array<int, string>
     */
    private const REDACTED = [
        'password', 'password_confirmation', 'remember_token', 'api_key', 'secret',
        'token', 'access_token', 'raw_callback', 'two_factor_secret',
    ];

    private ?Model $subject = null;

    private ?Model $causer = null;

    /** @var array<int, array{field: string, label: string, from: mixed, to: mixed}> */
    private array $changes = [];

    /** @var array<string, mixed> */
    private array $extra = [];

    public static function of(?Model $subject): self
    {
        $audit = new self;
        $audit->subject = $subject;

        return $audit;
    }

    public function by(?Model $causer): self
    {
        $this->causer = $causer;

        return $this;
    }

    /**
     * Field-level diff between two snapshots.
     *
     * Only keys present in $after are compared, so a controller that passes
     * the validated input logs the fields that were actually submitted rather
     * than every column on the model.
     *
     * @param  array<string, mixed>|Model  $before
     * @param  array<string, mixed>|Model  $after
     */
    public function changes(array|Model $before, array|Model $after): self
    {
        $before = $this->attributes($before);
        $after = $this->attributes($after);

        foreach ($after as $field => $to) {
            $from = $before[$field] ?? null;

            if ($this->same($from, $to)) {
                continue;
            }

            $this->change($field, $from, $to);
        }

        return $this;
    }

    /**
     * One change recorded explicitly — for things that are not table columns:
     * a role's permission list, a tier's access matrix, a status the caller
     * computed.
     */
    public function change(string $field, mixed $from, mixed $to, ?string $label = null): self
    {
        $this->changes[] = [
            'field' => Str::camel($field),
            'label' => $label ?? Str::headline($field),
            'from' => $this->present($field, $from),
            'to' => $this->present($field, $to),
        ];

        return $this;
    }

    /**
     * Context that is not a change: a rejection note, a reason, a gateway
     * reference. Kept alongside the diff rather than mixed into it.
     *
     * @param  array<string, mixed>  $extra
     */
    public function with(array $extra): self
    {
        $this->extra = [...$this->extra, ...$extra];

        return $this;
    }

    /**
     * Write the entry. Returns silently when there is nothing to say and the
     * caller only wanted to log a diff — an "updated" entry listing no changes
     * is the vagueness this class exists to remove.
     */
    public function log(string $description, bool $evenIfUnchanged = true): void
    {
        if ($this->changes === [] && $this->extra === [] && ! $evenIfUnchanged) {
            return;
        }

        $activity = activity();

        if ($this->causer !== null) {
            $activity->causedBy($this->causer);
        }

        if ($this->subject !== null) {
            $activity->performedOn($this->subject);
        }

        $activity->withProperties($this->properties())->log($description);
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(): array
    {
        return array_filter([
            'changes' => $this->changes,
            // The subject's own identity, captured now: a log row that points
            // at a deleted role or user otherwise says nothing about which one.
            'subject' => $this->subject === null ? null : array_filter([
                'label' => $this->label($this->subject),
                'publicId' => $this->subject->getAttribute('public_id'),
            ], fn ($value) => $value !== null),
            'context' => $this->context(),
            ...$this->extra,
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * Where the change came from. A scheduled release and an admin clicking
     * the same button are not the same event, and the log should not read as
     * though they were.
     *
     * Keyed on whether a route was matched rather than on
     * `runningInConsole()`, which is true under the test runner and would file
     * every tested request as a console change.
     */
    private function context(): array
    {
        $request = request();

        if ($request?->route() === null) {
            // A scheduler run, a queue worker, or an artisan command.
            return ['via' => 'system'];
        }

        return array_filter([
            'via' => 'api',
            'ip' => $request->ip(),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>|Model  $source
     * @return array<string, mixed>
     */
    private function attributes(array|Model $source): array
    {
        return $source instanceof Model ? $source->getAttributes() : $source;
    }

    /**
     * Loose on purpose: a form submits "1" where the column holds 1, and a
     * cast date compares against a string. Logging those as changes would
     * bury the real ones.
     */
    private function same(mixed $from, mixed $to): bool
    {
        if (is_array($from) && is_array($to)) {
            sort($from);
            sort($to);

            return $from === $to;
        }

        if ($from === null || $to === null) {
            return $from === $to;
        }

        if (is_scalar($from) && is_scalar($to)) {
            return (string) $from === (string) $to;
        }

        return $from == $to;
    }

    /**
     * Values as they should read on a dashboard row — and never a credential.
     */
    private function present(string $field, mixed $value): mixed
    {
        if (in_array(Str::snake($field), self::REDACTED, true)) {
            return $value === null ? null : '[redacted]';
        }

        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof Model => $this->label($value),
            is_array($value) => array_values(Arr::map($value, fn ($item) => is_scalar($item) ? $item : json_encode($item))),
            is_string($value) => Str::limit($value, 500),
            default => $value,
        };
    }

    /**
     * The most human-readable name the subject has.
     */
    private function label(Model $model): ?string
    {
        foreach (['full_name', 'name', 'title', 'code', 'email'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return Str::limit($value, 120);
            }
        }

        return null;
    }
}
