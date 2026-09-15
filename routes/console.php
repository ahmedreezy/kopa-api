<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\Tenant;
use App\Support\TenantContext;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tenants:migrate {--tenant= : Migrate one tenant slug only}', function (TenantContext $context) {
    $query = Tenant::query()->where('status', 'active')->orderBy('sequence');
    if ($slug = $this->option('tenant')) $query->where('slug', $slug);
    $tenants = $query->get();
    if ($tenants->isEmpty()) {
        $this->warn('No matching active tenants found.');
        return Command::SUCCESS;
    }
    foreach ($tenants as $tenant) {
        $this->components->task("Migrating {$tenant->slug}", function () use ($tenant, $context) {
            $context->initialize($tenant);
            return Artisan::call('migrate', [
                '--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true,
            ]) === Command::SUCCESS;
        });
    }
    $context->forget();
    return Command::SUCCESS;
})->purpose('Run outstanding tenant-schema migrations for all or one active workspace');
