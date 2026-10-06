<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class InitializeTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->header('X-Tenant');
        abort_if(blank($slug), 400, 'Select a lending company to continue.');

        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        app(TenantContext::class)->initialize($tenant);
        Auth::forgetGuards();
        $user = Auth::guard('sanctum')->user();
        abort_unless($user && $user->is_active, 401, 'Sign in to continue.');
        $request->setUserResolver(fn () => $user);

        try {
            return $next($request);
        } finally {
            Auth::forgetGuards();
            app(TenantContext::class)->forget();
        }
    }
}
