<?php

namespace Tests\Feature;

use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_company_staff_branches_and_reports(): void
    {
        app(TenantProvisioningService::class)->provision(['name' => 'Kiboga Capital'], [
            'name' => 'Ahmed Kato', 'email' => 'owner@kiboga.test', 'password' => 'password',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'tenant' => 'kiboga-capital', 'email' => 'owner@kiboga.test', 'password' => 'password',
        ])->assertOk();
        $headers = [
            'Authorization' => 'Bearer '.$login->json('token'),
            'X-Tenant' => 'kiboga-capital',
        ];

        $this->withHeaders($headers)->putJson('/api/company', [
            'name' => 'Kiboga Capital Limited',
            'phone' => '0772400118',
            'email' => 'accounts@kiboga.test',
            'address' => 'Kiboga Town',
            'currency' => 'UGX',
        ])->assertOk()->assertJsonPath('name', 'Kiboga Capital Limited');

        $branch = $this->withHeaders($headers)->postJson('/api/branches', [
            'name' => 'Kampala Branch', 'code' => 'KLA', 'phone' => '0700000000',
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/staff', [
            'name' => 'Mary Nakato',
            'email' => 'mary@kiboga.test',
            'phone' => '0711000000',
            'role' => 'collector',
            'branch_id' => $branch->json('id'),
            'password' => 'password',
        ])->assertCreated()->assertJsonPath('role', 'collector');

        $this->withHeaders($headers)->getJson('/api/reports/summary?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonStructure(['money_lent', 'money_collected', 'interest_expected', 'outstanding', 'overdue', 'collections_by_staff']);
    }
}
