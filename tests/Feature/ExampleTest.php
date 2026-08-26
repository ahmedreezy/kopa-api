<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_api_health_endpoint_is_available_to_the_frontend(): void
    {
        $response = $this
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/health');

        $response
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertJson([
                'status' => 'ok',
                'message' => 'Kopa API is connected.',
            ]);
    }
}
