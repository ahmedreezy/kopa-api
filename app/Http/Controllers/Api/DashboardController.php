<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\RepaymentSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('dashboard.view'), 403);
        $activeLoans = Loan::query()->where('status', 'active')->get();
        $outstanding = $activeLoans->sum(fn (Loan $loan) => $loan->outstanding_amount);
        $dueToday = RepaymentSchedule::query()->whereDate('due_date', today())->where('status', '!=', 'paid');
        $overdue = RepaymentSchedule::query()->whereDate('due_date', '<', today())->where('status', '!=', 'paid');
        $attention = RepaymentSchedule::query()->with('loan.borrower')
            ->where('status', '!=', 'paid')->whereDate('due_date', '<=', today())->orderBy('due_date')->limit(8)->get();

        return response()->json([
            'metrics' => [
                'outstanding' => $outstanding,
                'due_today' => (clone $dueToday)->get()->sum(fn ($s) => $s->amount_due - $s->amount_paid),
                'due_today_count' => (clone $dueToday)->count(),
                'overdue' => (clone $overdue)->get()->sum(fn ($s) => $s->amount_due - $s->amount_paid),
                'overdue_count' => (clone $overdue)->count(),
                'collected_today' => Repayment::query()->where('entry_type', 'payment')->whereDate('paid_at', today())->sum('amount'),
                'active_loans' => $activeLoans->count(),
            ],
            'attention' => $attention,
        ]);
    }

    public function search(Request $request, string $query): JsonResponse
    {
        abort_unless($request->user()->canPerform('search.use'), 403);
        $borrowers = Borrower::query()->with(['loans' => fn ($q) => $q->where('status', 'active')])
            ->where(fn ($q) => $q->where('full_name', 'like', "%{$query}%")->orWhere('phone_number', 'like', "%{$query}%"))
            ->limit(8)->get();
        $loans = Loan::query()->with('borrower')->where('loan_number', 'like', "%{$query}%")->limit(8)->get();

        return response()->json(['borrowers' => $borrowers, 'loans' => $loans]);
    }
}
