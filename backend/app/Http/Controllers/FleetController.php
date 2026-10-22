<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStatus;
use App\Http\Resources\FleetMemberResource;
use App\Models\ShiftAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

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
            'realtime' => $this->realtime(),
            'meta' => [
                'generatedAt' => now()->toIso8601ZuluString(),
                'timezone' => config('smc.shifts.timezone'),
                'onShift' => $rows->count(),
                'live' => $rows->where('freshness', 'LIVE')->count(),
                'lagging' => $rows->where('freshness', 'LAGGING')->count(),
                'offline' => $rows->where('freshness', 'OFFLINE')->count(),
            ],
        ]);
    }

    /**
     * The breadcrumb trail of one shift, oldest first, for the map drawer.
     * Long shifts are thinned to at most `limit` points, keeping the latest.
     */
    public function trail(Request $request, ShiftAssignment $assignment): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:10', 'max:5000']]);
        $limit = (int) $request->input('limit', 1500);

        $query = DB::table('location_logs')->where('shift_assignment_id', $assignment->id);
        $total = (clone $query)->count();
        $step = max(1, (int) ceil($total / $limit));

        $points = $query->orderBy('recorded_at')
            ->get(['latitude', 'longitude', 'speed', 'recorded_at'])
            ->filter(fn ($p, $i) => $i % $step === 0 || $i === $total - 1)
            ->map(fn ($p) => [
                'lat' => (float) $p->latitude,
                'lng' => (float) $p->longitude,
                'speed' => $p->speed === null ? null : (float) $p->speed,
                'recordedAt' => CarbonImmutable::parse($p->recorded_at)->toIso8601ZuluString(),
            ])
            ->values();

        return response()->json(['data' => $points, 'meta' => ['totalPings' => $total, 'returned' => $points->count()]]);
    }

    /**
     * What the console needs to open a WebSocket, or enabled=false to poll.
     * Only the public app key is exposed; the secret never leaves the server.
     */
    private function realtime(): array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return ['enabled' => false];
        }

        $options = config('broadcasting.connections.reverb.options');

        return [
            'enabled' => true,
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => $options['host'],
            'port' => (int) $options['port'],
            'scheme' => $options['scheme'],
            'authEndpoint' => '/api/v1/broadcasting/auth',
            'channel' => 'private-fleet',
            'event' => '.position.updated',
        ];
    }
}
