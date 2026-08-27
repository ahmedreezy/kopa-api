<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepaymentSchedule extends TenantModel
{
    protected function casts(): array
    {
        return ['due_date' => 'date'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
