<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoanProduct;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LoanProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => LoanProduct::query()->orderByDesc('is_active')->orderBy('name')->get()]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        abort_unless($request->user()->canPerform('loan_products.manage'), 403);
        $data = $request->validate($this->rules());
        $this->assertCompliant($data, $context);

        return response()->json(['data' => LoanProduct::query()->create($data)], 201);
    }

    public function update(Request $request, TenantContext $context, string $product): JsonResponse
    {
        abort_unless($request->user()->canPerform('loan_products.manage'), 403);
        $product = LoanProduct::query()->findOrFail($product);
        $data = $request->validate($this->rules($product->id));
        $this->assertCompliant($data, $context);
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

    private function rules(?string $ignore = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash', 'unique:tenant.loan_products,code'.($ignore ? ','.$ignore : '')],
            'description' => ['nullable', 'string', 'max:1000'], 'is_active' => ['boolean'],
            'minimum_principal' => ['required', 'integer', 'min:1000'],
            'maximum_principal' => ['required', 'integer', 'gte:minimum_principal'],
            'interest_rate' => ['required', 'numeric', 'min:0'], 'interest_period' => ['required', 'in:monthly,annual,loan_term'],
            'interest_method' => ['required', 'in:simple'],
            'minimum_duration' => ['required', 'integer', 'min:1'], 'maximum_duration' => ['required', 'integer', 'gte:minimum_duration'],
            'duration_unit' => ['required', 'in:days,weeks,months'],
            'repayment_frequencies' => ['required', 'array', 'min:1'], 'repayment_frequencies.*' => ['in:daily,weekly,biweekly,monthly'],
            'fee_type' => ['required', 'in:fixed,percentage'], 'fee_value' => ['required', 'numeric', 'min:0'],
            'minimum_guarantors' => ['required', 'integer', 'min:0', 'max:5'],
            'collateral_required' => ['required', 'boolean'], 'collateral_coverage_percent' => ['nullable', 'numeric', 'min:0'],
            'required_documents' => ['nullable', 'array'], 'required_documents.*' => ['in:national_id_front,national_id_back,borrower_photo,proof_of_residence,lc1_letter,income_evidence,business_evidence,bank_statement,mobile_money_statement'],
        ];
    }

    private function assertCompliant(array $data, TenantContext $context): void
    {
        if ($context->tenant()->regulatory_class !== 'money_lender') {
            return;
        }
        $monthly = match ($data['interest_period']) {
            'annual' => (float) $data['interest_rate'] / 12,
            'monthly' => (float) $data['interest_rate'],
            default => (float) $data['interest_rate'] / max(1, $this->months((int) $data['maximum_duration'], $data['duration_unit'])),
        };
        if ($monthly > 2.8 + 0.00001) {
            throw ValidationException::withMessages(['interest_rate' => 'Licensed money-lender products may not exceed the configured 2.8% monthly ceiling.']);
        }
    }

    private function months(int $duration, string $unit): float
    {
        return match ($unit) { 'days' => $duration / 30.4375, 'weeks' => ($duration * 7) / 30.4375, default => $duration };
    }
}
