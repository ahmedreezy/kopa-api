<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanProduct extends TenantModel
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'interest_rate' => 'decimal:4',
            'processing_fee_value' => 'decimal:2',
            'collateral_required' => 'boolean',
            'minimum_collateral_value_percent' => 'decimal:2',
            'repayment_frequencies' => 'array',
            'required_documents' => 'array',
        ];
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
