<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Loan extends TenantModel
{
    protected $appends = ['outstanding_amount'];

    protected function casts(): array
    {
        return [
            'disbursement_date' => 'date', 'first_repayment_date' => 'date',
            'maturity_date' => 'date', 'confirmed_at' => 'datetime',
            'interest_rate' => 'decimal:4',
            'effective_monthly_rate' => 'decimal:4', 'effective_annual_rate' => 'decimal:4',
            'product_snapshot' => 'array',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(RepaymentSchedule::class)->orderBy('installment_number');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(Repayment::class);
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(Guarantor::class);
    }

    public function collateral(): HasMany
    {
        return $this->hasMany(Collateral::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function getOutstandingAmountAttribute(): int
    {
        return max(0, (int) $this->total_payable - (int) $this->repayments()->sum('amount'));
    }
}
