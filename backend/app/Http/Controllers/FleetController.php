<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStatus;
use App\Http\Resources\FleetMemberResource;
use App\Models\ShiftAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Snapshot of everyone on shift with their latest position. The live map
 * loads this first, then applies private-fleet broadcasts on top (or polls
 * it while no WebSocket server is running).
 */
class FleetController extends Controller
{
    public function live(Request $request): AnonymousResourceCollection
    {
        $request->validate(['departmentId' => ['nullable', 'uuid']]);

        $members = ShiftAssignment::with(['user.department', 'user.position', 'shift'])
            ->where('status', AssignmentStatus::Active)
            ->when($request->input('departmentId'), fn ($q, $id) => $q->whereHas('user', fn ($u) => $u->where('department_id', $id)))
            ->get();

        $resource = FleetMemberResource::collection($members);
        $rows = collect($resource->toArray($request));

        return $resource->additional([
            'meta' => [
                'generatedAt' => now()->toIso8601ZuluString(),
                'onShift' => $rows->count(),
                'live' => $rows->where('freshness', 'LIVE')->count(),
                'lagging' => $rows->where('freshness', 'LAGGING')->count(),
                'offline' => $rows->where('freshness', 'OFFLINE')->count(),
            ],
        ]);
    }
}
