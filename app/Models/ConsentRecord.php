<?php

namespace App\Models;

class ConsentRecord extends TenantModel
{
    protected function casts(): array
    {
        return ['granted_at' => 'datetime'];
    }
}
