<?php

namespace App\Http\Controllers;

use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** MOD-06: departments / teams with the pin color used on the live map. */
class DepartmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Department::orderBy('name');

        // Counts are for the console; the public list used at registration stays minimal.
        if ($request->user('sanctum')?->role->usesConsole()) {
            $query->withCount('users');
        }

        return DepartmentResource::collection($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $department = Department::create($this->validated($request));

        return (new DepartmentResource($department))->response()->setStatusCode(201);
    }

    public function update(Request $request, Department $department): DepartmentResource
    {
        $department->update($this->validated($request, $department));

        return new DepartmentResource($department);
    }

    /** Members are kept and simply become unassigned. */
    public function destroy(Department $department): Response
    {
        $department->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Department $department = null): array
    {
        $sometimes = $department ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$sometimes, 'string', 'max:100', Rule::unique('departments', 'name')->ignore($department?->id)],
            'colorHex' => [$sometimes, 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'iconCode' => ['sometimes', 'nullable', 'string', 'max:50'],
        ], ['colorHex.regex' => 'The color must be a hex value like #3B82F6.']);

        return collect([
            'name' => $data['name'] ?? null,
            'color_hex' => isset($data['colorHex']) ? strtoupper($data['colorHex']) : null,
        ])->filter()->when(array_key_exists('iconCode', $data), fn ($c) => $c->put('icon_code', $data['iconCode']))->all();
    }
}
