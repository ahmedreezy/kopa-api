<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Borrower;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BorrowerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Borrower::query()->with(['loans' => fn ($q) => $q->whereIn('status', ['active', 'overdue'])->latest()]);
        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(fn ($q) => $q->where('full_name', 'like', "%{$search}%")->orWhere('phone_number', 'like', "%{$search}%"));
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('borrowers.manage'), 403);
        $data = $request->validate([
            'branch_id' => ['required', 'uuid', 'exists:tenant.branches,id'], 'full_name' => ['required', 'string', 'max:150'],
            'phone_number' => ['required', 'string', 'max:30'], 'alternative_phone' => ['nullable', 'string', 'max:30'],
            'nin' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:100'], 'occupation' => ['nullable', 'string', 'max:120'],
            'next_of_kin' => ['nullable', 'string', 'max:150'], 'next_of_kin_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(Borrower::query()->create($data), 201);
    }

    public function show(string $borrower): JsonResponse
    {
        $borrower = Borrower::query()->findOrFail($borrower);

        return response()->json($borrower->load(['loans' => fn ($q) => $q->with('schedules')->latest()]));
    }
}
