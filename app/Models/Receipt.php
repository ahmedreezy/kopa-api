<?php

namespace App\Models;

class Receipt extends TenantModel
{
    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }
}
