<?php

namespace App\Services\Loans;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\Document;
use App\Models\Guarantor;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\RepaymentSchedule;
use App\Support\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoanService
{
    public function __construct(private LoanCalculationService $calculator, private TenantContext $context) {}

    public function create(array $data, LoanProduct $product, string $userId): Loan
    {
        $borrower = Borrower::query()->with('documents')->findOrFail($data['borrower_id']);
        $this->assertRequirements($borrower, $product, $data);

        return DB::connection('tenant')->transaction(function () use ($data, $product, $userId) {
            $calculation = $this->calculator->calculateForProduct($product, $data);
            $loan = Loan::query()->create(array_merge(Arr::only($data, [
                'borrower_id', 'branch_id', 'principal_amount', 'duration', 'duration_unit', 'repayment_frequency',
                'disbursement_date', 'first_repayment_date', 'purpose', 'source_of_repayment', 'declared_disposable_income',
            ]), [
                'loan_product_id' => $product->id, 'loan_number' => 'LN-'.now()->format('ymd').'-'.strtoupper(Str::random(5)),
                'created_by' => $userId, 'status' => 'active', 'confirmed_at' => now(),
                'product_snapshot' => $product->toArray(),
            ], Arr::except($calculation, ['schedule'])));

            foreach ($calculation['schedule'] as $entry) RepaymentSchedule::query()->create($entry + ['loan_id' => $loan->id]);
            foreach ($data['guarantors'] ?? [] as $row) {
                Guarantor::query()->create(Arr::except($row, ['consent_confirmed']) + ['loan_id' => $loan->id, 'consent_given_at' => now()]);
            }
            foreach ($data['collateral'] ?? [] as $row) Collateral::query()->create($row + ['loan_id' => $loan->id]);
            $this->attachDocuments($loan, $data['document_ids'] ?? [], $userId);

            AuditLog::query()->create([
                'user_id' => $userId, 'action' => 'loan.created', 'entity_type' => Loan::class,
                'entity_id' => $loan->id, 'new_values' => $loan->only(['loan_number', 'loan_product_id', 'principal_amount', 'total_payable']),
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
            ]);

            return $loan->load('borrower', 'product', 'schedules', 'guarantors', 'collateral', 'documents');
        });
    }

    private function assertRequirements(Borrower $borrower, LoanProduct $product, array $data): void
    {
        $errors = [];
        $categories = $borrower->documents->pluck('category')->unique()->all();
        $missing = array_values(array_diff($product->required_documents ?? [], $categories));
        if ($missing) $errors['documents'] = 'Missing required borrower documents: '.implode(', ', array_map(fn ($item) => str_replace('_', ' ', $item), $missing)).'.';
        if (count($data['guarantors'] ?? []) < $product->minimum_guarantors) $errors['guarantors'] = "This product requires at least {$product->minimum_guarantors} guarantor(s).";
        if ($product->collateral_required && empty($data['collateral'])) $errors['collateral'] = 'Collateral is required for this product.';
        if ($product->collateral_required && $product->collateral_coverage_percent) {
            $value = collect($data['collateral'] ?? [])->sum(fn ($item) => (int) ($item['forced_sale_value'] ?? $item['estimated_value'] ?? 0));
            $required = (int) ceil((int) $data['principal_amount'] * ((float) $product->collateral_coverage_percent / 100));
            if ($value < $required) $errors['collateral_coverage'] = 'Collateral value does not meet this product’s required coverage.';
        }
        $tenant = $this->context->tenant();
        if ($tenant->regulatory_class === 'money_lender' && (float) $this->monthlyRate($product, $data) > 2.8 + 0.00001) {
            $errors['interest_rate'] = 'This product exceeds the 2.8% monthly money-lender ceiling.';
        }
        if ($errors) throw ValidationException::withMessages($errors);
    }

    private function monthlyRate(LoanProduct $product, array $data): float
    {
        return match ($product->interest_period) {
            'annual' => (float) $product->interest_rate / 12,
            'monthly' => (float) $product->interest_rate,
            default => (float) $product->interest_rate / max(0.0001, $this->calculator->months((int) $data['duration'], $data['duration_unit'])),
        };
    }

    private function attachDocuments(Loan $loan, array $ids, string $userId): void
    {
        if ($ids === []) return;
        Document::query()->whereIn('id', $ids)->where('documentable_type', 'temporary')->where('documentable_id', $userId)
            ->update(['documentable_type' => Loan::class, 'documentable_id' => $loan->id]);
    }
}
