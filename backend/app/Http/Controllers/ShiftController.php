<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * MOD-07: shift templates. Editing a template only affects assignments made
 * afterwards; existing assignments keep the times they were created with.
 */
class ShiftController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $shifts = Shift::withCount('assignments')
            ->when($request->boolean('activeOnly'), fn ($q) => $q->where('is_active', true))
            ->orderBy('start_time')
            ->get();

        return ShiftResource::collection($shifts);
    }

    public function store(Request $request): JsonResponse
    {
        $shift = Shift::create($this->validated($request));

        return (new ShiftResource($shift))->response()->setStatusCode(201);
    }

    public function update(Request $request, Shift $shift): ShiftResource
    {
        $shift->update($this->validated($request, $shift));

        return new ShiftResource($shift->loadCount('assignments'));
    }

    public function destroy(Shift $shift): Response
    {
        if ($shift->assignments()->exists()) {
            throw new ApiException('SHIFT_IN_USE', 'This shift has assignments. Deactivate it instead so its history is kept.', 409);
        }

        $shift->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Shift $shift = null): array
    {
        $required = $shift ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:100', Rule::unique('shifts', 'name')->ignore($shift?->id)],
            'startTime' => [$required, 'date_format:H:i'],
            'endTime' => [$required, 'date_format:H:i', 'different:startTime'],
            'daysOfWeek' => [$required, 'array', 'min:1', 'max:7'],
            'daysOfWeek.*' => ['integer', 'between:1,7', 'distinct'],
            'geofence' => ['sometimes', 'nullable', 'array', 'min:3', 'max:200'],
            'geofence.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'geofence.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'isActive' => ['sometimes', 'boolean'],
        ], ['endTime.different' => 'A shift cannot start and end at the same time.']);

        $map = ['name' => 'name', 'startTime' => 'start_time', 'endTime' => 'end_time', 'isActive' => 'is_active'];
        $attributes = [];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }
        foreach (['start_time', 'end_time'] as $column) {
            if (isset($attributes[$column])) {
                $attributes[$column] .= ':00';
            }
        }
        if (array_key_exists('daysOfWeek', $data)) {
            $attributes['days_of_week'] = collect($data['daysOfWeek'])->map(fn ($d) => (int) $d)->sort()->values()->all();
        }
        if (array_key_exists('geofence', $data)) {
            $attributes['geofence'] = $data['geofence'] === null ? null : array_map(
                fn ($p) => ['lat' => round((float) $p['lat'], 7), 'lng' => round((float) $p['lng'], 7)],
                $data['geofence'],
            );
        }

        return $attributes;
    }
}
