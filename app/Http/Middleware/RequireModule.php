<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(AppSetting::isOn($module), 404);

        return $next($request);
    }
}
