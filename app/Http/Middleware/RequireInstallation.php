<?php
namespace App\Http\Middleware;
class RequireInstallation
{
    public function handle($request, \Closure $next) {
        if (!$request->is('setup') && !\App\Models\User::exists()) return redirect('/setup');
        return $next($request);
    }
}
