<?php

namespace App\Services\Tenancy;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class TenantProvisioningService
{
    public function provision(array $company, array $owner): array
    {
        $sequence = ((int) Tenant::query()->max('sequence')) + 1;
        $tenant = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'sequence' => $sequence,
            'name' => $company['name'],
            'slug' => $this->uniqueSlug($company['name']),
            'database_name' => 'tenant_'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'owner_email' => $owner['email'],
            'status' => 'provisioning',
            'settings' => ['currency' => 'UGX', 'phone' => $company['phone'] ?? null],
        ]);

        try {
            $this->createDatabase($tenant);
            app(TenantContext::class)->initialize($tenant->forceFill(['status' => 'active']));
            Artisan::call('migrate', [
                '--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true,
            ]);

            $branch = Branch::query()->create(['name' => 'Head Office', 'code' => 'HQ', 'is_active' => true]);
            $user = User::query()->create([
                'id' => (string) Str::uuid(),
                'branch_id' => $branch->id,
                'name' => $owner['name'],
                'email' => $owner['email'],
                'phone' => $owner['phone'] ?? null,
                'role' => 'owner',
                'password' => Hash::make($owner['password']),
                'is_active' => true,
            ]);

            $tenant->forceFill(['status' => 'active', 'provisioned_at' => now()])->save();

            return compact('tenant', 'user');
        } catch (Throwable $exception) {
            $tenant->forceFill(['status' => 'provisioning_failed', 'provisioning_error' => $exception->getMessage()])->save();
            throw $exception;
        }
    }

    private function createDatabase(Tenant $tenant): void
    {
        if (config('database.connections.tenant.driver') === 'sqlite') {
            $directory = config('database.connections.tenant.database_directory');
            File::ensureDirectoryExists($directory);
            File::put($directory.'/'.$tenant->database_name.'.sqlite', '');

            return;
        }

        abort_unless(preg_match('/^tenant_[0-9]{6}$/', $tenant->database_name), 500, 'Unsafe tenant database name.');
        DB::connection('platform')->statement('CREATE DATABASE "'.$tenant->database_name.'"');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;
        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
