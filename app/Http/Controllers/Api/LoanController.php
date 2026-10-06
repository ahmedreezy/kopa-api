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
        abort_unless($request->user()->canPerform('loans.view'), 403);
        $query = Loan::query()->with('borrower', 'product')->latest();
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate(20));
    }

    public function calculate(Request $request, LoanCalculationService $calculator): JsonResponse
    {
        abort_unless($request->user()->canPerform('loans.manage'), 403);
        [$product, $data] = $this->validatedTerms($request);

        return response()->json($calculator->calculateForProduct($product, $data));
    }

    public function store(Request $request, LoanService $service): JsonResponse
    {
        abort_unless($request->user()->canPerform('loans.manage'), 403);
        [$product, $data] = $this->validatedTerms($request, true);

        return response()->json($service->create($data, $product, $request->user()->id), 201);
    }

    public function show(Request $request, string $loan): JsonResponse
    {
        abort_unless($request->user()->canPerform('loans.view'), 403);
        $loan = Loan::query()->findOrFail($loan);
        $relations = ['borrower', 'product', 'creator', 'schedules', 'repayments.receipt', 'guarantors', 'collateral'];
        if ($request->user()->canPerform('documents.view')) {
            $relations = ['borrower.documents', 'product', 'creator', 'schedules', 'repayments.receipt', 'guarantors.documents', 'collateral.documents'];
        }

        return response()->json($loan->load($relations));
    }

    private function validatedTerms(Request $request, bool $withEvidence = false): array
    {
        $rules = [
            'loan_product_id' => ['required', 'uuid', 'exists:tenant.loan_products,id'],
            'borrower_id' => ['required', 'uuid', 'exists:tenant.borrowers,id'], 'branch_id' => ['required', 'uuid', 'exists:tenant.branches,id'],
            'principal_amount' => ['required', 'integer', 'min:1000'], 'duration' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['required', 'in:days,weeks,months'], 'repayment_frequency' => ['required', 'in:daily,weekly,biweekly,monthly'],
            'first_repayment_date' => ['required', 'date', 'after:today'],
            'purpose' => ['required', 'string', 'max:500'], 'source_of_repayment' => ['required', 'string', 'max:1000'],
        ];
        if ($withEvidence) {
            $rules += [
                'guarantors' => ['sometimes', 'array', 'max:5'], 'guarantors.*.name' => ['required', 'string', 'max:150'],
                'guarantors.*.phone' => ['required', 'string', 'max:30'],
                'guarantors.*.id_type' => ['required', 'in:national_id,passport,refugee_id'],
                'guarantors.*.nin' => ['required', 'string', 'max:30'], 'guarantors.*.relationship' => ['nullable', 'string', 'max:80'],
                'guarantors.*.address' => ['nullable', 'string', 'max:255'],
                'guarantors.*.consent_confirmed' => ['accepted'],
                'guarantors.*.document_ids' => ['required', 'array', 'min:1'], 'guarantors.*.document_ids.*' => ['uuid'],
                'collateral' => ['sometimes', 'array'], 'collateral.*.security_type' => ['required', 'in:land,vehicle,motorcycle,business_asset,household_asset,inventory,equipment,livestock,other'],
                'collateral.*.description' => ['nullable', 'required_if:collateral.*.security_type,other', 'string', 'max:1000'], 'collateral.*.estimated_value' => ['nullable', 'integer', 'min:0'],
                'collateral.*.owner' => ['nullable', 'string', 'max:150'], 'collateral.*.condition' => ['nullable', 'string', 'max:100'],
                'collateral.*.location' => ['nullable', 'string', 'max:255'], 'collateral.*.custody_status' => ['nullable', 'in:with_borrower,held_by_lender,registered_interest'],
                'collateral.*.simpo_registration_number' => ['nullable', 'string', 'max:100'], 'collateral.*.notes' => ['nullable', 'string', 'max:1000'],
                'collateral.*.document_ids' => ['required', 'array', 'min:1'], 'collateral.*.document_ids.*' => ['uuid'],
                'terms_confirmed' => ['accepted'],
            ];
        }
        $data = $request->validate($rules);
        $data['disbursement_date'] = today()->toDateString();
        if ($withEvidence && empty($data['guarantors']) && empty($data['collateral'])) {
            throw ValidationException::withMessages(['security' => 'Add at least one guarantor or collateral item.']);
        }
        $product = LoanProduct::query()->where('is_active', true)->findOrFail($data['loan_product_id']);
        $errors = [];
        if ((int) $data['principal_amount'] !== $product->principal_amount) {
            $errors['principal_amount'] = 'The principal must match the selected product.';
        }
        if ($data['duration_unit'] !== $product->duration_unit || (int) $data['duration'] !== $product->duration) {
            $errors['duration'] = 'The duration must match the selected product.';
        }
        if (! in_array($data['repayment_frequency'], $product->repayment_frequencies, true)) {
            $errors['repayment_frequency'] = 'This repayment frequency is not offered for the selected product.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [$product, $data];
    }
}
