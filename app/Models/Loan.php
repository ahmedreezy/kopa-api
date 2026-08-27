<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends TenantModel
{
    protected $appends = ['outstanding_amount'];

    protected function casts(): array
    {
        return [
            'disbursement_date' => 'date', 'first_repayment_date' => 'date',
            'maturity_date' => 'date', 'confirmed_at' => 'datetime',
            'interest_rate' => 'decimal:4',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(RepaymentSchedule::class)->orderBy('installment_number');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(Repayment::class);
    }

    public function getOutstandingAmountAttribute(): int
    {
        return max(0, (int) $this->total_payable - (int) $this->repayments()->sum('amount'));
    }
}
