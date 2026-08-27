<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasUuids;

    protected $connection = 'platform';

    protected $fillable = [
        'id', 'sequence', 'name', 'slug', 'database_name', 'status', 'plan', 'owner_email',
        'provisioning_error', 'settings', 'provisioned_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'provisioned_at' => 'datetime',
        ];
    }
}
