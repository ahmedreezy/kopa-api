<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Repayment extends TenantModel
{
    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'metadata' => 'array'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
