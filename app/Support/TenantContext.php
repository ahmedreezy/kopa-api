<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class TenantContext
{
    private ?Tenant $tenant = null;

    public function initialize(Tenant $tenant): void
    {
        abort_unless($tenant->status === 'active', 423, 'This lending company is not active.');

        DB::purge('tenant');
        $driver = config('database.connections.tenant.driver');
        Config::set('database.connections.tenant.database', $driver === 'sqlite'
            ? config('database.connections.tenant.database_directory').'/'.$tenant->database_name.'.sqlite'
            : $tenant->database_name);
        DB::reconnect('tenant');
        $this->tenant = $tenant;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new \RuntimeException('Tenant context has not been initialized.');
    }

    public function forget(): void
    {
        DB::purge('tenant');
        $this->tenant = null;
    }
}
