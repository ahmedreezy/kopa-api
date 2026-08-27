<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_records_are_isolated_by_database(): void
    {
        $service = app(TenantProvisioningService::class);
        $first = $service->provision(['name' => 'Alpha Loans'], [
            'name' => 'Alpha Owner', 'email' => 'alpha@example.test', 'password' => 'password',
        ]);
        $second = $service->provision(['name' => 'Beta Loans'], [
            'name' => 'Beta Owner', 'email' => 'beta@example.test', 'password' => 'password',
        ]);

        app(TenantContext::class)->initialize($first['tenant']);
        Borrower::query()->create([
            'branch_id' => $first['user']->branch_id, 'full_name' => 'John Kato', 'phone_number' => '0772000000',
        ]);
        $this->assertSame(1, Borrower::query()->count());

        app(TenantContext::class)->initialize($second['tenant']);
        $this->assertSame(0, Borrower::query()->count());
    }
}
