<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStatus;
use App\Http\Resources\ShiftAssignmentResource;
use App\Models\ShiftAssignment;
use App\Services\Attendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Technician-facing shift endpoints (spec 4.3 / 5.2). */
class AttendanceController extends Controller
{
    public function __construct(private readonly Attendance $attendance) {}

    /** The current shift, if checked in, and the next week of scheduled shifts. */
    public function myShifts(Request $request): JsonResponse
    {
        $base = ShiftAssignment::with('shift')->where('user_id', $request->user()->id);

        $active = (clone $base)->where('status', AssignmentStatus::Active)->first();
        $upcoming = (clone $base)
            ->where('status', AssignmentStatus::Scheduled)
            ->where('scheduled_end_at', '>', now())
            ->where('scheduled_start_at', '<', now()->addDays(7))
            ->orderBy('scheduled_start_at')
            ->get();

        return response()->json([
            'active' => $active ? new ShiftAssignmentResource($active) : null,
            'upcoming' => ShiftAssignmentResource::collection($upcoming),
            'checkInOpensMinutesBefore' => config('smc.shifts.early_check_in_minutes'),
        ]);
    }

    public function checkIn(Request $request): ShiftAssignmentResource
    {
        $data = $request->validate([
            'assignmentId' => ['required', 'uuid'],
            'location.lat' => ['required', 'numeric', 'between:-90,90'],
            'location.lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $assignment = ShiftAssignment::with('shift')->findOrFail($data['assignmentId']);

        return new ShiftAssignmentResource(
            $this->attendance->checkIn($request->user(), $assignment, (float) $data['location']['lat'], (float) $data['location']['lng'])
        );
    }

    public function checkOut(Request $request): ShiftAssignmentResource
    {
        $data = $request->validate([
            'assignmentId' => ['required', 'uuid'],
            'location.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'location.lng' => ['nullable', 'required_with:location.lat', 'numeric', 'between:-180,180'],
        ]);

        $assignment = ShiftAssignment::with('shift')->findOrFail($data['assignmentId']);
        $lat = isset($data['location']['lat']) ? (float) $data['location']['lat'] : null;
        $lng = isset($data['location']['lng']) ? (float) $data['location']['lng'] : null;

        return new ShiftAssignmentResource($this->attendance->checkOut($request->user(), $assignment, $lat, $lng));
    }
}
