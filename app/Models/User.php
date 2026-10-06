<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $connection = 'tenant';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $appends = ['permissions'];

    private const ROLE_PERMISSIONS = [
        'owner' => ['*'],
        'manager' => [
            'dashboard.view', 'search.use', 'borrowers.view', 'borrowers.manage', 'borrowers.export',
            'borrowers.export_bulk', 'documents.view', 'documents.manage', 'loan_products.view',
            'loan_products.manage', 'loans.view', 'loans.manage', 'collections.view', 'repayments.create',
            'repayments.reverse', 'receipts.view', 'reports.view', 'staff.view', 'branches.view',
            'branches.manage', 'company.view', 'audit.view',
        ],
        'loan_officer' => [
            'dashboard.view', 'search.use', 'borrowers.view', 'borrowers.manage', 'borrowers.export',
            'documents.view', 'documents.manage', 'loan_products.view', 'loans.view', 'loans.manage',
            'branches.view', 'company.view',
        ],
        'collector' => [
            'dashboard.view', 'search.use', 'borrowers.view', 'loans.view', 'collections.view',
            'repayments.create', 'receipts.view', 'company.view',
        ],
        'accountant' => [
            'dashboard.view', 'search.use', 'borrowers.view', 'loans.view', 'collections.view',
            'receipts.view', 'reports.view', 'company.view',
        ],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'branch_id',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function canPerform(string $permission): bool
    {
        $granted = $this->permissions();

        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    public function permissions(): array
    {
        return self::ROLE_PERMISSIONS[$this->role] ?? [];
    }

    public function getPermissionsAttribute(): array
    {
        return $this->permissions();
    }
}
