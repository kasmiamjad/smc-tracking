<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\FleetMemberResource;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Routes\RouteHistory;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

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
                // Seconds since the last ping at which a pin turns amber / grey, per rhythm.
                'freshness' => config('smc.telemetry.freshness'),
                'movingSpeed' => config('smc.telemetry.moving_speed'),
                'onShift' => $rows->count(),
                'live' => $rows->where('freshness', 'LIVE')->count(),
                'lagging' => $rows->where('freshness', 'LAGGING')->count(),
                'offline' => $rows->where('freshness', 'OFFLINE')->count(),
            ],
        ]);
    }

    /**
     * The cleaned breadcrumb trail of one shift, oldest first, for the map
     * drawer: inaccurate readings and spikes removed, stays collapsed to stops.
     * Long shifts are thinned to at most `limit` points, keeping the latest.
     */
    public function trail(Request $request, ShiftAssignment $assignment): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:10', 'max:5000']]);
        $limit = (int) $request->input('limit', 1500);

        $segment = RouteHistory::make()->segment($assignment);
        $total = count($segment['points']);
        $step = max(1, (int) ceil($total / $limit));

        $points = collect($segment['points'])
            ->filter(fn ($p, $i) => $i % $step === 0 || $i === $total - 1 || $p['stop'] !== null)
            ->map(fn ($p) => [
                'lat' => $p['lat'],
                'lng' => $p['lng'],
                'speed' => $p['speed'],
                'recordedAt' => CarbonImmutable::createFromTimestampMs($p['t'])->toIso8601ZuluString(),
            ])
            ->values();

        return response()->json([
            'data' => $points,
            'stops' => $segment['stops'],
            'meta' => ['totalPings' => $segment['stats']['rawPoints'], 'returned' => $points->count(), 'source' => $segment['source']],
        ]);
    }

    /**
     * MOD-18: a technician's route over a time range (default: today in the
     * business timezone), shift by shift, cleaned for drawing.
     */
    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'userId' => ['required', 'uuid', Rule::exists('users', 'id')],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after:from'],
        ]);

        $tz = config('smc.shifts.timezone');
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->utc() : CarbonImmutable::now($tz)->startOfDay()->utc();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->utc() : $from->addDay();

        if ($from->diffInHours($to) > config('smc.routes.max_history_hours')) {
            throw new ApiException('RANGE_TOO_LONG', 'Route history covers at most '.config('smc.routes.max_history_hours').' hours at a time.', 422);
        }

        $user = User::with('department')->findOrFail($data['userId']);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'fullName' => $user->full_name,
                'phone' => $user->phone_number,
                'department' => $user->department ? ['id' => $user->department->id, 'name' => $user->department->name, 'colorHex' => $user->department->color_hex] : null,
            ],
            'from' => $from->toIso8601ZuluString(),
            'to' => $to->toIso8601ZuluString(),
            'timezone' => $tz,
            ...RouteHistory::make()->forUser($user->id, $from, $to),
        ]);
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
