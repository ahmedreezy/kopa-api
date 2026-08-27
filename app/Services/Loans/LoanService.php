<?php

namespace App\Services\Loans;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\RepaymentSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoanService
{
    public function __construct(private LoanCalculationService $calculator) {}

    public function create(array $data, string $userId): Loan
    {
        return DB::connection('tenant')->transaction(function () use ($data, $userId) {
            $calculation = $this->calculator->calculate($data);
            $loan = Loan::query()->create(array_merge($data, [
                'loan_number' => 'LN-'.now()->format('ymd').'-'.strtoupper(Str::random(5)),
                'created_by' => $userId,
                'status' => 'active',
                'confirmed_at' => now(),
            ], collect($calculation)->except('schedule')->all()));

            foreach ($calculation['schedule'] as $entry) {
                RepaymentSchedule::query()->create(array_merge($entry, ['loan_id' => $loan->id]));
            }

            AuditLog::query()->create([
                'user_id' => $userId, 'action' => 'loan.created', 'entity_type' => Loan::class,
                'entity_id' => $loan->id, 'new_values' => $loan->only(['loan_number', 'principal_amount', 'total_payable']),
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
            ]);

            return $loan->load('borrower', 'schedules');
        });
    }
}
