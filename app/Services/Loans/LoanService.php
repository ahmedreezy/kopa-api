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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class LoanService
{
    public function __construct(private LoanCalculationService $calculator) {}

    public function create(array $data, LoanProduct $product, string $userId): Loan
    {
        $borrower = Borrower::query()->with('documents')->findOrFail($data['borrower_id']);
        $this->assertRequirements($borrower, $product, $data);
        $this->assertEvidenceDocuments($data, $userId);

        return DB::connection('tenant')->transaction(function () use ($data, $product, $userId) {
            $calculation = $this->calculator->calculateForProduct($product, $data);
            $loan = Loan::query()->create(array_merge(Arr::only($data, [
                'borrower_id', 'branch_id', 'principal_amount', 'duration', 'duration_unit', 'repayment_frequency',
                'disbursement_date', 'first_repayment_date', 'purpose', 'source_of_repayment',
            ]), [
                'loan_product_id' => $product->id, 'loan_number' => 'LN-'.now()->format('ymd').'-'.strtoupper(Str::random(5)),
                'created_by' => $userId, 'status' => 'active', 'confirmed_at' => now(),
                'product_snapshot' => $product->toArray(),
            ], Arr::except($calculation, ['schedule'])));

            foreach ($calculation['schedule'] as $entry) {
                RepaymentSchedule::query()->create($entry + ['loan_id' => $loan->id]);
            }
            foreach ($data['guarantors'] ?? [] as $row) {
                $documentIds = $row['document_ids'];
                $guarantor = Guarantor::query()->create(Arr::except($row, ['consent_confirmed', 'document_ids']) + ['loan_id' => $loan->id, 'consent_given_at' => now()]);
                $this->attachDocuments($guarantor, $documentIds, $userId);
            }
            foreach ($data['collateral'] ?? [] as $row) {
                $documentIds = $row['document_ids'];
                $collateral = Collateral::query()->create(Arr::except($row, ['document_ids']) + ['loan_id' => $loan->id]);
                $this->attachDocuments($collateral, $documentIds, $userId);
            }

            AuditLog::query()->create([
                'user_id' => $userId, 'action' => 'loan.created', 'entity_type' => Loan::class,
                'entity_id' => $loan->id, 'new_values' => $loan->only(['loan_number', 'loan_product_id', 'principal_amount', 'net_disbursement_amount', 'fees_amount', 'total_payable']),
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
            ]);

            return $loan->load('borrower', 'product', 'creator', 'schedules', 'guarantors.documents', 'collateral.documents');
        });
    }

    private function assertRequirements(Borrower $borrower, LoanProduct $product, array $data): void
    {
        $errors = [];
        $categories = $borrower->documents->pluck('category')->unique()->all();
        $requirements = $product->required_documents ?? [];
        $identityCategories = match ($borrower->id_type) {
            'passport' => ['passport'],
            'refugee_id' => ['refugee_id_front', 'refugee_id_back'],
            default => ['national_id_front', 'national_id_back'],
        };
        $identityComplete = array_diff($identityCategories, $categories) === [];
        $missing = array_values(array_filter($requirements, function ($requirement) use ($categories, $identityComplete) {
            return $requirement === 'identity_document' ? ! $identityComplete : ! in_array($requirement, $categories, true);
        }));
        if ($missing) {
            $errors['documents'] = 'Missing required borrower documents: '.implode(', ', array_map(fn ($item) => str_replace('_', ' ', $item), $missing)).'.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertEvidenceDocuments(array $data, string $userId): void
    {
        $errors = [];
        foreach ($data['guarantors'] ?? [] as $index => $guarantor) {
            $required = match ($guarantor['id_type']) {
                'passport' => ['passport'],
                'refugee_id' => ['refugee_id_front', 'refugee_id_back'],
                default => ['national_id_front', 'national_id_back'],
            };
            $categories = $this->temporaryDocumentCategories($guarantor['document_ids'], $userId);
            if (array_diff($required, $categories) !== []) {
                $errors["guarantors.{$index}.document_ids"] = 'Upload all identification document sides for this guarantor.';
            }
        }
        foreach ($data['collateral'] ?? [] as $index => $collateral) {
            $categories = $this->temporaryDocumentCategories($collateral['document_ids'], $userId);
            if (! in_array('security_evidence', $categories, true)) {
                $errors["collateral.{$index}.document_ids"] = 'Upload a photo or supporting document for this collateral item.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function temporaryDocumentCategories(array $ids, string $userId): array
    {
        return Document::query()->whereIn('id', $ids)->where('documentable_type', 'temporary')
            ->where('documentable_id', $userId)->pluck('category')->unique()->all();
    }

    private function attachDocuments(Model $documentable, array $ids, string $userId): void
    {
        if ($ids === []) {
            return;
        }
        Document::query()->whereIn('id', $ids)->where('documentable_type', 'temporary')->where('documentable_id', $userId)
            ->update(['documentable_type' => $documentable::class, 'documentable_id' => $documentable->getKey()]);
    }
}
