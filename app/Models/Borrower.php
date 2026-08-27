<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Borrower extends TenantModel
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
