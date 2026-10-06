<?php

namespace Tests\Feature;

use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
            ->assertJsonStructure([
                'period' => ['from', 'to'], 'money_lent', 'loans_disbursed', 'money_collected',
                'gross_collected', 'reversed_amount', 'loans_with_collections', 'completed_loans',
                'scheduled_due', 'collection_rate', 'interest_expected', 'outstanding', 'overdue',
                'collections_by_staff', 'loan_collections',
            ]);
    }

    public function test_roles_receive_distinct_views_and_privileges(): void
    {
        app(TenantProvisioningService::class)->provision(['name' => 'Role Test Capital'], [
            'name' => 'Workspace Owner', 'email' => 'owner@roles.test', 'password' => 'password',
        ]);

        $ownerLogin = $this->postJson('/api/auth/login', [
            'tenant' => 'role-test-capital', 'email' => 'owner@roles.test', 'password' => 'password',
        ])->assertOk()->assertJsonPath('user.permissions.0', '*');
        $ownerHeaders = [
            'Authorization' => 'Bearer '.$ownerLogin->json('token'),
            'X-Tenant' => 'role-test-capital',
        ];
        $branch = $this->withHeaders($ownerHeaders)->getJson('/api/branches')->assertOk()->json('0.id');

        foreach (['manager', 'loan_officer', 'collector', 'accountant'] as $role) {
            $this->withHeaders($ownerHeaders)->postJson('/api/staff', [
                'name' => Str::headline($role),
                'email' => $role.'@roles.test',
                'role' => $role,
                'branch_id' => $branch,
                'password' => 'password',
            ])->assertCreated();
        }

        $headersFor = function (string $role): array {
            $login = $this->postJson('/api/auth/login', [
                'tenant' => 'role-test-capital', 'email' => $role.'@roles.test', 'password' => 'password',
            ])->assertOk();

            return [
                'Authorization' => 'Bearer '.$login->json('token'),
                'X-Tenant' => 'role-test-capital',
            ];
        };

        $manager = $headersFor('manager');
        $this->withHeaders($manager)->getJson('/api/reports/summary')->assertOk();
        $this->withHeaders($manager)->getJson('/api/staff')->assertOk();
        $this->withHeaders($manager)->postJson('/api/staff', [])->assertForbidden();
        $this->withHeaders($manager)->putJson('/api/company', [])->assertForbidden();

        $officer = $headersFor('loan_officer');
        $this->withHeaders($officer)->getJson('/api/loans')->assertOk();
        $this->withHeaders($officer)->getJson('/api/reports/summary')->assertForbidden();
        $this->withHeaders($officer)->getJson('/api/collections')->assertForbidden();

        $collector = $headersFor('collector');
        $this->withHeaders($collector)->getJson('/api/collections')->assertOk();
        $this->withHeaders($collector)->getJson('/api/borrowers')->assertOk();
        $this->withHeaders($collector)->getJson('/api/reports/summary')->assertForbidden();

        $accountant = $headersFor('accountant');
        $this->withHeaders($accountant)->getJson('/api/reports/summary')->assertOk();
        $this->withHeaders($accountant)->getJson('/api/collections')->assertOk();
        $this->withHeaders($accountant)->postJson('/api/loans/'.Str::uuid().'/repayments', [])->assertForbidden();
    }
}
