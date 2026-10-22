<?php

namespace App\Http\Controllers;

use App\Services\TelemetryIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MOD-11: POST /telemetry/ping. Accepts one ping (spec 4.2 payload) or
 * {"pings": [...]} for buffered offline batches. The technician is always
 * the token's owner; any userId / shiftId in the body is ignored.
 */
class TelemetryController extends Controller
{
    public function ping(Request $request, TelemetryIngestor $ingestor): JsonResponse
    {
        $batch = $request->has('pings');
        $prefix = $batch ? 'pings.*.' : '';
        $max = config('smc.telemetry.max_batch_size');

        $data = $request->validate([
            ...($batch ? ['pings' => ['required', 'array', 'min:1', "max:{$max}"]] : []),
            $prefix.'latitude' => ['required', 'numeric', 'between:-90,90'],
            $prefix.'longitude' => ['required', 'numeric', 'between:-180,180'],
            $prefix.'accuracy' => ['nullable', 'numeric', 'min:0'],
            $prefix.'speed' => ['nullable', 'numeric', 'min:0'],
            $prefix.'heading' => ['nullable', 'numeric', 'between:0,360'],
            $prefix.'batteryLevel' => ['nullable', 'integer', 'between:0,100'],
            $prefix.'isCharging' => ['nullable', 'boolean'],
            $prefix.'recordedAt' => ['required', 'date'],
        ]);

        $fields = ['latitude', 'longitude', 'accuracy', 'speed', 'heading', 'batteryLevel', 'isCharging', 'recordedAt'];
        $pings = array_map(
            fn (array $p) => array_intersect_key($p, array_flip($fields)),
            $batch ? $data['pings'] : [$data],
        );

        return response()->json($ingestor->ingest($request->user(), $pings));
    }
}
