<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * @group Admin Portal
 *
 * Audit log listing (FR-40, §20 Auditability).
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = Activity::query()
            ->with('causer')
            ->when($request->query('description'), fn ($q, $desc) => $q->where('description', 'like', "%{$desc}%"))
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => $logs->getCollection()->map(fn (Activity $log) => [
                'id' => $log->id,
                'description' => $log->description,
                'subjectType' => class_basename($log->subject_type ?? ''),
                'causer' => $log->causer?->full_name,
                'properties' => $log->properties,
                'createdAt' => $log->created_at->format('Y-m-d H:i:s'),
            ]),
            'meta' => [
                'currentPage' => $logs->currentPage(),
                'lastPage' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
