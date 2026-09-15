<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\Receipt;
use App\Models\Repayment;
use App\Services\Repayments\RepaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RepaymentController extends Controller
{
    public function store(Request $request, string $loan, RepaymentService $service): JsonResponse
    {
        abort_unless($request->user()->canPerform('repayments.create'), 403);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'], 'payment_method' => ['required', 'in:cash,mtn_momo,airtel_money,bank'],
            'transaction_reference' => ['nullable', 'required_unless:payment_method,cash', 'string', 'max:100', Rule::unique('tenant.repayments')->where(fn ($query) => $query->where('payment_method', $request->input('payment_method'))->where('entry_type', 'payment'))], 'notes' => ['nullable', 'string', 'max:1000'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        return response()->json($service->post(Loan::query()->findOrFail($loan), $data, $request->user()->id), 201);
    }

    public function reverse(Request $request, string $repayment, RepaymentService $service): JsonResponse
    {
        abort_unless($request->user()->canPerform('repayments.reverse'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json($service->reverse(Repayment::query()->findOrFail($repayment), $data['reason'], $request->user()->id), 201);
    }

    public function receipt(string $receipt): JsonResponse
    {
        $receipt = Receipt::query()->findOrFail($receipt);

        return response()->json($receipt->loadMissing(['repayment', 'loan.borrower']));
    }
}
