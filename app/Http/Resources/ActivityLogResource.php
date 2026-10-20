<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * One audit-log entry, in the form the question "who changed what" is actually
 * asked in.
 *
 * The entry used to be `{description, causer, properties}` — the properties
 * being whatever each call site happened to attach, usually nothing. Entries
 * written through App\Support\Audit carry a field-level diff, and this
 * resource turns it into something a dashboard can render directly: a list of
 * changes and a one-line summary. `properties` is still returned whole, so
 * older entries and anything a caller attached are never hidden.
 */
class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $changes = $this->changes();

        return [
            'id' => $this->id,
            'event' => $this->description,
            // "Jane Doe changed Status from pending to active on John Smith"
            'summary' => $this->summary($changes),
            'subject' => $this->subject(),
            'causer' => $this->causer(),
            'changes' => $changes,
            'context' => $this->property('context', []),
            // Everything the call site attached, diff included — nothing a
            // client was reading before has been taken away.
            'properties' => $this->properties,
            'createdAt' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function changes(): array
    {
        $changes = $this->property('changes', []);

        if (! is_array($changes)) {
            return [];
        }

        return array_values(array_filter($changes, 'is_array'));
    }

    /**
     * Who or what the change was made to. The label was captured when the
     * entry was written, so a deleted role still has a name here.
     *
     * @return array<string, mixed>|null
     */
    private function subject(): ?array
    {
        if ($this->subject_type === null) {
            return null;
        }

        return [
            'type' => Str::headline(class_basename($this->subject_type)),
            'id' => $this->subject_id,
            'publicId' => $this->property('subject.publicId'),
            'label' => $this->property('subject.label'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function causer(): ?array
    {
        if ($this->causer === null) {
            // Not an omission: the scheduler releases products and the gateway
            // confirms payments, and saying so is more honest than a blank.
            return ['name' => 'System', 'publicId' => null, 'email' => null];
        }

        return [
            'name' => $this->causer->full_name ?? $this->causer->name ?? null,
            'publicId' => $this->causer->public_id ?? null,
            'email' => $this->causer->email ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function summary(array $changes): string
    {
        $who = $this->causer?->full_name ?? 'System';
        $what = $this->property('subject.label');
        $on = $what === null ? '' : " on {$what}";

        if ($changes === []) {
            return trim("{$who}: {$this->description}{$on}");
        }

        $parts = array_map(
            fn (array $change) => sprintf(
                '%s %s → %s',
                $change['label'] ?? $change['field'] ?? 'value',
                $this->readable($change['from'] ?? null),
                $this->readable($change['to'] ?? null),
            ),
            array_slice($changes, 0, 5),
        );

        $more = count($changes) > 5 ? sprintf(' (+%d more)', count($changes) - 5) : '';

        return "{$who}: {$this->description}{$on} — ".implode(', ', $parts).$more;
    }

    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => $value === [] ? 'none' : Str::limit(implode(', ', array_map(
                fn ($item) => is_scalar($item) ? (string) $item : json_encode($item),
                $value,
            )), 120),
            default => Str::limit((string) $value, 120),
        };
    }

    private function property(string $key, mixed $default = null): mixed
    {
        return data_get($this->properties, $key, $default);
    }
}
