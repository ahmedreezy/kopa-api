<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationsController extends Controller
{
    public function collections(Request $request): JsonResponse
    {
        $view = $request->input('view', 'due_today');
        $query = RepaymentSchedule::query()->with('loan.borrower')->where('status', '!=', 'paid');
        match ($view) {
            'overdue' => $query->whereDate('due_date', '<', today()),
            'upcoming' => $query->whereDate('due_date', '>', today()),
            default => $query->whereDate('due_date', today()),
        };

        return response()->json($query->orderBy('due_date')->paginate(25));
    }

    public function branches(): JsonResponse
    {
        return response()->json(Branch::query()->orderBy('name')->get());
    }

    public function staff(): JsonResponse
    {
        return response()->json(User::query()->with([])->orderBy('name')->get());
    }

    public function audit(): JsonResponse
    {
        return response()->json(AuditLog::query()->latest('created_at')->paginate(30));
    }
}
