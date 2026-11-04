<?php
namespace App\Http\Middleware;
use App\Support\FileStore\Store;
class LocalWrite
{
    public function handle($request, \Closure $next)
    {
        if ($request->method() !== 'POST') return $next($request);
        $response = null;
        try {
            return Store::transaction(function () use ($request, $next, &$response) {
                $response = $next($request);
                if ($response->getStatusCode() >= 400) throw new LocalWriteRollback;
                return $response;
            });
        } catch (LocalWriteRollback $exception) { return $response; }
    }
}
class LocalWriteRollback extends \RuntimeException {}
