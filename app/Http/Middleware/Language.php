<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;

class Language
{
    public function handle($request, Closure $next)
    {
        $requestedLocale = $request->header('x-locale')
            ?: $request->header('content-language')
            ?: $request->header('accept-language');

        if (is_string($requestedLocale) && $requestedLocale !== '') {
            $locale = strtolower(str_replace('_', '-', trim(explode(',', $requestedLocale)[0])));

            if (strpos($locale, 'en') === 0) {
                App::setLocale('en-US');
            } elseif (strpos($locale, 'zh') === 0) {
                App::setLocale('zh-CN');
            }
        }
        return $next($request);
    }
}
