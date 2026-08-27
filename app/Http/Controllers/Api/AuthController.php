<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterCompanyRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterCompanyRequest $request, TenantProvisioningService $provisioner): JsonResponse
    {
        $result = $provisioner->provision(
            ['name' => $request->string('company_name')->toString(), 'phone' => $request->input('company_phone')],
            $request->only('name', 'email', 'phone', 'password'),
        );
        $token = $result['user']->createToken('web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'tenant' => $result['tenant']->only('id', 'name', 'slug', 'status'),
            'user' => $result['user']->only('id', 'name', 'email', 'role', 'branch_id'),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'tenant' => ['required', 'string'], 'email' => ['required', 'email'], 'password' => ['required', 'string'],
        ]);
        $tenant = Tenant::query()->where('slug', $credentials['tenant'])->firstOrFail();
        app(TenantContext::class)->initialize($tenant);
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'The company, email, or password is incorrect.'], 422);
        }

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'tenant' => $tenant->only('id', 'name', 'slug', 'status'),
            'user' => $user->only('id', 'name', 'email', 'role', 'branch_id'),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }
}
