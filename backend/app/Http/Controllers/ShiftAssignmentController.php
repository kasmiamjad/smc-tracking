<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStatus;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\ShiftAssignmentResource;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\ShiftScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** MOD-08: allocating technicians (individually or by department) to shifts. */
class ShiftAssignmentController extends Controller
{
    public function assign(Request $request, ShiftScheduler $scheduler): JsonResponse
    {
        $today = now(config('smc.shifts.timezone'))->toDateString();

        $data = $request->validate([
            'shiftId' => ['required', 'uuid', Rule::exists('shifts', 'id')->where('is_active', true)],
            'userIds' => ['required_without:departmentId', 'array', 'min:1', 'max:500'],
            'userIds.*' => ['uuid', 'distinct'],
            'departmentId' => ['required_without:userIds', 'nullable', 'uuid', Rule::exists('departments', 'id')],
            'startDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'endDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:startDate'],
        ], ['shiftId.exists' => 'Choose an active shift.', 'startDate.after_or_equal' => 'Shifts can only be assigned from today onwards.']);

        $days = CarbonImmutable::parse($data['startDate'])->diffInDays(CarbonImmutable::parse($data['endDate'])) + 1;
        if ($days > config('smc.shifts.max_assignment_days')) {
            throw new ApiException('RANGE_TOO_LONG', 'Assign at most '.config('smc.shifts.max_assignment_days').' days at a time.', 422);
        }

        $users = User::technicians()
            ->where('status', UserStatus::Active)
            ->whereNull('blocked_at')
            ->when($data['userIds'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->when(! isset($data['userIds']), fn ($q) => $q->where('department_id', $data['departmentId']))
            ->get();

        if ($users->isEmpty()) {
            throw new ApiException('NO_ELIGIBLE_TECHNICIANS', 'None of the selected people are active, unblocked technicians.', 422);
        }

        $result = $scheduler->assign(Shift::findOrFail($data['shiftId']), $users, $data['startDate'], $data['endDate'], $request->user());

        return response()->json([...$result, 'technicians' => $users->count()], 201);
    }

    /** Assignments in a date range (default: the current week), for the roster grid. */
    public function roster(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'departmentId' => ['nullable', 'uuid'],
            'userId' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::enum(AssignmentStatus::class)],
        ]);

        $from = CarbonImmutable::parse($data['from'] ?? now(config('smc.shifts.timezone'))->startOfWeek()->toDateString());
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : $from->addDays(6);

        if ($from->diffInDays($to) > 31) {
            throw new ApiException('RANGE_TOO_LONG', 'Request at most 31 days of roster at a time.', 422);
        }

        $assignments = ShiftAssignment::with(['user.department', 'shift'])
            ->whereBetween('shift_date', [$from->toDateString(), $to->toDateString()])
            ->when($data['userId'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['departmentId'] ?? null, fn ($q, $id) => $q->whereHas('user', fn ($u) => $u->where('department_id', $id)))
            ->orderBy('scheduled_start_at')
            ->get();

        return ShiftAssignmentResource::collection($assignments)->additional([
            'meta' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => config('smc.shifts.timezone')],
        ]);
    }

    public function destroy(ShiftAssignment $assignment): Response
    {
        if ($assignment->status !== AssignmentStatus::Scheduled) {
            throw new ApiException('ASSIGNMENT_STARTED', 'Only shifts that have not started can be removed.', 409);
        }

        $assignment->delete();

        return response()->noContent();
    }
}
