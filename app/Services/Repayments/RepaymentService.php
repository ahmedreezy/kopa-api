<?php

namespace App\Services\Repayments;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\Receipt;
use App\Models\Repayment;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RepaymentService
{
    public function __construct(private TenantContext $context) {}

    public function post(Loan $loan, array $data, string $userId): Repayment
    {
        return DB::connection('tenant')->transaction(function () use ($loan, $data, $userId) {
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);
            $outstanding = $loan->outstanding_amount;
            $amount = (int) $data['amount'];
            throw_if($loan->status !== 'active', ValidationException::withMessages(['loan' => 'This loan is not active.']));
            throw_if($amount <= 0 || $amount > $outstanding, ValidationException::withMessages(['amount' => 'Payment must not exceed the outstanding balance.']));

            $repayment = Repayment::query()->create([
                'loan_id' => $loan->id, 'recorded_by' => $userId, 'amount' => $amount,
                'entry_type' => 'payment', 'payment_method' => $data['payment_method'],
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'notes' => $data['notes'] ?? null, 'paid_at' => $data['paid_at'] ?? now(),
            ]);

            $remaining = $amount;
            foreach ($loan->schedules()->whereColumn('amount_paid', '<', 'amount_due')->lockForUpdate()->get() as $schedule) {
                $allocated = min($remaining, $schedule->amount_due - $schedule->amount_paid);
                if ($allocated <= 0) {
                    continue;
                }
                DB::connection('tenant')->table('repayment_allocations')->insert([
                    'repayment_id' => $repayment->id, 'repayment_schedule_id' => $schedule->id,
                    'amount' => $allocated, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $schedule->increment('amount_paid', $allocated);
                $schedule->update(['status' => $schedule->fresh()->amount_paid >= $schedule->amount_due ? 'paid' : 'partially_paid']);
                $remaining -= $allocated;
                if ($remaining === 0) {
                    break;
                }
            }

            $balance = $outstanding - $amount;
            if ($balance === 0) {
                $loan->update(['status' => 'completed']);
            }
            $borrower = $loan->borrower()->first();
            $collector = User::query()->find($userId);
            $tenant = $this->context->tenant();
            $issuedAt = now();
            $receipt = Receipt::query()->create([
                'receipt_number' => 'RCPT-'.now()->format('ymd').'-'.strtoupper(Str::random(6)),
                'repayment_id' => $repayment->id, 'loan_id' => $loan->id,
                'amount_paid' => $amount, 'remaining_balance' => $balance, 'issued_at' => $issuedAt,
                'snapshot' => [
                    'company' => ['name' => $tenant->name, 'phone' => $tenant->settings['phone'] ?? null, 'email' => $tenant->settings['email'] ?? null, 'address' => $tenant->settings['address'] ?? null],
                    'borrower' => ['id' => $borrower?->id, 'name' => $borrower?->full_name, 'phone' => $borrower?->phone_number],
                    'loan' => ['id' => $loan->id, 'number' => $loan->loan_number],
                    'payment' => ['method' => $repayment->payment_method, 'reference' => $repayment->transaction_reference, 'paid_at' => $repayment->paid_at],
                    'collector' => ['id' => $collector?->id, 'name' => $collector?->name],
                ],
            ]);
            AuditLog::query()->create([
                'user_id' => $userId, 'action' => 'repayment.recorded', 'entity_type' => Repayment::class,
                'entity_id' => $repayment->id, 'new_values' => ['amount' => $amount, 'receipt' => $receipt->receipt_number],
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
            ]);

            return $repayment->setRelation('receipt', $receipt);
        });
    }

    public function reverse(Repayment $original, string $reason, string $userId): Repayment
    {
        return DB::connection('tenant')->transaction(function () use ($original, $reason, $userId) {
            $original = Repayment::query()->lockForUpdate()->findOrFail($original->id);
            throw_if($original->entry_type !== 'payment', ValidationException::withMessages(['repayment' => 'Only confirmed payments can be reversed.']));
            throw_if(Repayment::query()->where('reverses_repayment_id', $original->id)->exists(), ValidationException::withMessages(['repayment' => 'This payment has already been reversed.']));

            $reversal = Repayment::query()->create([
                'loan_id' => $original->loan_id, 'recorded_by' => $userId,
                'reverses_repayment_id' => $original->id, 'amount' => -$original->amount,
                'entry_type' => 'reversal', 'payment_method' => $original->payment_method,
                'reversal_reason' => $reason, 'paid_at' => now(),
            ]);
            $allocations = DB::connection('tenant')->table('repayment_allocations')->where('repayment_id', $original->id)->get();
            foreach ($allocations as $allocation) {
                $schedule = DB::connection('tenant')->table('repayment_schedules')->where('id', $allocation->repayment_schedule_id)->first();
                $paid = max(0, $schedule->amount_paid - $allocation->amount);
                DB::connection('tenant')->table('repayment_schedules')->where('id', $schedule->id)->update([
                    'amount_paid' => $paid, 'status' => $paid === 0 ? 'upcoming' : 'partially_paid', 'updated_at' => now(),
                ]);
            }
            Loan::query()->whereKey($original->loan_id)->update(['status' => 'active']);
            Receipt::query()->where('repayment_id', $original->id)->update(['status' => 'reversed', 'reversed_at' => now()]);
            AuditLog::query()->create([
                'user_id' => $userId, 'action' => 'repayment.reversed', 'entity_type' => Repayment::class,
                'entity_id' => $reversal->id, 'old_values' => ['repayment_id' => $original->id, 'amount' => $original->amount],
                'new_values' => ['amount' => -$original->amount, 'reason' => $reason],
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
            ]);

            return $reversal;
        });
    }
}
