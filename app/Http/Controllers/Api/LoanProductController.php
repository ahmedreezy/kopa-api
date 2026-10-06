<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoanProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LoanProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => LoanProduct::query()->orderByDesc('is_active')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('loan_products.manage'), 403);
        $data = $request->validate($this->rules($request));
        $data['code'] = $this->generateCode();

        return response()->json(['data' => LoanProduct::query()->create($data)], 201);
    }

    public function update(Request $request, string $product): JsonResponse
    {
        abort_unless($request->user()->canPerform('loan_products.manage'), 403);
        $product = LoanProduct::query()->findOrFail($product);
        $data = $request->validate($this->rules($request));
        $product->update($data);

        return response()->json(['data' => $product->fresh()]);
    }

    public function disable(Request $request, string $product): JsonResponse
    {
        abort_unless($request->user()->canPerform('loan_products.manage'), 403);
        $product = LoanProduct::query()->findOrFail($product);
        $product->update(['is_active' => false]);

        return response()->json(['data' => $product]);
    }

    private function rules(Request $request): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'], 'is_active' => ['boolean'],
            'principal_amount' => ['required', 'integer', 'min:1000'],
            'interest_rate' => ['required', 'numeric', 'min:0'], 'interest_period' => ['required', 'in:monthly,annual,loan_term'],
            'interest_method' => ['required', 'in:simple'],
            'duration' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['required', 'in:days,weeks,months'],
            'repayment_frequencies' => ['required', 'array', 'min:1'], 'repayment_frequencies.*' => ['in:daily,weekly,biweekly,monthly'],
            'processing_fee_type' => ['nullable', 'in:fixed,percentage', 'required_with:processing_fee_value'],
            'processing_fee_value' => ['nullable', 'numeric', 'min:0',
                Rule::when($request->input('processing_fee_type') === 'percentage', ['lt:100']),
                Rule::when($request->input('processing_fee_type') === 'fixed', ['lt:principal_amount']),
            ],
            'minimum_guarantors' => ['required', 'integer', 'min:0', 'max:5'],
            'collateral_required' => ['required', 'boolean'], 'minimum_collateral_value_percent' => ['nullable', 'numeric', 'min:0'],
            'required_documents' => ['nullable', 'array'], 'required_documents.*' => ['in:identity_document,borrower_photo,proof_of_residence,lc1_letter,income_evidence,business_evidence,bank_statement,mobile_money_statement'],
        ];
    }

    private function generateCode(): string
    {
        do {
            $code = 'LP-'.Str::upper(Str::random(6));
        } while (LoanProduct::query()->where('code', $code)->exists());

        return $code;
    }
}
