<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends TenantModel
{
    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'reversed_at' => 'datetime', 'snapshot' => 'array'];
    }

    public function repayment(): BelongsTo
    {
        return $this->belongsTo(Repayment::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
