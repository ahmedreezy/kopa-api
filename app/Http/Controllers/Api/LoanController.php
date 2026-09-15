<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\Loans\LoanCalculationService;
use App\Services\Loans\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LoanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Loan::query()->with('borrower', 'product')->latest();
        if ($status = $request->input('status')) $query->where('status', $status);

        return response()->json($query->paginate(20));
    }

    public function calculate(Request $request, LoanCalculationService $calculator): JsonResponse
    {
        [$product, $data] = $this->validatedTerms($request);

        return response()->json($calculator->calculateForProduct($product, $data));
    }

    public function store(Request $request, LoanService $service): JsonResponse
    {
        abort_unless($request->user()->canPerform('loans.manage'), 403);
        [$product, $data] = $this->validatedTerms($request, true);

        return response()->json($service->create($data, $product, $request->user()->id), 201);
    }

    public function show(string $loan): JsonResponse
    {
        $loan = Loan::query()->findOrFail($loan);

        return response()->json($loan->load('borrower.documents', 'product', 'schedules', 'repayments.receipt', 'guarantors', 'collateral', 'documents'));
    }

    private function validatedTerms(Request $request, bool $withEvidence = false): array
    {
        $rules = [
            'loan_product_id' => ['required', 'uuid', 'exists:tenant.loan_products,id'],
            'borrower_id' => ['required', 'uuid', 'exists:tenant.borrowers,id'], 'branch_id' => ['required', 'uuid', 'exists:tenant.branches,id'],
            'principal_amount' => ['required', 'integer', 'min:1000'], 'duration' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['required', 'in:days,weeks,months'], 'repayment_frequency' => ['required', 'in:daily,weekly,biweekly,monthly'],
            'disbursement_date' => ['required', 'date'], 'first_repayment_date' => ['required', 'date', 'after_or_equal:disbursement_date'],
            'purpose' => ['required', 'string', 'max:500'], 'source_of_repayment' => ['required', 'string', 'max:1000'],
            'declared_disposable_income' => ['required', 'integer', 'min:0'],
        ];
        if ($withEvidence) {
            $rules += [
                'guarantors' => ['sometimes', 'array', 'max:5'], 'guarantors.*.name' => ['required', 'string', 'max:150'],
                'guarantors.*.phone' => ['required', 'string', 'max:30'], 'guarantors.*.alternative_phone' => ['nullable', 'string', 'max:30'],
                'guarantors.*.nin' => ['nullable', 'string', 'max:30'], 'guarantors.*.relationship' => ['nullable', 'string', 'max:80'],
                'guarantors.*.address' => ['nullable', 'string', 'max:255'], 'guarantors.*.occupation' => ['nullable', 'string', 'max:120'],
                'guarantors.*.employer_name' => ['nullable', 'string', 'max:150'], 'guarantors.*.monthly_income' => ['nullable', 'integer', 'min:0'],
                'guarantors.*.consent_confirmed' => ['accepted'],
                'collateral' => ['sometimes', 'array'], 'collateral.*.security_type' => ['required', 'in:land,vehicle,motorcycle,business_asset,household_asset,inventory,equipment,livestock,other'],
                'collateral.*.description' => ['required', 'string', 'max:1000'], 'collateral.*.estimated_value' => ['nullable', 'integer', 'min:0'],
                'collateral.*.forced_sale_value' => ['nullable', 'integer', 'min:0'], 'collateral.*.reference_number' => ['nullable', 'string', 'max:100'],
                'collateral.*.owner' => ['nullable', 'string', 'max:150'], 'collateral.*.condition' => ['nullable', 'string', 'max:100'],
                'collateral.*.location' => ['nullable', 'string', 'max:255'], 'collateral.*.custody_status' => ['nullable', 'in:with_borrower,held_by_lender,registered_interest'],
                'collateral.*.simpo_registration_number' => ['nullable', 'string', 'max:100'], 'collateral.*.notes' => ['nullable', 'string', 'max:1000'],
                'document_ids' => ['sometimes', 'array'], 'document_ids.*' => ['uuid'],
                'terms_confirmed' => ['accepted'],
            ];
        }
        $data = $request->validate($rules);
        $product = LoanProduct::query()->where('is_active', true)->findOrFail($data['loan_product_id']);
        $errors = [];
        if ((int) $data['principal_amount'] < $product->minimum_principal || (int) $data['principal_amount'] > $product->maximum_principal) $errors['principal_amount'] = 'The amount is outside this product’s allowed range.';
        if ($data['duration_unit'] !== $product->duration_unit || (int) $data['duration'] < $product->minimum_duration || (int) $data['duration'] > $product->maximum_duration) $errors['duration'] = 'The duration is outside this product’s allowed range.';
        if (! in_array($data['repayment_frequency'], $product->repayment_frequencies, true)) $errors['repayment_frequency'] = 'This repayment frequency is not offered for the selected product.';
        if ($errors) throw ValidationException::withMessages($errors);

        return [$product, $data];
    }
}
