<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\Permission\Models\Permission;

/**
 * @group Admin Portal
 *
 * Every permission in the system — the vocabulary for direct grants on top of
 * a user's roles (FR-39).
 */
class PermissionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PermissionResource::collection(
            Permission::orderBy('name')->get(),
        );
    }
}
