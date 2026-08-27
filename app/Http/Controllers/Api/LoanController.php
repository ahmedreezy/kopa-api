<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\Loans\LoanCalculationService;
use App\Services\Loans\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanController extends Controller
{
    private function rules(): array
    {
        return [
            'borrower_id' => ['required', 'uuid', 'exists:tenant.borrowers,id'], 'branch_id' => ['required', 'uuid', 'exists:tenant.branches,id'],
            'principal_amount' => ['required', 'integer', 'min:1000'], 'interest_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'interest_method' => ['required', 'in:simple'], 'interest_period' => ['required', 'in:loan_term,monthly'],
            'duration' => ['required', 'integer', 'min:1', 'max:60'], 'duration_unit' => ['required', 'in:days,weeks,months'],
            'repayment_frequency' => ['required', 'in:daily,weekly,biweekly,monthly'],
            'disbursement_date' => ['required', 'date'], 'first_repayment_date' => ['required', 'date', 'after_or_equal:disbursement_date'],
            'fees_amount' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = Loan::query()->with('borrower')->latest();
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate(20));
    }

    public function calculate(Request $request, LoanCalculationService $calculator): JsonResponse
    {
        return response()->json($calculator->calculate($request->validate($this->rules())));
    }

    public function store(Request $request, LoanService $service): JsonResponse
    {
        abort_unless($request->user()->canPerform('loans.manage'), 403);

        return response()->json($service->create($request->validate($this->rules()), $request->user()->id), 201);
    }

    public function show(string $loan): JsonResponse
    {
        $loan = Loan::query()->findOrFail($loan);

        return response()->json($loan->load('borrower', 'schedules', 'repayments'));
    }
}
