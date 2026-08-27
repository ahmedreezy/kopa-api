<?php

namespace Tests\Feature;

use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanctum_resolves_the_tenant_before_hydrating_the_user(): void
    {
        app(TenantProvisioningService::class)->provision(['name' => 'Kiboga Capital'], [
            'name' => 'Ahmed Kato', 'email' => 'owner@kiboga.test', 'password' => 'password',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'tenant' => 'kiboga-capital', 'email' => 'owner@kiboga.test', 'password' => 'password',
        ])->assertOk();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$login->json('token'),
            'X-Tenant' => 'kiboga-capital',
        ])->getJson('/api/dashboard')->assertOk()->assertJsonPath('metrics.active_loans', 0);
    }
}
