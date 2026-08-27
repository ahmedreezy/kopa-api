<?php

namespace App\Models;

class Branch extends TenantModel
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
