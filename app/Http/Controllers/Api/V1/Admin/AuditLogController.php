<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * @group Admin Portal
 *
 * Audit log listing (FR-40, §20 Auditability).
 *
 * Entries carry a field-level diff — what was changed, from what, to what, by
 * whom — written through App\Support\Audit. Filters match the questions the
 * log gets asked: what did this person do, what happened to this record, what
 * happened on this day.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $logs = Activity::query()
            ->with('causer')
            ->when($request->query('description'), fn (Builder $q, string $description) => $q->where(
                'description',
                'like',
                "%{$description}%",
            ))
            // Who did it. Accepts the actor's public id, so a caller that has
            // a user from /admin/users can filter without knowing its row id.
            ->when($request->query('causer'), fn (Builder $q, string $causer) => $q->whereHasMorph(
                'causer',
                '*',
                fn (Builder $query) => $query->where('public_id', $causer),
            ))
            // What it was done to: `Product`, `User`, `SubscriptionTier`…
            // matched on the class name, so callers never see namespaces.
            // Resolved to exact class names rather than a LIKE, which would
            // have to escape the backslashes and would match `AdminUser` for
            // `User`.
            ->when($request->query('subjectType'), fn (Builder $q, string $type) => $q->whereIn(
                'subject_type',
                self::classesNamed($type),
            ))
            // One record's whole history, by the public id captured on the
            // entry — which still resolves after the record itself is gone.
            ->when($request->query('subject'), fn (Builder $q, string $subject) => $q->where(
                'properties->subject->publicId',
                $subject,
            ))
            // Free text over the summary fields, for "what happened to Acme".
            ->when($request->query('search'), fn (Builder $q, string $search) => $q->where(
                fn (Builder $query) => $query
                    ->where('description', 'like', "%{$search}%")
                    ->orWhere('properties->subject->label', 'like', "%{$search}%"),
            ))
            ->when($request->query('from'), fn (Builder $q, string $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $q, string $to) => $q->whereDate('created_at', '<=', $to))
            ->latest()
            ->paginate((int) $request->integer('perPage', 50));

        return ActivityLogResource::collection($logs)
            // The camelCase keys this endpoint has always returned, kept
            // alongside the paginator's own house-standard meta so an existing
            // client is not broken by the richer rows.
            ->additional(['meta' => [
                'currentPage' => $logs->currentPage(),
                'lastPage' => $logs->lastPage(),
                'total' => $logs->total(),
            ]]);
    }

    /**
     * The fully qualified names a short subject type could mean. Subjects are
     * our own models and Spatie's role/permission models; nothing else is ever
     * logged against.
     *
     * @return array<int, string>
     */
    private static function classesNamed(string $type): array
    {
        $class = Str::studly(str_replace(' ', '', $type));

        return [
            "App\\Models\\{$class}",
            "Spatie\\Permission\\Models\\{$class}",
        ];
    }
}
