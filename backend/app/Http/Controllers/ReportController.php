<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\Timesheets\TimesheetExporter;
use App\Services\Timesheets\TimesheetReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Spec 5.4: timesheet report and its CSV / XLSX / PDF export. */
class ReportController extends Controller
{
    public function timesheet(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->report($request)->toArray()]);
    }

    public function export(Request $request): Response
    {
        $request->validate(['exportType' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])]]);

        return (new TimesheetExporter($this->report($request)))->download($request->input('exportType'));
    }

    private function report(Request $request): TimesheetReport
    {
        $data = $request->validate([
            'period' => ['required', Rule::in(TimesheetReport::PERIODS)],
            'startDate' => ['required', 'date_format:Y-m-d'],
            'endDate' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:startDate'],
            'departmentId' => ['nullable', 'uuid'],
            'userId' => ['nullable', 'uuid'],
        ]);

        if ($data['period'] === 'custom') {
            $days = CarbonImmutable::parse($data['startDate'])->diffInDays(CarbonImmutable::parse($data['endDate'])) + 1;
            if ($days > TimesheetReport::MAX_CUSTOM_DAYS) {
                throw new ApiException('RANGE_TOO_LONG', 'Custom reports can cover at most '.TimesheetReport::MAX_CUSTOM_DAYS.' days.', 422);
            }
        }

        return new TimesheetReport(
            $data['period'],
            $data['startDate'],
            $data['endDate'] ?? null,
            $data['departmentId'] ?? null,
            $data['userId'] ?? null,
        );
    }
}
